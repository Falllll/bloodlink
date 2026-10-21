<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure;

use App\Modules\Notification\Application\Exceptions\DeliveryFailed;
use App\Modules\Notification\Domain\NotificationChannel;
use App\Modules\Notification\Domain\NotificationSender;
use App\Modules\Notification\Domain\RenderedNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Push lewat FCM HTTP v1 (kartu 460). Legacy server key sudah dimatikan Google:
 * v1 menuntut access token OAuth2 yang ditukar dari service account.
 *
 * Penukarannya ditulis MANUAL (JWT RS256 lewat ext-openssl, grant jwt-bearer)
 * alih-alih menambah google/auth: satu request, alurnya didokumentasikan di
 * https://developers.google.com/identity/protocols/oauth2/service-account#httprest
 *
 * Singleton: access token (berlaku <= 1 jam) disimpan di memori proses worker,
 * tidak di Redis maupun database.
 */
final class FcmNotificationSender implements NotificationSender
{
    private const string SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const string DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    // Ditukar ulang sedikit sebelum kedaluwarsa, supaya tidak kedaluwarsa di tengah request.
    private const int EXPIRY_MARGIN_SECONDS = 60;

    private const int TIMEOUT_SECONDS = 10;

    /**
     * Kode FCM yang TIDAK diulang (kartu 470), diverifikasi ke
     * https://firebase.google.com/docs/cloud-messaging/error-codes pada 21 Okt 2026:
     * dokumentasinya menyatakan keempatnya tidak boleh dicoba ulang sebelum
     * request/token/kredensialnya diperbaiki.
     *
     * Hanya UNREGISTERED yang mencabut token: tokennya sendiri yang mati.
     * SENDER_ID_MISMATCH SENGAJA tidak mencabut -- FCM_PROJECT_ID yang salah akan
     * membuat SEMUA token mismatch, dan mencabutnya berarti menghapus seluruh registri.
     *
     * Semua kode lain -- QUOTA_EXCEEDED, UNAVAILABLE, INTERNAL, UNSPECIFIED_ERROR, dan
     * kode yang belum dikenal -- adalah TRANSIENT: menyerah terlalu cepat pada
     * notifikasi darurat lebih buruk daripada satu percobaan berlebih.
     */
    private const array PERMANENT_FCM_CODES = [
        'UNREGISTERED' => true,
        'INVALID_ARGUMENT' => false,
        'SENDER_ID_MISMATCH' => false,
        'THIRD_PARTY_AUTH_ERROR' => false,
    ];

    private ?string $accessToken = null;

    private int $accessTokenExpiresAt = 0;

    public function __construct(
        private readonly ?string $credentialsPath,
        private readonly ?string $projectId,
        private readonly string $title,
    ) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::PUSH;
    }

    public function send(string $address, RenderedNotification $message): void
    {
        $projectId = $this->projectId ?: throw DeliveryFailed::permanent('fcm_not_configured', 'FCM_PROJECT_ID is empty.');

        try {
            $response = Http::withToken($this->accessToken())
                ->timeout(self::TIMEOUT_SECONDS)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'token' => $address,
                        'notification' => [
                            'title' => $this->title,
                            'body' => $message->body,
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            throw DeliveryFailed::transient('fcm_unreachable', 'FCM could not be reached (connection failed or timed out).');
        }

        if ($response->successful()) {
            return;
        }

        $fcmCode = $this->fcmErrorCode($response);

        if ($fcmCode !== null && array_key_exists($fcmCode, self::PERMANENT_FCM_CODES)) {
            throw DeliveryFailed::permanent(
                'fcm_'.strtolower($fcmCode),
                "FCM rejected the message permanently: {$fcmCode}.",
                addressIsDead: self::PERMANENT_FCM_CODES[$fcmCode],
            );
        }

        if ($response->status() === 401) {
            // Access token kita dicabut lebih awal: percobaan berikutnya menukar yang baru.
            $this->accessToken = null;
        }

        // Kode yang tidak dikenal = TRANSIENT (lihat PERMANENT_FCM_CODES).
        throw DeliveryFailed::transient(
            $fcmCode === null ? 'fcm_http_'.$response->status() : 'fcm_'.strtolower($fcmCode),
            'FCM rejected the message: '.($fcmCode ?? 'HTTP '.$response->status()).'.',
        );
    }

    /**
     * Kode spesifik FCM ada di error.details[] bertipe FcmError, BUKAN di error.status
     * (yang hanya status kanonik Google, mis. NOT_FOUND untuk UNREGISTERED).
     */
    private function fcmErrorCode(Response $response): ?string
    {
        $details = $response->json('error.details');

        foreach (is_array($details) ? $details : [] as $detail) {
            if (is_array($detail)
                && str_ends_with((string) ($detail['@type'] ?? ''), 'google.firebase.fcm.v1.FcmError')
                && is_string($detail['errorCode'] ?? null)) {
                return $detail['errorCode'];
            }
        }

        return null;
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt - self::EXPIRY_MARGIN_SECONDS) {
            return $this->accessToken;
        }

        $account = $this->serviceAccount();
        $now = time();

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($account['token_uri'], [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->signedAssertion($account, $now),
                ]);
        } catch (ConnectionException) {
            throw DeliveryFailed::transient('fcm_auth_unreachable', 'The OAuth2 token endpoint for FCM could not be reached.');
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        if (! $response->successful() || ! is_string($token) || ! is_int($expiresIn)) {
            // Bisa pemadaman Google, bisa kunci yang dicabut: tidak bisa dibedakan dengan
            // pasti dari sini, jadi TRANSIENT (default untuk yang tidak dikenal).
            throw DeliveryFailed::transient('fcm_auth', 'OAuth2 token exchange for FCM failed with HTTP '.$response->status().'.');
        }

        $this->accessToken = $token;
        $this->accessTokenExpiresAt = $now + $expiresIn;

        return $token;
    }

    /**
     * Konfigurasi yang hilang atau rusak adalah PERMANENT: lima percobaan tidak akan
     * memunculkan berkas service account, dan dead-letter seketika membuatnya terlihat.
     *
     * @return array{client_email: string, private_key: string, token_uri: string}
     */
    private function serviceAccount(): array
    {
        $path = $this->credentialsPath ?: throw DeliveryFailed::permanent('fcm_not_configured', 'FCM_CREDENTIALS_PATH is empty.');
        $raw = is_readable($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            throw DeliveryFailed::permanent('fcm_not_configured', 'FCM service account file is not readable.');
        }

        try {
            $json = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DeliveryFailed::permanent('fcm_not_configured', 'FCM service account file is not valid JSON.');
        }

        if (! is_array($json) || ! is_string($json['client_email'] ?? null) || ! is_string($json['private_key'] ?? null)) {
            throw DeliveryFailed::permanent('fcm_not_configured', 'FCM service account file lacks client_email or private_key.');
        }

        return [
            'client_email' => $json['client_email'],
            'private_key' => $json['private_key'],
            'token_uri' => is_string($json['token_uri'] ?? null) ? $json['token_uri'] : self::DEFAULT_TOKEN_URI,
        ];
    }

    /** @param  array{client_email: string, private_key: string, token_uri: string}  $account */
    private function signedAssertion(array $account, int $now): string
    {
        $segments = [
            self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => $account['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $key = openssl_pkey_get_private($account['private_key']);
        $signature = '';

        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw DeliveryFailed::permanent('fcm_not_configured', 'FCM service account private key cannot sign.');
        }

        return implode('.', [...$segments, self::base64Url($signature)]);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
