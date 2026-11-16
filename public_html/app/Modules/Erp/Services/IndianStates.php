<?php
namespace App\Modules\Erp\Services;

use Illuminate\Validation\ValidationException;

/**
 * States and union territories for GST place of supply, with their GST state codes.
 * Free text such as "KL" or "kerala " is mapped to one canonical name so CGST/SGST vs IGST is decided reliably.
 */
class IndianStates
{
    /** Canonical name => [GST state code, common abbreviations]. */
    public const STATES = [
        'Andaman and Nicobar Islands' => ['35', ['AN']],
        'Andhra Pradesh' => ['37', ['AP']],
        'Arunachal Pradesh' => ['12', ['AR']],
        'Assam' => ['18', ['AS']],
        'Bihar' => ['10', ['BR', 'BH']],
        'Chandigarh' => ['04', ['CH']],
        'Chhattisgarh' => ['22', ['CG', 'CT']],
        'Dadra and Nagar Haveli and Daman and Diu' => ['26', ['DN', 'DD', 'DNHDD']],
        'Delhi' => ['07', ['DL', 'NCT of Delhi', 'New Delhi']],
        'Goa' => ['30', ['GA']],
        'Gujarat' => ['24', ['GJ']],
        'Haryana' => ['06', ['HR']],
        'Himachal Pradesh' => ['02', ['HP']],
        'Jammu and Kashmir' => ['01', ['JK', 'J&K']],
        'Jharkhand' => ['20', ['JH']],
        'Karnataka' => ['29', ['KA']],
        'Kerala' => ['32', ['KL']],
        'Ladakh' => ['38', ['LA']],
        'Lakshadweep' => ['31', ['LD']],
        'Madhya Pradesh' => ['23', ['MP']],
        'Maharashtra' => ['27', ['MH']],
        'Manipur' => ['14', ['MN']],
        'Meghalaya' => ['17', ['ML']],
        'Mizoram' => ['15', ['MZ']],
        'Nagaland' => ['13', ['NL']],
        'Odisha' => ['21', ['OD', 'OR', 'Orissa']],
        'Puducherry' => ['34', ['PY', 'Pondicherry']],
        'Punjab' => ['03', ['PB']],
        'Rajasthan' => ['08', ['RJ']],
        'Sikkim' => ['11', ['SK']],
        'Tamil Nadu' => ['33', ['TN']],
        'Telangana' => ['36', ['TS', 'TG']],
        'Tripura' => ['16', ['TR']],
        'Uttar Pradesh' => ['09', ['UP']],
        'Uttarakhand' => ['05', ['UK', 'UT', 'Uttaranchal']],
        'West Bengal' => ['19', ['WB']],
        'Other Territory' => ['97', ['OT']],
        'Outside India' => ['96', ['Foreign', 'Overseas']],
    ];

    public static function names(): array
    {
        return array_keys(self::STATES);
    }

    private static function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', str_replace('&', 'and', mb_strtolower($value)));
    }

    /** The canonical name for a name, abbreviation or GST code; null when empty or not recognised. */
    public static function canonical(?string $value): ?string
    {
        $key = self::key((string) $value);
        if ($key === '') return null;
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::STATES as $name => [$code, $aliases]) {
                foreach (array_merge([$name, $code], $aliases) as $alias) $index[self::key($alias)] = $name;
            }
        }
        return $index[$key] ?? null;
    }

    public static function code(?string $value): ?string
    {
        $name = self::canonical($value);
        return $name ? self::STATES[$name][0] : null;
    }

    /** Validates and normalises a state field; empty stays null. */
    public static function normalize(?string $value, string $field): ?string
    {
        if ($value === null || trim($value) === '') return null;
        $name = self::canonical($value);
        if (!$name) throw ValidationException::withMessages([$field => 'Choose a state or union territory from the list (for example Kerala or Tamil Nadu).']);
        return $name;
    }

    /** Same place for GST purposes? Unrecognised legacy values fall back to a case-insensitive text match. */
    public static function same(?string $a, ?string $b): bool
    {
        $ca = self::canonical($a); $cb = self::canonical($b);
        if ($ca && $cb) return $ca === $cb;
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }
}
