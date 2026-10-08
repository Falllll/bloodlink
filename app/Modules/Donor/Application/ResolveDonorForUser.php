<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Donor;
use App\Models\User;
use App\Modules\Donor\Application\Exceptions\DonorAccountNotLinked;

final class ResolveDonorForUser
{
    public function handle(User $user): Donor
    {
        // Baris hasil merge adalah batu nisan: donor yang digabung harus 404,
        // bukan melihat profil lamanya. Soft delete sudah diurus SoftDeletes.
        $donor = Donor::query()
            ->where('user_id', $user->getKey())
            ->whereNull('merged_into_id')
            ->first();

        if ($donor === null) {
            throw DonorAccountNotLinked::forUser((int) $user->getKey());
        }

        return $donor;
    }
}
