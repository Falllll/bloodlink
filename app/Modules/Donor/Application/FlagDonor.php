<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Donor;
use App\Models\DonorFlag;
use App\Models\User;
use App\Modules\Donor\Application\Exceptions\DonorFlagRejected;
use App\Modules\Donor\Domain\DonorFlagReason;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menempatkan dan mencabut flag administratif donor (kartu 430). Kanal KEDUA di
 * sebelah deferral medis -- tidak pernah menulis ke tabel deferrals.
 */
final class FlagDonor
{
    /**
     * SATU-SATUNYA definisi "flag masih aktif" di SQL: belum dicabut DAN (tanpa
     * batas waktu ATAU belum berakhir). Klausa ini IDENTIK dengan DonorFlagWindow::isActiveOn
     * yang dipakai DonorFlagRule, dan dipakai ulang oleh DonorEligibilityService serta
     * filter daftar -- preseden SummariseDeferralStatus. Dua definisi "aktif" yang
     * berbeda satu jam saja memberi jawaban bertentangan pada orang yang menunggu.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function whereActive(Builder $query, DateTimeInterface $asOf): Builder
    {
        return $query
            ->whereNull('lifted_at')
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $asOf));
    }

    public function place(Donor $donor, DonorFlagReason $reason, string $note, ?DateTimeImmutable $endsAt, User $placedBy): DonorFlag
    {
        return DB::transaction(function () use ($donor, $reason, $note, $endsAt, $placedBy): DonorFlag {
            // Kunci baris donor: dua penempatan serentak tidak boleh sama-sama lolos cek.
            Donor::query()->whereKey($donor->getKey())->lockForUpdate()->firstOrFail();

            if (self::whereActive(DonorFlag::query()->where('donor_id', $donor->id), now())->exists()) {
                throw DonorFlagRejected::alreadyFlagged();
            }

            $flag = new DonorFlag([
                'reason' => $reason,
                'note' => $note,
                'starts_at' => now(),
                'ends_at' => $endsAt,
            ]);

            $flag->forceFill([
                'public_id' => (string) Str::uuid(),
                'donor_id' => $donor->id,
                'placed_by' => $placedBy->id,
            ])->save();

            return $flag->setRelation('donor', $donor);
        });
    }

    public function lift(DonorFlag $flag, string $liftNote, User $liftedBy): DonorFlag
    {
        DB::transaction(function () use ($flag, $liftNote, $liftedBy): void {
            $fresh = DonorFlag::query()->whereKey($flag->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->lifted_at !== null) {
                throw DonorFlagRejected::alreadyLifted();
            }

            $fresh->forceFill([
                'lifted_at' => now(),
                'lifted_by' => $liftedBy->id,
                'lift_note' => $liftNote,
            ])->save();

            $flag->setRawAttributes($fresh->getAttributes(), sync: true);
        });

        return $flag;
    }
}
