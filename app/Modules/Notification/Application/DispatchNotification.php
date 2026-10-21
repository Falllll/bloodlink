<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Modules\Notification\Application\Exceptions\NotificationTemplateRejected;
use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Domain\NotificationChannel;
use App\Modules\Notification\Infrastructure\Jobs\SendNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pintu masuk jalur kirim (kartu 460): satu baris notification_deliveries 'pending'
 * per alamat per kanal, lalu satu job per baris. Ia mengirim ke penerima yang
 * DIBERIKAN; memilih siapa penerimanya urusan pemanggil (kartu 400).
 */
final class DispatchNotification
{
    public function __construct(
        private readonly ChannelPolicy $policy,
        private readonly RenderNotification $render,
    ) {}

    /**
     * @param  array<string, string|int>  $variables  gabungan variabel semua kanal
     * @return list<string> public_id setiap delivery yang dibuat
     */
    public function handle(User $user, string $templateCode, array $variables, string $locale = 'id'): array
    {
        $variablesByChannel = $this->variablesByChannel($templateCode, $variables, $locale);

        return DB::transaction(function () use ($user, $templateCode, $locale, $variablesByChannel): array {
            $created = [];

            foreach ($this->policy->permittedChannels() as $channel) {
                $targets = $channel === NotificationChannel::EMAIL
                    ? [null]
                    : DeviceToken::query()->deliverable()->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();

                foreach ($targets as $deviceTokenId) {
                    $delivery = new NotificationDelivery(['template_code' => $templateCode, 'channel' => $channel]);
                    $delivery->forceFill([
                        'public_id' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'device_token_id' => $deviceTokenId,
                        'locale' => $locale,
                        'variables' => $variablesByChannel[$channel->value],
                        'status' => DeliveryStatus::PENDING,
                        'queued_at' => now(),
                    ])->save();

                    $created[] = $delivery->public_id;
                }
            }

            // Antre SETELAH commit -- SendNotification menyetel afterCommit() sendiri.
            foreach ($created as $publicId) {
                SendNotification::dispatch($publicId);
            }

            return $created;
        });
    }

    /**
     * Template email dan push sengaja berbeda variabel (push tampil di layar kunci,
     * jadi tanpa nama donor). Tiap kanal menerima irisan variabel miliknya, dan
     * setiap kanal dirender SEKARANG sebagai validasi: template yang rusak gagal di
     * pemanggil, bukan diam-diam di worker. Variabel yang tidak dipakai kanal mana
     * pun tetap ditolak -- salah nama tetap bug pemanggil.
     *
     * @param  array<string, string|int>  $variables
     * @return array<string, array<string, string|int>>
     */
    private function variablesByChannel(string $templateCode, array $variables, string $locale): array
    {
        $byChannel = [];
        $declaredAnywhere = [];

        foreach ($this->policy->permittedChannels() as $channel) {
            $declared = NotificationTemplate::query()
                ->where('code', $templateCode)
                ->where('channel', $channel->value)
                ->where('locale', $locale)
                ->where('is_active', true)
                ->value('variables');

            if (! is_array($declared)) {
                throw NotificationTemplateRejected::notFound($templateCode, $channel, $locale);
            }

            $subset = array_intersect_key($variables, array_flip(array_map(strval(...), $declared)));
            $this->render->handle($templateCode, $channel, $subset, $locale);

            $byChannel[$channel->value] = $subset;
            $declaredAnywhere = [...$declaredAnywhere, ...array_map(strval(...), $declared)];
        }

        $unexpected = array_values(array_diff(array_map(strval(...), array_keys($variables)), $declaredAnywhere));
        if ($unexpected !== []) {
            throw NotificationTemplateRejected::unexpectedVariables($templateCode, $unexpected);
        }

        return $byChannel;
    }
}
