<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Donor;
use App\Modules\Donor\Application\Exceptions\DonorIdentityConflict;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RegisterDonor
{
    public function handle(Donor $donor): Donor
    {
        try {
            DB::transaction(fn () => $donor->save());
        } catch (UniqueConstraintViolationException $e) {
            throw $this->translate($e);
        }

        return $donor;
    }

    private function translate(UniqueConstraintViolationException $e): Throwable
    {
        $message = $e->getMessage();

        if (str_contains($message, 'donors_nik_hash_active_unique')) {
            return DonorIdentityConflict::duplicateNik();
        }

        if (str_contains($message, 'donors_phone_hash_active_unique')) {
            return DonorIdentityConflict::duplicatePhone();
        }

        return $e;
    }
}
