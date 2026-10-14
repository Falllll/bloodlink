<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index untuk query kandidat FEFO (AllocateUnitsFefo::candidatesFor()).
     *
     * Kolom = persis kolom kesetaraan yang disaring query (facility_id, status,
     * component), lalu urutannya (expires_at, id). Golongan darah SENGAJA tidak
     * ada: kompatibilitas diputuskan gerbang kartu 315 di luar query, jadi
     * blood_group/rh_factor di tengah index justru memutus urutan expires_at.
     * Dengan id di ujung, ORDER BY expires_at, id dilayani index tanpa sort
     * (dibuktikan EXPLAIN saat kartu 320 dikerjakan).
     *
     * blood_batches_matching_index dan blood_batches_status_expires_at_index
     * TIDAK dihapus: keduanya melayani query lain (pencarian stok, kartu 280).
     */
    public function up(): void
    {
        Schema::table('blood_batches', function (Blueprint $table): void {
            $table->index(['facility_id', 'status', 'component', 'expires_at', 'id'], 'blood_batches_fefo_index');
        });
    }

    public function down(): void
    {
        Schema::table('blood_batches', function (Blueprint $table): void {
            $table->dropIndex('blood_batches_fefo_index');
        });
    }
};
