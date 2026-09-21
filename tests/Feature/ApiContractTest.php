<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Errors\ErrorCode;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private function spec(): OpenApi
    {
        $path = realpath(base_path('docs/api/openapi.yaml'));

        /** @var OpenApi $spec */
        $spec = Reader::readFromYamlFile($path === false ? base_path('docs/api/openapi.yaml') : $path);

        return $spec;
    }

    public function test_the_specification_document_is_valid(): void
    {
        $spec = $this->spec();

        $this->assertTrue($spec->validate(), implode("\n", $spec->getErrors()));
    }

    public function test_validation_details_carry_rule_keys_not_sentences(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => '',
            'password' => 'abc',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);

        $response->assertJsonPath('error.details.password', fn (array $entries) => collect($entries)
            ->contains(fn (array $e) => $e['rule'] === 'min' && $e['params'] === ['min' => '8']));

        $this->assertNotEmpty($response->json('error.details.email'));

        $details = $response->json('error.details');

        array_walk_recursive($details, function ($value): void {
            $this->assertIsString($value);
            $this->assertStringNotContainsString(' ', $value);
        });
    }

    public function test_the_error_code_enum_matches_the_specification(): void
    {
        $spec = $this->spec();

        $php = array_column(ErrorCode::cases(), 'value');
        /** @var array<int, string> $fromSpec */
        $fromSpec = $spec->components->schemas['ErrorCode']->enum;

        sort($php);
        sort($fromSpec);

        $this->assertSame([], array_diff($php, $fromSpec));
        $this->assertSame([], array_diff($fromSpec, $php));
    }
}
