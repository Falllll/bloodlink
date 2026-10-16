<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ujung rantai telusur donor -> donasi -> unit -> alokasi -> transfusi (kartu 370).
        // TIDAK menyalin donor_id ke sini: denormalisasi itu bisa bohong begitu MergeDonors
        // menggabungkan dua donor. Penelusuran lewat relasi (Transfusion::batch()).
        // Tidak ada kolom identitas pasien: blood_requests.patient_reference satu-satunya pegangan.
        Schema::create('transfusions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // Unique BIASA, bukan parsial: satu kantong ditransfusikan sekali, dan tidak ada
            // konsep "transfusi yang dibatalkan" yang membebaskan slotnya.
            $table->foreignId('blood_request_allocation_id')->unique()
                ->constrained('blood_request_allocations')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();   // null = sedang berjalan
            $table->unsignedSmallInteger('volume_transfused_ml')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('started_at');
            $table->index('recorded_by');
        });

        // Implikasi SATU ARAH: transfusi yang belum selesai tidak boleh sudah punya volume.
        DB::statement(<<<'SQL'
            ALTER TABLE transfusions
            ADD CONSTRAINT transfusions_completion_shape CHECK (
                completed_at IS NOT NULL OR volume_transfused_ml IS NULL
            )
        SQL);

        // Waktu yang mundur adalah baris yang bohong.
        DB::statement(<<<'SQL'
            ALTER TABLE transfusions
            ADD CONSTRAINT transfusions_time_order CHECK (
                completed_at IS NULL OR completed_at >= started_at
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE transfusions
            ADD CONSTRAINT transfusions_volume_positive CHECK (
                volume_transfused_ml IS NULL OR volume_transfused_ml > 0
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS transfusions DROP CONSTRAINT IF EXISTS transfusions_volume_positive;');
        DB::statement('ALTER TABLE IF EXISTS transfusions DROP CONSTRAINT IF EXISTS transfusions_time_order;');
        DB::statement('ALTER TABLE IF EXISTS transfusions DROP CONSTRAINT IF EXISTS transfusions_completion_shape;');

        Schema::dropIfExists('transfusions');
    }
};
