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
        // Satu baris per KEJADIAN (kartu 489): satu panggilan DispatchNotification untuk
        // satu penerima. Barisnya di notification_deliveries adalah per alamat per kanal --
        // email dan push dari kejadian yang sama. Riwayat donor memaginasi tabel ini,
        // bukan delivery, supaya satu kabar tidak tampil sebagai dua.
        // SENGAJA tanpa isi pesan maupun variabel, sama seperti notification_deliveries.
        Schema::create('notification_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('template_code', 64);
            $table->string('locale', 8);
            $table->timestamp('dispatched_at');
            $table->timestamps();

            // Urutan riwayat: GET /me/notifications, terbaru dulu, tie-breaker id.
            // Juga index untuk user_id (kolom terdepan).
            $table->index(['user_id', 'dispatched_at', 'id']);
            // Target foreign key komposit di bawah.
            $table->unique(['id', 'user_id']);
        });

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->unsignedBigInteger('notification_dispatch_id')->nullable();
            // Index PENUH untuk FK komposit di bawah; kolom terdepannya juga melayani
            // eager-load deliveries per kejadian.
            $table->index(['notification_dispatch_id', 'user_id']);
        });

        // Baris lama tidak membawa kejadiannya. Pengelompokan terbaik yang tersedia:
        // DispatchNotification menulis semua baris satu kejadian di satu transaksi, dan
        // queued_at berpresisi detik -- jadi (user, template, locale, queued_at) yang sama
        // dianggap satu kejadian. Dua kejadian identik di detik yang sama ikut melebur;
        // itu hanya mungkin untuk data sebelum migrasi ini.
        DB::statement(<<<'SQL'
            INSERT INTO notification_dispatches (public_id, user_id, template_code, locale, dispatched_at, created_at, updated_at)
            SELECT gen_random_uuid(), user_id, template_code, locale, queued_at, MIN(created_at), MIN(created_at)
            FROM notification_deliveries
            GROUP BY user_id, template_code, locale, queued_at
        SQL);
        DB::statement(<<<'SQL'
            UPDATE notification_deliveries d
            SET notification_dispatch_id = n.id
            FROM notification_dispatches n
            WHERE n.user_id = d.user_id
              AND n.template_code = d.template_code
              AND n.locale = d.locale
              AND n.dispatched_at = d.queued_at
        SQL);
        DB::statement('ALTER TABLE notification_deliveries ALTER COLUMN notification_dispatch_id SET NOT NULL');

        // KOMPOSIT (dispatch, user): delivery milik user lain secara fisik tidak bisa
        // menempel pada kejadian user ini -- riwayat seseorang tidak bisa memuat status
        // pengiriman orang lain, sekalipun kode penulisnya salah.
        DB::statement(<<<'SQL'
            ALTER TABLE notification_deliveries
            ADD CONSTRAINT notification_deliveries_dispatch_owner_fk
            FOREIGN KEY (notification_dispatch_id, user_id)
            REFERENCES notification_dispatches (id, user_id)
            ON DELETE CASCADE
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_dispatch_owner_fk');
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['notification_dispatch_id', 'user_id']);
            $table->dropColumn('notification_dispatch_id');
        });
        Schema::dropIfExists('notification_dispatches');
    }
};
