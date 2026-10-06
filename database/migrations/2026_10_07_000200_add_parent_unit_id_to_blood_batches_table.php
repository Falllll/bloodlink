<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string STATUSES_WITHOUT_SEPARATED = "'quarantined', 'testing', 'released', 'reserved', 'issued', 'discarded', 'expired'";

    public function up(): void
    {
        Schema::table('blood_batches', function (Blueprint $table): void {
            // Jejak look-back §6.3: turunan -> induk. restrictOnDelete: induk yang
            // punya turunan tidak boleh hilang dari sejarah.
            $table->foreignId('parent_unit_id')->nullable()->constrained('blood_batches')->restrictOnDelete();
            $table->index('parent_unit_id');

            // CHECK per-baris tidak bisa menjumlah baris saudara, jadi induk membawa
            // total volume yang sudah dipisah.
            $table->unsignedInteger('separated_volume_ml')->default(0);
            $table->timestamp('separated_at')->nullable();
        });

        DB::statement('ALTER TABLE blood_batches DROP CONSTRAINT blood_batches_status_check');
        DB::statement('ALTER TABLE blood_batches ADD CONSTRAINT blood_batches_status_check CHECK (status IN ('.self::STATUSES_WITHOUT_SEPARATED.", 'separated'))");

        DB::statement(<<<'SQL'
            ALTER TABLE blood_batches
            ADD CONSTRAINT blood_batches_separated_volume CHECK (separated_volume_ml <= volume_ml)
        SQL);

        // Turunan tidak pernah membawa donation_id (kolom itu unique milik induknya,
        // Kartu 190) dan tidak pernah menjadi induknya sendiri.
        DB::statement(<<<'SQL'
            ALTER TABLE blood_batches
            ADD CONSTRAINT blood_batches_derived_shape CHECK (
                parent_unit_id IS NULL OR (donation_id IS NULL AND parent_unit_id <> id)
            )
        SQL);

        // Status separated dan cap waktunya lahir bersama, tidak pernah sendiri-sendiri.
        DB::statement(<<<'SQL'
            ALTER TABLE blood_batches
            ADD CONSTRAINT blood_batches_separated_shape CHECK (
                (status = 'separated') = (separated_at IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE blood_batches DROP CONSTRAINT IF EXISTS blood_batches_separated_shape');
        DB::statement('ALTER TABLE blood_batches DROP CONSTRAINT IF EXISTS blood_batches_derived_shape');
        DB::statement('ALTER TABLE blood_batches DROP CONSTRAINT IF EXISTS blood_batches_separated_volume');

        // Gagal keras kalau masih ada induk berstatus separated -- rollback tidak
        // boleh diam-diam mengubah status unit.
        DB::statement('ALTER TABLE blood_batches DROP CONSTRAINT blood_batches_status_check');
        DB::statement('ALTER TABLE blood_batches ADD CONSTRAINT blood_batches_status_check CHECK (status IN ('.self::STATUSES_WITHOUT_SEPARATED.'))');

        Schema::table('blood_batches', function (Blueprint $table): void {
            $table->dropIndex(['parent_unit_id']);
            $table->dropConstrainedForeignId('parent_unit_id');
            $table->dropColumn(['separated_volume_ml', 'separated_at']);
        });
    }
};
