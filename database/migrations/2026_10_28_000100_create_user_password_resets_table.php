<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Token reset password (kartu 485). Tabel sendiri, BUKAN password_reset_tokens
        // bawaan Laravel: yang itu berkunci email polos dan satu baris per alamat.
        // Di sini: hanya hash SHA-256 token yang disimpan (token aslinya hanya pernah
        // ada di email), berumur pendek, sekali pakai (used_at), terikat user_id.
        Schema::create('user_password_resets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_password_resets');
    }
};
