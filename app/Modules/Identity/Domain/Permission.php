<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum Permission: string
{
    case DONOR_VIEW = 'donor.view';
    case DONOR_CREATE = 'donor.create';
    case DONOR_UPDATE = 'donor.update';
    case INVENTORY_VIEW = 'inventory.view';
    case INVENTORY_CREATE = 'inventory.create';
    case INVENTORY_RELEASE = 'inventory.release';
    case REQUEST_VIEW = 'request.view';
    case REQUEST_CREATE = 'request.create';
    case REQUEST_APPROVE = 'request.approve';
    case FACILITY_MANAGE = 'facility.manage';
    case USER_MANAGE = 'user.manage';
    case AUDIT_VIEW = 'audit.view';
}
