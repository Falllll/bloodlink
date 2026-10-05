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
        Schema::create('component_storage_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('component_type_id');
            $table->decimal('storage_temp_min_celsius', 4, 1);
            $table->decimal('storage_temp_max_celsius', 4, 1);
            $table->unsignedSmallInteger('shelf_life_value');
            $table->enum('shelf_life_unit', ['hours', 'days', 'months']);
            $table->boolean('is_default')->default(false);
            $table->string('source_reference');
            $table->timestamps();

            $table->foreign('component_type_id')->references('id')->on('component_types')->cascadeOnDelete();
            // Kolom terdepan index ini adalah component_type_id, jadi FK sudah terindeks.
            $table->unique(['component_type_id', 'storage_temp_min_celsius', 'storage_temp_max_celsius'], 'component_storage_profiles_band_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE component_storage_profiles
            ADD CONSTRAINT component_storage_profiles_band_shape CHECK (
                storage_temp_min_celsius <= storage_temp_max_celsius
                AND shelf_life_value > 0
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX component_storage_profiles_one_default
            ON component_storage_profiles (component_type_id)
            WHERE is_default
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('component_storage_profiles');
    }
};
