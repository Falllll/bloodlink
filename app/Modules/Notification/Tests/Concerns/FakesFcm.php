<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests\Concerns;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\After;

/**
 * FCM palsu di level TRANSPORT (Http::fake): service account dengan kunci RSA
 * sungguhan, dibuat per test dan dihapus sesudahnya. Pengirimnya tetap kode asli.
 */
trait FakesFcm
{
    private const string FCM_SEND_URL = 'https://fcm.googleapis.com/v1/projects/bloodlink-test/messages:send';

    private const string TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private ?string $fcmCredentialsPath = null;

    /**
     * @param  int  $sendStatus  status HTTP balasan messages:send
     * @param  string|null  $fcmErrorCode  isi FcmError.errorCode di error.details, bila gagal
     */
    private function fakeFcm(int $sendStatus = 200, ?string $fcmErrorCode = null): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $pem);

        $this->fcmCredentialsPath = (string) tempnam(sys_get_temp_dir(), 'fcm-test-');
        file_put_contents($this->fcmCredentialsPath, json_encode([
            'type' => 'service_account',
            'client_email' => 'sender@bloodlink-test.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => self::TOKEN_URI,
        ]));

        config(['services.fcm.credentials_path' => $this->fcmCredentialsPath, 'services.fcm.project_id' => 'bloodlink-test']);

        // Bentuk galat FCM v1: kode spesifik di details[] bertipe FcmError, bukan di status.
        $error = ['error' => array_filter([
            'code' => $sendStatus,
            'status' => 'FAILED_PRECONDITION',
            'details' => $fcmErrorCode === null ? null : [[
                '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                'errorCode' => $fcmErrorCode,
            ]],
        ], fn (mixed $value): bool => $value !== null)];

        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599, 'token_type' => 'Bearer']),
            self::FCM_SEND_URL => $sendStatus === 200
                ? Http::response(['name' => 'projects/bloodlink-test/messages/1'])
                : Http::response($error, $sendStatus),
        ]);
    }

    #[After]
    protected function forgetFakeFcmCredentials(): void
    {
        if ($this->fcmCredentialsPath !== null && is_file($this->fcmCredentialsPath)) {
            unlink($this->fcmCredentialsPath);
        }
    }
}
