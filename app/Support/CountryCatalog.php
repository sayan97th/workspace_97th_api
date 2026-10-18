<?php

namespace App\Support;

use App\Models\BoardColumn;

/**
 * Every country a {@see BoardColumn::TYPE_COUNTRY} cell can hold, keyed by its
 * ISO 3166 alpha 2 code, with the English name an export writes and the usual
 * short forms people type. Mirrors the frontend's `COUNTRY_CODES` list in
 * `columnValueFormats.ts`. The API runs without the intl extension, so the
 * names live here instead of coming from `Locale`.
 */
final class CountryCatalog
{
    /** @var array<string, string> code => English name */
    public const NAMES = [
        'AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan', 'AG' => 'Antigua and Barbuda',
        'AI' => 'Anguilla', 'AL' => 'Albania', 'AM' => 'Armenia', 'AO' => 'Angola', 'AR' => 'Argentina',
        'AS' => 'American Samoa', 'AT' => 'Austria', 'AU' => 'Australia', 'AW' => 'Aruba', 'AX' => 'Aland Islands',
        'AZ' => 'Azerbaijan', 'BA' => 'Bosnia and Herzegovina', 'BB' => 'Barbados', 'BD' => 'Bangladesh',
        'BE' => 'Belgium', 'BF' => 'Burkina Faso', 'BG' => 'Bulgaria', 'BH' => 'Bahrain', 'BI' => 'Burundi',
        'BJ' => 'Benin', 'BL' => 'Saint Barthelemy', 'BM' => 'Bermuda', 'BN' => 'Brunei', 'BO' => 'Bolivia',
        'BQ' => 'Caribbean Netherlands', 'BR' => 'Brazil', 'BS' => 'Bahamas', 'BT' => 'Bhutan', 'BW' => 'Botswana',
        'BY' => 'Belarus', 'BZ' => 'Belize', 'CA' => 'Canada', 'CC' => 'Cocos (Keeling) Islands',
        'CD' => 'Congo - Kinshasa', 'CF' => 'Central African Republic', 'CG' => 'Congo - Brazzaville',
        'CH' => 'Switzerland', 'CI' => 'Cote d\'Ivoire', 'CK' => 'Cook Islands', 'CL' => 'Chile', 'CM' => 'Cameroon',
        'CN' => 'China', 'CO' => 'Colombia', 'CR' => 'Costa Rica', 'CU' => 'Cuba', 'CV' => 'Cape Verde',
        'CW' => 'Curacao', 'CX' => 'Christmas Island', 'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DE' => 'Germany',
        'DJ' => 'Djibouti', 'DK' => 'Denmark', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'DZ' => 'Algeria',
        'EC' => 'Ecuador', 'EE' => 'Estonia', 'EG' => 'Egypt', 'EH' => 'Western Sahara', 'ER' => 'Eritrea',
        'ES' => 'Spain', 'ET' => 'Ethiopia', 'FI' => 'Finland', 'FJ' => 'Fiji', 'FK' => 'Falkland Islands',
        'FM' => 'Micronesia', 'FO' => 'Faroe Islands', 'FR' => 'France', 'GA' => 'Gabon', 'GB' => 'United Kingdom',
        'GD' => 'Grenada', 'GE' => 'Georgia', 'GF' => 'French Guiana', 'GG' => 'Guernsey', 'GH' => 'Ghana',
        'GI' => 'Gibraltar', 'GL' => 'Greenland', 'GM' => 'Gambia', 'GN' => 'Guinea', 'GP' => 'Guadeloupe',
        'GQ' => 'Equatorial Guinea', 'GR' => 'Greece', 'GT' => 'Guatemala', 'GU' => 'Guam', 'GW' => 'Guinea-Bissau',
        'GY' => 'Guyana', 'HK' => 'Hong Kong', 'HN' => 'Honduras', 'HR' => 'Croatia', 'HT' => 'Haiti',
        'HU' => 'Hungary', 'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel', 'IM' => 'Isle of Man',
        'IN' => 'India', 'IO' => 'British Indian Ocean Territory', 'IQ' => 'Iraq', 'IR' => 'Iran', 'IS' => 'Iceland',
        'IT' => 'Italy', 'JE' => 'Jersey', 'JM' => 'Jamaica', 'JO' => 'Jordan', 'JP' => 'Japan', 'KE' => 'Kenya',
        'KG' => 'Kyrgyzstan', 'KH' => 'Cambodia', 'KI' => 'Kiribati', 'KM' => 'Comoros', 'KN' => 'Saint Kitts and Nevis',
        'KP' => 'North Korea', 'KR' => 'South Korea', 'KW' => 'Kuwait', 'KY' => 'Cayman Islands', 'KZ' => 'Kazakhstan',
        'LA' => 'Laos', 'LB' => 'Lebanon', 'LC' => 'Saint Lucia', 'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka',
        'LR' => 'Liberia', 'LS' => 'Lesotho', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia',
        'LY' => 'Libya', 'MA' => 'Morocco', 'MC' => 'Monaco', 'MD' => 'Moldova', 'ME' => 'Montenegro',
        'MF' => 'Saint Martin', 'MG' => 'Madagascar', 'MH' => 'Marshall Islands', 'MK' => 'North Macedonia',
        'ML' => 'Mali', 'MM' => 'Myanmar', 'MN' => 'Mongolia', 'MO' => 'Macao', 'MP' => 'Northern Mariana Islands',
        'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MS' => 'Montserrat', 'MT' => 'Malta', 'MU' => 'Mauritius',
        'MV' => 'Maldives', 'MW' => 'Malawi', 'MX' => 'Mexico', 'MY' => 'Malaysia', 'MZ' => 'Mozambique',
        'NA' => 'Namibia', 'NC' => 'New Caledonia', 'NE' => 'Niger', 'NF' => 'Norfolk Island', 'NG' => 'Nigeria',
        'NI' => 'Nicaragua', 'NL' => 'Netherlands', 'NO' => 'Norway', 'NP' => 'Nepal', 'NR' => 'Nauru', 'NU' => 'Niue',
        'NZ' => 'New Zealand', 'OM' => 'Oman', 'PA' => 'Panama', 'PE' => 'Peru', 'PF' => 'French Polynesia',
        'PG' => 'Papua New Guinea', 'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland',
        'PM' => 'Saint Pierre and Miquelon', 'PN' => 'Pitcairn Islands', 'PR' => 'Puerto Rico', 'PS' => 'Palestine',
        'PT' => 'Portugal', 'PW' => 'Palau', 'PY' => 'Paraguay', 'QA' => 'Qatar', 'RE' => 'Reunion', 'RO' => 'Romania',
        'RS' => 'Serbia', 'RU' => 'Russia', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia', 'SB' => 'Solomon Islands',
        'SC' => 'Seychelles', 'SD' => 'Sudan', 'SE' => 'Sweden', 'SG' => 'Singapore', 'SH' => 'Saint Helena',
        'SI' => 'Slovenia', 'SK' => 'Slovakia', 'SL' => 'Sierra Leone', 'SM' => 'San Marino', 'SN' => 'Senegal',
        'SO' => 'Somalia', 'SR' => 'Suriname', 'SS' => 'South Sudan', 'ST' => 'Sao Tome and Principe',
        'SV' => 'El Salvador', 'SX' => 'Sint Maarten', 'SY' => 'Syria', 'SZ' => 'Eswatini',
        'TC' => 'Turks and Caicos Islands', 'TD' => 'Chad', 'TG' => 'Togo', 'TH' => 'Thailand', 'TJ' => 'Tajikistan',
        'TK' => 'Tokelau', 'TL' => 'Timor-Leste', 'TM' => 'Turkmenistan', 'TN' => 'Tunisia', 'TO' => 'Tonga',
        'TR' => 'Turkey', 'TT' => 'Trinidad and Tobago', 'TV' => 'Tuvalu', 'TW' => 'Taiwan', 'TZ' => 'Tanzania',
        'UA' => 'Ukraine', 'UG' => 'Uganda', 'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan',
        'VA' => 'Vatican City', 'VC' => 'Saint Vincent and the Grenadines', 'VE' => 'Venezuela',
        'VG' => 'British Virgin Islands', 'VI' => 'U.S. Virgin Islands', 'VN' => 'Vietnam', 'VU' => 'Vanuatu',
        'WF' => 'Wallis and Futuna', 'WS' => 'Samoa', 'XK' => 'Kosovo', 'YE' => 'Yemen', 'YT' => 'Mayotte',
        'ZA' => 'South Africa', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    ];

