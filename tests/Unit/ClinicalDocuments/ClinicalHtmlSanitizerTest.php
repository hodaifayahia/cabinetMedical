<?php

namespace Tests\Unit\ClinicalDocuments;

use App\ClinicalDocuments\ClinicalHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class ClinicalHtmlSanitizerTest extends TestCase
{
    public function test_it_removes_active_content_remote_images_and_unsafe_links(): void
    {
        $html = <<<'HTML'
<section>
    <p onclick="steal()" style="color:#123456;position:fixed;background-image:url(https://tracker.test/pixel)">
        Bonjour<script>alert('x')</script>
        <a href="javascript:alert(1)">refusé</a>
        <a href="https://example.test/notice" onmouseover="steal()">autorisé</a>
        <img src="https://tracker.test/patient.png" onerror="steal()">
        <iframe src="https://tracker.test"></iframe>
    </p>
</section>
HTML;

        $sanitized = (new ClinicalHtmlSanitizer)->sanitize($html);

        $this->assertIsString($sanitized);
        $this->assertStringContainsString('Bonjour', $sanitized);
        $this->assertStringContainsString('refusé', $sanitized);
        $this->assertStringContainsString('href="https://example.test/notice"', $sanitized);
        $this->assertStringContainsString('rel="noopener noreferrer"', $sanitized);
        $this->assertStringContainsString('style="color: #123456"', $sanitized);

        foreach ([
            '<script',
            'alert(',
            'onclick',
            'onmouseover',
            'onerror',
            'javascript:',
            'tracker.test',
            '<iframe',
            'position:',
            'background-image',
        ] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $sanitized);
        }
    }

    public function test_it_keeps_only_verified_embedded_raster_images_and_bounded_layout_attributes(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z9mAAAAAASUVORK5CYII=';
        $html = '<table style="width:100%;border-collapse:collapse"><tr>'
            .'<td colspan="2" rowspan="999" style="border:1px solid #333;padding:6px">'
            .'<img src="data:image/png;base64,'.$png.'" alt="Courbe" width="1" height="1">'
            .'</td></tr></table>';

        $sanitized = (new ClinicalHtmlSanitizer)->sanitize($html);

        $this->assertIsString($sanitized);
        $this->assertStringContainsString('data:image/png;base64,', $sanitized);
        $this->assertStringContainsString('alt="Courbe"', $sanitized);
        $this->assertStringContainsString('colspan="2"', $sanitized);
        $this->assertStringNotContainsString('rowspan="999"', $sanitized);
        $this->assertStringContainsString('border-collapse: collapse', $sanitized);
    }
}
