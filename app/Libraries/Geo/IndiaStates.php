<?php

declare(strict_types=1);

namespace App\Libraries\Geo;

/**
 * IndiaStates — the GST state codes (the 2-digit code that prefixes a GSTIN and
 * decides intra/inter-state tax). Used to turn a state name (typed, or returned
 * by Google Maps on the registration map) into its official code, and to build
 * the state dropdown. Pure / data-only.
 */
final class IndiaStates
{
    /** GST state code => canonical state/UT name (current codes; 25 & 28 are retired). */
    public const CODES = [
        '01' => 'Jammu and Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '27' => 'Maharashtra',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
    ];

    /** Extra spellings Google / users may use, mapped to a GST code. */
    private const ALIASES = [
        'nct of delhi'                        => '07',
        'national capital territory of delhi' => '07',
        'orissa'                              => '21',
        'pondicherry'                         => '34',
        'uttaranchal'                         => '05',
        'andaman and nicobar'                 => '35',
        'dadra and nagar haveli'              => '26',
        'daman and diu'                       => '26',
    ];

    /** @return array<string,string> code => name, ordered by name (for a dropdown). */
    public static function list(): array
    {
        $out = self::CODES;
        asort($out);

        return $out;
    }

    /** Resolve a state name (typed or from Google) to its GST code, or '' if unknown. */
    public static function codeForName(string $name): string
    {
        return self::nameToCodeMap()[self::norm($name)] ?? '';
    }

    /** @return array<string,string> normalised-name => code (incl. aliases), for JS lookups. */
    public static function nameToCodeMap(): array
    {
        $map = [];
        foreach (self::CODES as $code => $cn) {
            $map[self::norm($cn)] = (string) $code; // numeric-string array keys become ints in PHP
        }
        foreach (self::ALIASES as $name => $code) {
            $map[self::norm($name)] = $code;
        }

        return $map;
    }

    /** Normalise a state name for matching: lowercase, & -> and, strip punctuation/filler. */
    public static function norm(string $s): string
    {
        $s = str_replace('&', ' and ', strtolower(trim($s)));
        $s = preg_replace('/\b(state of|the)\b/', ' ', $s) ?? $s;
        $s = preg_replace('/[^a-z ]+/', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return trim($s);
    }
}
