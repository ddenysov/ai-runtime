<?php

declare(strict_types=1);

namespace App\Libs\Telegram;

use Parsedown;

/**
 * Converts assistant markdown to Telegram Bot API HTML (parse_mode: HTML).
 */
final class MarkdownToHtml
{
    /** @var list<string> */
    private const ALLOWED_TAG_NAMES = [
        'b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del',
        'code', 'pre', 'a', 'blockquote',
    ];

    public static function convert(string $markdown): string
    {
        if ($markdown === '' || trim($markdown) === '') {
            return $markdown;
        }

        $parsedown = new Parsedown;
        $html = $parsedown->text($markdown);

        $codeBlocks = [];
        $i = 0;
        $html = preg_replace_callback(
            '/<pre>\s*<code(?:\s[^>]*)?>([\s\S]*?)<\/code>\s*<\/pre>/i',
            static function (array $m) use (&$codeBlocks, &$i): string {
                $placeholder = "\0CODE_BLOCK_".($i++)."\0";
                $codeBlocks[$placeholder] = $m[0];

                return $placeholder;
            },
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '/<h([1-6])\b[^>]*>([\s\S]*?)<\/h\1>/i',
            static fn (array $m): string => '<b>'.$m[2].'</b>'."\n",
            $html
        ) ?? $html;

        $html = self::tablesToPlainText($html);
        $html = preg_replace('/<hr\b[^>]*\/?>/i', "\n———\n", $html) ?? $html;
        $html = self::flattenLists($html);
        $html = preg_replace('/<p\b[^>]*>/i', '', $html) ?? $html;
        $html = str_replace('</p>', "\n\n", $html);

        $html = preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function (array $m): string {
                $tag = $m[0];
                if (preg_match('/\balt="([^"]*)"/i', $tag, $q)) {
                    return $q[1];
                }
                if (preg_match("/\balt='([^']*)'/i", $tag, $q)) {
                    return $q[1];
                }

                return '';
            },
            $html
        ) ?? $html;

        $html = preg_replace('/<br\b[^>]*\/?>/i', "\n", $html) ?? $html;
        $html = self::stripDisallowedTags($html);

        foreach ($codeBlocks as $placeholder => $fragment) {
            $html = str_replace($placeholder, $fragment, $html);
        }

        $html = preg_replace('/<pre\b[^>]*>/i', '<pre>', $html) ?? $html;
        $html = preg_replace('/<code\b[^>]*>/i', '<code>', $html) ?? $html;
        $html = self::escapeForTelegramHtml($html);
        $html = preg_replace("/\n{3,}/", "\n\n", $html) ?? $html;

