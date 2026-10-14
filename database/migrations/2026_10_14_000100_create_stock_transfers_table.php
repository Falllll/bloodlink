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
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('blood_batch_id')->constrained('blood_batches')->restrictOnDelete();
            $table->foreignId('from_facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('to_facility_id')->constrained('facilities')->restrictOnDelete();
            // Nilai harus sama persis dengan TransferStatus.
            $table->enum('status', ['requested', 'approved', 'rejected', 'shipped', 'received', 'cancelled']);
            $table->text('reason')->nullable();          // alasan permintaan, diisi peminta
            $table->text('decision_note')->nullable();   // alasan tolak/setuju, diisi pemilik
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['from_facility_id', 'status']);
            $table->index(['to_facility_id', 'status']);
            $table->index(['blood_batch_id', 'status']);
            $table->index('requested_by');
            $table->index('decided_by');
        });

        // Satu transfer terbuka per unit. Predikat harus sama dengan TransferStatus::isOpen().
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX stock_transfers_one_open_per_unit
            ON stock_transfers (blood_batch_id)
            WHERE status IN ('requested', 'approved', 'shipped')
        SQL);

        // Memindahkan unit ke fasilitasnya sendiri bukan transfer.
        DB::statement(<<<'SQL'
            ALTER TABLE stock_transfers
            ADD CONSTRAINT stock_transfers_direction_shape CHECK (
                from_facility_id <> to_facility_id
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE stock_transfers
            ADD CONSTRAINT stock_transfers_received_shape CHECK (
                status <> 'received' OR received_at IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS stock_transfers DROP CONSTRAINT IF EXISTS stock_transfers_received_shape;');
        DB::statement('ALTER TABLE IF EXISTS stock_transfers DROP CONSTRAINT IF EXISTS stock_transfers_direction_shape;');
        DB::statement('DROP INDEX IF EXISTS stock_transfers_one_open_per_unit;');

        Schema::dropIfExists('stock_transfers');
    }
};
