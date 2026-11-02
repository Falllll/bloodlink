<?php

declare(strict_types=1);

namespace App\Modules\Chat\Tests;

use App\Models\Conversation;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Chat\Application\EscalateConversation;
use App\Modules\Chat\Application\StartConversation;
use App\Modules\Chat\Domain\EscalationReason;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Lingkup fasilitas kotak masuk (kartu 495). Data DUA fasilitas benar-benar ada di
 * setiap test: DB kosong tidak membuktikan penyaringan. Satu petugas per test.
 */
final class ConversationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Facility $a;

    private Facility $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->a = Facility::factory()->create();
        $this->b = Facility::factory()->create();
    }

    private function staffOf(?Facility $facility, string $role = 'hospital_staff'): User
    {
        $user = User::factory()->create(['facility_id' => $facility?->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility?->id));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    private function escalatedAt(Facility $facility): Conversation
    {
        $conversation = app(StartConversation::class)->handle(null, bin2hex(random_bytes(32)));

        return app(EscalateConversation::class)->handle($conversation, EscalationReason::OTHER, $facility->id);
    }

    /** 403, bukan 404 dan bukan berhasil: percakapan ditemukan, policy menolak. */
    public function test_a_staff_member_of_another_facility_cannot_assign(): void
    {
        $theirs = $this->escalatedAt($this->a);
        $this->escalatedAt($this->b);

        $this->postJson("/api/v1/inbox/conversations/{$theirs->public_id}/assign", [], $this->headers($this->staffOf($this->b)))
            ->assertForbidden();

        $this->assertNull($theirs->fresh()?->assigned_user_id);
    }

    public function test_a_staff_member_of_another_facility_cannot_read_or_close(): void
    {
        $theirs = $this->escalatedAt($this->a);
        $headers = $this->headers($this->staffOf($this->b));

        $this->getJson("/api/v1/inbox/conversations/{$theirs->public_id}/messages", $headers)->assertForbidden();
        $this->postJson("/api/v1/inbox/conversations/{$theirs->public_id}/close", [], $headers)->assertForbidden();
    }

    /** Kotak masuk: hanya yang sudah dieskalasi ke fasilitas sendiri. */
    public function test_the_inbox_lists_only_conversations_escalated_to_the_own_facility(): void
    {
        $mine = $this->escalatedAt($this->b);
        $this->escalatedAt($this->a);
        // Belum dieskalasi: tanpa fasilitas, bukan milik kotak masuk siapa pun.
        app(StartConversation::class)->handle(null, bin2hex(random_bytes(32)));
        app(StartConversation::class)->handle($this->b->id, bin2hex(random_bytes(32)));

        $ids = array_column($this->getJson('/api/v1/inbox/conversations', $this->headers($this->staffOf($this->b)))->assertOk()->json('data'), 'id');

        $this->assertSame([$mine->public_id], $ids);
    }

    public function test_a_user_without_the_chat_permission_cannot_open_the_inbox(): void
    {
        $this->escalatedAt($this->a);

        $this->getJson('/api/v1/inbox/conversations', $this->headers($this->staffOf($this->a, 'donor')))->assertForbidden();
    }
}