        return trim($html);
    }

    private static function tablesToPlainText(string $html): string
    {
        return preg_replace_callback(
            '/<table\b[^>]*>[\s\S]*?<\/table>/i',
            static function (array $m): string {
                $table = $m[0];
                $rows = [];
                if (preg_match_all('/<tr\b[^>]*>([\s\S]*?)<\/tr>/i', $table, $trMatches)) {
                    foreach ($trMatches[1] as $trInner) {
                        $cells = [];
                        if (preg_match_all('/<t[hd]\b[^>]*>([\s\S]*?)<\/t[hd]>/i', $trInner, $cellMatches)) {
                            foreach ($cellMatches[1] as $cellHtml) {
                                $cells[] = trim(html_entity_decode(strip_tags($cellHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                            }
                        }
                        if ($cells !== []) {
                            $rows[] = implode(' | ', $cells);
                        }
                    }
                }

                return "\n".implode("\n", $rows)."\n";
            },
            $html
        ) ?? $html;
    }

    private static function flattenLists(string $html): string
    {
        for ($pass = 0; $pass < 20; $pass++) {
            $next = self::replaceOneInnermostList($html);
            if ($next === null) {
                break;
            }
            $html = $next;
        }

        return $html;
    }

    private static function replaceOneInnermostList(string $html): ?string
    {
        $len = strlen($html);
        $seek = 0;
        while ($seek < $len) {
            $ul = stripos($html, '<ul', $seek);
            $ol = stripos($html, '<ol', $seek);
            if ($ul === false && $ol === false) {
                return null;
            }
            if ($ul === false) {
                $pos = $ol;
            } elseif ($ol === false) {
                $pos = $ul;
            } else {
                $pos = min($ul, $ol);
            }
            if (! preg_match('/^<(ul|ol)\b[^>]*>/i', substr($html, $pos), $tm)) {
                $seek = $pos + 1;

                continue;
            }
            $tag = strtolower($tm[1]);
            $innerStart = $pos + strlen($tm[0]);
            $closePos = self::findMatchingListClose($html, $innerStart);
            if ($closePos === null) {
                $seek = $pos + 1;

                continue;
            }
            $inner = substr($html, $innerStart, $closePos - $innerStart);
            if (preg_match('/<[uo]l\b/i', $inner)) {
                $seek = $pos + 1;

                continue;
            }
            $plain = self::listItemsToLines($inner, $tag === 'ol');

            return substr($html, 0, $pos).$plain.substr($html, $closePos + strlen('</'.$tag.'>'));
        }

        return null;
    }

    private static function findMatchingListClose(string $html, int $innerStart): ?int
    {
        $depth = 1;
        $i = $innerStart;
        $len = strlen($html);
        while ($i < $len && $depth > 0) {
            $sub = substr($html, $i);
            if (! preg_match('/<(ul|ol)\b[^>]*>|<\/(ul|ol)>/i', $sub, $m, PREG_OFFSET_CAPTURE)) {
                return null;
            }
            $abs = $i + (int) $m[0][1];
            $full = $m[0][0];
            if (str_starts_with($full, '</')) {
                $depth--;
                if ($depth === 0) {
                    return $abs;
                }
                $i = $abs + strlen($full);
            } else {
                $depth++;
                $i = $abs + strlen($full);
            }
        }

        return null;
    }

    private static function listItemsToLines(string $inner, bool $ordered): string
    {
        $lines = [];
        $n = 1;
        if (preg_match_all('/<li\b[^>]*>([\s\S]*?)<\/li>/i', $inner, $items)) {
            foreach ($items[1] as $itemHtml) {
                $prefix = $ordered ? ($n++.'. ') : '• ';
                $lines[] = $prefix.trim($itemHtml);
            }
        }

        if ($lines === []) {
            return trim($inner);
        }

        return implode("\n", $lines)."\n";
    }

    private static function stripDisallowedTags(string $html): string
    {
        /** @var array<string, true> $allowed */
        $allowed = array_fill_keys(array_map(strtolower(...), self::ALLOWED_TAG_NAMES), true);

        $html = preg_replace_callback(
            '/<([a-z][\w:-]*)\b[^>]*>/iu',
            static function (array $m) use ($allowed): string {
                $name = strtolower($m[1]);

                return isset($allowed[$name]) ? $m[0] : '';
            },
            $html
        ) ?? $html;

        return preg_replace_callback(
            '/<\/([a-z][\w:-]*)>/iu',
            static function (array $m) use ($allowed): string {
                $name = strtolower($m[1]);

                return isset($allowed[$name]) ? $m[0] : '';
            },
            $html
        ) ?? $html;
    }

    private static function escapeForTelegramHtml(string $html): string
    {
        $allowed = array_map(strtolower(...), self::ALLOWED_TAG_NAMES);
        $len = mb_strlen($html, 'UTF-8');
        $out = '';
        $i = 0;
        while ($i < $len) {
            $ch = mb_substr($html, $i, 1, 'UTF-8');
            if ($ch !== '<' && $ch !== '&') {
                $out .= $ch;
                $i++;

                continue;
            }
            if ($ch === '&') {
                $rest = mb_substr($html, $i, null, 'UTF-8');
                if (preg_match('/^&(amp|lt|gt|quot|apos|#\d+|#x[0-9A-Fa-f]+);/', $rest, $em)) {
                    $out .= $em[0];
                    $i += mb_strlen($em[0], 'UTF-8');

                    continue;
                }
                $out .= '&amp;';
                $i++;

                continue;
            }
            $rest = mb_substr($html, $i, null, 'UTF-8');
            if (preg_match('/^<\/?([a-z][a-z0-9:-]*)\b[^>]*>/iu', $rest, $tm)) {
                $name = strtolower($tm[1]);
                if (in_array($name, $allowed, true)) {
                    $out .= $tm[0];
                    $i += mb_strlen($tm[0], 'UTF-8');

                    continue;
                }
            }
            $out .= '&lt;';
            $i++;
        }

        return $out;
    }
}