    /** @var array<string, string> lower-cased alternative name => code */
    private const ALIASES = [
        'usa' => 'US', 'u.s.' => 'US', 'u.s.a.' => 'US', 'united states of america' => 'US', 'america' => 'US',
        'uk' => 'GB', 'u.k.' => 'GB', 'great britain' => 'GB', 'britain' => 'GB', 'england' => 'GB', 'scotland' => 'GB', 'wales' => 'GB',
        'uae' => 'AE', 'czech republic' => 'CZ', 'holland' => 'NL', 'the netherlands' => 'NL', 'russian federation' => 'RU',
        'korea' => 'KR', 'republic of korea' => 'KR', 'turkiye' => 'TR', 'türkiye' => 'TR', 'ivory coast' => 'CI',
        'côte d\'ivoire' => 'CI', 'swaziland' => 'SZ', 'burma' => 'MM', 'cabo verde' => 'CV', 'macedonia' => 'MK',
        'east timor' => 'TL', 'vatican' => 'VA', 'democratic republic of the congo' => 'CD', 'drc' => 'CD',
        'republic of the congo' => 'CG', 'méxico' => 'MX', 'brasil' => 'BR', 'españa' => 'ES', 'deutschland' => 'DE',
        'perú' => 'PE', 'panamá' => 'PA', 'curaçao' => 'CW', 'réunion' => 'RE', 'åland islands' => 'AX',
    ];

