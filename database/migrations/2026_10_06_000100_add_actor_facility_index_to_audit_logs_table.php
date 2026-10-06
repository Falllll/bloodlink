<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin fasilitas selalu dibatasi ke actor_facility_id (AuditLog::scopeReadableBy)
        // dan diurutkan dari occurred_at terbaru; tanpa index ini listing default-nya
        // memindai seluruh tabel.
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['actor_facility_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['actor_facility_id', 'occurred_at']);
        });
    }
};
