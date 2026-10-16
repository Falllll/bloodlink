<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis reaksi transfusi adalah materi medis: master data terseed, bukan enum
     * PHP (pola deferral_reasons dan compatibility_rules). source_reference tidak
     * nullable dan provenance hanya mengenal 'cited' -- kategori tanpa kutipan dari
     * Rujukan Pedoman Medis tidak bisa disimpan sama sekali. Tabel kosong itu sah.
     */
    public function up(): void
    {
        Schema::create('transfusion_reaction_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('source_reference');
            $table->enum('provenance', ['cited']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfusion_reaction_types');
    }
};
