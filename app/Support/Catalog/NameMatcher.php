<?php

namespace App\Support\Catalog;

/**
 * Compares catalog names (attributes, attribute groups) the way an admin reads
 * them, so bulk imports don't create near-copies of what already exists.
 *
 *  key()     — same name once case, spaces and symbols are ignored:
 *              "Wi-Fi", "wi fi" and "WiFi" all give "wifi".
 *  similar() — probably a typo or spelling variant: "Colour" / "Color",
 *              "Processors" / "Processor". Not "Port 1" / "Port 2" (the numbers
 *              differ) and not short names like "RAM" / "LAN".
 */
class NameMatcher
{
    private const MIN_LENGTH = 5;

    public static function key(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $key = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', $lower);

        return $key !== '' && $key !== null ? $key : $lower;
    }

    /** Both arguments are key() values. */
    public static function similar(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $longest = max(strlen($a), strlen($b));
        if (min(strlen($a), strlen($b)) < self::MIN_LENGTH || abs(strlen($a) - strlen($b)) > 2) {
            return false;
        }
        if (preg_replace('/\D/', '', $a) !== preg_replace('/\D/', '', $b)) {
            return false;
        }

        return levenshtein($a, $b) <= ($longest >= 10 ? 2 : 1);
    }

    /**
     * The first known name similar to $key.
     *
     * @param  array<string, string>  $known  key() => display name
     */
    public static function findSimilar(string $key, array $known): ?string
    {
        foreach ($known as $knownKey => $name) {
            if (self::similar($key, (string) $knownKey)) {
                return $name;
            }
        }

        return null;
    }
}
