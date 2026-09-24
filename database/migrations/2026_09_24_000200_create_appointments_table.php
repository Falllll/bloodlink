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
        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->timestamp('scheduled_for')->nullable();      // NULL = walk-in
            $table->enum('status', ['booked', 'arrived', 'screened', 'completed', 'no_show', 'cancelled']);
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('screened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['facility_id', 'scheduled_for']);
            $table->index(['donor_id', 'status']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX appointments_one_open_per_donor
            ON appointments (donor_id)
            WHERE status IN ('booked', 'arrived', 'screened')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_walk_in_shape CHECK (
                scheduled_for IS NOT NULL OR status <> 'booked'
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_walk_in_shape;');
        DB::statement('DROP INDEX IF EXISTS appointments_one_open_per_donor;');

        Schema::dropIfExists('appointments');
    }
};
