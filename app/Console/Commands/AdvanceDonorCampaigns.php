<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BloodRequest;
use App\Models\DonorCampaign;
use App\Modules\Request\Application\AdvanceDonorCampaign;
use App\Modules\Request\Domain\CampaignEndReason;
use Illuminate\Console\Command;

/**
 * Memajukan dan menutup kampanye panggilan donor (kartu 400). TIDAK PERNAH memulai
 * kampanye: itu keputusan manusia. Dry run adalah default; scheduler memanggilnya
 * dengan --force tiap lima menit.
 *
 * Tanpa visibleTo(): command berjalan tanpa user, dan scope yang gagal-tertutup
 * akan membalas nol baris tanpa error.
 */
final class AdvanceDonorCampaigns extends Command
{
    protected $signature = 'matching:advance-campaigns {--force : benar-benar majukan kampanye}';

    protected $description = 'Naikkan tahap radius kampanye panggilan donor yang jedanya habis, dan tutup yang sudah selesai';

    public function __construct(private readonly AdvanceDonorCampaign $advance)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (! $force) {
            $this->warn('DRY RUN -- tidak ada yang ditulis. Jalankan dengan --force untuk memajukan kampanye.');
        }

        // Id diambil dulu, bukan cursor(): pemajuan menulis di koneksi yang sama.
        $ids = DonorCampaign::query()->active()->orderBy('id')->pluck('id')->all();
        $acted = 0;

        foreach ($ids as $id) {
            $campaign = DonorCampaign::query()->findOrFail($id);

            if ($force) {
                $result = $this->advance->handle($campaign);
                $this->line("  {$campaign->public_id}: ".($result instanceof CampaignEndReason
                    ? "ditutup ({$result->value})"
                    : "radius {$campaign->current_radius_meters} m, {$result} donor baru dihubungi"));
            } else {
                $decision = $this->advance->decide($campaign, BloodRequest::query()->findOrFail($campaign->blood_request_id));
                $this->line("  akan: {$campaign->public_id} -> ".($decision instanceof CampaignEndReason ? "tutup ({$decision->value})" : $decision));
            }

            $acted++;
        }

        $this->info($force
            ? "Memproses {$acted} kampanye aktif."
            : "Dry run: {$acted} kampanye aktif diperiksa.");

        return self::SUCCESS;
    }
}
