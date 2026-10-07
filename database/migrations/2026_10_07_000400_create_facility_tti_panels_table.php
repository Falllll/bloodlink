<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Opt-in uji regional (§6.2) per fasilitas. Uji wajib tidak perlu baris di
        // sini: ResolveTtiPanel selalu menyertakannya.
        Schema::create('facility_tti_panels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('tti_test_type_id')->constrained('tti_test_types')->restrictOnDelete();
            $table->timestamps();
            // Kolom terdepan unique ini facility_id, jadi FK itu sudah terindeks.
            $table->unique(['facility_id', 'tti_test_type_id']);
            $table->index('tti_test_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_tti_panels');
    }
};
