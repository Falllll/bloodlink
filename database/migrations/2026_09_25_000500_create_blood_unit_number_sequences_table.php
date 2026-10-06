<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris per (prefix fasilitas, tanggal); GenerateUnitNumber menaikkan
        // last_value lewat INSERT ... ON CONFLICT ... RETURNING, atomik di database.
        Schema::create('blood_unit_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('prefix', 4);
            $table->string('period', 6);
            $table->unsignedInteger('last_value');
            $table->timestamps();
            $table->unique(['prefix', 'period'], 'blood_unit_number_sequences_prefix_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_unit_number_sequences');
    }
};
