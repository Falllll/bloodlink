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
        // Riwayat pengiriman (kartu 460). Baris ditulis SEBELUM mengirim (pending),
        // supaya pengiriman yang crash di tengah tetap meninggalkan jejak.
        // SENGAJA tanpa alamat maupun isi pesan yang sudah dirender: body memuat data
        // yang bisa mengidentifikasi orang; template_code + user_id sudah menjawab
        // "pesan apa, ke siapa, kapan".
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_token_id')->nullable()->constrained('device_tokens')->nullOnDelete();
            $table->string('template_code', 64);
            $table->string('locale', 8);
            // Bahan render, BUKAN hasil render: job hanya membawa public_id, jadi
            // variabel template harus tinggal di sini. Dienkripsi (cast encrypted:array)
            // dan dikosongkan saat baris menjadi 'sent'.
            $table->text('variables')->nullable();
            // Nilai harus sama dengan NotificationChannel.
            $table->enum('channel', ['email', 'push']);
            $table->enum('status', ['pending', 'sent', 'failed']);
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_detail')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'queued_at']);
            $table->index('device_token_id');
        });

        // Bentuk penyelesaian: baris 'sent' yang menyimpan kode kegagalan, atau
        // 'failed' tanpa kode, secara fisik tidak bisa masuk.
        DB::statement(<<<'SQL'
            ALTER TABLE notification_deliveries
            ADD CONSTRAINT notification_deliveries_settle_shape CHECK (
                (status = 'pending' AND settled_at IS NULL AND failure_code IS NULL)
                OR (status = 'sent' AND settled_at IS NOT NULL AND failure_code IS NULL)
                OR (status = 'failed' AND settled_at IS NOT NULL AND failure_code IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
