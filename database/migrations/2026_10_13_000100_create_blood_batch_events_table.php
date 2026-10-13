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
        Schema::create('blood_batch_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blood_batch_id')->constrained('blood_batches')->restrictOnDelete();
            // Disalin: riwayat satu unit dicapai dari public_id tanpa resolve id internal.
            $table->uuid('batch_public_id');
            // Fasilitas UNIT, bukan aktor: riwayat satu fasilitas tetap lengkap.
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->string('from_status', 16)->nullable();   // NULL hanya pada peristiwa lahirnya unit
            $table->string('to_status', 16);
            $table->string('reason', 255)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('actor_facility_id')->nullable();   // tanpa FK, mengikuti audit_logs
            $table->uuid('trace_id')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['blood_batch_id', 'occurred_at']);
            $table->index(['facility_id', 'to_status', 'occurred_at']);
            $table->index(['to_status', 'occurred_at']);
            $table->index('batch_public_id');
            $table->index('actor_id');
        });

        // Sebuah peristiwa harus berarti perubahan; status yang "berubah" menjadi
        // dirinya sendiri adalah kebisingan, bukan jejak.
        DB::statement(<<<'SQL'
            ALTER TABLE blood_batch_events
            ADD CONSTRAINT blood_batch_events_change_shape CHECK (
                from_status IS NULL OR from_status <> to_status
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION blood_batch_events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'blood_batch_events is append-only';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER blood_batch_events_no_change
            BEFORE UPDATE OR DELETE ON blood_batch_events
            FOR EACH ROW EXECUTE FUNCTION blood_batch_events_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS blood_batch_events_no_change ON blood_batch_events;');
        DB::statement('DROP FUNCTION IF EXISTS blood_batch_events_append_only();');
        DB::statement('ALTER TABLE IF EXISTS blood_batch_events DROP CONSTRAINT IF EXISTS blood_batch_events_change_shape;');

        Schema::dropIfExists('blood_batch_events');
    }
};
