<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hitungan percobaan hidup di baris, bukan hanya di payload antrean (kartu 470):
        // payload hilang saat Redis dibersihkan, dan "berapa kali kami coba menghubungi
        // donor ini" harus tetap terjawab.
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->index(['status', 'attempts']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['status', 'attempts']);
            $table->dropColumn(['attempts', 'last_attempt_at']);
        });
    }
};
