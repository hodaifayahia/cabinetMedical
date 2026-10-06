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

    public function test_it_keeps_the_document_editor_formatting_allowlist(): void
    {
        $html = '<p style="text-align: justify; line-height: 1.5; margin-left: 40px; text-indent: 20px">Paragraphe</p>'
            .'<p><span style="font-family: &quot;Times New Roman&quot;, serif; font-size: 14pt; color: #c00000">Texte</span>'
            .'<mark data-color="#fff2a8" style="background-color: #fff2a8; color: inherit">surligné</mark></p>'
            .'<table style="min-width: 50px"><colgroup><col style="width: 120px"></colgroup><tbody><tr>'
            .'<td colspan="1" rowspan="1" colwidth="120,80">A</td><td colwidth="javascript:1">B</td></tr></tbody></table>'
            .'<div class="page-break other"></div><p class="tracker">Classe retirée</p>';

        $sanitized = (string) (new ClinicalHtmlSanitizer)->sanitize($html);

        $this->assertStringContainsString('text-align: justify', $sanitized);
        $this->assertStringContainsString('line-height: 1.5', $sanitized);
        $this->assertStringContainsString('margin-left: 40px', $sanitized);
        $this->assertStringContainsString('text-indent: 20px', $sanitized);
        $this->assertStringContainsString('font-size: 14pt', $sanitized);
        $this->assertStringContainsString('font-family:', $sanitized);
        $this->assertStringContainsString('data-color="#fff2a8"', $sanitized);
        $this->assertStringContainsString('<colgroup><col style="width: 120px"></colgroup>', $sanitized);
        $this->assertStringContainsString('colwidth="120,80"', $sanitized);
        $this->assertStringNotContainsString('javascript', $sanitized);
        $this->assertStringContainsString('<div class="page-break"></div>', $sanitized);
        $this->assertStringNotContainsString('other', $sanitized);
        $this->assertStringNotContainsString('tracker', $sanitized);
        $this->assertStringContainsString('<p>Classe retirée</p>', $sanitized);
    }

    public function test_style_values_cannot_smuggle_urls_or_expressions(): void
    {
        $html = '<p style="line-height: expression(alert(1)); text-indent: url(https://x.test/a); vertical-align: top">x</p>'
            .'<mark data-color="javascript:alert(1)">y</mark>';

        $sanitized = (string) (new ClinicalHtmlSanitizer)->sanitize($html);

        $this->assertStringContainsString('vertical-align: top', $sanitized);
        $this->assertStringNotContainsString('expression', $sanitized);
        $this->assertStringNotContainsString('url(', $sanitized);
        $this->assertStringNotContainsString('javascript', $sanitized);
        $this->assertStringContainsString('<mark>y</mark>', $sanitized);
    }
}
