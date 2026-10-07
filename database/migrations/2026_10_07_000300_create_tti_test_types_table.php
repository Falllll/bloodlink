<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daftar uji IMLTD sebagai BARIS, bukan satu kolom hasil per infeksi:
        // §6.2 menyatakan uji tambahan mengikuti pola penyakit regional.
        Schema::create('tti_test_types', function (Blueprint $table): void {
            $table->id();
            $table->string('jurisdiction', 8)->default('WHO');
            $table->enum('code', ['hiv_1_2', 'hbsag', 'hcv', 'syphilis', 'malaria', 'chagas', 'htlv']);
            $table->string('label');
            $table->enum('requirement', ['mandatory', 'regional']);
            // §6.3 "preferably" >= 99,5%: dibaca manusia, tidak menolak input.
            $table->decimal('preferred_min_sensitivity_percent', 4, 1)->nullable();
            $table->decimal('preferred_min_specificity_percent', 4, 1)->nullable();
            $table->string('source_reference');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['jurisdiction', 'code']);
            $table->index(['jurisdiction', 'requirement', 'is_active']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tti_test_types
            ADD CONSTRAINT tti_test_types_quality_and_mandate CHECK (
                (requirement <> 'mandatory' OR is_active)
                AND (preferred_min_sensitivity_percent IS NULL OR preferred_min_sensitivity_percent BETWEEN 0 AND 100)
                AND (preferred_min_specificity_percent IS NULL OR preferred_min_specificity_percent BETWEEN 0 AND 100)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tti_test_types');
    }
};
