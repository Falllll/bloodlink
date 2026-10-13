<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_temperature_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_location_id')->constrained('storage_locations')->restrictOnDelete();
            $table->decimal('reading_celsius', 4, 1);
            // DISIMPAN, bukan dihitung saat dibaca: pembacaan dinilai terhadap band
            // yang berlaku saat ia diambil, dan revisi band tidak boleh mengubahnya surut.
            $table->boolean('is_out_of_range');
            $table->enum('source', ['manual', 'device']);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['storage_location_id', 'recorded_at']);
            // Melayani "semua pelanggaran suhu periode ini".
            $table->index(['is_out_of_range', 'recorded_at']);
            $table->index('recorded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_temperature_readings');
    }
};
