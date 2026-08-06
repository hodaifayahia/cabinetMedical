<?php

namespace Tests\Feature\Desktop;

use App\Configuration\ApplicationSettingRegistry;
use App\Services\ApplicationSettingService;
use App\Services\MachineFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MachineFingerprintServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervised_runtime_uses_and_mirrors_the_native_installation_identity(): void
    {
        $nativeId = 'e169a732-1f4e-46ed-b5b8-a0bc752f6f09';
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.installation_id' => $nativeId,
        ]);

        $this->assertSame($nativeId, app(MachineFingerprintService::class)->installationId());
        $this->assertSame(
            $nativeId,
            app(ApplicationSettingService::class)->get(
                ApplicationSettingRegistry::DESKTOP_INSTALLATION_ID,
            ),
        );
    }

    public function test_supervised_runtime_fails_closed_without_a_valid_native_identity(): void
    {
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.installation_id' => 'invalid',
        ]);

        $this->expectException(RuntimeException::class);

        app(MachineFingerprintService::class)->installationId();
    }
}
