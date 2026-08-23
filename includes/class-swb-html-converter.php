<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wandelt gerenderten WordPress-Seiteninhalt (HTML) in Slack-mrkdwn um.
 * https://api.slack.com/reference/surfaces/formatting
 */
class SWB_Html_Converter
{
    public static function to_slack_mrkdwn(string $html): string
    {
        // Script/Style-Blöcke entfernen
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);

        // Links: <a href="URL">TEXT</a> -> <URL|TEXT>
        $html = preg_replace_callback('#<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) {
            $url = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(wp_strip_all_tags($m[2]));
            return $text !== '' ? "<{$url}|{$text}>" : $url;
        }, $html);

        // Fett / Kursiv
        $html = preg_replace('#<(strong|b)[^>]*>(.*?)</\1>#is', '*$2*', $html);
        $html = preg_replace('#<(em|i)[^>]*>(.*?)</\1>#is', '_$2_', $html);

        // Überschriften -> fette Zeile
        $html = preg_replace_callback('#<h[1-6][^>]*>(.*?)</h[1-6]>#is', function ($m) {
            return "\n*" . trim(wp_strip_all_tags($m[1])) . "*\n";
        }, $html);

        // Listenelemente -> Bullet-Points
        $html = preg_replace('#<li[^>]*>(.*?)</li>#is', "• $1\n", $html);
        $html = preg_replace('#</?(ul|ol)[^>]*>#i', "\n", $html);

        // Zeilen-/Absatzumbrüche
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</p>\s*<p[^>]*>#i', "\n\n", $html);
        $html = preg_replace('#</?(p|div)[^>]*>#i', "\n", $html);

        // Restliche Tags entfernen
        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Whitespace normalisieren
        $lines = array_map('rtrim', explode("\n", $text));
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Slack erlaubt max. ~3000 Zeichen pro section-Block. Lange Texte werden
     * bevorzugt an Absatzgrenzen in mehrere Blöcke aufgeteilt.
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
                        : str_split($para, $max_len); // Fallback ohne mbstring; sehr seltener Fall auf Shared-Hosting
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
