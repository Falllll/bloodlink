<?php

declare(strict_types=1);

it('answers the liveness probe without touching the database', function (): void {
    $this->getJson('/health/live')->assertOk();
});
