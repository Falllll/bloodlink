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
        // Tabel penghubung permintaan -> unit: permintaan 4 kantong dipenuhi
        // bertahap, tiap kantong dengan waktu alokasinya sendiri (keputusan kartu 310).
        Schema::create('blood_request_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('blood_request_id')->constrained('blood_requests')->restrictOnDelete();
            $table->foreignId('blood_batch_id')->constrained('blood_batches')->restrictOnDelete();
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamp('released_at')->nullable();   // alokasi dilepas (dibatalkan)
            $table->string('release_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['blood_request_id', 'released_at']);
            $table->index('allocated_by');
        });

        // Satu unit, satu alokasi aktif. Predikatnya memuat SELURUH definisi
        // "aktif"; kalau kelak ada kolom lain yang ikut menentukan aktif,
        // predikat ini wajib ikut diperbarui.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX blood_request_allocations_one_active_per_unit
            ON blood_request_allocations (blood_batch_id)
            WHERE released_at IS NULL
        SQL);

        // Alasan pelepasan tanpa pelepasannya adalah baris yang bohong.
        DB::statement(<<<'SQL'
            ALTER TABLE blood_request_allocations
            ADD CONSTRAINT blood_request_allocations_release_shape CHECK (
                released_at IS NOT NULL OR release_reason IS NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS blood_request_allocations DROP CONSTRAINT IF EXISTS blood_request_allocations_release_shape;');
        DB::statement('DROP INDEX IF EXISTS blood_request_allocations_one_active_per_unit;');

        Schema::dropIfExists('blood_request_allocations');
    }
};
