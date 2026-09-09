<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DevDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeederSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_data_seeder_creates_valid_data(): void
    {
        $this->seed(DevDataSeeder::class);

        $this->assertGreaterThan(0, DB::table('donors')->count());
        $this->assertSame(
            0,
            DB::table('blood_batches')
                ->whereColumn('expires_at', '<=', 'collected_at')
                ->count(),
        );
        $this->assertSame(0, DB::table('donors')->whereNull('location')->count());
    }
}
