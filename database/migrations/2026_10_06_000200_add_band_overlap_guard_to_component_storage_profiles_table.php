<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // btree_gist dibutuhkan untuk memakai `=` (bigint) di dalam EXCLUDE USING gist.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist;');

        // Unique band_unique hanya menolak band yang PERSIS sama; dua band yang
        // tumpang-tindih untuk satu komponen membuat suhu simpan ambigu.
        DB::statement(<<<'SQL'
            ALTER TABLE component_storage_profiles
            ADD CONSTRAINT component_storage_profiles_no_band_overlap EXCLUDE USING gist (
                component_type_id WITH =,
                numrange(storage_temp_min_celsius, storage_temp_max_celsius, '[]') WITH &&
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE component_storage_profiles DROP CONSTRAINT IF EXISTS component_storage_profiles_no_band_overlap;');
    }
};
