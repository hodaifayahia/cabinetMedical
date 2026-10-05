<?php

namespace Tests\Feature\Desktop;

use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Models\ApplicationSetting;
use App\Services\ApplicationSettingService;
use App\Services\NetworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Browser-development (unsupervised) and listener behaviour of the LAN
 * address selection. The supervised native inventory has its own test.
 */
class NetworkServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $inventoryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryPath = storage_path('framework/testing/lan-adapters-'.bin2hex(random_bytes(8)).'.json');
        config([
            'medismart.runtime.desktop_supervised' => false,
            'medismart.runtime.lan_upload_url' => null,
            'medismart.runtime.lan_listener_status' => 'stopped',
            'medismart.runtime.lan_port' => 8000,
            'medismart.runtime.lan_adapters_file' => $this->inventoryPath,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->inventoryPath);

        parent::tearDown();
    }

    public function test_a_manual_private_address_is_offered_as_a_labelled_candidate(): void
    {
        app(ApplicationSettingService::class)->set(Setting::CONNECTIVITY_MANUAL_IPV4, '192.168.10.20');

        $manual = $this->candidatesFrom('manual');

        $this->assertSame([[
            'id' => '192.168.10.20',
            'label' => 'Adresse manuelle · 192.168.10.20',
            'address' => '192.168.10.20',
            'private' => true,
            'source' => 'manual',
            'index' => 0,
        ]], $manual);
    }

    public function test_the_legacy_manual_address_is_used_when_no_registered_override_exists(): void
    {
        ApplicationSetting::putValue('network.manual_ipv4', '10.1.2.3');

        $this->assertSame(['10.1.2.3'], array_column($this->candidatesFrom('manual'), 'address'));
    }

    public function test_a_public_legacy_address_is_offered_but_marked_public(): void
    {
        ApplicationSetting::putValue('network.manual_ipv4', '8.8.4.4');

        $manual = $this->candidatesFrom('manual');

        $this->assertCount(1, $manual);
        $this->assertFalse($manual[0]['private']);
    }

    public function test_loopback_link_local_and_broadcast_addresses_are_never_candidates(): void
    {
        foreach (['127.0.0.1', '169.254.10.1', '0.0.0.0', '255.255.255.255', 'not-an-ip', '::1'] as $address) {
            ApplicationSetting::putValue('network.manual_ipv4', $address);

            $this->assertSame([], $this->candidatesFrom('manual'), "[{$address}] was offered.");
        }
    }

    public function test_private_candidates_are_sorted_before_public_ones(): void
    {
        ApplicationSetting::putValue('network.manual_ipv4', '8.8.4.4');

        $candidates = app(NetworkService::class)->ipv4Candidates();
        $privateFlags = array_column($candidates, 'private');

        $this->assertSame($privateFlags, array_values(array_merge(
            array_filter($privateFlags),
            array_filter($privateFlags, static fn (bool $private): bool => ! $private),
        )));
    }

    public function test_no_adapter_is_selected_by_default(): void
    {
        $this->assertNull(app(NetworkService::class)->selectedAdapterId());
    }

    public function test_the_legacy_selected_private_address_is_preferred(): void
    {
        ApplicationSetting::putValue('network.selected_ipv4', '172.20.0.5');

        $this->assertSame('172.20.0.5', app(NetworkService::class)->preferredIpv4());
    }

    public function test_a_legacy_selected_public_or_loopback_address_is_ignored(): void
    {
        config(['medismart.runtime.desktop_supervised' => true]);

        foreach (['8.8.8.8', '127.0.0.1'] as $address) {
            ApplicationSetting::putValue('network.selected_ipv4', $address);

            $this->assertNull(app(NetworkService::class)->preferredIpv4(), "[{$address}] was preferred.");
        }
    }

    public function test_a_supervised_desktop_prefers_the_selected_native_adapter(): void
    {
        config(['medismart.runtime.desktop_supervised' => true]);
        $first = 'adapter-v1:'.str_repeat('a', 64);
        $second = 'adapter-v1:'.str_repeat('b', 64);
        $this->writeInventory([
            ['id' => $first, 'label' => 'Ethernet', 'address' => '192.168.1.10', 'index' => 1],
            ['id' => $second, 'label' => 'Wi-Fi', 'address' => '10.0.0.10', 'index' => 2],
        ]);

        $this->assertSame('192.168.1.10', app(NetworkService::class)->preferredIpv4());

        app(ApplicationSettingService::class)->set(Setting::CONNECTIVITY_SELECTED_ADAPTER_ID, $second);

        $this->assertSame($second, app(NetworkService::class)->selectedAdapterId());
        $this->assertSame('10.0.0.10', app(NetworkService::class)->preferredIpv4());
    }

    public function test_a_selected_adapter_missing_from_the_inventory_falls_back_to_the_first_private_one(): void
    {
        config(['medismart.runtime.desktop_supervised' => true]);
        $this->writeInventory([
            ['id' => 'adapter-v1:'.str_repeat('c', 64), 'label' => 'Ethernet', 'address' => '192.168.5.5', 'index' => 0],
        ]);
        app(ApplicationSettingService::class)->set(
            Setting::CONNECTIVITY_SELECTED_ADAPTER_ID,
            'adapter-v1:'.str_repeat('d', 64),
        );

        $this->assertSame('192.168.5.5', app(NetworkService::class)->preferredIpv4());
    }

    public function test_a_symlinked_or_oversized_inventory_is_ignored(): void
    {
        config(['medismart.runtime.desktop_supervised' => true]);
        file_put_contents($this->inventoryPath, str_repeat(' ', 64 * 1024 + 1));
        $this->assertSame([], app(NetworkService::class)->ipv4Candidates());

        $this->writeInventory([
            ['id' => 'adapter-v1:'.str_repeat('a', 64), 'label' => 'Ethernet', 'address' => '192.168.1.10', 'index' => 1],
        ]);
        $link = $this->inventoryPath.'.link';
        symlink($this->inventoryPath, $link);
        config(['medismart.runtime.lan_adapters_file' => $link]);

        try {
            $this->assertSame([], app(NetworkService::class)->ipv4Candidates());
        } finally {
            @unlink($link);
        }
    }

    public function test_an_inventory_with_extra_top_level_keys_is_ignored(): void
    {
        config(['medismart.runtime.desktop_supervised' => true]);
        file_put_contents($this->inventoryPath, json_encode([
            'schema_version' => 1,
            'adapters' => [],
            'extra' => true,
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([], app(NetworkService::class)->ipv4Candidates());
    }

    public function test_the_lan_listener_state_comes_only_from_the_runtime(): void
    {
        $this->assertFalse(app(NetworkService::class)->lanListenerActive());

        config(['medismart.runtime.lan_listener_status' => 'active']);

        $this->assertTrue(app(NetworkService::class)->lanListenerActive());
    }

    public function test_no_upload_url_is_offered_while_the_listener_is_stopped(): void
    {
        ApplicationSetting::putValue('network.selected_ipv4', '192.168.1.5');

        $this->assertNull(app(NetworkService::class)->localUploadBaseUrl());
    }

    public function test_the_development_upload_url_uses_the_preferred_address_and_port(): void
    {
        config(['medismart.runtime.lan_listener_status' => 'active']);
        ApplicationSetting::putValue('network.selected_ipv4', '192.168.1.5');

        $this->assertSame('http://192.168.1.5:8000', app(NetworkService::class)->localUploadBaseUrl());

        ApplicationSetting::putValue('runtime.lan_port', 9100, type: 'integer');
        $this->assertSame('http://192.168.1.5:9100', app(NetworkService::class)->localUploadBaseUrl());

        app(ApplicationSettingService::class)->set(Setting::CONNECTIVITY_PREFERRED_PORT, 50123);
        $this->assertSame('http://192.168.1.5:50123', app(NetworkService::class)->localUploadBaseUrl());

        $this->assertSame('http://192.168.1.5:40000', app(NetworkService::class)->localUploadBaseUrl(40000));
    }

    public function test_an_out_of_range_port_yields_no_upload_url(): void
    {
        config(['medismart.runtime.lan_listener_status' => 'active']);
        ApplicationSetting::putValue('network.selected_ipv4', '192.168.1.5');

        $this->assertNull(app(NetworkService::class)->localUploadBaseUrl(0));
        $this->assertNull(app(NetworkService::class)->localUploadBaseUrl(65536));
    }

    public function test_an_unsupervised_install_with_a_lan_origin_configured_offers_no_development_url(): void
    {
        config([
            'medismart.runtime.lan_listener_status' => 'active',
            'medismart.runtime.lan_upload_url' => 'http://192.168.1.5:50000',
        ]);
        ApplicationSetting::putValue('network.selected_ipv4', '192.168.1.5');

        $this->assertNull(app(NetworkService::class)->localUploadBaseUrl());
    }

    public function test_a_supervised_desktop_uses_only_the_exact_configured_lan_origin(): void
    {
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.lan_listener_status' => 'active',
            'medismart.runtime.lan_upload_url' => 'http://192.168.1.5:50000',
        ]);

        $this->assertSame('http://192.168.1.5:50000', app(NetworkService::class)->localUploadBaseUrl());

        foreach (['http://192.168.1.5:50000/', 'http://8.8.8.8:50000', 'http://192.168.1.5:80', 'http://192.168.1.5'] as $origin) {
            config(['medismart.runtime.lan_upload_url' => $origin]);

            $this->assertNull(app(NetworkService::class)->localUploadBaseUrl(), "[{$origin}] was accepted.");
        }
    }

    /** @return list<array<string, mixed>> */
    private function candidatesFrom(string $source): array
    {
        return array_values(array_filter(
            app(NetworkService::class)->ipv4Candidates(),
            static fn (array $candidate): bool => $candidate['source'] === $source,
        ));
    }

    /** @param list<array<string, mixed>> $adapters */
    private function writeInventory(array $adapters): void
    {
        file_put_contents($this->inventoryPath, json_encode([
            'schema_version' => 1,
            'adapters' => $adapters,
        ], JSON_THROW_ON_ERROR));
    }
}
