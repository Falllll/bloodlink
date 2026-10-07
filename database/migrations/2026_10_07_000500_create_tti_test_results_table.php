<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tti_test_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('blood_batch_id')->constrained('blood_batches')->restrictOnDelete();
            // Disalin dari unit: rantai donasi -> unit -> hasil utuh untuk look-back §6.3.
            $table->foreignId('donation_id')->nullable()->constrained('donations')->restrictOnDelete();
            $table->foreignId('tti_test_type_id')->constrained('tti_test_types')->restrictOnDelete();
            // Uji konfirmasi (§6.3) adalah baris kedua, bukan kolom.
            $table->boolean('is_confirmatory')->default(false);
            $table->enum('result', ['non_reactive', 'reactive', 'indeterminate']);
            $table->timestamp('tested_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['blood_batch_id', 'tti_test_type_id', 'is_confirmatory'], 'tti_test_results_unit_test_stage_unique');
            $table->index('donation_id');
            $table->index('tti_test_type_id');
            $table->index('recorded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tti_test_results');
    }
};
