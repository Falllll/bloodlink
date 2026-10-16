<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SENGAJA tanpa kolom severity/grade: derajat keparahan reaksi adalah materi
        // medis, dan enum karangan (mild/moderate/severe) menghasilkan angka laporan
        // yang terlihat resmi padahal tidak bersumber. Kalau kelak dibutuhkan, sumbernya
        // wajib dari Rujukan Pedoman Medis lebih dulu.
        //
        // SENGAJA tanpa kolom identitas pasien (nama, NIK, nomor rekam medis): ini bank
        // darah, bukan rekam medis (keputusan migration blood_requests). Pasien dicapai
        // lewat transfusi -> alokasi -> permintaan -> patient_reference.
        //
        // Satu transfusi boleh punya banyak laporan (reaksi segera dan tertunda).
        Schema::create('transfusion_reactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('transfusion_id')->constrained('transfusions')->restrictOnDelete();
            // Nullable: tanpa kategori terkutip, laporan naratif yang jujur tetap bisa dicatat.
            $table->foreignId('reaction_type_id')->nullable()
                ->constrained('transfusion_reaction_types')->restrictOnDelete();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('observed_at');      // kapan terjadi, BUKAN kapan dilaporkan
            $table->text('description');           // selalu wajib
            $table->timestamps();

            $table->index(['transfusion_id', 'observed_at']);
            $table->index('reaction_type_id');
            $table->index('reported_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfusion_reactions');
    }
};
