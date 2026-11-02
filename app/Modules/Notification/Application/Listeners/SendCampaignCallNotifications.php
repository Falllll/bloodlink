<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Models\Donor;
use App\Models\DonorCampaignContact;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Notification\Application\DispatchNotification;
use App\Modules\Request\Domain\Events\CampaignDonorsContacted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Menyambungkan kampanye panggilan donor (kartu 400) ke jalur kirim (kartu 460).
 * Tinggal di modul Notification, BUKAN Request: deptrac mengizinkan
 * ModNotification -> EvtRequest, tidak sebaliknya.
 *
 * DIANTRE dan afterCommit: pemancarnya ada di dalam transaksi kunci kampanye.
 * Sinkron berarti satu template yang hilang me-rollback seluruh tahap kampanye.
 *
 * Tidak mengklaim ulang dan tidak memeriksa preferensi: satu baris kontak sudah berarti
 * terklaim tepat sekali (kartu 480), dan penolak panggilan tidak pernah mendapat baris
 * kontak (kartu 488).
 */
final class SendCampaignCallNotifications implements ShouldQueue
{
    public const string TEMPLATE = 'campaign.donors_contacted';

    public bool $afterCommit = true;

    public function __construct(private readonly DispatchNotification $dispatch) {}

    public function handle(CampaignDonorsContacted $event): void
    {
        $contacts = DonorCampaignContact::query()
            ->whereIn('public_id', $event->contactIds)
            ->with('campaign.bloodRequest.facility')
            ->orderBy('id')
            ->get();

        $donors = Donor::query()->with('user')->whereKey($contacts->pluck('donor_id')->all())->get()->keyBy('id');

        // Job ini diulang worker bila gagal di tengah batch. Satu panggilan DispatchNotification
        // satu transaksi, jadi donor yang sudah punya barisnya tidak boleh dikirimi lagi --
        // kirim ganda melanggar "satu panggilan per donor per permintaan" (kartu 480).
        $alreadySent = array_flip(NotificationDelivery::query()
            ->whereIn('user_id', $donors->pluck('user_id')->filter()->all())
            ->whereIn('data->invitation_id', $event->contactIds)
            ->pluck('data')
            ->map(fn (array $data): string => $data['invitation_id'])
            ->all());

        foreach ($contacts as $contact) {
            if (isset($alreadySent[$contact->public_id])) {
                continue;
            }

            /** @var User|null $user */
            $user = $donors->get($contact->donor_id)?->user;

            // Sah: reachability menganggap donor ber-email terjangkau walau tanpa akun, sedangkan
            // jalur kirim menuntut User. Melempar di sini menahan semua donor lain di batch.
            if ($user === null) {
                Log::warning('campaign.contact_without_user', ['contact_id' => $contact->public_id]);

                continue;
            }

            $this->dispatch->handle(
                $user,
                self::TEMPLATE,
                ['facility_name' => $contact->campaign->bloodRequest->facility->name],
                data: ['invitation_id' => $contact->public_id],
            );
        }
    }
}
