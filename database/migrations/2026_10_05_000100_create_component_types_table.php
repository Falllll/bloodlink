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
        Schema::create('component_types', function (Blueprint $table): void {
            $table->id();
            $table->string('jurisdiction', 8)->default('WHO');
            $table->enum('code', ['whole_blood', 'packed_red_cells', 'fresh_frozen_plasma', 'platelet_concentrate', 'cryoprecipitate']);
            $table->string('label');
            $table->boolean('is_frozen')->default(false);
            $table->boolean('requires_agitation')->default(false);
            $table->unsignedSmallInteger('post_issue_window_minutes')->nullable();
            $table->unsignedSmallInteger('post_thaw_window_hours')->nullable();
            $table->decimal('post_thaw_storage_min_celsius', 4, 1)->nullable();
            $table->decimal('post_thaw_storage_max_celsius', 4, 1)->nullable();
            $table->string('source_reference');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['jurisdiction', 'code']);
            $table->index(['jurisdiction', 'is_active']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE component_types
            ADD CONSTRAINT component_types_thaw_shape CHECK (
                (
                    (is_frozen = true AND post_thaw_window_hours IS NOT NULL)
                    OR (
                        is_frozen = false
                        AND post_thaw_window_hours IS NULL
                        AND post_thaw_storage_min_celsius IS NULL
                        AND post_thaw_storage_max_celsius IS NULL
                    )
                )
                AND (
                    post_thaw_storage_min_celsius IS NULL
                    OR post_thaw_storage_max_celsius IS NULL
                    OR post_thaw_storage_min_celsius <= post_thaw_storage_max_celsius
                )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('component_types');
    }
};
