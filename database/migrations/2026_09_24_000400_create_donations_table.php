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
        Schema::create('donations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            // unique(): satu janji temu = paling banyak satu donasi, ditegakkan database.
            $table->foreignId('appointment_id')->unique()->constrained('appointments')->restrictOnDelete();
            $table->foreignId('screening_id')->nullable()->constrained('donor_screenings')->nullOnDelete();
            $table->smallInteger('volume_ml');
            $table->timestamp('started_at');
            $table->timestamp('completed_at');
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['donor_id', 'completed_at']);
            $table->index(['facility_id', 'completed_at']);
            $table->index('screening_id');
            $table->index('collected_by');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE donations
            ADD CONSTRAINT donations_volume_and_order CHECK (
                volume_ml > 0 AND completed_at >= started_at
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE donations DROP CONSTRAINT IF EXISTS donations_volume_and_order;');

        Schema::dropIfExists('donations');
    }
};
