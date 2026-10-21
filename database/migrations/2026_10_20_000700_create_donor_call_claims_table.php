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
        // "Donor ini sudah pernah dipanggil untuk permintaan ini" (kartu 480). Tabel
        // sendiri, bukan unique di notification_deliveries: satu panggilan menghasilkan
        // beberapa delivery (email DAN push), dan unique di sana memblokir kanal kedua.
        // Juga bukan unique donor_campaign_contacts (kartu 400): kuncinya per KAMPANYE,
        // dan kampanye yang dihentikan lalu dimulai ulang memanggil donor yang sama lagi.
        Schema::create('donor_call_claims', function (Blueprint $table): void {
            $table->id();
            // Keduanya NOT NULL dengan sengaja: unique PostgreSQL menganggap NULL berbeda,
            // jadi kolom nullable di kunci = penjaga yang bolong.
            $table->foreignId('blood_request_id')->constrained('blood_requests')->cascadeOnDelete();
            $table->foreignId('donor_id')->constrained('donors')->cascadeOnDelete();
            // integer, BUKAN unsignedSmallInteger: PostgreSQL tidak punya unsigned, jadi
            // Laravel memetakannya ke smallint bertanda -- maksimum 32767, bukan 65535.
            // Tangga sekarang berhenti di 30000; satu jenjang 35 km sudah menolak insert.
            // Sama dengan donor_campaign_contacts.radius_meters.
            $table->unsignedInteger('radius_meters');   // jenjang PERTAMA yang menangkapnya
            $table->timestamp('claimed_at');
            $table->timestamps();

            $table->unique(['blood_request_id', 'donor_id'], 'donor_call_claims_one_per_donor_per_request');
            // Unique komposit tidak melayani "panggilan apa saja yang diterima donor ini":
            // kolom pertamanya tidak bisa dilewati.
            $table->index('donor_id');
        });

        // Mengangkat dedup kartu 400 ke penjaga ini: setiap kontak kampanye yang sudah
        // ada adalah panggilan yang sudah terjadi. Jenjang dan waktu PERTAMA per donor.
        DB::statement(<<<'SQL'
            INSERT INTO donor_call_claims (blood_request_id, donor_id, radius_meters, claimed_at, created_at, updated_at)
            SELECT c.blood_request_id, k.donor_id, min(k.radius_meters), min(k.contacted_at), now(), now()
            FROM donor_campaign_contacts k
            JOIN donor_campaigns c ON c.id = k.donor_campaign_id
            GROUP BY c.blood_request_id, k.donor_id
            ON CONFLICT (blood_request_id, donor_id) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_call_claims');
    }
};
