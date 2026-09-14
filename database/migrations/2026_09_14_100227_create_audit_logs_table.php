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
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('auditable_type', 255);
            $table->unsignedBigInteger('auditable_id');
            $table->string('action', 16);                 // created | updated | deleted
            $table->foreignId('actor_id')->nullable();
            $table->unsignedBigInteger('actor_facility_id')->nullable();
            $table->jsonb('changes');
            $table->uuid('trace_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('occurred_at');

            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('actor_id');
            $table->index('occurred_at');
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_logs_no_change
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_change ON audit_logs;');
        DB::statement('DROP FUNCTION IF EXISTS audit_logs_append_only();');

        Schema::dropIfExists('audit_logs');
    }
};
