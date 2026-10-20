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
        // Verifikasi = "boleh ikut sistem", TERPISAH dari is_active = "sedang beroperasi"
        // (kartu 430): fasilitas yang libur sementara tidak perlu diverifikasi ulang.
        // verified_at nullable, bukan enum: dua keadaan saja, dan kolomnya membawa kapan.
        Schema::table('facilities', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_note')->nullable();

            $table->index('verified_at');   // disaring kartu 440
            $table->index('verified_by');
        });

        // IMPLIKASI, bukan kesetaraan (pola deferrals_lift_shape): catatan boleh kosong
        // pada baris terverifikasi, tapi verifikator/catatan tanpa verifikasi itu bohong.
        DB::statement(<<<'SQL'
            ALTER TABLE facilities
            ADD CONSTRAINT facilities_verification_shape CHECK (
                verified_at IS NOT NULL OR (verified_by IS NULL AND verification_note IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS facilities DROP CONSTRAINT IF EXISTS facilities_verification_shape;');

        Schema::table('facilities', function (Blueprint $table): void {
            $table->dropIndex(['verified_at']);
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['verified_at', 'verification_note']);
        });
    }
};
