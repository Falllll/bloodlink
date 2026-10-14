<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/**
 * Vonis gerbang kompatibilitas beserta alasannya. sourceReferences ikut dibawa
 * supaya pemanggil bisa menunjukkan pasal per pasal KENAPA sesuatu boleh.
 */
final readonly class CompatibilityDecision
{
    /** @param  list<string>  $sourceReferences */
    public function __construct(
        public CompatibilityOutcome $outcome,
        public ?RuleProvenance $provenance,
        public array $sourceReferences,
        public ?string $detail = null,
    ) {}

    public function isCompatible(): bool
    {
        return $this->outcome === CompatibilityOutcome::COMPATIBLE;
    }
}
