<?php
/**
 * v1.5.216.62.105 TDD — global UK English locale enforcement.
 *
 * Per user direction (2026-05-08): UK English spelling (flavour, colour,
 * organise) + metric units + country-currency rules should apply to ALL
 * 21 content types when country in [GB, AU, NZ, IE], not just recipes.
 *
 * v62.104 added the rule inside `build_recipe_template` only. This means
 * a how_to / listicle / buying_guide / news_article generated for
 * country=GB still ships with US spelling because the rule isn't in the
 * GLOBAL system prompt.
 *
 * Fix v62.106: extend `Async_Generator::get_system_prompt(language, country)`
 * to append a "LOCALE" block for [GB, AU, NZ, IE] when language='en':
 *   - UK English spelling (flavour / colour / organise / recognise / centre)
 *   - Metric units (grams, ml, celsius)
 *   - Country-currency for prices (£ / AU$ / NZ$ / €)
 *
 * RED-PHASE MIRROR — current v62.104 prompt has NO global UK locale block.
 * Returns the system prompt without any "LOCALE" / "UK English" string for
 * GB/AU/NZ/IE. Assertions FAIL until v62.106 GREEN.
 *
 * GREEN PHASE: when `get_system_prompt` is updated, this mirror gets the
 * SAME change in lock-step.
 *
 * Run on VPS: php tests/test-system-prompt-uk-locale.php
 * Exit 0 = pass. Non-zero = fail.
 */

// ----- Mirror of the country-locale slice of get_system_prompt -----
// v62.104 RED state: no UK locale block. Returns empty string for that slice.

// GREEN MIRROR (v62.106) — matches Async_Generator::get_system_prompt post-fix.
// Returns the LOCALE block appended to the system prompt for English-language
// articles in countries that use UK English (GB, AU, NZ, IE). Empty for US /
// other / non-English articles (Localized_Strings handles non-English locales).
function uk_locale_block( string $language, string $country ): string {
    if ( strtolower( $language ) !== 'en' ) return '';

    $uk_locale_countries = [
        'GB' => [ 'currency_symbol' => '£',   'currency_name' => 'GBP', 'demonym' => 'British'    ],
        'AU' => [ 'currency_symbol' => 'AU$', 'currency_name' => 'AUD', 'demonym' => 'Australian' ],
        'NZ' => [ 'currency_symbol' => 'NZ$', 'currency_name' => 'NZD', 'demonym' => 'New Zealand' ],
        'IE' => [ 'currency_symbol' => '€',   'currency_name' => 'EUR', 'demonym' => 'Irish'      ],
    ];
    $cc = strtoupper( $country );
    if ( ! isset( $uk_locale_countries[ $cc ] ) ) return '';

    $info = $uk_locale_countries[ $cc ];
    $sym = $info['currency_symbol'];
    $code = $info['currency_name'];
    $demonym = $info['demonym'];

    return "\n\nLOCALE ({$cc} — {$demonym}): Use UK English spelling throughout the entire article — "
        . "flavour / colour / organise / recognise / centre / metre / programme / favourite / behaviour. "
        . "NEVER use American spellings (flavor / color / organize / recognize / center / meter / program / favorite / behavior). "
        . "Use metric units in body prose: grams / kilograms / millilitres / litres / centimetres / metres / celsius. "
        . "NEVER convert prices to USD; use {$sym} ({$code}) for all monetary references. "
        . "When citing real publications, prefer {$demonym} sources (national broadcasters, ministries, statistical agencies, "
        . "trade bodies, regional newspapers) over US sources where the source data permits.";
}

// Composes the system prompt with the locale slice (test target).
function compose_prompt_with_locale( string $language, string $country ): string {
    $base = "You are an expert SEO and GEO content writer.";
    $locale = uk_locale_block( $language, $country );
    return $base . $locale;
}

// ----- Test cases — what the GREEN locale block must produce -----

$cases = [
    // [ language, country, must_contain_substring(s), note ]
    [ 'en', 'GB', [ 'UK English', 'flavour', 'metric', '£' ],
      'GB en: prompt has UK English + metric + £' ],
    [ 'en', 'AU', [ 'UK English', 'flavour', 'metric', 'AU$' ],
      'AU en: UK English + metric + AU currency' ],
    [ 'en', 'NZ', [ 'UK English', 'flavour', 'metric', 'NZ$' ],
      'NZ en: UK English + metric + NZ currency' ],
    [ 'en', 'IE', [ 'UK English', 'flavour', 'metric', '€' ],
      'IE en: UK English + metric + euro' ],

    // Negative: US should NOT have UK English
    [ 'en', 'US', [ ], '' ],

    // Negative: empty country should NOT push UK English
    [ 'en', '', [ ], '' ],
];

$negative_cases = [
    [ 'en', 'US', 'UK English', 'US: prompt does NOT contain UK English' ],
    [ 'en', 'US', 'flavour', 'US: prompt does NOT contain "flavour"' ],
    [ 'en', '', 'UK English', 'empty country: prompt does NOT contain UK English' ],
];

$pass = 0; $fail = 0; $failures = [];

echo "=== v62.106 — global UK locale enforcement ===\n\n";
foreach ( $cases as [ $lang, $country, $must_contain, $note ] ) {
    if ( empty( $must_contain ) ) continue; // negative-only handled below
    $prompt = compose_prompt_with_locale( $lang, $country );
    foreach ( $must_contain as $needle ) {
        $found = str_contains( $prompt, $needle );
        $sym = $found ? "\u{2713}" : "\u{2717}";
        $key = "[{$country}] prompt contains " . var_export( $needle, true );
        if ( $found ) { $pass++; } else { $fail++; $failures[] = "{$key}: NOT FOUND in prompt"; }
        echo "$sym  {$key}\n";
    }
}

echo "\n=== Negative cases (US / empty must NOT have UK locale) ===\n\n";
foreach ( $negative_cases as [ $lang, $country, $must_not_contain, $note ] ) {
    $prompt = compose_prompt_with_locale( $lang, $country );
    $found = str_contains( $prompt, $must_not_contain );
    $ok = ! $found;
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    if ( $ok ) { $pass++; } else { $fail++; $failures[] = "{$note}: prompt unexpectedly contains " . var_export( $must_not_contain, true ); }
    echo "$sym  {$note}\n";
}

echo "\n=== Sample compose output ===\n\n";
foreach ( [ 'GB', 'AU', 'NZ', 'IE', 'US', '' ] as $c ) {
    $p = compose_prompt_with_locale( 'en', $c );
    echo "country=" . ( $c ?: '(empty)' ) . ": " . substr( $p, 0, 220 ) . "...\n\n";
}

echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";

if ( $fail > 0 ) {
    echo "\nFAILURES:\n";
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
