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
        Schema::create('deferral_reasons', function (Blueprint $table): void {
            $table->id();
            $table->string('jurisdiction', 8)->default('WHO');
            $table->string('code', 64);
            $table->enum('type', ['temporary', 'permanent']);
            $table->unsignedSmallInteger('default_duration_value')->nullable();
            $table->enum('default_duration_unit', ['hours', 'days', 'months', 'years'])->nullable();
            $table->string('label');
            $table->text('anchor_note')->nullable();
            $table->string('source_reference');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['jurisdiction', 'code']);
            $table->index(['jurisdiction', 'is_active']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deferral_reasons
            ADD CONSTRAINT deferral_reasons_duration_shape CHECK (
                (type = 'permanent' AND default_duration_value IS NULL AND default_duration_unit IS NULL)
                OR (
                    type = 'temporary'
                    AND (default_duration_value IS NULL) = (default_duration_unit IS NULL)
                    AND (default_duration_value IS NULL OR default_duration_value > 0)
                )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deferral_reasons');
    }
};
