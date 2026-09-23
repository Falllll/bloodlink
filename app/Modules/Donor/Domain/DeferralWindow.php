<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeferralWindow
{
    public function __construct(
        public string $reasonCode,
        public DeferralType $type,
        public DateTimeImmutable $anchorDate,
        public ?int $durationValue = null,
        public ?DeferralDurationUnit $durationUnit = null,
    ) {
        if ($this->type === DeferralType::PERMANENT) {
            if ($this->durationValue !== null || $this->durationUnit !== null) {
                throw new InvalidArgumentException('A permanent deferral window must not carry a duration.');
            }

            return;
        }

        if (($this->durationValue === null) !== ($this->durationUnit === null)) {
            throw new InvalidArgumentException('A temporary deferral window must have both duration fields or neither.');
        }
    }

    public function endsOn(): ?DateTimeImmutable
    {
        if ($this->type === DeferralType::PERMANENT) {
            return null;
        }

        if ($this->durationValue === null || $this->durationUnit === null) {
            return null;
        }

        return $this->anchorDate->add(new DateInterval(
            $this->durationUnit->toDateIntervalSpec($this->durationValue)
        ));
    }

    public function isActiveOn(DateTimeImmutable $today): bool
    {
        if ($this->type === DeferralType::PERMANENT) {
            return true;
        }

        $endsOn = $this->endsOn();

        if ($endsOn === null) {
            return true;
        }

        return $today < $endsOn;
    }
}
