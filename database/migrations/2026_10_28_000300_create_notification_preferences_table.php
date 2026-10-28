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
        // Kategori milik template (kartu 488). Nilai harus sama dengan NotificationCategory.
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->enum('category', [
                'emergency_call', 'appointment_reminder', 'donation_status', 'general',
                'account_security', 'facility_operations',
            ])->nullable();
        });

        // Template yang sudah terseed diisi menurut awalan kodenya; kode yang tidak
        // dikenali tetap NULL dan menggagalkan SET NOT NULL di bawah -- gagal keras,
        // bukan ditebak.
        DB::statement(<<<'SQL'
            UPDATE notification_templates SET category = CASE
                WHEN code LIKE 'auth.%' THEN 'account_security'
                WHEN code LIKE 'donor.%' THEN 'donation_status'
                WHEN code LIKE 'storage.%' OR code LIKE 'inventory.%'
                  OR code LIKE 'stock_transfer.%' OR code LIKE 'transfusion.%' THEN 'facility_operations'
            END
        SQL);
        DB::statement('ALTER TABLE notification_templates ALTER COLUMN category SET NOT NULL');

        // Satu kode, satu kategori -- lintas kanal dan locale. Tanpa ini email sebuah
        // peristiwa bisa 'general' sementara push-nya 'emergency_call', dan donor yang
        // menolak salah satunya tetap menerima peristiwa itu lewat kanal lain.
        // btree_gist sudah dipasang migrasi sebelumnya; IF NOT EXISTS untuk DB baru.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist;');
        DB::statement(<<<'SQL'
            ALTER TABLE notification_templates
            ADD CONSTRAINT notification_templates_one_category_per_code EXCLUDE USING gist (
                code WITH =,
                category WITH <>
            )
        SQL);

        // Preferensi per PENGGUNA per kategori. DEFAULT IKUT-SERTA, dan itu keputusan
        // sadar: orang yang mendaftar di aplikasi donor darah sudah menyatakan niat.
        // Ketiadaan baris = ikut-serta. Kembali ikut-serta mengosongkan opted_out_at,
        // tidak menghapus baris.
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // HANYA kategori yang boleh ditolak (NotificationCategory::isOptional()):
            // baris untuk account_security secara fisik tidak bisa masuk.
            $table->enum('category', ['emergency_call', 'appointment_reminder', 'donation_status', 'general']);
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();

            // Juga index untuk user_id (kolom terdepan).
            $table->unique(['user_id', 'category']);
        });

        // Pemilihan kandidat (DonorDirectory::reachability) mencari penolak panggilan
        // darurat per user_id; index parsial menjaga index-nya sekecil jumlah penolak.
        DB::statement(<<<'SQL'
            CREATE INDEX notification_preferences_opted_out_idx
            ON notification_preferences (category, user_id)
            WHERE opted_out_at IS NOT NULL
        SQL);

        // 'suppressed' (kartu 488): selesai saat ditulis, tanpa kode kegagalan, tanpa
        // percobaan, dan tanpa bahan render -- tidak ada yang akan merendernya.
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE notification_deliveries
            ADD CONSTRAINT notification_deliveries_status_check
            CHECK (status IN ('pending', 'sent', 'failed', 'suppressed'))
        SQL);
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_settle_shape');
        DB::statement(<<<'SQL'
            ALTER TABLE notification_deliveries
            ADD CONSTRAINT notification_deliveries_settle_shape CHECK (
                (status = 'pending' AND settled_at IS NULL AND failure_code IS NULL)
                OR (status = 'sent' AND settled_at IS NOT NULL AND failure_code IS NULL)
                OR (status = 'failed' AND settled_at IS NOT NULL AND failure_code IS NOT NULL)
                OR (status = 'suppressed' AND settled_at IS NOT NULL AND failure_code IS NULL
                    AND attempts = 0 AND variables IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM notification_deliveries WHERE status = 'suppressed'");
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_settle_shape');
        DB::statement(<<<'SQL'
            ALTER TABLE notification_deliveries
            ADD CONSTRAINT notification_deliveries_settle_shape CHECK (
                (status = 'pending' AND settled_at IS NULL AND failure_code IS NULL)
                OR (status = 'sent' AND settled_at IS NOT NULL AND failure_code IS NULL)
                OR (status = 'failed' AND settled_at IS NOT NULL AND failure_code IS NOT NULL)
            )
        SQL);
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE notification_deliveries
            ADD CONSTRAINT notification_deliveries_status_check
            CHECK (status IN ('pending', 'sent', 'failed'))
        SQL);

        Schema::dropIfExists('notification_preferences');

        DB::statement('ALTER TABLE notification_templates DROP CONSTRAINT notification_templates_one_category_per_code');
        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
