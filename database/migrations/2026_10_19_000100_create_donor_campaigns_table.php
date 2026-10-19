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
        // Kampanye panggilan donor berjenjang (kartu 400). Tahap BUKAN entitas: cukup
        // current_radius_meters di sini plus radius_meters pada tiap baris kontak.
        Schema::create('donor_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('blood_request_id')->constrained('blood_requests')->restrictOnDelete();
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('current_radius_meters');
            $table->timestamp('stage_entered_at');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            // Nilai harus sama dengan CampaignEndReason.
            $table->enum('end_reason', ['request_fulfilled', 'request_cancelled', 'request_expired', 'stages_exhausted', 'stopped_manually'])->nullable();
            $table->timestamps();

            $table->index('ended_at');   // dipakai command penjadwal
            $table->index('started_by');
        });

        // Satu kampanye aktif per permintaan (preseden blood_request_allocations_one_active_per_unit):
        // dua kampanye berarti donor dipanggil dua kali oleh sistem yang sama.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX donor_campaigns_one_active_per_request
            ON donor_campaigns (blood_request_id)
            WHERE ended_at IS NULL
        SQL);

        // KESETARAAN: berhenti tanpa alasan dan alasan tanpa berhenti dua-duanya bohong.
        DB::statement(<<<'SQL'
            ALTER TABLE donor_campaigns
            ADD CONSTRAINT donor_campaigns_end_shape CHECK (
                (ended_at IS NULL) = (end_reason IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS donor_campaigns DROP CONSTRAINT IF EXISTS donor_campaigns_end_shape;');
        DB::statement('DROP INDEX IF EXISTS donor_campaigns_one_active_per_request;');

        Schema::dropIfExists('donor_campaigns');
    }
};
