<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts rendered WordPress page content (HTML) into Slack mrkdwn.
 * https://api.slack.com/reference/surfaces/formatting
 */
class SWB_Html_Converter
{
    public static function to_slack_mrkdwn(string $html): string
    {
        // Strip script/style blocks
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);

        // Links: <a href="URL">TEXT</a> -> <URL|TEXT>. The result is hidden behind a
        // placeholder, because the final wp_strip_all_tags() below would otherwise
        // strip "<URL|TEXT>" itself as if it were an HTML tag.
        $link_placeholders = [];
        $html = preg_replace_callback('#<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) use (&$link_placeholders) {
            $url = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(wp_strip_all_tags($m[2]));
            $slack_link = $text !== '' ? "<{$url}|{$text}>" : $url;

            // Keep this purely alphanumeric: control characters like \x00 would be
            // stripped by strip_tags() below too, destroying the placeholder.
            $placeholder = 'SWBLINKPLACEHOLDER' . count($link_placeholders) . 'END';
            $link_placeholders[$placeholder] = $slack_link;

            return $placeholder;
        }, $html);

        // Bold / italic
        $html = preg_replace('#<(strong|b)[^>]*>(.*?)</\1>#is', '*$2*', $html);
        $html = preg_replace('#<(em|i)[^>]*>(.*?)</\1>#is', '_$2_', $html);

        // Headings -> bold line
        $html = preg_replace_callback('#<h[1-6][^>]*>(.*?)</h[1-6]>#is', function ($m) {
            return "\n*" . trim(wp_strip_all_tags($m[1])) . "*\n";
        }, $html);

        // List items -> bullet points
        $html = preg_replace('#<li[^>]*>(.*?)</li>#is', "• $1\n", $html);
        $html = preg_replace('#</?(ul|ol)[^>]*>#i', "\n", $html);

        // Line/paragraph breaks
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</p>\s*<p[^>]*>#i', "\n\n", $html);
        $html = preg_replace('#</?(p|div)[^>]*>#i', "\n", $html);

        // Strip remaining tags
        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $lines = array_map('rtrim', explode("\n", $text));
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);

        return $link_placeholders !== [] ? strtr($text, $link_placeholders) : $text;
    }

    /**
     * Slack allows a max. of ~3000 characters per section block. Long texts
     * are split into multiple blocks, preferring paragraph boundaries.
     */
    public static function split_into_blocks(string $text, int $max_len = 2900): array
    {
        if (mb_strlen($text) <= $max_len) {
            return [$text];
        }

        $paragraphs = explode("\n\n", $text);
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $para) {
            $candidate = $current === '' ? $para : $current . "\n\n" . $para;

            if (mb_strlen($candidate) > $max_len) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                if (mb_strlen($para) > $max_len) {
                    $pieces = function_exists('mb_str_split')
                        ? mb_str_split($para, $max_len)
                        : str_split($para, $max_len); // Fallback without mbstring; a rare edge case on shared hosting
                    foreach ($pieces as $piece) {
                        $chunks[] = $piece;
                    }
                } else {
                    $current = $para;
                }
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
