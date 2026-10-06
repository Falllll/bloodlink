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
        Schema::table('blood_batches', function (Blueprint $table): void {
            // Kunci idempoten CreateQuarantinedUnit: satu donasi, paling banyak satu unit.
            // NULL tetap boleh berulang untuk unit transfer tanpa donasi.
            $table->foreignId('donation_id')->nullable()->unique()->constrained('donations')->nullOnDelete();

            // Kedaluwarsa diisi Kartu 200, golongan darah dikonfirmasi lab di Kartu 230;
            // unit yang baru lahir dari donasi belum punya ketiganya.
            $table->timestamp('expires_at')->nullable()->change();
        });

        // Bukan ->enum()->change(): grammar PostgreSQL Laravel menulis ulang enum jadi
        // `type varchar(255) check (...)`, yang merupakan SQL tidak sah. Cukup lepas
        // NOT NULL; check constraint enum dari migration asal tetap utuh.
        DB::statement('ALTER TABLE blood_batches ALTER COLUMN blood_group DROP NOT NULL');
        DB::statement('ALTER TABLE blood_batches ALTER COLUMN rh_factor DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('blood_batches', function (Blueprint $table): void {
            $table->dropUnique('blood_batches_donation_id_unique');
            $table->dropConstrainedForeignId('donation_id');

            $table->timestamp('expires_at')->nullable(false)->change();
        });

        // Gagal keras kalau sudah ada unit tanpa golongan darah/kedaluwarsa --
        // itu memang benar: rollback tidak boleh mengarang nilainya.
        DB::statement('ALTER TABLE blood_batches ALTER COLUMN blood_group SET NOT NULL');
        DB::statement('ALTER TABLE blood_batches ALTER COLUMN rh_factor SET NOT NULL');
    }
};
