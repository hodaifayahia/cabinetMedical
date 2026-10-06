<?php

namespace Tests\Unit\ClinicalDocuments;

use App\ClinicalDocuments\HtmlToWordprocessingMl;
use PHPUnit\Framework\TestCase;

class HtmlToWordprocessingMlTest extends TestCase
{
    private function convert(string $html): array
    {
        return (new HtmlToWordprocessingMl)->convert($html, 9906);
    }

    /**
     * The fragment must be well-formed once wrapped in a w:body element.
     */
    private function assertWellFormed(string $xml): void
    {
        $document = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'.$xml.'</w:body></w:document>';

        $this->assertNotFalse(simplexml_load_string($document), $xml);
    }

    public function test_inline_formatting_and_paragraph_properties(): void
    {
        $result = $this->convert(
            '<h1 style="text-align: center">Titre</h1>'
            .'<p style="text-align: justify; margin-left: 40px; line-height: 1.5">'
            .'<strong>gras</strong> <em>italique</em> <u>souligné</u> <s>barré</s> '
            .'<span style="color: #c00000; font-size: 14pt; font-family: &quot;Arial&quot;, sans-serif">rouge</span> '
            .'<mark data-color="#fff2a8">surligné</mark>A<br>B</p><p></p>',
        );
        $xml = $result['xml'];

        $this->assertWellFormed($xml);
        $this->assertStringContainsString('<w:pStyle w:val="Heading1"/>', $xml);
        $this->assertStringContainsString('<w:jc w:val="center"/>', $xml);
        $this->assertStringContainsString('<w:jc w:val="both"/>', $xml);
        $this->assertStringContainsString('<w:ind w:left="600"/>', $xml);
        $this->assertStringContainsString('<w:spacing w:line="360" w:lineRule="auto"/>', $xml);
        $this->assertStringContainsString('<w:b/>', $xml);
        $this->assertStringContainsString('<w:i/>', $xml);
        $this->assertStringContainsString('<w:u w:val="single"/>', $xml);
        $this->assertStringContainsString('<w:strike/>', $xml);
        $this->assertStringContainsString('<w:color w:val="C00000"/>', $xml);
        $this->assertStringContainsString('<w:sz w:val="28"/>', $xml);
        $this->assertStringContainsString('w:ascii="Arial"', $xml);
        $this->assertStringContainsString('w:fill="FFF2A8"', $xml);
        $this->assertStringContainsString('<w:br/>', $xml);
        // The empty paragraph is kept as a blank line.
        $this->assertStringEndsWith('<w:p></w:p>', $xml);
        $this->assertNull($result['numbering']);
        $this->assertSame([], $result['images']);
    }

    public function test_lists_get_numbering_definitions(): void
    {
        $result = $this->convert(
            '<ul><li><p>Un</p><ul><li><p>Imbriqué</p></li></ul></li></ul>'
            .'<ol start="3"><li><p>Trois</p></li></ol>',
        );

        $this->assertWellFormed($result['xml']);
        $this->assertStringContainsString('<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>', $result['xml']);
        $this->assertStringContainsString('<w:numPr><w:ilvl w:val="1"/><w:numId w:val="2"/></w:numPr>', $result['xml']);
        $this->assertStringContainsString('<w:numId w:val="3"/>', $result['xml']);
        $this->assertIsString($result['numbering']);
        $this->assertNotFalse(simplexml_load_string($result['numbering']));
        $this->assertStringContainsString('<w:startOverride w:val="3"/>', $result['numbering']);
        $this->assertStringContainsString('w:val="bullet"', $result['numbering']);
    }

    public function test_tables_with_spans_become_word_tables(): void
    {
        $result = $this->convert(
            '<table><tbody>'
            .'<tr><th colspan="2">En-tête</th><td rowspan="2">Fusion</td></tr>'
            .'<tr><td>A</td><td style="background-color: #eeeeee">B</td></tr>'
            .'</tbody></table>',
        );
        $xml = $result['xml'];

        $this->assertWellFormed($xml);
        $this->assertSame(3, substr_count($xml, '<w:gridCol '));
        $this->assertStringContainsString('<w:gridSpan w:val="2"/>', $xml);
        $this->assertStringContainsString('<w:vMerge w:val="restart"/>', $xml);
        $this->assertStringContainsString('<w:vMerge/>', $xml);
        $this->assertStringContainsString('w:fill="EEEEEE"', $xml);
        $this->assertSame(2, substr_count($xml, '<w:tr>'));
    }

    public function test_images_rules_and_page_breaks(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z9mAAAAAASUVORK5CYII=';
        $result = $this->convert(
            '<p><img src="data:image/png;base64,'.$png.'" width="100" alt="Logo &amp; co"></p>'
            .'<hr><div class="page-break"></div>'
            .'<p><img src="https://tracker.test/x.png"></p>',
        );

        $this->assertWellFormed($result['xml']);
        $this->assertCount(1, $result['images']);
        $this->assertSame('rIdImg1', $result['images'][0]['id']);
        $this->assertSame('png', $result['images'][0]['extension']);
        $this->assertStringContainsString('<wp:extent cx="952500" cy="952500"/>', $result['xml']);
        $this->assertStringContainsString('name="Logo &amp; co"', $result['xml']);
        $this->assertStringContainsString('<w:pBdr><w:bottom', $result['xml']);
        $this->assertStringContainsString('<w:br w:type="page"/>', $result['xml']);
        $this->assertStringNotContainsString('tracker.test', $result['xml']);
    }

    public function test_text_is_xml_escaped(): void
    {
        $result = $this->convert('<p>a &lt; b &amp; "c"</p>');

        $this->assertWellFormed($result['xml']);
        $this->assertStringContainsString('a &lt; b &amp; &quot;c&quot;', $result['xml']);
    }
}
