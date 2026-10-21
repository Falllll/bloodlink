<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kartu 445. Kedua FK ini hanya ter-index oleh unique index PARSIAL
 * (... WHERE released_at IS NULL / WHERE ended_at IS NULL). Baris yang sudah
 * dilepas/selesai tidak masuk index itu, jadi riwayat per kantong, riwayat
 * kampanye per permintaan, dan pemeriksaan restrictOnDelete memindai tabel.
 *
 * Bukan index "untuk aman": EXPLAIN pada salinan berisi data realistis, planner normal:
 *   alokasi per kantong (200k baris):  Seq Scan cost 5531   -> Index Scan cost 8.44
 *   kampanye terbaru per permintaan
 *   (DonorCampaignController::show, 100k): Seq Scan cost 2975 -> Index Scan cost 11.33
 * Dijaga QueryPlanTest di modul Request dan ForeignKeyIndexTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blood_request_allocations', function (Blueprint $table): void {
            $table->index('blood_batch_id');
        });

        Schema::table('donor_campaigns', function (Blueprint $table): void {
            $table->index('blood_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('donor_campaigns', function (Blueprint $table): void {
            $table->dropIndex(['blood_request_id']);
        });

        Schema::table('blood_request_allocations', function (Blueprint $table): void {
            $table->dropIndex(['blood_batch_id']);
        });
    }
};
