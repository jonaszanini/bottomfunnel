<?php

namespace App\Support;

class CopyGuard
{
    public static function clean(string $text): string
    {
        $text = str_replace(["\u{2014}", "\u{2013}"], ', ', $text);

        $replacements = [
            '/\bbuy now\b/i' => 'take the next step',
            '/\bexpensive\b/i' => 'high value',
            '/\bdiscounts\b/i' => 'special conditions',
            '/\bdiscount\b/i' => 'special condition',
            '/\bprices\b/i' => 'values',
            '/\bprice\b/i' => 'value',
            '/\bbuy\b/i' => 'take the next step',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return trim((string) preg_replace('/\s{2,}/', ' ', $text));
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed>
     */
    public static function cleanStructure(array $structure): array
    {
        $walk = function (mixed $value) use (&$walk): mixed {
            if (is_string($value)) {
                return self::clean($value);
            }
            if (! is_array($value)) {
                return $value;
            }
            $clean = [];
            foreach ($value as $key => $item) {
                $clean[$key] = $walk($item);
            }

            return $clean;
        };

        return $walk($structure);
    }
}
