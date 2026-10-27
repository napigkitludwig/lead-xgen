<?php

namespace App\Support;

/**
 * Best-effort split of a free-text address into town, zip code and country.
 *
 * Addresses come from Google Maps / Facebook as one comma separated line in many
 * national formats, so this is a heuristic and any part may be null.
 */
final class AddressParser
{
    /** Zip code formats, most specific first. */
    private const ZIP_PATTERNS = [
        '/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i',
        '/\b[A-Z]\d[A-Z]\s*\d[A-Z]\d\b/i',
        '/\b\d{5}(?:-\d{4})?\b/',
        '/\b\d{4,6}\b/',
    ];

    /**
     * @param  ?string  $hint  The place the user searched for, e.g. "Makati, Philippines" or "Philippines".
     * @return array{town: ?string, zip_code: ?string, country: ?string}
     */
    public static function parse(?string $address, ?string $hint = null): array
    {
        $segments = self::segments($address);
        $country = null;

        if ($segments !== [] && Countries::isCountry(end($segments))) {
            $country = array_pop($segments);
        }

        [$zip, $zipIndex] = self::findZip($segments);
        $town = self::findTown($segments, $zip, $zipIndex);

        $hintParts = self::segments($hint);

        if ($country === null && $hintParts !== [] && Countries::isCountry(end($hintParts))) {
            $country = end($hintParts);
        }

        if ($town === null && $hintParts !== [] && ! Countries::isCountry($hintParts[0])) {
            $town = $hintParts[0];
        }

        return ['town' => $town, 'zip_code' => $zip, 'country' => $country];
    }

    /**
     * @return list<string>
     */
    private static function segments(?string $text): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) $text)),
            fn (string $segment) => $segment !== '',
        ));
    }

    /**
     * Zip codes sit in the last segments; earlier digits are usually house numbers.
     *
     * @param  list<string>  $segments
     * @return array{0: ?string, 1: ?int}
     */
    private static function findZip(array $segments): array
    {
        $lowest = max(count($segments) - 3, 0);

        for ($index = count($segments) - 1; $index >= $lowest; $index--) {
            foreach (self::ZIP_PATTERNS as $pattern) {
                if (preg_match($pattern, $segments[$index], $match)) {
                    return [strtoupper(trim($match[0])), $index];
                }
            }
        }

        return [null, null];
    }

    /**
     * @param  list<string>  $segments
     */
    private static function findTown(array $segments, ?string $zip, ?int $zipIndex): ?string
    {
        if ($segments === []) {
            return null;
        }

        if ($zipIndex === null) {
            return count($segments) > 1 ? end($segments) : null;
        }

        $previous = $segments[$zipIndex - 1] ?? null;

        if ($previous !== null && ! preg_match('/\d/', $previous)) {
            return $previous;
        }

        $remainder = trim(preg_replace('/\s+/', ' ', str_ireplace($zip, '', $segments[$zipIndex])) ?? '');

        return preg_match('/\p{L}{3,}/u', $remainder) ? $remainder : null;
    }
}
