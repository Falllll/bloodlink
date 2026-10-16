<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\Transfusion;
use App\Models\TransfusionReaction;
use App\Models\TransfusionReactionType;
use App\Models\User;
use App\Modules\Request\Domain\Events\TransfusionReactionReported;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Melaporkan satu reaksi pasca-transfusi. Pengamatan, bukan tindakan: TIDAK
 * mengubah status unit maupun permintaan. Satu-satunya efek sistemiknya adalah
 * TransfusionReactionReported.
 */
final class ReportTransfusionReaction
{
    public function handle(
        Transfusion $transfusion,
        User $reportedBy,
        DateTimeImmutable $observedAt,
        string $description,
        ?string $reactionTypeCode = null,
    ): TransfusionReaction {
        $reaction = DB::transaction(function () use ($transfusion, $reportedBy, $observedAt, $description, $reactionTypeCode): TransfusionReaction {
            $type = $reactionTypeCode === null
                ? null
                : TransfusionReactionType::query()->where('code', $reactionTypeCode)->where('is_active', true)->firstOrFail();

            $reaction = new TransfusionReaction([
                'observed_at' => $observedAt,
                'description' => $description,
            ]);

            $reaction->forceFill([
                'public_id' => (string) Str::uuid(),
                'transfusion_id' => $transfusion->id,
                'reaction_type_id' => $type?->id,
                'reported_by' => $reportedBy->id,
            ])->save();

            return $reaction->setRelation('transfusion', $transfusion)->setRelation('type', $type);
        });

        // Setelah commit: listener kelak (notifikasi, kartu 450/460) tidak boleh
        // mengabarkan laporan yang ternyata batal tersimpan.
        event(new TransfusionReactionReported(
            $reaction->public_id,
            $transfusion->public_id,
            $reaction->observed_at->format(DateTimeInterface::ATOM),
        ));

        return $reaction;
    }
}
