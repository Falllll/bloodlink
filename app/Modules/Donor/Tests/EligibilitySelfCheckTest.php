<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EligibilitySelfCheckTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function fitAdultPayload(): array
    {
        return [
            'date_of_birth' => now()->subYears(25)->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
        ];
    }

    public function test_a_fit_adult_is_eligible(): void
    {
        $response = $this->postJson('/api/v1/eligibility/self-check', $this->fitAdultPayload());

        $response->assertOk()
            ->assertJsonPath('data.outcome', 'eligible')
            ->assertJsonPath('data.is_provisional', true)
            ->assertJsonPath('data.findings', []);
    }

    public function test_a_sixteen_year_old_is_not_eligible_with_an_age_code(): void
    {
        $response = $this->postJson('/api/v1/eligibility/self-check', [
            'date_of_birth' => now()->subYears(16)->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.outcome', 'not_eligible')
            ->assertJsonPath('data.findings.0.code', 'AGE_BELOW_MINIMUM');
    }

    public function test_a_recent_donation_returns_a_retry_after_date(): void
    {
        $response = $this->postJson('/api/v1/eligibility/self-check', [
            'date_of_birth' => now()->subYears(25)->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
            'last_donation_date' => now()->subDays(10)->toDateString(),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.outcome', 'not_eligible')
            ->assertJsonPath('data.findings.0.code', 'DONATION_INTERVAL_NOT_MET');

        $this->assertNotNull($response->json('data.retry_after_date'));
    }

    public function test_a_missing_weight_returns_undetermined_not_not_eligible(): void
    {
        $response = $this->postJson('/api/v1/eligibility/self-check', [
            'date_of_birth' => now()->subYears(25)->toDateString(),
            'sex' => 'male',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.outcome', 'undetermined')
            ->assertJsonPath('data.findings.0.code', 'DATA_MISSING');
    }

    public function test_the_endpoint_needs_no_token(): void
    {
        $response = $this->postJson('/api/v1/eligibility/self-check', $this->fitAdultPayload());

        $response->assertOk();
        $this->assertNotSame(401, $response->status());
    }

    public function test_the_endpoint_needs_no_idempotency_key(): void
    {
        $response = $this->postJson('/api/v1/eligibility/self-check', $this->fitAdultPayload());

        $response->assertOk();
        $this->assertNotSame(400, $response->status());
    }

    public function test_an_unknown_body_key_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/eligibility/self-check', [
            ...$this->fitAdultPayload(),
            'nik' => '3171010190000001',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.details.nik.0.rule', 'not_allowed');
    }

    public function test_the_twenty_first_request_in_a_minute_returns_429(): void
    {
        $payload = $this->fitAdultPayload();

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/eligibility/self-check', $payload)->assertOk();
        }

        $response = $this->postJson('/api/v1/eligibility/self-check', $payload);

        $response->assertStatus(429);
    }

    public function test_no_row_is_written_anywhere(): void
    {
        $donorsBefore = DB::table('donors')->count();
        $deferralsBefore = DB::table('deferrals')->count();
        $idempotencyBefore = DB::table('idempotency_keys')->count();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/eligibility/self-check', $this->fitAdultPayload())->assertOk();
        }

        $this->assertSame($donorsBefore, DB::table('donors')->count());
        $this->assertSame($deferralsBefore, DB::table('deferrals')->count());
        $this->assertSame($idempotencyBefore, DB::table('idempotency_keys')->count());
    }
}
