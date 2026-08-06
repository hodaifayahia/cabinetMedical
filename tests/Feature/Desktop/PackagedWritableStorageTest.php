<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PackagedWritableStorageTest extends TestCase
{
    public function test_public_branding_assets_are_served_without_a_public_storage_symlink(): void
    {
        Storage::fake('public');
        $logo = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        $this->assertIsString($logo);
        Storage::disk('public')->put('clinic-identity/logo.png', $logo);

        $response = $this->get('/storage/clinic-identity/logo.png')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
        $this->assertSame($logo, $response->streamedContent());
    }

    public function test_private_medical_storage_is_not_exposed_by_the_branding_asset_route(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('patient-documents/private.pdf', '%PDF-private');

        $this->get('/storage/patient-documents/private.pdf')->assertNotFound();
    }
}
