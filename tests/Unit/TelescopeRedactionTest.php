<?php

namespace Tests\Unit;

use App\Providers\TelescopeServiceProvider;
use Laravel\Telescope\Telescope;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelescopeRedactionTest extends TestCase
{
    #[Test]
    public function it_redacts_inbound_and_outbound_license_material(): void
    {
        $provider = new class(app()) extends TelescopeServiceProvider
        {
            public function applySensitiveRequestPolicy(): void
            {
                $this->hideSensitiveRequestDetails();
            }
        };
        $provider->applySensitiveRequestPolicy();

        $this->assertContains('serial', Telescope::$hiddenRequestParameters);
        $this->assertContains('license_certificate', Telescope::$hiddenRequestParameters);
        $this->assertContains('machine_fingerprint_hash', Telescope::$hiddenRequestParameters);
        $this->assertContains('license_certificate', Telescope::$hiddenResponseParameters);
    }
}
