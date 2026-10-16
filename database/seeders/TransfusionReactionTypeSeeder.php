<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * SENGAJA KOSONG: per 2026-10-17 halaman Rujukan Pedoman Medis belum memuat satu
 * pun kutipan jenis reaksi transfusi. Baris hanya boleh ditambahkan setelah
 * kategorinya dikutip di sana (judul dokumen + halaman/bagian, provenance 'cited');
 * sampai itu, laporan reaksi dicatat naratif dengan reaction_type_id null.
 */
final class TransfusionReactionTypeSeeder extends Seeder
{
    public function run(): void {}
}
