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
        $hasDuplicatePhoneHashes = DB::table('donors')
            ->whereNotNull('phone_hash')
            ->groupBy('phone_hash')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($hasDuplicatePhoneHashes) {
            throw new RuntimeException('duplicate phone_hash rows exist; merge them first');
        }

        Schema::table('donors', function (Blueprint $table): void {
            $table->text('nik')->nullable()->after('full_name');
            $table->string('nik_hash', 64)->nullable()->after('nik');
            $table->foreignId('merged_into_id')->nullable()->constrained('donors')->restrictOnDelete();
            $table->timestamp('merged_at')->nullable();
            $table->index('date_of_birth');
        });

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');
        DB::statement('CREATE UNIQUE INDEX donors_nik_hash_active_unique ON donors (nik_hash) WHERE nik_hash IS NOT NULL AND merged_into_id IS NULL AND deleted_at IS NULL;');
        DB::statement('CREATE UNIQUE INDEX donors_phone_hash_active_unique ON donors (phone_hash) WHERE phone_hash IS NOT NULL AND merged_into_id IS NULL AND deleted_at IS NULL;');
        DB::statement('ALTER TABLE donors ADD CONSTRAINT donors_not_merged_into_self CHECK (merged_into_id IS NULL OR merged_into_id <> id);');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE donors DROP CONSTRAINT IF EXISTS donors_not_merged_into_self;');
        DB::statement('DROP INDEX IF EXISTS donors_phone_hash_active_unique;');
        DB::statement('DROP INDEX IF EXISTS donors_nik_hash_active_unique;');

        Schema::table('donors', function (Blueprint $table): void {
            $table->dropForeign(['merged_into_id']);
            $table->dropIndex(['date_of_birth']);
            $table->dropColumn(['nik', 'nik_hash', 'merged_into_id', 'merged_at']);
        });
    }
};
