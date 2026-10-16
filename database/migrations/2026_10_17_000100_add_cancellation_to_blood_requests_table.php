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
        // Diisi CancelBloodRequest (kartu 360). Siapa yang membatalkan tercatat di audit_logs.
        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
        });

        // KESETARAAN, sama dengan blood_request_allocations_reservation_shape:
        // pembatalan tanpa alasan dan alasan tanpa pembatalan dua-duanya baris yang bohong.
        DB::statement(<<<'SQL'
            ALTER TABLE blood_requests
            ADD CONSTRAINT blood_requests_cancellation_shape CHECK (
                (cancelled_at IS NULL) = (cancel_reason IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS blood_requests DROP CONSTRAINT IF EXISTS blood_requests_cancellation_shape;');

        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
