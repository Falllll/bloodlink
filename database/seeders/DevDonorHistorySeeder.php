<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\User;
use App\Modules\Donor\Domain\AppointmentStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Akun donor@bloodlink.test ditautkan ke satu baris donor dengan riwayat
 * donasi lebih dari satu halaman (20 per halaman), untuk menguji layar
 * riwayat dan paginasi kursor di klien mobile.
 */
class DevDonorHistorySeeder extends Seeder
{
    public const string EMAIL = 'donor@bloodlink.test';

    public const int DONATIONS = 24;

    /** Di atas jarak minimum donor whole blood, supaya riwayatnya masuk akal secara medis. */
    private const int DAYS_APART = 70;

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $user = User::query()->where('email', self::EMAIL)->first();

        if ($user === null) {
            throw new \RuntimeException('DevDonorHistorySeeder butuh akun '.self::EMAIL.'. Jalankan DevUserSeeder lebih dulu.');
        }

        $donor = Donor::query()->where('user_id', $user->id)->first()
            ?? Donor::query()->whereNull('user_id')->whereNull('merged_into_id')->orderBy('id')->firstOrFail();

        // Sudah pernah di-seed: jangan menggandakan riwayat.
        if (Donation::query()->where('donor_id', $donor->id)->exists()) {
            return;
        }

        $collectedBy = User::query()->where('email', 'staff@bloodlink.test')->value('id');

        DB::transaction(function () use ($user, $donor, $collectedBy): void {
            $donor->forceFill(['user_id' => $user->id])->save();

            $latest = now()->subDays(30)->setTime(9, 0);

            for ($i = 0; $i < self::DONATIONS; $i++) {
                $completedAt = $latest->copy()->subDays($i * self::DAYS_APART);
                // Selang-seling booking dan walk-in, seperti data nyata.
                $walkIn = $i % 3 === 0;

                $appointment = new Appointment(['scheduled_for' => $walkIn ? null : $completedAt->copy()->subHour()]);
                $appointment->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'donor_id' => $donor->id,
                    'facility_id' => $donor->registered_facility_id,
                    'status' => AppointmentStatus::COMPLETED,
                    'arrived_at' => $completedAt->copy()->subMinutes(50),
                    'screened_at' => $completedAt->copy()->subMinutes(30),
                    'completed_at' => $completedAt,
                    'created_by' => $collectedBy,
                ])->save();

                $donation = new Donation([
                    'volume_ml' => 450,
                    'started_at' => $completedAt->copy()->subMinutes(10),
                    'completed_at' => $completedAt,
                    'note' => null,
                ]);
                $donation->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'donor_id' => $donor->id,
                    'facility_id' => $donor->registered_facility_id,
                    'appointment_id' => $appointment->id,
                    'collected_by' => $collectedBy,
                ])->save();
            }

            $donor->forceFill([
                'last_donation_date' => $latest->toDateString(),
                'donation_count' => self::DONATIONS,
            ])->save();
        });
    }
}
