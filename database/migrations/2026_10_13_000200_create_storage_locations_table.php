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
        Schema::create('storage_locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('label');
            // Nilai harus sama persis dengan StorageKind dan StoragePurpose.
            $table->enum('kind', ['refrigerator', 'freezer', 'deep_freezer', 'platelet_agitator', 'ambient_shelf']);
            $table->enum('purpose', ['untested', 'reactive', 'indeterminate', 'release_ready', 'general']);
            // Band milik ALAT yang nyata, bukan band referensi per komponen di component_storage_profiles.
            $table->decimal('temp_min_celsius', 4, 1);
            $table->decimal('temp_max_celsius', 4, 1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['facility_id', 'code'], 'storage_locations_code_unique');
            $table->index(['facility_id', 'is_active']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE storage_locations
            ADD CONSTRAINT storage_locations_band_shape CHECK (
                temp_min_celsius <= temp_max_celsius
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS storage_locations DROP CONSTRAINT IF EXISTS storage_locations_band_shape;');

        Schema::dropIfExists('storage_locations');
    }
};
