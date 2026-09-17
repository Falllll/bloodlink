<?php

declare(strict_types=1);

namespace App\Shared\Database;

trait Auditable
{
    /**
     * Kolom yang tidak pernah masuk diff audit.
     *
     * Sensitive fields seperti remember_token tetap dicatat
     * agar nilainya dapat direduksi oleh SensitiveKeys.
     *
     * @return list<string>
     */
    public function auditExcept(): array
    {
        return ['updated_at'];
    }
}