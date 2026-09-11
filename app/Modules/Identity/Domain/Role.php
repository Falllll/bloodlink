<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum Role: string
{
    case DONOR = 'donor';
    case HOSPITAL_STAFF = 'hospital_staff';
    case ADMIN = 'admin';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::DONOR => [
                Permission::REQUEST_VIEW,
                Permission::REQUEST_CREATE,
            ],
            self::HOSPITAL_STAFF => [
                Permission::DONOR_VIEW,
                Permission::DONOR_CREATE,
                Permission::DONOR_UPDATE,
                Permission::INVENTORY_VIEW,
                Permission::INVENTORY_CREATE,
                Permission::INVENTORY_RELEASE,
                Permission::REQUEST_VIEW,
                Permission::REQUEST_CREATE,
            ],
            self::ADMIN => Permission::cases(),
        };
    }
}
