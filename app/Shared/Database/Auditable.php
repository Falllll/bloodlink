<?php

declare(strict_types=1);

namespace App\Shared\Database;

trait Auditable
{
    /**
     * Kolom yang tidak pernah masuk diff audit.
     *
     * @return list<string>
     */
    public function auditExcept(): array
    {
        return ['updated_at', 'remember_token'];
    }
}
