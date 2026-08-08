<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The compiled Vite manifest is not present in the test/CI environment
        // (front-end assets are built separately). Disabling Vite lets Blade /
        // Inertia root views render without a manifest while still exercising
        // controllers, middleware and Inertia response assertions.
        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
