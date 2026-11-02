<?php

declare(strict_types=1);

namespace App\Modules\Chat\Tests;

use App\Models\Conversation;
use App\Models\Facility;
use App\Models\Message;
use App\Models\User;
use App\Modules\Chat\Application\PostMessage;
use App\Modules\Chat\Domain\MessageSenderKind;
use App\Modules\Chat\Infrastructure\Broadcasting\ConversationActivityBroadcast;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Percakapan & eskalasi (kartu 495). Pengunjung anonim lewat X-Visitor-Token;
 * paling banyak SATU petugas terautentikasi per test (guard Sanctum memegang user
 * dari request pertama).
 */
final class ConversationTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'Saya minum obat pengencer darah, boleh donor?';

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeSecond();
        $this->facility = Facility::factory()->create();
    }

    /** Nama role sengaja literal: ModChat tidak boleh bergantung ke DomIdentity. */
    private function staff(string $role = 'hospital_staff'): User
    {
        $user = User::factory()->create(['facility_id' => $this->facility->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($this->facility->id));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function staffHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    /** @return array{id: string, token: string} */
    private function start(string $message = self::SECRET): array
    {
        $data = $this->postJson('/api/v1/conversations', ['message' => $message])->assertCreated()->json('data');

        return ['id' => $data['conversation']['id'], 'token' => $data['visitor_token']];
    }

    /** @param array{id: string, token: string} $c */
    private function escalate(array $c, string $reason = 'personal_medical_question'): void
    {
        $this->postJson("/api/v1/conversations/{$c['id']}/escalate", ['reason' => $reason, 'facility_id' => $this->facility->public_id], ['X-Visitor-Token' => $c['token']])
            ->assertOk();
    }

    private function conversation(string $publicId): Conversation
    {
        return Conversation::query()->where('public_id', $publicId)->sole();
    }

    public function test_an_anonymous_visitor_starts_a_conversation_and_gets_the_token_once(): void
    {
        $response = $this->postJson('/api/v1/conversations', ['message' => 'Halo, jam buka UDD?'])
            ->assertCreated()
            ->assertJsonPath('data.conversation.status', 'open')
            ->assertJsonPath('data.conversation.facility_id', null)
            ->assertJsonPath('data.message.sender_kind', 'visitor')
            ->assertJsonMissingPath('data.conversation.visitor_token_hash');

        $token = $response->json('data.visitor_token');
        $id = $response->json('data.conversation.id');
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));

        // Yang tersimpan hanya HMAC-nya.
        $stored = $this->conversation($id);
        $this->assertSame(Conversation::visitorTokenHash($token), $stored->visitor_token_hash);
        $this->assertSame(0, DB::table('conversations')->where('visitor_token_hash', $token)->count());

        // Dan tidak pernah muncul lagi di respons mana pun.
        $headers = ['X-Visitor-Token' => $token];
        $bodies = [
            $this->getJson("/api/v1/conversations/{$id}/messages", $headers)->assertOk()->getContent(),
            $this->postJson("/api/v1/conversations/{$id}/messages", ['body' => 'Satu lagi'], $headers)->assertCreated()->getContent(),
        ];
        foreach ($bodies as $body) {
            $this->assertStringNotContainsString($token, (string) $body);
            $this->assertStringNotContainsString((string) $stored->visitor_token_hash, (string) $body);
        }
    }

    public function test_a_wrong_or_missing_visitor_token_is_rejected_the_same_way(): void
    {
        $c = $this->start();

        foreach ([[], ['X-Visitor-Token' => 'tebakan'], ['X-Visitor-Token' => str_repeat('a', 64)]] as $headers) {
            $this->postJson("/api/v1/conversations/{$c['id']}/messages", ['body' => 'x'], $headers)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'CONVERSATION_REJECTED');
        }

        $this->assertSame(1, Message::query()->count());
    }

    public function test_an_expired_visitor_token_is_rejected(): void
    {
        $c = $this->start();
        DB::table('conversations')->where('public_id', $c['id'])->update(['visitor_expires_at' => now()->subMinute()]);

        $this->postJson("/api/v1/conversations/{$c['id']}/messages", ['body' => 'masih di sini?'], ['X-Visitor-Token' => $c['token']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CONVERSATION_REJECTED');
    }

    public function test_escalation_sets_status_reason_and_facility_together(): void
    {
        $c = $this->start();

        $this->postJson("/api/v1/conversations/{$c['id']}/escalate", ['reason' => 'lab_result_question', 'facility_id' => $this->facility->public_id], ['X-Visitor-Token' => $c['token']])
            ->assertOk()
            ->assertJsonPath('data.status', 'escalated')
            ->assertJsonPath('data.escalation_reason', 'lab_result_question')
            ->assertJsonPath('data.facility_id', $this->facility->public_id);

        $stored = $this->conversation($c['id']);
        $this->assertSame($this->facility->id, $stored->facility_id);
        $this->assertNotNull($stored->escalated_at);

        // Sekali jalan.
        $this->postJson("/api/v1/conversations/{$c['id']}/escalate", ['reason' => 'other', 'facility_id' => $this->facility->public_id], ['X-Visitor-Token' => $c['token']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONVERSATION_REJECTED');
    }

    public function test_escalation_requires_a_facility_and_a_known_reason(): void
    {
        $c = $this->start();
        $headers = ['X-Visitor-Token' => $c['token']];

        $this->postJson("/api/v1/conversations/{$c['id']}/escalate", ['reason' => 'other'], $headers)
            ->assertStatus(422)->assertJsonPath('error.details.facility_id.0.rule', 'required');
        $this->postJson("/api/v1/conversations/{$c['id']}/escalate", ['reason' => 'tolong saya', 'facility_id' => $this->facility->public_id], $headers)
            ->assertStatus(422)->assertJsonPath('error.details.reason.0.rule', 'enum');
    }

    /** Petugas mengambil alih: riwayat (termasuk pesan AI) tetap utuh, tidak diubah. */
    public function test_a_staff_member_takes_over_with_the_history_intact(): void
    {
        $c = $this->start();
        app(PostMessage::class)->handle($this->conversation($c['id']), MessageSenderKind::AI, 'Pertanyaan ini perlu petugas.', null);
        $this->escalate($c);
        $before = Message::query()->orderBy('id')->get(['public_id', 'sender_kind', 'body', 'updated_at'])->toArray();

        $staff = $this->staff();
        $headers = $this->staffHeaders($staff);

        $this->postJson("/api/v1/inbox/conversations/{$c['id']}/assign", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.assigned_user_id', $staff->public_id);

        $this->postJson("/api/v1/inbox/conversations/{$c['id']}/messages", ['body' => 'Saya petugas, mari kita bahas.'], ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertCreated()
            ->assertJsonPath('data.sender_kind', 'staff');

        $this->assertSame($before, Message::query()->orderBy('id')->limit(count($before))->get(['public_id', 'sender_kind', 'body', 'updated_at'])->toArray());

        $kinds = array_column($this->getJson("/api/v1/inbox/conversations/{$c['id']}/messages", $headers)->assertOk()->json('data'), 'sender_kind');
        $this->assertSame(['visitor', 'ai', 'staff'], $kinds);
        $this->assertSame($staff->id, Message::query()->where('sender_kind', 'staff')->sole()->sender_user_id);
    }

    public function test_only_the_assigned_staff_member_replies(): void
    {
        $c = $this->start();
        $this->escalate($c);

        // Belum diambil alih siapa pun: membalas ditolak.
        $this->postJson("/api/v1/inbox/conversations/{$c['id']}/messages", ['body' => 'x'], $this->staffHeaders($this->staff()))
            ->assertForbidden();
    }

    public function test_a_closed_conversation_accepts_nothing_more(): void
    {
        $c = $this->start();
        $this->escalate($c);
        $headers = $this->staffHeaders($this->staff());

        $this->postJson("/api/v1/inbox/conversations/{$c['id']}/close", [], $headers)->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson("/api/v1/inbox/conversations/{$c['id']}/close", [], ['Idempotency-Key' => (string) Str::uuid()] + $headers)->assertStatus(409);

        $this->postJson("/api/v1/conversations/{$c['id']}/messages", ['body' => 'halo?'], ['X-Visitor-Token' => $c['token']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONVERSATION_REJECTED');
    }

    /** Data kesehatan: ciphertext di database, dan tidak ada body di audit_logs. */
    public function test_the_message_body_is_encrypted_at_rest_and_kept_out_of_audit_logs(): void
    {
        $this->start();

        $raw = (string) DB::table('messages')->value('body');
        $this->assertStringNotContainsString('pengencer', $raw);
        $this->assertSame(self::SECRET, Message::query()->sole()->body);

        $changes = DB::table('audit_logs')->where('auditable_type', Message::class)->pluck('changes')->implode(' ');
        $this->assertNotSame('', $changes, 'Message harus tetap teraudit -- hanya body yang dikecualikan.');
        $this->assertStringNotContainsString('"body"', $changes);
        $this->assertStringNotContainsString($raw, $changes);
        $this->assertStringNotContainsString('visitor_token_hash', DB::table('audit_logs')->where('auditable_type', Conversation::class)->pluck('changes')->implode(' '));
    }

    public function test_the_database_refuses_an_escalation_without_a_facility(): void
    {
        $c = $this->start();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('conversations_escalation_shape');

        DB::table('conversations')->where('public_id', $c['id'])
            ->update(['status' => 'escalated', 'escalated_at' => now(), 'escalation_reason' => 'other']);
    }

    public function test_the_database_refuses_a_visitor_token_without_an_expiry(): void
    {
        $c = $this->start();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('conversations_visitor_shape');

        DB::table('conversations')->where('public_id', $c['id'])->update(['visitor_expires_at' => null]);
    }

    public function test_the_database_refuses_a_sender_account_on_a_non_staff_message(): void
    {
        $this->start();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('messages_sender_shape');

        DB::table('messages')->update(['sender_user_id' => User::factory()->create()->id]);
    }

    /** Siaran ke channel fasilitas: id dan jenis pengirim saja, tidak pernah isi pesan. */
    public function test_the_broadcast_never_carries_the_message_body(): void
    {
        Queue::fake();
        $c = $this->start();
        $this->escalate($c);
        $this->postJson("/api/v1/conversations/{$c['id']}/messages", ['body' => self::SECRET], ['X-Visitor-Token' => $c['token']])->assertCreated();

        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);
        $broadcasts = array_values(array_filter(
            array_map(fn (BroadcastEvent $job): object => $job->event, $queue->pushed(BroadcastEvent::class)->all()),
            fn (object $event): bool => $event instanceof ConversationActivityBroadcast,
        ));

        // Pesan pertama (sebelum eskalasi) tidak punya fasilitas = tidak disiarkan.
        $this->assertSame(['conversation.escalated', 'conversation.message-posted'], array_map(fn (ConversationActivityBroadcast $b): string => $b->broadcastAs(), $broadcasts));

        $posted = $broadcasts[1];
        $this->assertSame('private-facility.'.$this->facility->public_id, $posted->broadcastOn()->name);
        $this->assertSame(['conversation_id', 'message_id', 'sender_kind'], array_keys($posted->broadcastWith()));
        foreach ($broadcasts as $broadcast) {
            $this->assertStringNotContainsString('pengencer', (string) json_encode($broadcast->broadcastWith()));
        }
    }

    public function test_a_rolled_back_message_is_never_broadcast(): void
    {
        Queue::fake();
        $c = $this->start();
        $this->escalate($c);
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);
        $before = $queue->pushed(BroadcastEvent::class)->count();

        try {
            DB::transaction(function () use ($c): void {
                app(PostMessage::class)->handle($this->conversation($c['id']), MessageSenderKind::VISITOR, 'batal', null);

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, $queue->pushed(BroadcastEvent::class)->count());
    }

    public function test_expired_message_bodies_are_purged_but_the_rows_stay(): void
    {
        config(['privacy.retention.health_years' => 5]);
        $this->start();
        $this->travel(6)->years();
        $this->start('Pesan baru');

        $this->artisan('privacy:purge-expired', ['--force' => true])->assertSuccessful();

        $this->assertSame(2, Message::query()->count());
        $this->assertSame([null, 'Pesan baru'], Message::query()->orderBy('id')->pluck('body')->all());
    }
}
