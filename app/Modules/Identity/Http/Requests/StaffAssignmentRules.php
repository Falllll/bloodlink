<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\StaffRoles;
use App\Modules\Identity\Domain\Role;
use Illuminate\Validation\Rule;

/** Aturan fasilitas + peran akun staf (kartu 487), dipakai pembuatan akun dan pengaturan ulang. */
final class StaffAssignmentRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            // Hanya fasilitas aktif yang belum dihapus.
            'facility_id' => ['required', 'uuid', Rule::exists('facilities', 'public_id')->where('is_active', true)->whereNull('deleted_at')],
            // Hanya peran staf: donor mendaftar sendiri, operator global di luar API.
            'role' => ['required', 'string', Rule::in(array_map(fn (Role $r): string => $r->value, StaffRoles::ASSIGNABLE))],
        ];
    }
}
