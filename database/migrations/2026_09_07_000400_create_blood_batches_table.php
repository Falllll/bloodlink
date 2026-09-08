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
        Schema::create('blood_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('batch_number', 32)->unique();
            // Some units may be transferred between blood banks without a donor record attached.
            $table->foreignId('donor_id')->nullable();
            $table->foreignId('facility_id');
            $table->enum('component', ['whole_blood', 'packed_red_cells', 'fresh_frozen_plasma', 'platelet_concentrate', 'cryoprecipitate']);
            $table->enum('blood_group', ['A', 'B', 'AB', 'O']);
            $table->enum('rh_factor', ['positive', 'negative']);
            $table->unsignedInteger('volume_ml');
            $table->decimal('hemoglobin_g_dl', 4, 2)->nullable();
            $table->enum('status', ['quarantined', 'testing', 'released', 'reserved', 'issued', 'discarded', 'expired'])->default('quarantined');
            $table->timestamp('collected_at');
            $table->timestamp('expires_at');
            $table->string('discard_reason')->nullable();
            $table->timestamps();

            $table->foreign('donor_id')->references('id')->on('donors')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->index('donor_id');
            $table->index('facility_id');
            $table->index(['status', 'expires_at']);
            $table->index(['blood_group', 'rh_factor', 'component', 'status'], 'blood_batches_matching_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blood_batches');
    }
};
