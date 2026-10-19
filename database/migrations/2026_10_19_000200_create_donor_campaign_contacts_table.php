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
        // Satu baris = "pesan dikirim ke orang ini". Hanya donor yang layak DAN bisa
        // dihubungi yang ditulis -- baris untuk orang yang tidak dikirimi merusak
        // angka tingkat respons.
        Schema::create('donor_campaign_contacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('donor_campaign_id')->constrained('donor_campaigns')->cascadeOnDelete();
            $table->foreignId('donor_id')->constrained('donors')->restrictOnDelete();
            $table->unsignedInteger('radius_meters');       // tahap saat ia dihubungi
            $table->unsignedInteger('distance_meters');
            $table->timestamp('contacted_at');
            $table->timestamp('responded_at')->nullable();
            // Nilai harus sama dengan CampaignResponse.
            $table->enum('response', ['accepted', 'declined'])->nullable();
            $table->timestamps();

            // Satu donor dihubungi paling banyak sekali per kampanye -- dijaga database,
            // bukan PHP: jaring pengaman ketika dua penjadwal berjalan bersamaan.
            $table->unique(['donor_campaign_id', 'donor_id']);
            $table->index(['donor_campaign_id', 'response']);
            $table->index('donor_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE donor_campaign_contacts
            ADD CONSTRAINT donor_campaign_contacts_response_shape CHECK (
                (responded_at IS NULL) = (response IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS donor_campaign_contacts DROP CONSTRAINT IF EXISTS donor_campaign_contacts_response_shape;');

        Schema::dropIfExists('donor_campaign_contacts');
    }
};
