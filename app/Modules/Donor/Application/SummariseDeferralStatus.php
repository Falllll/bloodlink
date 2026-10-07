<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Donor;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "Masih tertahan?" untuk tabel daftar donor. Dihitung dari baris deferrals,
 * bukan dari kolom turunan di donors (Kartu 120 sengaja menghapus is_deferred).
 */
final class SummariseDeferralStatus
{
    public const string NONE = 'none';

    public const string TEMPORARY = 'temporary';

    public const string PERMANENT = 'permanent';

    /**
     * Satu query untuk seluruh halaman, bukan satu per baris.
     *
     * @param  list<int>  $donorIds
     * @return array<int, string> donor_id => 'none'|'temporary'|'permanent'
     */
    public function forDonorIds(array $donorIds, DateTimeImmutable $asOf): array
    {
        $statuses = array_fill_keys($donorIds, self::NONE);

        if ($donorIds === []) {
            return $statuses;
        }

        $rows = $this->activeDeferrals($asOf)
            ->whereIn('donor_id', $donorIds)
            ->select('donor_id')
            // Permanen mengalahkan temporer: cukup tahu apakah ada satu saja.
            ->selectRaw("bool_or(type = 'permanent') AS has_permanent")
            ->groupBy('donor_id')
            ->get();

        foreach ($rows as $row) {
            $statuses[(int) $row->donor_id] = $row->has_permanent ? self::PERMANENT : self::TEMPORARY;
        }

        return $statuses;
    }

    /**
     * filter[deferral_status] di daftar donor, dengan definisi "aktif" yang sama.
     *
     * @param  Builder<Donor>  $donors
     */
    public function applyFilter(Builder $donors, string $status, DateTimeImmutable $asOf): void
    {
        $active = fn (?string $type) => fn (QueryBuilder $query) => $this->activeDeferrals($asOf, $query)
            ->whereColumn('deferrals.donor_id', 'donors.id')
            ->when($type !== null, fn (QueryBuilder $q) => $q->where('type', $type));

        match ($status) {
            self::NONE => $donors->whereNotExists($active(null)),
            self::PERMANENT => $donors->whereExists($active(self::PERMANENT)),
            self::TEMPORARY => $donors
                ->whereExists($active(self::TEMPORARY))
                ->whereNotExists($active(self::PERMANENT)),
            // Nilai lain tidak boleh jatuh diam-diam ke "tanpa filter".
            default => throw new InvalidArgumentException("Unknown deferral status \"{$status}\"."),
        };
    }

    /**
     * Klausa "deferral masih aktif" -- IDENTIK dengan DonorEligibilityService,
     * supaya tabel admin tidak pernah berbeda pendapat dengan mesin kelayakan.
     */
    private function activeDeferrals(DateTimeImmutable $asOf, ?QueryBuilder $query = null): QueryBuilder
    {
        return ($query ?? DB::query())
            ->from('deferrals')
            ->whereNull('lifted_at')
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $asOf));
    }
}
