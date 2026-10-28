<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Models\NotificationTemplate;
use App\Modules\Notification\Application\Exceptions\NotificationTemplateRejected;
use App\Modules\Notification\Application\RenderNotification;
use App\Modules\Notification\Domain\NotificationChannel;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RenderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationTemplateSeeder::class);
    }

    private function renderer(): RenderNotification
    {
        return app(RenderNotification::class);
    }

    /** @param  list<string>  $variables */
    private function template(string $body, array $variables, ?string $subject = 'Subjek'): void
    {
        NotificationTemplate::query()->create([
            'code' => 'test.template',
            'channel' => NotificationChannel::EMAIL,
            'locale' => 'id',
            'subject' => $subject,
            'body' => $body,
            'variables' => $variables,
        ]);
    }

    public function test_the_seed_holds_five_codes_times_two_channels_plus_the_email_only_auth_mails(): void
    {
        $this->assertSame(12, NotificationTemplate::query()->count());

        foreach (['donor.permanently_deferred', 'storage.temperature_out_of_range', 'inventory.units_nearing_expiry', 'stock_transfer.requested', 'transfusion.reaction_reported'] as $code) {
            $this->assertEqualsCanonicalizing(['email', 'push'], NotificationTemplate::query()->where('code', $code)->pluck('channel')->map->value->all(), $code);
        }

        // Reset password (kartu 485): email SAJA -- tautan reset tidak boleh tampil di layar kunci.
        $this->assertSame(['email'], NotificationTemplate::query()->where('code', 'auth.password_reset')->pluck('channel')->map->value->all());
        // Undangan staf (kartu 487): juga email saja.
        $this->assertSame(['email'], NotificationTemplate::query()->where('code', 'auth.staff_invitation')->pluck('channel')->map->value->all());
    }

    public function test_every_seeded_template_renders_completely_with_its_declared_variables(): void
    {
        foreach (NotificationTemplate::query()->get() as $template) {
            $variables = array_fill_keys($template->variables, 'NILAI');

            $rendered = $this->renderer()->handle($template->code, $template->channel, $variables, $template->locale);

            $this->assertStringNotContainsString('{{', $rendered->body, $template->code);
            $this->assertStringNotContainsString('{{', (string) $rendered->subject, $template->code);
            $this->assertSame($template->channel === NotificationChannel::EMAIL, $rendered->subject !== null);
        }
    }

    /** Ambang hidup di kode (kartu 280); teks yang memuat angka bisa berbeda diam-diam. */
    public function test_no_seeded_text_carries_a_number(): void
    {
        foreach (NotificationTemplate::query()->get() as $template) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $template->subject.' '.$template->body, $template->code);
        }
    }

    public function test_it_substitutes_every_placeholder(): void
    {
        $rendered = $this->renderer()->handle('inventory.units_nearing_expiry', NotificationChannel::EMAIL, [
            'facility_name' => 'RS Sehat',
            'unit_count' => 4,
        ]);

        $this->assertSame('Unit darah mendekati kedaluwarsa di RS Sehat', $rendered->subject);
        $this->assertStringContainsString('Sebanyak 4 unit di RS Sehat', $rendered->body);
    }

    public function test_a_missing_variable_throws_instead_of_rendering(): void
    {
        $this->expectException(NotificationTemplateRejected::class);
        $this->expectExceptionMessage('missing variables: facility_name');

        $this->renderer()->handle('inventory.units_nearing_expiry', NotificationChannel::EMAIL, ['unit_count' => 4]);
    }

    public function test_a_misnamed_variable_throws_instead_of_being_ignored(): void
    {
        $this->expectException(NotificationTemplateRejected::class);
        $this->expectExceptionMessage('undeclared variables: donorName');

        $this->renderer()->handle('donor.permanently_deferred', NotificationChannel::EMAIL, [
            'donor_name' => 'Budi',
            'facility_name' => 'PMI',
            'donorName' => 'Budi',
        ]);
    }

    public function test_a_placeholder_not_declared_in_variables_throws(): void
    {
        $this->template('Halo {{donor_name}}, {{blood_group}} dibutuhkan.', ['donor_name']);

        $this->expectException(NotificationTemplateRejected::class);
        $this->expectExceptionMessage('not declared in its variables: blood_group');

        $this->renderer()->handle('test.template', NotificationChannel::EMAIL, ['donor_name' => 'Budi']);
    }

    public function test_a_malformed_placeholder_throws(): void
    {
        $this->template('Halo {{ donor_name }}.', ['donor_name']);

        $this->expectException(NotificationTemplateRejected::class);
        $this->expectExceptionMessage('malformed placeholder');

        $this->renderer()->handle('test.template', NotificationChannel::EMAIL, ['donor_name' => 'Budi']);
    }

    public function test_a_declared_variable_the_text_never_uses_throws(): void
    {
        $this->template('Halo.', ['donor_name']);

        $this->expectException(NotificationTemplateRejected::class);
        $this->expectExceptionMessage('never uses: donor_name');

        $this->renderer()->handle('test.template', NotificationChannel::EMAIL, ['donor_name' => 'Budi']);
    }

    /** Satu lintasan: nilai berisi "{{...}}" tidak dipindai ulang dan tidak memicu penolakan. */
    public function test_a_value_containing_braces_is_inserted_verbatim(): void
    {
        $rendered = $this->renderer()->handle('transfusion.reaction_reported', NotificationChannel::PUSH, ['facility_name' => '{{facility_name}}']);

        $this->assertSame('Reaksi pasca-transfusi dilaporkan di {{facility_name}}.', $rendered->body);
    }

    public function test_an_unknown_or_inactive_template_throws(): void
    {
        NotificationTemplate::query()->where('code', 'transfusion.reaction_reported')->update(['is_active' => false]);

        $this->expectException(NotificationTemplateRejected::class);
        $this->expectExceptionMessage('No active notification template');

        $this->renderer()->handle('transfusion.reaction_reported', NotificationChannel::PUSH, ['facility_name' => 'PMI']);
    }

    public function test_the_rejection_is_a_server_error(): void
    {
        $this->assertSame(500, NotificationTemplateRejected::malformedPlaceholder('x')->httpStatus());
    }

    public function test_the_database_ties_subject_to_the_email_channel(): void
    {
        $base = ['code' => 'test.shape', 'locale' => 'id', 'body' => 'Isi.', 'variables' => '[]', 'created_at' => now(), 'updated_at' => now()];

        foreach ([['channel' => 'push', 'subject' => 'Subjek'], ['channel' => 'email', 'subject' => null]] as $row) {
            try {
                DB::transaction(fn () => DB::table('notification_templates')->insert($row + $base));
                $this->fail('CHECK notification_templates_subject_shape seharusnya menolak.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('notification_templates_subject_shape', $e->getMessage());
            }
        }
    }

    public function test_the_database_requires_variables_to_be_an_array(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('notification_templates_variables_shape');

        DB::table('notification_templates')->insert([
            'code' => 'test.shape', 'channel' => 'push', 'locale' => 'id', 'body' => 'Isi.',
            'variables' => '{"donor_name": true}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_one_row_per_code_channel_and_locale(): void
    {
        $this->expectException(QueryException::class);

        DB::table('notification_templates')->insert([
            'code' => 'transfusion.reaction_reported', 'channel' => 'push', 'locale' => 'id', 'body' => 'Duplikat.',
            'variables' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
