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
        // Pesan percakapan (kartu 495). Isinya DATA KESEHATAN: body terenkripsi lewat
        // cast 'encrypted' dan dikecualikan dari audit_logs (Message::auditExcept()).
        // Riwayat tidak pernah diubah saat petugas mengambil alih -- pesan hanya ditambah.
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            // Nilai harus sama dengan MessageSenderKind.
            $table->enum('sender_kind', ['visitor', 'ai', 'staff']);
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            // `text`, bukan `string`: ciphertext jauh lebih panjang dari plaintext
            // (lihat 2026_09_21_000100_widen_donor_pii_columns_for_encryption).
            // NULL = dikosongkan privacy:purge-expired setelah masa retensi; barisnya
            // (siapa, kapan) tetap, isinya tidak.
            $table->text('body')->nullable();
            $table->timestamps();

            // Juga index untuk conversation_id (kolom terdepan); urutan riwayat per percakapan.
            $table->index(['conversation_id', 'id']);
            $table->index('sender_user_id');
        });

        // Hanya pesan petugas yang membawa akun pengirim. Pengunjung anonim dan AI
        // tidak punya baris users -- sender_user_id di sana berarti pesan yang salah label.
        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_sender_shape CHECK (
                sender_user_id IS NULL OR sender_kind = 'staff'
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS messages DROP CONSTRAINT IF EXISTS messages_sender_shape;');

        Schema::dropIfExists('messages');
    }
};