    /** @var array<string, string>|null lower-cased name or alias => code, built on first use */
    private static ?array $codes_by_name = null;

    /**
     * The ISO code a cell names: an exact code ("mx", "MX"), an English name
     * ("Mexico"), or a common alias ("USA"), with or without a leading flag
     * emoji. Null when it names no country.
     */
    public static function codeFor(string $raw): ?string
    {
        // A flag emoji is two regional indicator symbols, monday.com writes one before the name.
        $value = trim((string) preg_replace('/[\x{1F1E6}-\x{1F1FF}]/u', '', $raw));

        if ($value === '') {
            return null;
        }

        $upper = mb_strtoupper($value);
        if (strlen($upper) === 2 && isset(self::NAMES[$upper])) {
            return $upper;
        }

        return self::codesByName()[mb_strtolower($value)] ?? null;
    }

    /** True when `$raw` spells out a country's name or alias, as opposed to a bare two letter code. */
    public static function isCountryName(string $raw): bool
    {
        $value = trim((string) preg_replace('/[\x{1F1E6}-\x{1F1FF}]/u', '', $raw));

        return mb_strlen($value) > 2 && isset(self::codesByName()[mb_strtolower($value)]);
    }

    /**
     * @return array<string, string>
     */
    private static function codesByName(): array
    {
        if (self::$codes_by_name === null) {
            $codes = [];
            foreach (self::NAMES as $code => $name) {
                $codes[mb_strtolower($name)] = $code;
            }
            self::$codes_by_name = [...$codes, ...self::ALIASES];
        }

        return self::$codes_by_name;
    }
}
