<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BloodBatch;
use App\Models\Donor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ModelFillableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function models(): array
    {
        return [
            'donor' => [Donor::class, 'donors'],
            'blood batch' => [BloodBatch::class, 'blood_batches'],
        ];
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('models')]
    public function test_every_fillable_attribute_is_a_real_column(string $model, string $table): void
    {
        foreach ((new $model)->getFillable() as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "Fillable attribute [{$column}] on {$model} is not a column of [{$table}]."
            );
        }
    }

    public function test_facility_ownership_is_not_mass_assignable(): void
    {
        $this->assertNotContains('facility_id', (new BloodBatch)->getFillable());
        $this->assertNotContains('registered_facility_id', (new Donor)->getFillable());
    }

    public function test_server_controlled_columns_are_not_mass_assignable(): void
    {
        $protected = ['status', 'public_id', 'batch_number', 'donor_number', 'donation_count'];

        foreach ([new Donor, new BloodBatch] as $model) {
            foreach ($protected as $column) {
                $this->assertNotContains($column, $model->getFillable());
            }
        }
    }

    public function test_assign_facility_sets_the_owner_column_without_saving(): void
    {
        $batch = (new BloodBatch)->assignFacility(7);
        $donor = (new Donor)->assignFacility(9);

        $this->assertSame(7, $batch->ownerFacilityId());
        $this->assertSame(9, $donor->ownerFacilityId());
        $this->assertFalse($batch->exists);
        $this->assertFalse($donor->exists);
    }
}
