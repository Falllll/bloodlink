<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teks pesan adalah data terseed, bukan konstanta PHP (pola deferral_reasons):
     * memperbaiki satu kalimat tidak boleh menuntut perubahan kelas mana pun, dan
     * tabel ini satu-satunya tempat melihat semua pesan yang bisa dikirim sistem.
     */
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120);
            // Nilai harus sama dengan NotificationChannel.
            $table->enum('channel', ['email', 'push']);
            $table->string('locale', 8)->default('id');
            $table->string('subject', 255)->nullable();   // email saja
            $table->text('body');
            // Daftar nama variabel yang SAH untuk template ini. RenderNotification
            // memvalidasi dua arah terhadapnya: kurang dan berlebih sama-sama ditolak.
            $table->jsonb('variables');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Satu baris per (code, channel, locale): email dan push untuk peristiwa
            // yang sama berbeda panjang dan bentuk.
            $table->unique(['code', 'channel', 'locale']);
        });

        // KESETARAAN, bukan implikasi (pola blood_request_allocations_reservation_shape):
        // email tanpa subject tidak bisa dikirim, dan push dengan subject berarti ada
        // teks yang ditulis tapi tidak pernah dibaca siapa pun.
        DB::statement(<<<'SQL'
            ALTER TABLE notification_templates
            ADD CONSTRAINT notification_templates_subject_shape CHECK (
                (channel = 'email') = (subject IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE notification_templates
            ADD CONSTRAINT notification_templates_variables_shape CHECK (
                jsonb_typeof(variables) = 'array'
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
