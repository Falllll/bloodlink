<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daftar undangan berkursor (kartu 538): donor_id + urut contacted_at. Tanpa ini
        // donor lama membayar sort penuh di setiap halaman. EXPLAIN pada 10.000 undangan, planner
        // normal, halaman 26 baris: Bitmap Heap Scan + Sort cost 154.49 -> Index Scan Backward +
        // Incremental Sort cost 64.81. Dijaga QueryBudgetTest modul Request.
        Schema::table('donor_campaign_contacts', function (Blueprint $table): void {
            $table->index(['donor_id', 'contacted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('donor_campaign_contacts', function (Blueprint $table): void {
            $table->dropIndex(['donor_id', 'contacted_at']);
        });
    }
};
