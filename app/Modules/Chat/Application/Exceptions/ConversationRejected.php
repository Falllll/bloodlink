<?php

declare(strict_types=1);

namespace App\Modules\Chat\Application\Exceptions;

use App\Modules\Chat\Domain\ConversationStatus;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class ConversationRejected extends DomainException
{
    private function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function alreadyEscalated(): self
    {
        return new self('This conversation has already been escalated to a facility.', 409);
    }

    public static function closed(): self
    {
        return new self('This conversation is closed; no further messages or changes are accepted.', 409);
    }

    public static function cannotTransition(ConversationStatus $from, ConversationStatus $to): self
    {
        return new self("A conversation in status \"{$from->value}\" cannot move to \"{$to->value}\".", 409);
    }

    /**
     * Token tidak ada, tidak cocok, atau kedaluwarsa -- SATU jawaban untuk ketiganya:
     * membedakannya memberi tahu penebak token mana yang pernah sah.
     */
    public static function visitorExpired(): self
    {
        return new self('The visitor token is missing, unknown, or has expired. Start a new conversation.', 422);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CONVERSATION_REJECTED;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}
