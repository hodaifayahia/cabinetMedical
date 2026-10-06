<?php

namespace Tests\Unit\ClinicalDocuments;

use App\ClinicalDocuments\TemplateBody;
use PHPUnit\Framework\TestCase;

class TemplateBodyTest extends TestCase
{
    public function test_legacy_text_becomes_headings_and_paragraphs(): void
    {
        $html = TemplateBody::textToHtml("## Antécédents\nLigne 1\nLigne <2>\n\n{{patient.allergies}}");

        $this->assertSame(
            '<h3>Antécédents</h3><p>Ligne 1<br>Ligne &lt;2&gt;</p><p>{{patient.allergies}}</p>',
            $html,
        );
    }

    public function test_to_html_only_converts_text_bodies(): void
    {
        $this->assertSame('<p>déjà</p>', TemplateBody::toHtml('<p>déjà</p>', 'html'));
        $this->assertSame('<p>texte</p>', TemplateBody::toHtml('texte', 'text'));
        $this->assertSame('<p>texte</p>', TemplateBody::toHtml('texte', null));
    }

    public function test_render_html_escapes_values_and_keeps_line_breaks(): void
    {
        $html = TemplateBody::renderHtml(
            '<p>{{patient.allergies}} — {{ doctor.name }} — {{unknown.token}}</p>',
            ['patient.allergies' => "Iode <b>\nLatex", 'doctor.name' => 'Dr A & B'],
        );

        $this->assertSame(
            '<p>Iode &lt;b&gt;<br>'."\n".'Latex — Dr A &amp; B — {{unknown.token}}</p>',
            $html,
        );

        $this->assertSame(
            '<p></p>',
            TemplateBody::renderHtml('<p>{{unknown.token}}</p>', [], keepUnknown: false),
        );
    }

    public function test_format_detection_and_normalisation(): void
    {
        $this->assertTrue(TemplateBody::looksLikeHtml('  <p>Bonjour</p>'));
        $this->assertFalse(TemplateBody::looksLikeHtml("## Titre\n<p> plus loin"));
        $this->assertSame('html', TemplateBody::normalizeFormat('html'));
        $this->assertSame('text', TemplateBody::normalizeFormat('markdown'));
        $this->assertSame('text', TemplateBody::normalizeFormat(null));
    }
}
