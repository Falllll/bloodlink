<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Policies;

use App\Models\User;
use App\Modules\Identity\Domain\Permission;

final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::AUDIT_VIEW->value);
    }
}
