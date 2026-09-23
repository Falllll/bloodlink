<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donors', function (Blueprint $table): void {
            $table->dropIndex(['is_deferred', 'deferred_until']);
            $table->dropColumn(['is_deferred', 'deferred_until']);
        });
    }

    public function down(): void
    {
        // Catatan: down() mengembalikan BENTUK kolom, bukan isinya.
        // Rollback tidak memulihkan status deferral lama.
        Schema::table('donors', function (Blueprint $table): void {
            $table->boolean('is_deferred')->default(false);
            $table->date('deferred_until')->nullable();
            $table->index(['is_deferred', 'deferred_until']);
        });
    }
};
