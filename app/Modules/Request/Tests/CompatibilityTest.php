<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\CheckBloodCompatibility;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Domain\CompatibilityDecision;
use App\Modules\Request\Domain\CompatibilityDirection;
use App\Modules\Request\Domain\CompatibilityOutcome;
use App\Modules\Request\Domain\RequestUrgency;
use App\Modules\Request\Domain\RuleProvenance;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ditulis ulang LANGSUNG dari teks WHO Module 3 §6.3 hlm. 45, sengaja tidak
     * memakai konstanta seeder: kalau seeder salah salin, test ini yang menangkap.
     * Penerima => donor yang boleh.
     */
    private const array WHO_RED_CELLS = ['O' => ['O'], 'A' => ['A', 'O'], 'B' => ['B', 'O'], 'AB' => ['AB', 'A', 'B', 'O']];

    /** "AB plasma ... any group; A plasma ... O and A; B plasma ... O and B; O plasma ... O only". */
    private const array WHO_PLASMA = ['O' => ['O', 'A', 'B', 'AB'], 'A' => ['A', 'AB'], 'B' => ['B', 'AB'], 'AB' => ['AB']];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CompatibilityRuleSeeder::class);
    }

    private function gate(): CheckBloodCompatibility
    {
        return app(CheckBloodCompatibility::class);
    }

    private function redCells(string $recipient, string $recipientRh, string $donor, string $donorRh): CompatibilityDecision
    {
        return $this->gate()->check(
            CompatibilityDirection::RED_CELLS,
            BloodGroup::from($recipient),
            RhFactor::from($recipientRh),
            BloodGroup::from($donor),
            RhFactor::from($donorRh),
        );
    }

    private function plasma(string $recipient, string $recipientRh, string $donor, string $donorRh): CompatibilityDecision
    {
        return $this->gate()->check(
            CompatibilityDirection::PLASMA,
            BloodGroup::from($recipient),
            RhFactor::from($recipientRh),
            BloodGroup::from($donor),
            RhFactor::from($donorRh),
        );
    }

    /** @return list<string> */
    private function compatibleRedCellDonors(string $recipient, string $recipientRh): array
    {
        $donors = [];
        foreach (BloodGroup::cases() as $group) {
            foreach (RhFactor::cases() as $rh) {
                if ($this->redCells($recipient, $recipientRh, $group->value, $rh->value)->isCompatible()) {
                    $donors[] = $group->value.($rh === RhFactor::POSITIVE ? '+' : '-');
                }
            }
        }
        sort($donors);

        return $donors;
    }

    private function request(BloodComponent $component, BloodGroup $group, RhFactor $rh): BloodRequest
    {
        $facility = Facility::factory()->create();

        return app(CreateBloodRequest::class)->handle(
            $facility->id,
            User::factory()->create(['facility_id' => $facility->id])->id,
            $component,
            $group,
            $rh,
            1,
            RequestUrgency::ROUTINE,
            new DateTimeImmutable('+1 day'),
            'RM-1',
        );
    }

    public function test_o_negative_accepts_only_o_negative_red_cells(): void
    {
        $this->assertSame(['O-'], $this->compatibleRedCellDonors('O', 'negative'));
    }

    public function test_a_positive_accepts_a_and_o_in_both_rh(): void
    {
        $this->assertSame(['A+', 'A-', 'O+', 'O-'], $this->compatibleRedCellDonors('A', 'positive'));
    }

    public function test_ab_negative_accepts_all_four_groups_but_only_rh_negative(): void
    {
        $this->assertSame(['A-', 'AB-', 'B-', 'O-'], $this->compatibleRedCellDonors('AB', 'negative'));
    }

    public function test_ab_positive_accepts_every_combination(): void
    {
        $this->assertCount(8, $this->compatibleRedCellDonors('AB', 'positive'));
    }

    public function test_the_full_red_cell_truth_table_matches_the_who_text(): void
    {
        foreach (BloodGroup::cases() as $recipient) {
            foreach (RhFactor::cases() as $recipientRh) {
                foreach (BloodGroup::cases() as $donor) {
                    foreach (RhFactor::cases() as $donorRh) {
                        $aboOk = in_array($donor->value, self::WHO_RED_CELLS[$recipient->value], true);
                        // RhD-negatif wajib RhD-negatif; RhD-positif menerima keduanya (yang negatif berlabel turunan).
                        $rhOk = $recipientRh === RhFactor::POSITIVE || $donorRh === RhFactor::NEGATIVE;

                        $this->assertSame(
                            $aboOk && $rhOk,
                            $this->redCells($recipient->value, $recipientRh->value, $donor->value, $donorRh->value)->isCompatible(),
                            "{$recipient->value} {$recipientRh->value} <- {$donor->value} {$donorRh->value}",
                        );
                    }
                }
            }
        }
    }

    public function test_the_full_plasma_truth_table_matches_the_who_text(): void
    {
        foreach (BloodGroup::cases() as $recipient) {
            foreach (BloodGroup::cases() as $donor) {
                $this->assertSame(
                    in_array($donor->value, self::WHO_PLASMA[$recipient->value], true),
                    $this->plasma($recipient->value, 'negative', $donor->value, 'positive')->isCompatible(),
                    "{$recipient->value} <- plasma {$donor->value}",
                );
            }
        }
    }

    /** Aturan paling terkutip, dan paling berbahaya kalau bocor. */
    public function test_an_rh_negative_recipient_never_accepts_rh_positive(): void
    {
        foreach (BloodGroup::cases() as $recipient) {
            foreach (self::WHO_RED_CELLS[$recipient->value] as $aboValidDonor) {
                $decision = $this->redCells($recipient->value, 'negative', $aboValidDonor, 'positive');

                $this->assertSame(CompatibilityOutcome::INCOMPATIBLE, $decision->outcome, "{$recipient->value}- <- {$aboValidDonor}+");
            }
        }
    }

    public function test_an_rh_positive_recipient_accepting_rh_negative_is_flagged_derived(): void
    {
        $derived = $this->redCells('A', 'positive', 'A', 'negative');
        $cited = $this->redCells('A', 'positive', 'A', 'positive');

        $this->assertSame(CompatibilityOutcome::COMPATIBLE, $derived->outcome);
        $this->assertSame(RuleProvenance::DERIVED, $derived->provenance);
        $this->assertContains('Rujukan Pedoman Medis §12.5 butir 1 — belum ada kutipan WHO', $derived->sourceReferences);
        $this->assertContains('WHO Module 3 §6.3 Figure 14', $derived->sourceReferences);

        $this->assertSame(RuleProvenance::CITED, $cited->provenance);
    }

    /** Satu pasangan, dua arah, dua hasil: pasangan yang sama di kedua arah tidak membuktikan apa pun. */
    public function test_plasma_runs_the_opposite_direction_from_red_cells(): void
    {
        $this->assertSame(CompatibilityOutcome::COMPATIBLE, $this->plasma('O', 'positive', 'AB', 'positive')->outcome);
        $this->assertSame(CompatibilityOutcome::INCOMPATIBLE, $this->redCells('O', 'positive', 'AB', 'positive')->outcome);

        foreach ([BloodComponent::FRESH_FROZEN_PLASMA, BloodComponent::CRYOPRECIPITATE] as $component) {
            $this->assertSame(CompatibilityDirection::PLASMA, CheckBloodCompatibility::directionFor($component));
        }
        foreach ([BloodComponent::WHOLE_BLOOD, BloodComponent::PACKED_RED_CELLS] as $component) {
            $this->assertSame(CompatibilityDirection::RED_CELLS, CheckBloodCompatibility::directionFor($component));
        }
    }

    public function test_plasma_ignores_the_rh_dimension(): void
    {
        // Sel darah merah menolak pasangan ini karena RhD; plasma tidak memeriksa RhD sama sekali.
        $this->assertFalse($this->redCells('A', 'negative', 'A', 'positive')->isCompatible());

        $decision = $this->plasma('A', 'negative', 'A', 'positive');
        $this->assertTrue($decision->isCompatible());
        $this->assertSame(['WHO Module 3 §6.3 Figure 15'], $decision->sourceReferences);
    }

    public function test_platelets_are_unsupported_not_compatible(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);

        foreach (BloodGroup::cases() as $group) {
            foreach (RhFactor::cases() as $rh) {
                // Termasuk pasangan yang jelas sah di arah sel darah merah (golongan & rhesus identik).
                $unit = BloodBatch::factory()->create(['blood_group' => $group->value, 'rh_factor' => $rh->value]);
                $decision = $this->gate()->forUnit($this->request(BloodComponent::PLATELET_CONCENTRATE, $group, $rh), $unit);

                $this->assertSame(CompatibilityOutcome::UNSUPPORTED, $decision->outcome, "{$group->value} {$rh->value}");
                $this->assertNull($decision->provenance);
            }
        }
    }

    public function test_a_unit_without_a_blood_group_is_incompatible(): void
    {
        $this->seed(ComponentTypeSeeder::class);
        $unit = BloodBatch::factory()->create(['status' => 'quarantined', 'blood_group' => null, 'rh_factor' => 'positive']);

        $decision = $this->gate()->forUnit($this->request(BloodComponent::PACKED_RED_CELLS, BloodGroup::AB, RhFactor::POSITIVE), $unit);

        $this->assertSame(CompatibilityOutcome::INCOMPATIBLE, $decision->outcome);
    }

    public function test_a_unit_without_an_rh_factor_is_incompatible(): void
    {
        $this->seed(ComponentTypeSeeder::class);
        $unit = BloodBatch::factory()->create(['status' => 'quarantined', 'blood_group' => 'O', 'rh_factor' => null]);

        // Plasma bahkan tidak memeriksa RhD -- tapi golongan yang belum lengkap tetap gagal-tertutup.
        foreach ([BloodComponent::PACKED_RED_CELLS, BloodComponent::FRESH_FROZEN_PLASMA] as $component) {
            $decision = $this->gate()->forUnit($this->request($component, BloodGroup::AB, RhFactor::POSITIVE), $unit);
            $this->assertSame(CompatibilityOutcome::INCOMPATIBLE, $decision->outcome, $component->value);
        }
    }

    public function test_a_unit_with_a_known_group_is_judged_by_the_rules(): void
    {
        $this->seed(ComponentTypeSeeder::class);
        $unit = BloodBatch::factory()->create(['blood_group' => 'O', 'rh_factor' => 'negative']);

        $decision = $this->gate()->forUnit($this->request(BloodComponent::PACKED_RED_CELLS, BloodGroup::B, RhFactor::NEGATIVE), $unit);

        $this->assertSame(CompatibilityOutcome::COMPATIBLE, $decision->outcome);
        $this->assertSame(RuleProvenance::CITED, $decision->provenance);
    }

    public function test_every_seeded_rule_carries_a_source_reference(): void
    {
        foreach (['abo_compatibility_rules', 'rh_compatibility_rules'] as $table) {
            foreach (DB::table($table)->pluck('source_reference') as $reference) {
                $this->assertIsString($reference);
                $this->assertMatchesRegularExpression('/WHO Module 3 §6\.3|Rujukan Pedoman Medis §12/', $reference, $table);
            }
        }

        $this->assertSame(18, DB::table('abo_compatibility_rules')->count());
        $this->assertSame(3, DB::table('rh_compatibility_rules')->count());
        $this->assertSame(0, DB::table('abo_compatibility_rules')->where('provenance', 'derived')->count());
        $this->assertSame(
            [['positive', 'negative']],
            DB::table('rh_compatibility_rules')->where('provenance', 'derived')->get(['recipient_rh', 'donor_rh'])
                ->map(fn (object $r): array => [$r->recipient_rh, $r->donor_rh])->all(),
        );
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(CompatibilityRuleSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);

        $this->assertSame(18, DB::table('abo_compatibility_rules')->count());
        $this->assertSame(3, DB::table('rh_compatibility_rules')->count());
    }

    public function test_a_missing_rule_row_means_incompatible_not_compatible(): void
    {
        $this->assertTrue($this->redCells('A', 'positive', 'O', 'positive')->isCompatible());

        DB::table('abo_compatibility_rules')
            ->where(['direction' => 'red_cells', 'recipient_group' => 'A', 'donor_group' => 'O'])
            ->delete();

        $this->assertSame(CompatibilityOutcome::INCOMPATIBLE, $this->redCells('A', 'positive', 'O', 'positive')->outcome);

        DB::table('rh_compatibility_rules')->where(['recipient_rh' => 'positive', 'donor_rh' => 'negative'])->delete();

        $this->assertSame(CompatibilityOutcome::INCOMPATIBLE, $this->redCells('A', 'positive', 'A', 'negative')->outcome);
    }

    public function test_the_endpoint_lists_only_o_negative_for_an_o_negative_red_cell_recipient(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $facility = Facility::factory()->create();
        $staff = User::factory()->create(['facility_id' => $facility->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility->id));
        $staff->assignRole('hospital_staff');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        $this->getJson(
            '/api/v1/compatibility?component=packed_red_cells&recipient_group=O&recipient_rh=negative',
            ['Authorization' => 'Bearer '.$staff->createToken('api')->plainTextToken],
        )
            ->assertOk()
            ->assertJsonPath('data.outcome', 'compatible')
            ->assertJsonCount(1, 'data.compatible_donors')
            ->assertJsonPath('data.compatible_donors.0.donor_group', 'O')
            ->assertJsonPath('data.compatible_donors.0.donor_rh', 'negative')
            ->assertJsonPath('data.compatible_donors.0.provenance', 'cited')
            ->assertJsonPath('data.compatible_donors.0.source_references', ['WHO Module 3 §6.3 Figure 14', 'WHO Module 3 §6.3']);
    }

    public function test_the_endpoint_needs_a_token(): void
    {
        $this->getJson('/api/v1/compatibility?component=packed_red_cells&recipient_group=O&recipient_rh=negative')->assertUnauthorized();
    }
}
