<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis;');

        // geography(Point, 4326) uses WGS84 meters for earth-distance checks.
        DB::statement('ALTER TABLE donors ADD COLUMN location geography(Point, 4326) NULL;');
        DB::statement('CREATE INDEX donors_location_gist ON donors USING GIST (location);');

        DB::statement('ALTER TABLE facilities ADD COLUMN location geography(Point, 4326) NULL;');
        DB::statement('CREATE INDEX facilities_location_gist ON facilities USING GIST (location);');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS donors_location_gist;');
        DB::statement('ALTER TABLE donors DROP COLUMN IF EXISTS location;');

        DB::statement('DROP INDEX IF EXISTS facilities_location_gist;');
        DB::statement('ALTER TABLE facilities DROP COLUMN IF EXISTS location;');
    }
};
