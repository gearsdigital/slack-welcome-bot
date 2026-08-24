<?php

use PHPUnit\Framework\TestCase;

final class HtmlConverterTest extends TestCase
{
    public function test_converts_bold_and_italic(): void
    {
        $result = SWB_Html_Converter::to_slack_mrkdwn('<p><strong>fett</strong> und <em>kursiv</em></p>');

        $this->assertSame('*fett* und _kursiv_', $result);
    }

    public function test_converts_link_to_slack_format(): void
    {
        $result = SWB_Html_Converter::to_slack_mrkdwn('<a href="https://example.com">Beispiel</a>');

        $this->assertSame('<https://example.com|Beispiel>', $result);
    }

    public function test_link_without_text_falls_back_to_bare_url(): void
    {
        $result = SWB_Html_Converter::to_slack_mrkdwn('<a href="https://example.com"></a>');

        $this->assertSame('https://example.com', $result);
    }

    public function test_converts_heading_to_bold_line(): void
    {
        $result = SWB_Html_Converter::to_slack_mrkdwn('<h2>Regel 1</h2><p>Text</p>');

        $this->assertSame("*Regel 1*\n\nText", $result);
    }

    public function test_converts_list_to_bullet_points(): void
    {
        $result = SWB_Html_Converter::to_slack_mrkdwn('<ul><li>Eins</li><li>Zwei</li></ul>');

        $this->assertSame("• Eins\n• Zwei", $result);
    }

    public function test_strips_script_and_style_blocks(): void
    {
        $result = SWB_Html_Converter::to_slack_mrkdwn('<p>Text</p><script>alert(1)</script><style>p{color:red}</style>');

        $this->assertSame('Text', $result);
    }

    public function test_decodes_html_entities(): void
    {
        $result = SWB_Html_Converter::to_slack_mrkdwn('<p>Regeln &amp; Richtlinien</p>');

        $this->assertSame('Regeln & Richtlinien', $result);
    }

    public function test_collapses_excessive_blank_lines(): void
    {
        $result = SWB_Html_Converter::to_slack_mrkdwn('<p>Eins</p><br><br><br><p>Zwei</p>');

        $this->assertSame("Eins\n\nZwei", $result);
    }

    public function test_short_text_is_not_split(): void
    {
        $chunks = SWB_Html_Converter::split_into_blocks('Kurzer Text', 100);

        $this->assertSame(['Kurzer Text'], $chunks);
    }

    public function test_splits_long_text_at_paragraph_boundaries(): void
    {
        $para1 = str_repeat('a', 40);
        $para2 = str_repeat('b', 40);
        $text = "{$para1}\n\n{$para2}";

        $chunks = SWB_Html_Converter::split_into_blocks($text, 50);

        $this->assertSame([$para1, $para2], $chunks);
    }

    public function test_splits_single_oversized_paragraph_hard(): void
    {
        $huge = str_repeat('x', 25);

        $chunks = SWB_Html_Converter::split_into_blocks($huge, 10);

        $this->assertSame(['xxxxxxxxxx', 'xxxxxxxxxx', 'xxxxx'], $chunks);
    }

    public function test_no_chunk_exceeds_max_length(): void
    {
        $text = implode("\n\n", array_fill(0, 5, str_repeat('word ', 20)));

        $chunks = SWB_Html_Converter::split_into_blocks($text, 60);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(60, mb_strlen($chunk));
        }
    }
}
