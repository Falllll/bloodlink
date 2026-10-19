<?php

declare(strict_types=1);

namespace App\Modules\Request\Application\Exceptions;

use App\Modules\Request\Domain\RequestStatus;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class CampaignRejected extends DomainException
{
    public static function requestNotOpen(RequestStatus $status): self
    {
        return new self("A donor campaign cannot start for a blood request in status \"{$status->value}\".");
    }

    public static function alreadyRunning(): self
    {
        return new self('This blood request already has an active donor campaign.');
    }

    public static function alreadyEnded(): self
    {
        return new self('This donor campaign has already ended.');
    }

    public static function alreadyResponded(): self
    {
        return new self('This invitation has already been answered; a response is given once.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CAMPAIGN_REJECTED;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
