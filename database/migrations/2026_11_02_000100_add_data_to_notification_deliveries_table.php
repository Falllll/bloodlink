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
        // Data push untuk klien (kartu 538), mis. id undangan. Bukan menumpang `variables`:
        // kolom itu disaring ke variabel template dan di-null-kan saat selesai. Isinya hanya
        // public id, bukan PII -- jadi tidak dienkripsi dan tidak dikosongkan.
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->jsonb('data')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE notification_deliveries
            ADD CONSTRAINT notification_deliveries_data_shape CHECK (
                data IS NULL OR jsonb_typeof(data) = 'object'
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_data_shape');

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropColumn('data');
        });
    }
};
