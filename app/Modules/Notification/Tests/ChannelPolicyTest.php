<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Application\ChannelPolicy;
use App\Modules\Notification\Application\Exceptions\ChannelNotPermitted;
use App\Modules\Notification\Domain\NotificationChannel;
use Tests\TestCase;

final class ChannelPolicyTest extends TestCase
{
    public function test_email_and_push_are_deliverable_under_the_configured_policy(): void
    {
        $policy = app(ChannelPolicy::class);

        $policy->assertDeliverable(NotificationChannel::EMAIL);
        $policy->assertDeliverable(NotificationChannel::PUSH);

        $this->assertSame([NotificationChannel::EMAIL, NotificationChannel::PUSH], $policy->permittedChannels());
    }

    public function test_the_policy_is_a_container_singleton(): void
    {
        $this->assertSame(app(ChannelPolicy::class), app(ChannelPolicy::class));
    }

    public function test_a_policy_built_from_email_only_rejects_push(): void
    {
        $policy = new ChannelPolicy(['email']);

        $this->assertFalse($policy->permits(NotificationChannel::PUSH));
        $this->assertSame([NotificationChannel::EMAIL], $policy->permittedChannels());

        $this->expectException(ChannelNotPermitted::class);
        $this->expectExceptionMessage('deferred until there is budget');

        $policy->assertDeliverable(NotificationChannel::PUSH);
    }

    /** Menolak secara bawaan: daftar kosong melempar, tidak mengembalikan false diam-diam. */
    public function test_an_empty_policy_throws_for_every_channel(): void
    {
        $policy = new ChannelPolicy([]);

        foreach (NotificationChannel::cases() as $channel) {
            try {
                $policy->assertDeliverable($channel);
                $this->fail("{$channel->value} seharusnya ditolak.");
            } catch (ChannelNotPermitted $e) {
                $this->assertSame(500, $e->httpStatus());
            }
        }
    }

    /** Baris config tanpa case enum (mis. 'sms') gagal saat kebijakan dibangun, bukan saat kirim. */
    public function test_a_configured_channel_without_an_enum_case_fails_to_build(): void
    {
        $this->expectException(ChannelNotPermitted::class);
        $this->expectExceptionMessage("Configured channel 'sms'");

        new ChannelPolicy(['email', 'push', 'sms']);
    }

    /**
     * PENJAGA ENUM. Satu-satunya test di repo yang SENGAJA menduplikasi daftar:
     * daftar literal di bawah adalah kanal yang punya implementasi pengirim. Begitu
     * ada yang menambah case ke NotificationChannel tanpa menambah pengirimnya, test
     * ini merah lebih dulu. Ubah daftar ini HANYA bersamaan dengan pengirimnya.
     */
    public function test_every_channel_case_has_a_sender(): void
    {
        $this->assertSame(
            ['email', 'push'],
            array_map(fn (NotificationChannel $channel): string => $channel->value, NotificationChannel::cases()),
        );
    }
}
