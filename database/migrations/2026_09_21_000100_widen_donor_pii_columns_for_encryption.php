<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom dilebarkan SEBELUM ada ciphertext yang masuk. Migration ini hanya aman
     * di tabel donors yang masih kosong -- baris lama tetap plaintext dan akan
     * melempar DecryptException saat dibaca lewat cast `encrypted`.
     */
    public function up(): void
    {
        if (DB::table('donors')->exists()) {
            throw new RuntimeException(
                'Tabel donors sudah berisi baris. Migration ini hanya aman di tabel kosong -- '.
                'backfill enkripsi & phone_hash untuk baris lama harus ditulis dulu sebelum migration ini jalan.'
            );
        }

        Schema::table('donors', function (Blueprint $table): void {
            $table->text('phone')->change();
            $table->text('email')->nullable()->change();
            $table->text('address')->change();
            $table->string('phone_hash', 64)->nullable()->after('phone');
            $table->index('phone_hash');
        });
    }

    public function down(): void
    {
        Schema::table('donors', function (Blueprint $table): void {
            $table->dropIndex(['phone_hash']);
            $table->dropColumn('phone_hash');
            $table->string('phone', 32)->change();
            $table->string('email')->nullable()->change();
            $table->string('address')->change();
        });
    }
};
