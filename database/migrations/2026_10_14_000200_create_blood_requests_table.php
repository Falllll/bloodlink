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
        // Menyimpan apa yang DIMINTA, bukan apa yang dialokasikan: unit yang
        // memenuhinya datang bertahap lewat tabel penghubung tersendiri (kartu 320).
        Schema::create('blood_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();   // fasilitas peminta
            // Rujukan internal rumah sakit, SENGAJA bukan identitas pasien: ini bank
            // darah, bukan rekam medis. Nama atau NIK pasien di sini hanya menambah
            // kewajiban PDP tanpa manfaat.
            $table->string('patient_reference', 64);
            // Nilai enum harus sama persis dengan BloodComponent, BloodGroup, RhFactor,
            // RequestUrgency, dan RequestStatus.
            $table->enum('component', ['whole_blood', 'packed_red_cells', 'fresh_frozen_plasma', 'platelet_concentrate', 'cryoprecipitate']);
            $table->enum('blood_group', ['A', 'B', 'AB', 'O']);
            $table->enum('rh_factor', ['positive', 'negative']);
            $table->unsignedSmallInteger('quantity');
            $table->enum('urgency', ['routine', 'urgent', 'emergency']);
            $table->enum('status', ['open', 'partially_fulfilled', 'fulfilled', 'cancelled', 'expired']);
            $table->timestamp('needed_by');
            $table->text('note')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['facility_id', 'status']);
            $table->index(['status', 'needed_by']);
            $table->index(['blood_group', 'rh_factor', 'component', 'status']);
            $table->index('requested_by');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE blood_requests
            ADD CONSTRAINT blood_requests_quantity_shape CHECK (quantity > 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS blood_requests DROP CONSTRAINT IF EXISTS blood_requests_quantity_shape;');

        Schema::dropIfExists('blood_requests');
    }
};
