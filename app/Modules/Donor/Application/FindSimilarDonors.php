<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Modules\Donor\Domain\DonorNameSimilarity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class FindSimilarDonors
{
    public const float THRESHOLD = DonorNameSimilarity::THRESHOLD;

    /** @return list<array{id: string, full_name: string, score: float}> */
    public function forIdentity(string $fullName, Carbon $dateOfBirth, ?int $excludeDonorId = null): array
    {
        $query = DB::table('donors')
            ->selectRaw('public_id, full_name, similarity(full_name, ?) AS score', [$fullName])
            ->whereNull('merged_into_id')
            ->whereNull('deleted_at')
            ->whereDate('date_of_birth', $dateOfBirth->toDateString())
            ->whereRaw('similarity(full_name, ?) >= ?', [$fullName, self::THRESHOLD]);

        if ($excludeDonorId !== null) {
            $query->where('id', '<>', $excludeDonorId);
        }

        $rows = $query
            ->orderByDesc('score')
            ->limit(5)
            ->get();

        return $rows->map(fn (object $row): array => [
            'id' => $row->public_id,
            'full_name' => $row->full_name,
            'score' => (float) $row->score,
        ])->all();
    }
}
