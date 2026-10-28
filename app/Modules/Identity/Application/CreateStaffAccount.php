<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Membuat akun petugas di satu fasilitas (kartu 487). TIDAK ADA password yang pernah
 * dibuat oleh admin, dikembalikan di respons, atau dikirim lewat email: akun lahir
 * dengan password acak yang tidak diketahui siapa pun, lalu pemiliknya menetapkan
 * sendiri lewat tautan undangan sekali pakai (mekanisme token kartu 485).
 */
final class CreateStaffAccount
{
    public function __construct(
        private readonly StaffRoles $roles,
        private readonly IssuePasswordReset $issue,
        private readonly RecordStaffAssignment $record,
    ) {}

    public function handle(Facility $facility, string $name, string $email, Role $role): User
    {
        return DB::transaction(function () use ($facility, $name, $email, $role): User {
            $user = new User(['name' => $name, 'email' => $email, 'password' => Str::random(64)]);
            $user->forceFill([
                'public_id' => (string) Str::uuid(),
                'facility_id' => $facility->id,
                'is_active' => true,
            ])->save();

            $this->roles->set($user, $role);
            $this->record->handle(
                $user,
                ['facility_id' => null, 'role' => null],
                ['facility_id' => $facility->public_id, 'role' => $role->value],
            );

            // Undangan dikirim lewat jalur kirim kartu 460; job-nya antre setelah commit.
            $this->issue->handle($user, invitation: true);

            return $user;
        });
    }
}
