<?php

declare(strict_types=1);

use App\Shared\Errors\ErrorCode;

it('keeps every error code value identical to its case name', function (): void {
    foreach (ErrorCode::cases() as $case) {
        expect($case->value)->toBe($case->name);
    }
});
