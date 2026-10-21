<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Setiap foreign key punya index PENUH (tanpa WHERE) yang diawali kolomnya (kartu 445).
 *
 * Index parsial tidak dihitung: unique index "satu aktif per X" tidak memuat baris
 * yang sudah dilepas/selesai, jadi pemeriksaan restrictOnDelete dan query riwayat
 * tetap memindai tabel -- itu persis temuan blood_request_allocations.blood_batch_id.
 */
final class ForeignKeyIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * FK tanpa index penuh yang DIPUTUSKAN dibiarkan. Setiap baris butuh alasan; "untuk
     * aman" bukan alasan menambah index, "lupa" bukan alasan membiarkannya.
     *
     * Kolom aktor/fasilitas di bawah tidak pernah difilter query mana pun, dan tabel
     * rujukannya (users, donors, facilities) hanya soft delete -- pemeriksaan FK
     * restrictOnDelete tidak pernah berjalan. Index-nya murni biaya tulis.
     *
     * @var array<string, string>
     */
    private const array ACCEPTED = [
        'appointments.created_by' => 'aktor; users hanya soft delete, tidak pernah difilter',
        'donor_consents.facility_id' => 'jejak fasilitas pencatat; consent dibaca per donor_id',
        'donor_consents.recorded_by' => 'aktor; users hanya soft delete, tidak pernah difilter',
        'donor_screenings.screened_by' => 'aktor; users hanya soft delete, tidak pernah difilter',
        // Hanya pernah difilter whereNull(): hampir semua baris NULL, index tidak selektif.
        'donors.merged_into_id' => 'donors hanya soft delete; filter satu-satunya IS NULL',
        // Tabel vendor Spatie: role/permission diseed, tidak dihapus saat runtime.
        'model_has_permissions.permission_id' => 'tabel vendor Spatie',
        'model_has_roles.role_id' => 'tabel vendor Spatie',
        'role_has_permissions.role_id' => 'tabel vendor Spatie',
    ];

    /** @return list<string> 'tabel.kolom' untuk setiap FK tanpa index penuh */
    private function foreignKeysWithoutFullIndex(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS tbl,
                   (SELECT string_agg(a.attname, ',' ORDER BY k.ord)
                      FROM unnest(c.conkey) WITH ORDINALITY AS k(att, ord)
                      JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.att) AS cols
            FROM pg_constraint c
            WHERE c.contype = 'f'
              AND c.connamespace = 'public'::regnamespace
              AND NOT EXISTS (
                  SELECT 1 FROM pg_index i
                  WHERE i.indrelid = c.conrelid
                    AND i.indpred IS NULL
                    AND (i.indkey::int2[])[0:array_length(c.conkey, 1) - 1] = c.conkey
              )
            ORDER BY 1, 2
        SQL);

        return array_map(fn (object $r): string => $r->tbl.'.'.$r->cols, $rows);
    }

    public function test_every_foreign_key_has_a_full_index_or_a_recorded_reason(): void
    {
        $missing = array_values(array_diff($this->foreignKeysWithoutFullIndex(), array_keys(self::ACCEPTED)));

        $this->assertSame([], $missing, 'FK tanpa index penuh. Tambah index, atau catat alasannya di ACCEPTED.');
    }

    /** Pengecualian yang sudah ter-index harus dihapus, bukan dibiarkan membusuk. */
    public function test_every_accepted_exception_is_still_unindexed(): void
    {
        $stale = array_values(array_diff(array_keys(self::ACCEPTED), $this->foreignKeysWithoutFullIndex()));

        $this->assertSame([], $stale);
    }
}
