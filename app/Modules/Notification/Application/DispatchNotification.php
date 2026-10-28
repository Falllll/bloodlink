<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Modules\Notification\Application\Exceptions\NotificationTemplateRejected;
use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Notification\Domain\NotificationChannel;
use App\Modules\Notification\Infrastructure\Jobs\SendNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pintu masuk jalur kirim (kartu 460): satu baris notification_deliveries 'pending'
 * per alamat per kanal, lalu satu job per baris. Ia mengirim ke penerima yang
 * DIBERIKAN; memilih siapa penerimanya urusan pemanggil (kartu 400).
 *
 * Penerima yang menolak kategori template (kartu 488) tetap mendapat barisnya,
 * berstatus 'suppressed', tanpa job.
 */
final class DispatchNotification
{
    public function __construct(
        private readonly ChannelPolicy $policy,
        private readonly RenderNotification $render,
    ) {}

    /**
     * @param  array<string, string|int>  $variables  gabungan variabel semua kanal
     * @param  list<NotificationChannel>|null  $only  batasi ke kanal ini (mis. reset password:
     *                                                email saja, kartu 485); null = semua yang diizinkan
     * @return list<string> public_id setiap delivery yang dibuat, termasuk yang 'suppressed'
     */
    public function handle(User $user, string $templateCode, array $variables, string $locale = 'id', ?array $only = null): array
    {
        $channels = $this->channels($only);
        [$variablesByChannel, $category] = $this->variablesByChannel($templateCode, $variables, $locale, $channels);

        // Preferensi ditegakkan DI SINI, satu tempat, bukan di tiap pemanggil (kartu 488):
        // pemanggil yang lupa memeriksa adalah pemanggil yang mengirim ke orang yang
        // menolaknya. Sesudah validasi template: template rusak tetap gagal di pemanggil.
        $suppressed = $category !== null && $this->optedOut($user, $category);

        return DB::transaction(function () use ($user, $templateCode, $locale, $variablesByChannel, $channels, $suppressed): array {
            $created = [];

            foreach ($channels as $channel) {
                $targets = $channel === NotificationChannel::EMAIL
                    ? [null]
                    : DeviceToken::query()->deliverable()->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();

                foreach ($targets as $deviceTokenId) {
                    // Yang ditolak tetap menjadi baris, 'suppressed' dan langsung selesai --
                    // bukan diam-diam tidak ditulis. Tanpa bahan render: tidak ada yang
                    // akan merendernya.
                    $delivery = new NotificationDelivery(['template_code' => $templateCode, 'channel' => $channel]);
                    $delivery->forceFill([
                        'public_id' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'device_token_id' => $deviceTokenId,
                        'locale' => $locale,
                        'variables' => $suppressed ? null : $variablesByChannel[$channel->value],
                        'status' => $suppressed ? DeliveryStatus::SUPPRESSED : DeliveryStatus::PENDING,
                        'queued_at' => now(),
                        'settled_at' => $suppressed ? now() : null,
                    ])->save();

                    $created[] = $delivery->public_id;
                }
            }

            // Antre SETELAH commit -- SendNotification menyetel afterCommit() sendiri.
            foreach ($suppressed ? [] : $created as $publicId) {
                SendNotification::dispatch($publicId);
            }

            return $created;
        });
    }

    /** Kategori wajib (isOptional() false) tidak pernah ditekan, ada baris atau tidak. */
    private function optedOut(User $user, NotificationCategory $category): bool
    {
        return $category->isOptional() && NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('category', $category->value)
            ->whereNotNull('opted_out_at')
            ->exists();
    }

    /**
     * Template email dan push sengaja berbeda variabel (push tampil di layar kunci,
     * jadi tanpa nama donor). Tiap kanal menerima irisan variabel miliknya, dan
     * setiap kanal dirender SEKARANG sebagai validasi: template yang rusak gagal di
     * pemanggil, bukan diam-diam di worker. Variabel yang tidak dipakai kanal mana
     * pun tetap ditolak -- salah nama tetap bug pemanggil.
     *
     * Kategori ikut terbaca dari baris yang sama. Satu kode satu kategori dijaga
     * EXCLUDE notification_templates_one_category_per_code, jadi baris kanal mana pun
     * memberi jawaban yang sama; null hanya bila tidak ada kanal sama sekali.
     *
     * @param  array<string, string|int>  $variables
     * @param  list<NotificationChannel>  $channels
     * @return array{array<string, array<string, string|int>>, NotificationCategory|null}
     */
    private function variablesByChannel(string $templateCode, array $variables, string $locale, array $channels): array
    {
        $byChannel = [];
        $declaredAnywhere = [];
        $category = null;

        foreach ($channels as $channel) {
            $template = NotificationTemplate::query()
                ->where('code', $templateCode)
                ->where('channel', $channel->value)
                ->where('locale', $locale)
                ->where('is_active', true)
                ->first(['variables', 'category']);

            if ($template === null) {
                throw NotificationTemplateRejected::notFound($templateCode, $channel, $locale);
            }

            $declared = $template->variables;
            $category = $template->category;

            $subset = array_intersect_key($variables, array_flip(array_map(strval(...), $declared)));
            $this->render->handle($templateCode, $channel, $subset, $locale);

            $byChannel[$channel->value] = $subset;
            $declaredAnywhere = [...$declaredAnywhere, ...array_map(strval(...), $declared)];
        }

        $unexpected = array_values(array_diff(array_map(strval(...), array_keys($variables)), $declaredAnywhere));
        if ($unexpected !== []) {
            throw NotificationTemplateRejected::unexpectedVariables($templateCode, $unexpected);
        }

        return [$byChannel, $category];
    }

    /**
     * Kanal yang diminta DIIRIS dengan kebijakan kanal, tidak pernah ditambah: $only
     * hanya bisa mempersempit. Kanal yang diminta tapi tidak diizinkan ditolak keras
     * oleh ChannelPolicy, bukan dilewati diam-diam.
     *
     * @param  list<NotificationChannel>|null  $only
     * @return list<NotificationChannel>
     */
    private function channels(?array $only): array
    {
        if ($only === null) {
            return $this->policy->permittedChannels();
        }

        foreach ($only as $channel) {
            $this->policy->assertDeliverable($channel);
        }

        return array_values(array_filter($this->policy->permittedChannels(), fn (NotificationChannel $c): bool => in_array($c, $only, true)));
    }
}
