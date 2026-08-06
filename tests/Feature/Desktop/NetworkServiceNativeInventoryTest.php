<?php

namespace Tests\Feature\Desktop;

use App\Configuration\ApplicationSettingRegistry;
use App\Services\ApplicationSettingService;
use App\Services\NetworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkServiceNativeInventoryTest extends TestCase
{
    use RefreshDatabase;

    private string $inventoryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryPath = storage_path('framework/testing/lan-adapters-'.bin2hex(random_bytes(8)).'.json');
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.lan_adapters_file' => $this->inventoryPath,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->inventoryPath);

        parent::tearDown();
    }

    public function test_supervised_runtime_uses_only_the_bounded_native_adapter_inventory(): void
    {
        $adapterId = 'adapter-v1:'.str_repeat('a', 64);
        file_put_contents($this->inventoryPath, json_encode([
            'schema_version' => 1,
            'adapters' => [[
                'id' => $adapterId,
                'label' => 'Wi-Fi cabinet',
                'address' => '192.168.50.12',
                'index' => 7,
            ]],
        ], JSON_THROW_ON_ERROR));

        app(ApplicationSettingService::class)->set(
            ApplicationSettingRegistry::CONNECTIVITY_SELECTED_ADAPTER_ID,
            $adapterId,
        );

        $network = app(NetworkService::class);
        $this->assertSame([[
            'id' => $adapterId,
            'label' => 'Wi-Fi cabinet',
            'address' => '192.168.50.12',
            'private' => true,
            'source' => 'native',
            'index' => 7,
        ]], $network->ipv4Candidates());
        $this->assertSame('192.168.50.12', $network->preferredIpv4());
    }

    public function test_malformed_or_public_native_inventory_fails_closed(): void
    {
        file_put_contents($this->inventoryPath, json_encode([
            'schema_version' => 1,
            'adapters' => [[
                'id' => 'adapter-v1:'.str_repeat('b', 64),
                'label' => 'Untrusted',
                'address' => '203.0.113.8',
                'index' => 1,
                'unexpected' => true,
            ]],
        ], JSON_THROW_ON_ERROR));

        $network = app(NetworkService::class);
        $this->assertSame([], $network->ipv4Candidates());
        $this->assertNull($network->preferredIpv4());
    }
}
