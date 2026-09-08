<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Existing user rows may already exist on another system, so this is backfilled later.
            $table->uuid('public_id')->nullable()->unique();
            $table->enum('role', ['admin', 'staff', 'lab_technician', 'doctor', 'donor'])->default('staff');
            $table->foreignId('facility_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();

            $table->index('facility_id');
            $table->index(['role', 'is_active']);
            $table->foreign('facility_id')->references('id')->on('facilities')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropIndex(['facility_id']);
            $table->dropIndex(['role', 'is_active']);
            $table->dropConstrainedForeignId('facility_id');
            $table->dropSoftDeletes();
            $table->dropColumn(['public_id', 'role', 'is_active']);
        });
    }
};
