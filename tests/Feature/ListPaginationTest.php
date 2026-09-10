<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BloodBatch;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use App\Shared\Http\ListRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ListPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->group(function () {
            Route::get('/api/v1/_test/blood-batches', function (BloodBatchListRequest $request) {
                $page = (new class
                {
                    use AppliesListQuery;

                    public function run($query, $request)
                    {
                        return $this->listing($query, $request);
                    }
                })->run(
                    BloodBatch::query()->select(['id', 'expires_at', 'status', 'blood_group']),
                    $request
                );

                return ApiResponse::paginated($page);
            });
        });
    }

    public function test_it_paginates_all_rows_exactly_once_even_with_duplicate_sort_values(): void
    {
        $tiedExpiry = now()->addDays(10);

        // Force several ties on the sort column so the id tie-breaker is the only
        // thing that keeps ordering (and therefore pagination) deterministic.
        BloodBatch::factory()->count(10)->create(['expires_at' => $tiedExpiry]);
        BloodBatch::factory()->count(20)->create();

        $seenIds = [];
        $cursor = null;

        do {
            $response = $this->getJson('/api/v1/_test/blood-batches?'.http_build_query(array_filter([
                'sort' => 'expires_at',
                'per_page' => 7,
                'cursor' => $cursor,
            ])))->assertOk();

            foreach ($response->json('data') as $row) {
                $seenIds[] = $row['id'];
            }

            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null);

        $this->assertCount(30, $seenIds);
        $this->assertCount(30, array_unique($seenIds), 'Pagination must not repeat or skip rows when sort values tie.');
    }

    public function test_it_rejects_sort_columns_outside_the_allowlist(): void
    {
        BloodBatch::factory()->create();

        $this->getJson('/api/v1/_test/blood-batches?sort=password')
            ->assertStatus(422);
    }

    public function test_it_rejects_filter_keys_outside_the_allowlist(): void
    {
        BloodBatch::factory()->create();

        $this->getJson('/api/v1/_test/blood-batches?'.http_build_query([
            'filter' => ['donor_id' => 1],
        ]))->assertStatus(422);
    }

    public function test_it_clamps_per_page_instead_of_rejecting_it(): void
    {
        BloodBatch::factory()->count(3)->create();

        $this->getJson('/api/v1/_test/blood-batches?per_page=1000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonStructure(['data', 'meta', 'links' => ['next', 'prev']]);
    }
}

class BloodBatchListRequest extends ListRequest
{
    protected array $filterable = ['status', 'blood_group'];

    protected array $sortable = ['expires_at'];

    public function authorize(): bool
    {
        return true;
    }
}
