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
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 255);
            $table->foreignId('user_id')->nullable();
            $table->string('endpoint', 255);          // method + path
            $table->string('request_hash', 64);       // sha256 body
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('created_at');

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('user_id');
            $table->index('created_at'); // untuk job pembersih
        });

        // NULLS NOT DISTINCT wajib — tanpa ini, baris ber-user_id NULL bisa duplikat.
        DB::statement(
            'CREATE UNIQUE INDEX idempotency_keys_user_key_unique
             ON idempotency_keys (user_id, key) NULLS NOT DISTINCT;'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
