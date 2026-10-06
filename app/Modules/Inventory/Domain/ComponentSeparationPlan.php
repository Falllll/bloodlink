<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use InvalidArgumentException;

final readonly class ComponentSeparationPlan
{
    /** @param  list<DerivedComponentSpec>  $components */
    public function __construct(public array $components)
    {
        if ($this->components === []) {
            throw new InvalidArgumentException('A separation must produce at least one component.');
        }
    }

    public function totalVolumeMl(): int
    {
        return array_sum(array_map(
            fn (DerivedComponentSpec $spec): int => $spec->volumeMl,
            $this->components,
        ));
    }
}
