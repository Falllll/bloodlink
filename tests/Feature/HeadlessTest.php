<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class HeadlessTest extends TestCase
{
    public function test_no_web_routes_are_registered(): void
    {
        $webRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('web', $route->gatherMiddleware(), true));

        $this->assertCount(0, $webRoutes);
    }

    public function test_the_root_path_is_not_served(): void
    {
        $this->get('/')->assertNotFound();
    }
}
