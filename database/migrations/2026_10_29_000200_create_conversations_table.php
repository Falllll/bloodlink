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
        // Percakapan pengunjung/donor dengan fasilitas (kartu 495). Isi pesannya di
        // tabel messages, terenkripsi; tabel ini hanya status dan siapa memegangnya.
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // Null untuk pengunjung anonim yang fasilitasnya belum jelas. WAJIB terisi
            // saat eskalasi: selama null, scopeVisibleTo menghasilkan nol baris.
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            // Nilai harus sama dengan ConversationStatus.
            $table->enum('status', ['open', 'escalated', 'assigned', 'closed']);
            // HMAC token pengunjung, bukan tokennya. Pola: donors.phone_hash.
            $table->string('visitor_token_hash', 64)->nullable();
            $table->timestamp('visitor_expires_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            // Nilai harus sama dengan EscalationReason.
            $table->enum('escalation_reason', ['personal_medical_question', 'lab_result_question', 'complaint', 'other'])->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('visitor_token_hash');
            // Juga index untuk facility_id (kolom terdepan).
            $table->index(['facility_id', 'status']);
            $table->index('assigned_user_id');
        });

        // Eskalasi = alasan + waktu + FASILITAS, sekaligus. Percakapan tereskalasi
        // tanpa fasilitas menghilang dari setiap kotak masuk tanpa error -- jadi
        // database yang menolaknya, bukan `if` di PHP yang bisa dilupakan jalur baru.
        DB::statement(<<<'SQL'
            ALTER TABLE conversations
            ADD CONSTRAINT conversations_escalation_shape CHECK (
                (escalated_at IS NULL) = (escalation_reason IS NULL)
                AND (escalated_at IS NULL OR facility_id IS NOT NULL)
            )
        SQL);

        // Token pengunjung selalu berumur: hash tanpa kedaluwarsa = identitas abadi.
        DB::statement(<<<'SQL'
            ALTER TABLE conversations
            ADD CONSTRAINT conversations_visitor_shape CHECK (
                (visitor_token_hash IS NULL) = (visitor_expires_at IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS conversations DROP CONSTRAINT IF EXISTS conversations_visitor_shape;');
        DB::statement('ALTER TABLE IF EXISTS conversations DROP CONSTRAINT IF EXISTS conversations_escalation_shape;');

        Schema::dropIfExists('conversations');
    }
};
