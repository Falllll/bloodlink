<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // TANPA NOT VALID, alasannya sama dengan blood_batches_released_shape:
        // baris discarded tanpa alasan harus memaksa keputusan sadar, bukan lolos diam-diam.
        //
        // Implikasi satu arah, bukan kesetaraan seperti separated_shape:
        // discard_reason boleh terbawa pada baris yang statusnya bukan discarded.
        DB::statement(<<<'SQL'
            ALTER TABLE blood_batches
            ADD CONSTRAINT blood_batches_discarded_shape CHECK (
                status <> 'discarded' OR discard_reason IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE blood_batches DROP CONSTRAINT IF EXISTS blood_batches_discarded_shape');
    }
};
