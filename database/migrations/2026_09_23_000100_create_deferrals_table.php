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
        Schema::create('deferrals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('donor_id')->constrained('donors')->restrictOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('deferral_reason_id')->constrained('deferral_reasons')->restrictOnDelete();
            $table->enum('type', ['temporary', 'permanent']);
            $table->timestamp('anchor_at');
            $table->unsignedSmallInteger('duration_value')->nullable();
            $table->enum('duration_unit', ['hours', 'days', 'months', 'years'])->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->enum('source', ['manual', 'screening', 'tti_result']);
            $table->text('note')->nullable();
            $table->text('referral_note')->nullable();
            $table->foreignId('placed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lift_note')->nullable();
            $table->timestamps();
            $table->index(['donor_id', 'lifted_at', 'ends_at']);
            $table->index(['facility_id', 'lifted_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deferrals
            ADD CONSTRAINT deferrals_duration_shape CHECK (
                (type = 'permanent'
                    AND duration_value IS NULL AND duration_unit IS NULL AND ends_at IS NULL)
                OR (
                    type = 'temporary'
                    AND (duration_value IS NULL) = (duration_unit IS NULL)
                    AND (duration_value IS NULL OR duration_value > 0)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE deferrals
            ADD CONSTRAINT deferrals_lift_shape CHECK (
                lifted_at IS NOT NULL OR (lifted_by IS NULL AND lift_note IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deferrals');
    }
};
