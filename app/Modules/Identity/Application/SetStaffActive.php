<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Events\StaffAccountDeactivated;
use Illuminate\Support\Facades\DB;

/**
 * Menonaktifkan, BUKAN menghapus (kartu 487): petugas yang keluar kerja tetap harus
 * bisa ditelusuri lewat audit_logs.actor_id. Login sudah menolak akun nonaktif; di
 * sini sesi yang MASIH hidup ikut dicabut, dan perangkat push-nya lewat event.
 */
final class SetStaffActive
{
    public function deactivate(User $target): User
    {
        return DB::transaction(function () use ($target): User {
            $target->forceFill(['is_active' => false])->save();
            $target->tokens()->delete();

            event(new StaffAccountDeactivated($target->id));

            return $target;
        });
    }

    /** Hidup lagi tanpa sesi apa pun: pemiliknya masuk lewat login seperti biasa. */
    public function activate(User $target): User
    {
        $target->forceFill(['is_active' => true])->save();

        return $target;
    }
}
