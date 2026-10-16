<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // cascadeOnDelete SENGAJA, berbeda dari restrictOnDelete di tabel lain:
            // token milik akun dan tidak punya nilai riwayat medis, jadi ia ikut mati
            // bersama akunnya.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Unik GLOBAL, bukan per pengguna: satu perangkat bisa berpindah akun.
            // Pendaftaran ulang memindahkan kepemilikan (RegisterDeviceToken).
            $table->string('token', 512)->unique();
            // Nilai harus sama dengan DevicePlatform.
            $table->enum('platform', ['android', 'ios', 'web']);
            $table->timestamp('last_seen_at');
            // Dicabut, tidak dihapus: baris yang dihapus tidak bisa menjawab
            // "kenapa pesan ini tidak sampai".
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
