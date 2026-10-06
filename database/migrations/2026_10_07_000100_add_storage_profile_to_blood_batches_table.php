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
        // Baris lama punya expires_at tanpa asal-usul (konstanta per komponen di
        // factory/seeder lama). Mengarang profilnya sama dengan mengarang kedaluwarsa,
        // jadi berhenti dengan pesan jelas alih-alih menambal diam-diam.
        if (DB::table('blood_batches')->whereNotNull('expires_at')->exists()) {
            throw new RuntimeException(
                'blood_batches berisi expires_at tanpa storage profile. Di dev: jalankan migrate:fresh --seed. '.
                'Di lingkungan lain: tetapkan profil penyimpanan tiap unit dulu sebelum migration ini jalan.'
            );
        }

        Schema::table('blood_batches', function (Blueprint $table): void {
            $table->foreignId('storage_profile_id')->nullable()->constrained('component_storage_profiles')->restrictOnDelete();
            $table->index('storage_profile_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE blood_batches
            ADD CONSTRAINT blood_batches_expiry_provenance CHECK (
                expires_at IS NULL
                OR (storage_profile_id IS NOT NULL AND expires_at > collected_at)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE blood_batches DROP CONSTRAINT IF EXISTS blood_batches_expiry_provenance;');

        Schema::table('blood_batches', function (Blueprint $table): void {
            $table->dropIndex(['storage_profile_id']);
            $table->dropConstrainedForeignId('storage_profile_id');
        });
    }
};
