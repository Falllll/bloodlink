<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('donors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('donor_number', 32)->unique();
            // Not every donor has an app account, but a single account can map to only one donor record.
            $table->foreignId('user_id')->nullable()->unique();
            $table->foreignId('registered_facility_id');
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->enum('sex', ['male', 'female']);
            $table->enum('blood_group', ['A', 'B', 'AB', 'O'])->nullable();
            $table->enum('rh_factor', ['positive', 'negative'])->nullable();
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->string('address');
            $table->string('city', 100);
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->date('last_donation_date')->nullable();
            $table->unsignedInteger('donation_count')->default(0);
            $table->boolean('is_deferred')->default(false);
            $table->date('deferred_until')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('registered_facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->index('registered_facility_id');
            $table->index(['blood_group', 'rh_factor']);
            $table->index('last_donation_date');
            $table->index(['is_deferred', 'deferred_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donors');
    }
};
