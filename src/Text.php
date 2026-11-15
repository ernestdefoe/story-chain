<?php

namespace ErnestDefoe\StoryChain;

/** Member text as one clean line: no control characters, no line breaks, single spaces. */
final class Text
{
    public static function clean(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    public static function length(string $value): int
    {
        return mb_strlen($value);
    }

    /**
     * How many characters must change to turn one text into the other
     * (Levenshtein, counted in characters rather than bytes, any length).
     */
    public static function distance(string $from, string $to): int
    {
        $a = mb_str_split($from);
        $b = mb_str_split($to);
        $previous = range(0, count($b));

        foreach ($a as $i => $char) {
            $current = [$i + 1];
            foreach ($b as $j => $other) {
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($char === $other ? 0 : 1));
            }
            $previous = $current;
        }

        return $previous[count($b)];
    }
}
