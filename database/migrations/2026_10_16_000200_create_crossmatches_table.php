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
        // Append-only: satu alokasi boleh punya banyak baris, yang berlaku adalah
        // performed_at terbaru. Hasil pemeriksaan yang pernah dicatat tidak boleh
        // hilang karena ada pemeriksaan berikutnya (preseden blood_batch_events).
        Schema::create('crossmatches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('blood_request_allocation_id')->constrained('blood_request_allocations')->restrictOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            // Nilai harus sama dengan CrossmatchResult.
            $table->enum('result', ['compatible', 'incompatible', 'inconclusive']);
            $table->string('method', 120)->nullable();   // jejak audit, TIDAK dipakai memutuskan
            $table->text('note')->nullable();
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['blood_request_allocation_id', 'performed_at']);
            $table->index('performed_by');
        });

        // Pemeriksaan yang gagal tanpa keterangan adalah baris yang tidak bisa
        // ditindaklanjuti siapa pun. Implikasi SATU ARAH (seperti
        // blood_batches_discarded_shape): hasil compatible boleh punya catatan, boleh tidak.
        DB::statement(<<<'SQL'
            ALTER TABLE crossmatches
            ADD CONSTRAINT crossmatches_failure_needs_note CHECK (
                result = 'compatible' OR note IS NOT NULL
            )
        SQL);

        // Append-only ditegakkan database, bukan konvensi (pola blood_batch_events).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION crossmatches_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'crossmatches is append-only';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER crossmatches_no_change
            BEFORE UPDATE OR DELETE ON crossmatches
            FOR EACH ROW EXECUTE FUNCTION crossmatches_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS crossmatches_no_change ON crossmatches;');
        DB::statement('DROP FUNCTION IF EXISTS crossmatches_append_only();');
        DB::statement('ALTER TABLE IF EXISTS crossmatches DROP CONSTRAINT IF EXISTS crossmatches_failure_needs_note;');

        Schema::dropIfExists('crossmatches');
    }
};
