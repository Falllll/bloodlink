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
        // Sanksi ADMINISTRATIF donor (kartu 430) -- tabel sendiri, BUKAN baris deferrals:
        // deferrals.deferral_reason_id menunjuk master data medis bersumber WHO, dan
        // sanksi administratif tidak punya pasal WHO. Laporan "berapa donor ditangguhkan
        // karena alasan medis" harus tetap bisa dijawab benar.
        Schema::create('donor_flags', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('donor_id')->constrained('donors')->restrictOnDelete();
            // Nilai harus sama dengan DonorFlagReason.
            $table->enum('reason', ['identity_fraud', 'repeated_no_show', 'staff_safety', 'other']);
            // NOT NULL, berbeda dari deferrals.note: sanksi tanpa alasan tertulis tidak
            // bisa dipertanggungjawabkan.
            $table->text('note');
            $table->timestamp('starts_at');
            // NULL = tidak berbatas waktu -- bukan "selamanya diam-diam": wajib bisa dicabut.
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('placed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lift_note')->nullable();
            $table->timestamps();

            // Bentuk sama dengan index deferrals: query "aktif" dilayani index yang sama.
            $table->index(['donor_id', 'lifted_at', 'ends_at']);
            $table->index('placed_by');
            $table->index('lifted_by');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE donor_flags
            ADD CONSTRAINT donor_flags_lift_shape CHECK (
                lifted_at IS NOT NULL OR (lifted_by IS NULL AND lift_note IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS donor_flags DROP CONSTRAINT IF EXISTS donor_flags_lift_shape;');

        Schema::dropIfExists('donor_flags');
    }
};
