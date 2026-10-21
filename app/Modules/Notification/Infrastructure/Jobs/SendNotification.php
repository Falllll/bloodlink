<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Jobs;

use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Notification\Application\RenderNotification;
use App\Modules\Notification\Application\ResolveSender;
use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Domain\NotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Satu job = satu penerima x satu kanal (kartu 460). Satu job per kampanye akan
 * gagal seluruhnya karena satu alamat buruk, dan retry-nya mengirim ganda ke
 * semua yang sudah menerima.
 *
 * $tries/$backoff/failed() SENGAJA tidak ada: kartu 470. Sampai itu, tries=3
 * dari supervisor Horizon yang berlaku.
 */
final class SendNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string QUEUE = 'notifications';

    /**
     * Payload HANYA public_id, bukan model: payload antrean yang memuat model membawa
     * data pengguna ke Redis dan ke failed_jobs.payload (longText) yang tidak pernah
     * dipangkas. Satu uuid sudah cukup untuk memuat ulang barisnya.
     */
    public function __construct(
        public readonly string $deliveryPublicId,
    ) {
        $this->onQueue(self::QUEUE);
        // config/queue.php menyetel after_commit => false di semua koneksi. Tanpa ini,
        // worker bisa mengambil job sebelum transaksi pembuat barisnya commit, tidak
        // menemukan barisnya, dan keluar diam-diam -- acak di bawah beban, hijau di test.
        // Di constructor, bukan di pemanggil: penerbit baru tidak bisa lupa.
        $this->afterCommit();
    }

    public function handle(ResolveSender $resolve, RenderNotification $render): void
    {
        // Baris dikunci selama pengiriman: dua salinan job atas delivery yang sama
        // berjalan berurutan, dan yang kedua melihat 'sent' lalu keluar tanpa mengirim.
        DB::transaction(function () use ($resolve, $render): void {
            $delivery = NotificationDelivery::query()
                ->where('public_id', $this->deliveryPublicId)
                ->lockForUpdate()
                ->first();

            // Sudah diselesaikan percobaan lain (atau barisnya hilang bersama akunnya).
            if ($delivery === null || $delivery->status !== DeliveryStatus::PENDING) {
                return;
            }

            // Kebijakan kanal diperiksa sebelum render: kanal yang dimatikan sesudah
            // dispatch melempar ChannelNotPermitted, tidak dikirim diam-diam.
            $sender = $resolve->for($delivery->channel);

            $address = $this->addressFor($delivery);

            if ($address === null) {
                // Penerima sudah tidak bisa dituju: akun dihapus, token dicabut, atau
                // token PINDAH ke akun lain (RegisterDeviceToken) -- mengirim ke sana
                // berarti pesan si A mendarat di layar si B. Retry tidak mengubahnya.
                $delivery->forceFill([
                    'status' => DeliveryStatus::FAILED,
                    'failure_code' => 'recipient_unreachable',
                    'settled_at' => now(),
                    'variables' => null,
                ])->save();

                return;
            }

            $message = $render->handle($delivery->template_code, $delivery->channel, $delivery->variables ?? [], $delivery->locale);

            // Gagal = exception naik, baris tetap 'pending' (transaksi di-rollback).
            // 'failed' + failure_code ditulis di failed() milik kartu 470, bukan di catch.
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
}
