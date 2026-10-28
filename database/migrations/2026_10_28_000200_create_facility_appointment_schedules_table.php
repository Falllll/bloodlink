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
        // Jam buka fasilitas adalah jam DINDING LOKAL (kartu 503); appointments.scheduled_for
        // adalah instan UTC. Zona ini yang menjembatani keduanya. Hanya tiga zona Indonesia:
        // zona lain adalah salah ketik, bukan fasilitas di luar negeri.
        Schema::table('facilities', function (Blueprint $table): void {
            $table->string('timezone', 32)->default('Asia/Jakarta');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE facilities
            ADD CONSTRAINT facilities_timezone_indonesia CHECK (
                timezone IN ('Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura')
            )
        SQL);

        // POLA mingguan, bukan satu baris per slot per hari: slot konkret dihitung dari
        // pola ini saat diminta. Satu baris = satu jendela pada satu hari dalam seminggu;
        // satu hari boleh punya beberapa jendela (pagi + sore), tidak boleh tumpang-tindih.
        // Menit sejak tengah malam (bukan kolom `time`) supaya rentangnya bisa dijaga gist.
        Schema::create('facility_appointment_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->unsignedSmallInteger('weekday');          // ISO-8601: 1 = Senin ... 7 = Minggu
            $table->unsignedSmallInteger('opens_minute');     // 0..1439, jam dinding lokal
            $table->unsignedSmallInteger('closes_minute');    // 1..1440, eksklusif
            $table->unsignedSmallInteger('slot_minutes');
            $table->unsignedSmallInteger('capacity');         // donor per slot
            $table->timestamps();

            $table->index(['facility_id', 'weekday']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE facility_appointment_schedules
            ADD CONSTRAINT facility_appointment_schedules_shape CHECK (
                weekday BETWEEN 1 AND 7
                AND opens_minute >= 0
                AND closes_minute <= 1440
                AND opens_minute < closes_minute
                AND slot_minutes BETWEEN 5 AND 480
                AND (closes_minute - opens_minute) % slot_minutes = 0
                AND capacity BETWEEN 1 AND 500
            )
        SQL);

        // btree_gist sudah dipasang migrasi profil penyimpanan; IF NOT EXISTS untuk DB baru.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist;');
        DB::statement(<<<'SQL'
            ALTER TABLE facility_appointment_schedules
            ADD CONSTRAINT facility_appointment_schedules_no_overlap EXCLUDE USING gist (
                facility_id WITH =,
                weekday WITH =,
                int4range(opens_minute, closes_minute) WITH &&
            )
        SQL);

        // Pengecualian: libur dan penutupan mendadak, per TANGGAL LOKAL fasilitas, sehari penuh.
        Schema::create('facility_appointment_closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->date('closed_on');
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['facility_id', 'closed_on']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_appointment_closures');
        Schema::dropIfExists('facility_appointment_schedules');
        DB::statement('ALTER TABLE facilities DROP CONSTRAINT IF EXISTS facilities_timezone_indonesia;');
        Schema::table('facilities', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
