<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dua tabel karena dua dimensi dengan tingkat kepercayaan berbeda: ABO
     * terkutip penuh dari WHO, separuh RhD tidak (Rujukan Pedoman Medis §12.7).
     * Tidak ada baris berarti TIDAK BOLEH. source_reference tidak nullable:
     * aturan medis tanpa sumber tidak bisa disimpan sama sekali.
     */
    public function up(): void
    {
        Schema::create('abo_compatibility_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('jurisdiction', 8)->default('WHO');
            $table->enum('direction', ['red_cells', 'plasma']);
            $table->enum('recipient_group', ['A', 'B', 'AB', 'O']);
            $table->enum('donor_group', ['A', 'B', 'AB', 'O']);
            $table->enum('provenance', ['cited', 'derived']);
            $table->string('source_reference');
            $table->timestamps();

            $table->unique(['jurisdiction', 'direction', 'recipient_group', 'donor_group'], 'abo_compatibility_rules_unique');
        });

        Schema::create('rh_compatibility_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('jurisdiction', 8)->default('WHO');
            $table->enum('recipient_rh', ['positive', 'negative']);
            $table->enum('donor_rh', ['positive', 'negative']);
            $table->enum('provenance', ['cited', 'derived']);
            $table->string('source_reference');
            $table->timestamps();

            $table->unique(['jurisdiction', 'recipient_rh', 'donor_rh'], 'rh_compatibility_rules_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rh_compatibility_rules');
        Schema::dropIfExists('abo_compatibility_rules');
    }
};
