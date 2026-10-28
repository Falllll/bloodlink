<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/**
 * Kategori pesan (kartu 488). Preferensi donor disimpan per KATEGORI, bukan per
 * template: kode template bertambah setiap kartu, kategori tidak. Setiap template
 * wajib menyatakan kategorinya (notification_templates.category).
 *
 * Nilai harus sama dengan enum kolom notification_templates.category; nilai yang
 * isOptional() harus sama dengan enum kolom notification_preferences.category.
 */
enum NotificationCategory: string
{
    case EMERGENCY_CALL = 'emergency_call';
    case APPOINTMENT_REMINDER = 'appointment_reminder';
    case DONATION_STATUS = 'donation_status';
    case GENERAL = 'general';

    // Tidak bisa ditolak: tautan reset password yang bisa dimatikan pemiliknya sendiri
    // adalah akun yang tidak bisa dipulihkan, dan peringatan suhu atau reaksi
    // transfusi adalah pekerjaan petugas, bukan langganan.
    case ACCOUNT_SECURITY = 'account_security';
    case FACILITY_OPERATIONS = 'facility_operations';

    public function isOptional(): bool
    {
        return ! in_array($this, [self::ACCOUNT_SECURITY, self::FACILITY_OPERATIONS], true);
    }

    /** @return list<self> */
    public static function optional(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c): bool => $c->isOptional()));
    }
}
