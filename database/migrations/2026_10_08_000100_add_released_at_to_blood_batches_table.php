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
            $table->timestamp('released_at')->nullable();
        });

        // Sengaja TANPA backfill dan TANPA NOT VALID. released_at adalah catatan
        // klinis kapan unit dilepas dari karantina; mengisinya dari updated_at atau
        // collected_at berarti mengarang waktu rilis. Migrasi ini akan jalan di DB
        // mana pun, termasuk yang berisi data sungguhan, jadi backfill di sini adalah
        // fabrikasi rekam medis yang tidak pernah melempar error. NOT VALID pun
        // menyisakan baris released ber-released_at NULL selamanya -- persis keadaan
        // yang constraint ini cegah. Gagal keras saat ada baris lama itu DIINGINKAN:
        // ia memaksa keputusan sadar, bukan tambalan diam-diam.
        //
        // Implikasi satu arah, bukan kesetaraan seperti separated_shape: unit yang
        // sudah released lalu pindah ke reserved/issued tetap membawa released_at.
        DB::statement(<<<'SQL'
            ALTER TABLE blood_batches
            ADD CONSTRAINT blood_batches_released_shape CHECK (
                status NOT IN ('released', 'reserved', 'issued') OR released_at IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE blood_batches DROP CONSTRAINT IF EXISTS blood_batches_released_shape');

        Schema::table('blood_batches', function (Blueprint $table): void {
            $table->dropColumn('released_at');
        });
    }
};
