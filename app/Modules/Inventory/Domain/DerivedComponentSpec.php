<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use InvalidArgumentException;

/** Satu komponen turunan yang diminta dari pemisahan whole blood. */
final readonly class DerivedComponentSpec
{
    public function __construct(
        public BloodComponent $component,
        public int $volumeMl,
        public float $storageTemperatureC,
    ) {
        if ($this->component === BloodComponent::WHOLE_BLOOD) {
            throw new InvalidArgumentException('Whole blood is the source of a separation, never one of its components.');
        }

        if ($this->volumeMl < 1) {
            throw new InvalidArgumentException('A derived component must have a positive volume.');
        }
    }
}
