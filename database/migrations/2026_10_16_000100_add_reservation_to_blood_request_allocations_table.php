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
        // Reservasi adalah properti alokasi, bukan entitas keempat: tabel terpisah
        // akan menduplikasi hubungan unit <-> permintaan yang sudah ada di sini.
        Schema::table('blood_request_allocations', function (Blueprint $table): void {
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            // Kenapa reservasi terakhir dilepas (timeout, manual). release_reason tidak
            // bisa dipakai: CHECK release_shape mengikatnya ke pelepasan ALOKASI.
            $table->string('reservation_release_reason', 255)->nullable();

            $table->index('reservation_expires_at');   // dipakai request:expire-reservations
        });

        // KESETARAAN, bukan implikasi satu arah: reservasi tanpa batas waktu DAN
        // batas waktu tanpa reservasi dua-duanya baris yang bohong. Bandingkan
        // dengan blood_batches_discarded_shape yang memang satu arah (alasan boleh
        // terbawa pada baris yang bukan discarded).
        DB::statement(<<<'SQL'
            ALTER TABLE blood_request_allocations
            ADD CONSTRAINT blood_request_allocations_reservation_shape CHECK (
                (reserved_at IS NULL) = (reservation_expires_at IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS blood_request_allocations DROP CONSTRAINT IF EXISTS blood_request_allocations_reservation_shape;');

        Schema::table('blood_request_allocations', function (Blueprint $table): void {
            $table->dropIndex(['reservation_expires_at']);
            $table->dropColumn(['reserved_at', 'reservation_expires_at', 'reservation_release_reason']);
        });
    }
};
