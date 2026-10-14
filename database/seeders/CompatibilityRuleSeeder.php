<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Aturan kompatibilitas ABO/RhD dari Rujukan Pedoman Medis §12. Isinya dicocokkan
 * ulang ke teks WHO Module 3 §6.3 hlm. 45 (Figure 14 & 15 beserta daftar
 * bernomornya) saat Kartu 315 dikerjakan.
 *
 * Pasangan yang TIDAK ada di sini berarti tidak boleh -- termasuk penerima RhD
 * negatif dengan donor RhD positif. Trombosit dan RhD untuk plasma sengaja
 * tidak punya baris: tidak ada sumbernya (§12.5, §12.6).
 */
final class CompatibilityRuleSeeder extends Seeder
{
    private const string JURISDICTION = 'WHO';

    private const string SOURCE_RED_CELLS = 'WHO Module 3 §6.3 Figure 14';

    private const string SOURCE_PLASMA = 'WHO Module 3 §6.3 Figure 15';

    /** Sel darah merah: penerima => donor yang boleh. */
    private const array RED_CELLS = [
        'O' => ['O'],
        'A' => ['A', 'O'],
        'B' => ['B', 'O'],
        'AB' => ['AB', 'A', 'B', 'O'],
    ];

    /** Plasma: arahnya TERBALIK dari sel darah merah -- bukan salah ketik. */
    private const array PLASMA = [
        'O' => ['O', 'A', 'B', 'AB'],
        'A' => ['A', 'AB'],
        'B' => ['B', 'AB'],
        'AB' => ['AB'],
    ];

    public function run(): void
    {
        $now = now();
        $abo = [];

        foreach (['red_cells' => [self::RED_CELLS, self::SOURCE_RED_CELLS], 'plasma' => [self::PLASMA, self::SOURCE_PLASMA]] as $direction => [$matrix, $source]) {
            foreach ($matrix as $recipient => $donors) {
                foreach ($donors as $donor) {
                    $abo[] = [
                        'jurisdiction' => self::JURISDICTION,
                        'direction' => $direction,
                        'recipient_group' => $recipient,
                        'donor_group' => $donor,
                        'provenance' => 'cited',
                        'source_reference' => $source,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        DB::table('abo_compatibility_rules')->upsert(
            $abo,
            ['jurisdiction', 'direction', 'recipient_group', 'donor_group'],
            ['provenance', 'source_reference', 'updated_at'],
        );

        $rh = [
            ['negative', 'negative', 'cited', 'WHO Module 3 §6.3'],
            ['positive', 'positive', 'cited', 'WHO Module 3 §6.3 (ABO/Rh compatibility required)'],
            // Tidak ada kutipan WHO yang menyatakan ini boleh; berlabel turunan supaya
            // setiap keputusan yang bergantung padanya terlihat.
            ['positive', 'negative', 'derived', 'Rujukan Pedoman Medis §12.5 butir 1 — belum ada kutipan WHO'],
        ];

        DB::table('rh_compatibility_rules')->upsert(
            array_map(fn (array $row): array => [
                'jurisdiction' => self::JURISDICTION,
                'recipient_rh' => $row[0],
                'donor_rh' => $row[1],
                'provenance' => $row[2],
                'source_reference' => $row[3],
                'created_at' => $now,
                'updated_at' => $now,
            ], $rh),
            ['jurisdiction', 'recipient_rh', 'donor_rh'],
            ['provenance', 'source_reference', 'updated_at'],
        );
    }
}
