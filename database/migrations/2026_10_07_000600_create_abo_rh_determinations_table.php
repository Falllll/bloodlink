<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu penetapan golongan per unit; blood_batches.blood_group/rh_factor
        // hanya salinan dari baris ini.
        Schema::create('abo_rh_determinations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('blood_batch_id')->unique()->constrained('blood_batches')->restrictOnDelete();
            $table->foreignId('donation_id')->nullable()->constrained('donations')->restrictOnDelete();
            $table->enum('blood_group', ['A', 'B', 'AB', 'O']);
            $table->enum('rh_factor', ['positive', 'negative']);
            $table->timestamp('determined_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('donation_id');
            $table->index('recorded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abo_rh_determinations');
    }
};
