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
        Schema::create('donor_screenings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('screened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('haemoglobin_dg_dl');          // 125 = 12,5 g/dL
            $table->smallInteger('systolic_mmhg');
            $table->smallInteger('diastolic_mmhg');
            $table->smallInteger('pulse_bpm');
            $table->smallInteger('temperature_dc');             // 376 = 37,6 °C
            $table->decimal('weight_kg', 5, 2);                 // sama bentuk dengan donors.weight_kg
            $table->unsignedSmallInteger('planned_volume_ml')->default(450);
            $table->boolean('passed');
            $table->jsonb('findings');                          // list kode ScreeningFindingCode
            $table->timestamp('screened_at');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['donor_id', 'screened_at']);
            $table->index(['facility_id', 'screened_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE donor_screenings
            ADD CONSTRAINT donor_screenings_positive_measurements CHECK (
                haemoglobin_dg_dl > 0
                AND systolic_mmhg > 0
                AND diastolic_mmhg > 0
                AND pulse_bpm > 0
                AND temperature_dc > 0
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE donor_screenings DROP CONSTRAINT IF EXISTS donor_screenings_positive_measurements;');

        Schema::dropIfExists('donor_screenings');
    }
};
