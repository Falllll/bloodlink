<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Jobs;

use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Notification\Application\Exceptions\DeliveryFailed;
use App\Modules\Notification\Application\RenderNotification;
use App\Modules\Notification\Application\ResolveSender;
use App\Modules\Notification\Application\RevokeDeviceToken;
use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Domain\NotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Satu job = satu penerima x satu kanal (kartu 460). Satu job per kampanye akan
 * gagal seluruhnya karena satu alamat buruk, dan retry-nya mengirim ganda ke
 * semua yang sudah menerima.
 *
 * Ketahanan (kartu 470): DeliveryFailed TRANSIENT diulang dengan backoff berjitter
 * sampai $tries; PERMANENT langsung dead-letter lewat $this->fail(). Status 'failed'
 * ditulis HANYA di failed().
 */
final class SendNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string QUEUE = 'notifications';

    /**
     * Dari config, bukan konstanta: angka operasional (config/notification.php).
     * Mengalahkan `tries` supervisor Horizon, yang hanya berlaku untuk job tanpa $tries.
     */
    public int $tries;

    /**
     * Payload HANYA public_id, bukan model: payload antrean yang memuat model membawa
     * data pengguna ke Redis dan ke failed_jobs.payload (longText) yang tidak pernah
     * dipangkas. Satu uuid sudah cukup untuk memuat ulang barisnya.
     */
    public function __construct(
        public readonly string $deliveryPublicId,
    ) {
        $this->tries = (int) config('notification.delivery_max_attempts');
        $this->onQueue(self::QUEUE);
        // config/queue.php menyetel after_commit => false di semua koneksi. Tanpa ini,
        // worker bisa mengambil job sebelum transaksi pembuat barisnya commit, tidak
        // menemukan barisnya, dan keluar diam-diam -- acak di bawah beban, hijau di test.
        // Di constructor, bukan di pemanggil: penerbit baru tidak bisa lupa.
        $this->afterCommit();
    }

    /**
     * Jeda (detik) sebelum percobaan ke-2, ke-3, dst: base * 2^i, digeser acak +/- rasio.
     *
     * Method, bukan properti: properti tidak bisa memuat nilai acak per job. Laravel
     * memanggilnya SEKALI saat dispatch dan menyimpan hasilnya di payload, jadi
     * jitter-nya per job -- dan itulah yang memecah serbuan: job-job yang gagal pada
     * pemadaman yang sama kembali di detik yang berbeda.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $base = (int) config('notification.delivery_base_backoff_seconds');
        $ratio = (float) config('notification.delivery_jitter_ratio');
        $delays = [];

        for ($i = 0; $i < $this->tries - 1; $i++) {
            $shift = $ratio * (random_int(-1000, 1000) / 1000);
            // Minimal 1 detik: jitter yang membuat jeda 0 menghapus backoff-nya.
            $delays[] = max(1, (int) round($base * (2 ** $i) * (1 + $shift)));
        }

        return $delays;
    }

    public function handle(ResolveSender $resolve, RenderNotification $render, RevokeDeviceToken $revoke): void
    {
        // Percobaan dicatat di LUAR transaksi kirim, supaya ikut ter-commit walau
        // pengirimannya gagal dan transaksinya di-rollback. Bersyarat 'pending':
        // delivery yang sudah selesai tidak dihitung dan tidak dikirim ulang.
        $claimed = NotificationDelivery::query()
            ->where('public_id', $this->deliveryPublicId)
            ->where('status', DeliveryStatus::PENDING->value)
            ->update(['attempts' => DB::raw('attempts + 1'), 'last_attempt_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        try {
            $this->send($resolve, $render);
        } catch (DeliveryFailed $e) {
            if (! $e->isPermanent()) {
                // TRANSIENT: naik ke worker, yang menjadwalkan ulang sesuai backoff().
                throw $e;
            }

            if ($e->addressIsDead) {
                $this->revokeDeadToken($revoke);
            }

            // PERMANENT: dead-letter sekarang, tanpa menghabiskan sisa percobaan.
            // fail() memanggil failed() -- satu-satunya penulis status 'failed'.
            $this->fail($e);
        }
    }

    /**
     * Dead-letter: satu-satunya tempat status 'failed' ditulis (kartu 470). Dipanggil
     * worker setelah percobaan habis, atau oleh $this->fail() untuk kegagalan permanen.
     * CHECK notification_deliveries_settle_shape menjamin failure_code terisi.
     */
    public function failed(?Throwable $e): void
    {
        [$code, $detail] = $e instanceof DeliveryFailed
            ? [$e->failureCode, $e->getMessage()]
            // Pesan exception lain tidak dijamin bebas data pribadi: kelasnya saja.
            : [substr(Str::snake(class_basename($e ?? 'unknown_failure')), 0, 64), $e === null ? null : $e::class];

        NotificationDelivery::query()
            ->where('public_id', $this->deliveryPublicId)
            ->where('status', DeliveryStatus::PENDING->value)
            ->update([
                'status' => DeliveryStatus::FAILED->value,
                'failure_code' => $code,
                'failure_detail' => $detail,
                'settled_at' => now(),
                'variables' => null,
            ]);
    }

    private function send(ResolveSender $resolve, RenderNotification $render): void
    {
        // Baris dikunci selama pengiriman: dua salinan job atas delivery yang sama
        // berjalan berurutan, dan yang kedua melihat 'sent' lalu keluar tanpa mengirim.
        DB::transaction(function () use ($resolve, $render): void {
            $delivery = NotificationDelivery::query()
                ->where('public_id', $this->deliveryPublicId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null || $delivery->status !== DeliveryStatus::PENDING) {
                return;
            }

            // Kebijakan kanal diperiksa sebelum render: kanal yang dimatikan sesudah
            // dispatch melempar ChannelNotPermitted, tidak dikirim diam-diam.
            $sender = $resolve->for($delivery->channel);

            // Penerima sudah tidak bisa dituju: akun dihapus, token dicabut, atau token
            // PINDAH ke akun lain (RegisterDeviceToken) -- mengirim ke sana berarti pesan
            // si A mendarat di layar si B. Retry tidak mengubahnya.
            $address = $this->addressFor($delivery)
                ?? throw DeliveryFailed::permanent('recipient_unreachable', 'The recipient account or device is no longer deliverable.');

            $message = $render->handle($delivery->template_code, $delivery->channel, $delivery->variables ?? [], $delivery->locale);

            $sender->send($address, $message);

            $delivery->forceFill([
                'status' => DeliveryStatus::SENT,
                'settled_at' => now(),
                // Bahan render tidak disimpan lebih lama dari yang dibutuhkan.
                'variables' => null,
            ])->save();
        });
    }

    private function addressFor(NotificationDelivery $delivery): ?string
    {
        if ($delivery->channel === NotificationChannel::EMAIL) {
            return User::query()->whereKey($delivery->user_id)->value('email');
        }

        return DeviceToken::query()
            ->deliverable()
            ->whereKey($delivery->device_token_id)
            ->where('user_id', $delivery->user_id)
            ->value('token');
    }

    /**
     * FCM menyatakan tokennya mati (UNREGISTERED): cabut, supaya scopeDeliverable()
     * tidak memilihnya lagi di kampanye berikutnya. Di sini, SESUDAH transaksi kirim
     * di-rollback -- dicabut di dalam pengirim akan ikut batal bersama rollback itu.
     */
    private function revokeDeadToken(RevokeDeviceToken $revoke): void
    {
        $tokenId = NotificationDelivery::query()->where('public_id', $this->deliveryPublicId)->value('device_token_id');
        $device = $tokenId === null ? null : DeviceToken::query()->find($tokenId);

        if ($device !== null) {
            $revoke->handle($device);
        }
    }
}
