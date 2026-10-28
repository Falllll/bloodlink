<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Template untuk seam yang menunggu pengiriman (kartu 460): satu baris email dan
 * satu baris push per kode. Kode mengikuti nama event domainnya.
 *
 * Teks SENGAJA tanpa angka atau kriteria medis apa pun ("mendekati kedaluwarsa",
 * bukan "kedaluwarsa dalam 7 hari"): ambang hidup di kode, dan menyalinnya ke teks
 * berarti dua tempat yang bisa berbeda diam-diam. Push tampil di layar kunci, jadi
 * teks push tidak memuat nama orang maupun detail pemeriksaan.
 *
 * Idempoten: updateOrCreate per (code, channel, locale).
 */
final class NotificationTemplateSeeder extends Seeder
{
    /** @var list<array{code: string, channel: string, subject: string|null, body: string, variables: list<string>}> */
    private const array TEMPLATES = [
        // DonorPermanentlyDeferred (kartu 250). Penerima: donor.
        [
            'code' => 'donor.permanently_deferred',
            'channel' => 'email',
            'subject' => 'Informasi penting tentang status donor Anda',
            'body' => "Halo {{donor_name}},\n\nAda informasi penting mengenai status Anda sebagai donor darah. Mohon hubungi {{facility_name}} untuk penjelasan dan tindak lanjut.\n\nTerima kasih atas kesediaan Anda mendonorkan darah.",
            'variables' => ['donor_name', 'facility_name'],
        ],
        [
            'code' => 'donor.permanently_deferred',
            'channel' => 'push',
            'subject' => null,
            'body' => 'Ada informasi penting tentang status donor Anda. Mohon hubungi {{facility_name}}.',
            'variables' => ['facility_name'],
        ],
        // StorageTemperatureOutOfRange (kartu 270). Penerima: petugas fasilitas.
        [
            'code' => 'storage.temperature_out_of_range',
            'channel' => 'email',
            'subject' => 'Suhu penyimpanan di luar rentang: {{location_name}}',
            'body' => "Pembacaan suhu {{reading_celsius}} °C di {{location_name}} pada {{occurred_at}} berada di luar rentang yang ditetapkan untuk lokasi tersebut.\n\nPeriksa lokasi penyimpanan dan unit di dalamnya.",
            'variables' => ['location_name', 'reading_celsius', 'occurred_at'],
        ],
        [
            'code' => 'storage.temperature_out_of_range',
            'channel' => 'push',
            'subject' => null,
            'body' => 'Suhu di {{location_name}} berada di luar rentang: {{reading_celsius}} °C.',
            'variables' => ['location_name', 'reading_celsius'],
        ],
        // BloodUnitsNearingExpiry (kartu 280). Penerima: petugas fasilitas.
        [
            'code' => 'inventory.units_nearing_expiry',
            'channel' => 'email',
            'subject' => 'Unit darah mendekati kedaluwarsa di {{facility_name}}',
            'body' => "Sebanyak {{unit_count}} unit di {{facility_name}} mendekati kedaluwarsa.\n\nTinjau daftar unitnya di BloodLink.",
            'variables' => ['facility_name', 'unit_count'],
        ],
        [
            'code' => 'inventory.units_nearing_expiry',
            'channel' => 'push',
            'subject' => null,
            'body' => '{{unit_count}} unit di {{facility_name}} mendekati kedaluwarsa.',
            'variables' => ['unit_count', 'facility_name'],
        ],
        // Transfer stok (kartu 300). Penerima: petugas fasilitas asal.
        [
            'code' => 'stock_transfer.requested',
            'channel' => 'email',
            'subject' => 'Permintaan transfer stok dari {{to_facility_name}}',
            'body' => "{{to_facility_name}} meminta transfer unit {{unit_number}} dari {{from_facility_name}}.\n\nTinjau dan putuskan permintaannya di BloodLink.",
            'variables' => ['to_facility_name', 'unit_number', 'from_facility_name'],
        ],
        [
            'code' => 'stock_transfer.requested',
            'channel' => 'push',
            'subject' => null,
            'body' => '{{to_facility_name}} meminta transfer unit {{unit_number}}.',
            'variables' => ['to_facility_name', 'unit_number'],
        ],
        // TransfusionReactionReported (kartu 370). Penerima: petugas fasilitas.
        // Tanpa patient_reference: event-nya sendiri tidak membawanya.
        [
            'code' => 'transfusion.reaction_reported',
            'channel' => 'email',
            'subject' => 'Laporan reaksi pasca-transfusi di {{facility_name}}',
            'body' => "Reaksi pasca-transfusi dilaporkan di {{facility_name}} untuk transfusi {{transfusion_id}}, teramati pada {{observed_at}}.\n\nTinjau laporannya di BloodLink.",
            'variables' => ['facility_name', 'transfusion_id', 'observed_at'],
        ],
        [
            'code' => 'transfusion.reaction_reported',
            'channel' => 'push',
            'subject' => null,
            'body' => 'Reaksi pasca-transfusi dilaporkan di {{facility_name}}.',
            'variables' => ['facility_name'],
        ],
        // PasswordResetRequested (kartu 485). Penerima: pemilik akun. EMAIL SAJA, sengaja
        // tanpa baris push: tautan reset tidak boleh tampil di layar kunci.
        [
            'code' => 'auth.password_reset',
            'channel' => 'email',
            'subject' => 'Atur ulang password BloodLink',
            'body' => "Ada permintaan untuk mengatur ulang password akun BloodLink Anda.\n\nBuka tautan berikut untuk membuat password baru. Tautan ini hanya berlaku {{ttl_minutes}} menit dan hanya bisa dipakai sekali:\n{{reset_url}}\n\nJika Anda tidak meminta ini, abaikan email ini. Password Anda tidak berubah.",
            'variables' => ['ttl_minutes', 'reset_url'],
        ],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $template) {
            NotificationTemplate::query()->updateOrCreate(
                ['code' => $template['code'], 'channel' => $template['channel'], 'locale' => 'id'],
                [
                    'subject' => $template['subject'],
                    'body' => $template['body'],
                    'variables' => $template['variables'],
                    'is_active' => true,
                ],
            );
        }
    }
}
