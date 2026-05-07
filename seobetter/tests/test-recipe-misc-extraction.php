<?php
/**
 * v1.5.216.62.100 TDD — three bugs found via post 774 audit:
 *
 *   Bug A: rendered HTML had 2 <h1> tags — body content includes
 *          <h1>Homemade Cornish Pasty Recipe</h1> in addition to the
 *          theme-rendered post-title <h1>. Fix: Content_Formatter must
 *          demote any body-content <h1> to <h2> before rendering.
 *
 *   Bug B: Recipe[0].recipeCuisine was missing on post 774 even though
 *          country=GB. Schema_Generator::build_recipe() relies on
 *          get_post_meta(_seobetter_country) which can be empty when
 *          AS-driven Bulk path schema is rendered before meta is
 *          finalized. Fix: body-keyword fallback — if cuisine is empty
 *          and body contains country-cuisine markers (Cornish/British/
 *          Italian/etc.), set recipeCuisine accordingly.
 *
 *   Bug C: Recipe[0].recipeCategory was missing. The v62.96 regex at
 *          Schema_Generator.php:1369 matches "pastry" but the post
 *          body (cornish pasty article) uses "pasty"/"pasties" and
 *          "cornish pasty". Fix: extend the category regex to include
 *          "pasty"/"pasties" → "Pastry".
 *
 * Run on VPS: php tests/test-recipe-misc-extraction.php
 * Exit 0 = pass, non-zero = fail.
 */

// ----- Helper: Bug A — demote body <h1> to <h2> -----
// v62.100 NEW: demote in BOTH HTML <h1> and markdown # forms.
function demote_body_h1( string $html ): string {
    // HTML form: <h1 [attrs]>X</h1>
    $html = preg_replace_callback(
        '/<h1\b([^>]*)>(.*?)<\/h1>/is',
        function ( $m ) {
            // preserve attrs but replace tag
            return '<h2' . $m[1] . '>' . $m[2] . '</h2>';
        },
        $html
    );
    // Markdown form: ^# X (single # at start of line — ATX heading 1)
    // Preserve ## (h2), ### (h3), etc.
    $html = preg_replace( '/^#\s+(.+)$/m', '## $1', $html );
    return $html;
}

// ----- Helper: Bug B — body-keyword cuisine fallback -----
// v62.100 NEW: if recipeCuisine is empty, scan body for country-cuisine markers.
function detect_cuisine_fallback( string $body_text, string $country, string $existing_cuisine ): string {
    if ( $existing_cuisine !== '' ) return $existing_cuisine;

    // Country code → cuisine map (mirrors Schema_Generator's $cuisine_map)
    $country_to_cuisine = [
        'AU' => 'Australian', 'US' => 'American', 'GB' => 'British', 'FR' => 'French',
        'IT' => 'Italian', 'JP' => 'Japanese', 'IN' => 'Indian', 'MX' => 'Mexican',
        'TH' => 'Thai', 'CN' => 'Chinese', 'KR' => 'Korean', 'ES' => 'Spanish',
        'DE' => 'German', 'BR' => 'Brazilian', 'GR' => 'Greek', 'TR' => 'Turkish',
        'VN' => 'Vietnamese', 'IE' => 'Irish', 'NZ' => 'New Zealand',
    ];
    if ( isset( $country_to_cuisine[ strtoupper( $country ) ] ) ) {
        return $country_to_cuisine[ strtoupper( $country ) ];
    }

    // Body-keyword detection — country-cuisine words mentioned in prose
    $body_keywords = [
        'British'    => '/\b(British|Cornish|English|Welsh|Scottish|Britain|UK)\b/i',
        'Italian'    => '/\bItalian\b/i',
        'French'     => '/\bFrench\b/i',
        'Mexican'    => '/\bMexican\b/i',
        'Japanese'   => '/\bJapanese\b/i',
        'Chinese'    => '/\bChinese\b/i',
        'Indian'     => '/\bIndian\b/i',
        'Thai'       => '/\bThai\b/i',
        'Korean'     => '/\bKorean\b/i',
        'Spanish'    => '/\bSpanish\b/i',
        'Greek'      => '/\bGreek\b/i',
        'American'   => '/\bAmerican\b/i',
        'Australian' => '/\bAustralian|Aussie\b/i',
    ];
    foreach ( $body_keywords as $cuisine => $rx ) {
        if ( preg_match( $rx, $body_text ) ) return $cuisine;
    }
    return '';
}

// ----- Helper: Bug C — extend category regex with "pasty"/"pasties" -----
function detect_category_with_pasty( string $body_text, string $heading, string $existing_category ): string {
    if ( $existing_category !== '' ) return $existing_category;

    // v62.100 — extended pattern adds: pasty/pasties (UK savory pies),
    // pancake, scone, muffin, cookie, brownie, cupcake, cheesecake, crumble.
    // Must precede the existing v62.96 pattern (which matches "pastry" but
    // not "pasty"). Ordered to prefer specific over generic.
    if ( preg_match( '/\b(pasty|pasties|pancake|scone|muffin|cookie|brownie|cupcake|cheesecake|crumble)\b/i', $body_text, $m ) ) {
        return 'Pastry';
    }
    if ( preg_match( '/\b(treat|snack|meal|drink|dessert|breakfast|dinner|lunch|side dish|appetizer|main course|biscuit|bread|cake|pastry|pie|soup|broth|stock|stew|salad|sauce|dip|smoothie|cocktail)\b/i', $body_text, $m ) ) {
        return ucfirst( strtolower( $m[1] ) );
    }
    return '';
}

// ----- Test cases -----

$cases_h1 = [
    [ '<h1 class="wp-block-heading">Homemade Cornish Pasty Recipe</h1>',
      false, 'body h1 demoted (post-774 case)' ],
    [ '<p>Intro text</p><h1>Some title</h1><p>more</p>',
      false, 'inline h1 demoted' ],
    [ '<h2>Already h2</h2>',
      true, 'h2 untouched' ],
    [ "# Body markdown h1\n\nMore text",
      false, 'markdown # demoted to ##' ],
    [ "## Body markdown h2\n\nMore",
      true, 'markdown ## untouched' ],
    [ '<p>Plain paragraph with no headings</p>',
      true, 'no headings — no change' ],
];

$cases_cuisine = [
    // [ body_text, country, existing_cuisine, expected_output, note ]
    [ 'Authentic British cornish pasty with traditional steak filling.', 'GB', '', 'British', 'GB country sets British directly' ],
    [ 'Authentic British cornish pasty with traditional steak filling.', '',   '', 'British', 'no country, but Cornish keyword → British via body fallback' ],
    [ 'Italian sourdough pizza dough.', '', '', 'Italian', 'no country, Italian keyword → Italian' ],
    [ 'A simple bake with butter.', '', '', '', 'no country, no keyword → empty' ],
    [ 'A simple bake.', 'US', '', 'American', 'US country sets American (preserves existing v62.96 logic)' ],
    [ 'A simple bake.', 'GB', 'Italian', 'Italian', 'existing cuisine respected — no override' ],
];

$cases_category = [
    [ 'Make this delicious cornish pasty with savory beef filling.', 'Cornish Pasty Recipe 1', '', 'Pastry', 'pasty keyword → Pastry (post-774 bug)' ],
    [ 'These mini pasties are perfect for parties.', 'Recipe 2', '', 'Pastry', 'pasties plural → Pastry' ],
    [ 'A flaky pastry envelope wraps the filling.', 'Recipe 3', '', 'Pastry', 'pastry keyword (existing v62.96 covered)' ],
    [ 'A simple meal for weeknights.', 'Recipe 4', '', 'Meal', 'meal keyword (v62.96 covered, regression check)' ],
    [ 'A hearty stew with vegetables.', 'Recipe 5', '', 'Stew', 'stew keyword (v62.96 covered)' ],
    [ 'Body text without category words.', 'Recipe 6', 'Existing', 'Existing', 'existing respected' ],
];

$pass = 0; $fail = 0;
$failures = [];

echo "=== Test A: body h1 demotion ===\n\n";
foreach ( $cases_h1 as [ $input, $expect_unchanged, $note ] ) {
    $out = demote_body_h1( $input );
    $unchanged = ( $out === $input );
    $has_h1 = (bool) preg_match( '/<h1\b/i', $out ) || (bool) preg_match( '/^#\s+/m', $out );
    $expected_no_h1 = ! $expect_unchanged;
    $ok = $expected_no_h1 ? ! $has_h1 : ( $expect_unchanged === $unchanged );
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    if ( $ok ) { $pass++; } else { $fail++; $failures[] = "[h1] {$note}: expected " . ( $expect_unchanged ? 'unchanged' : 'h1 removed' ) . ", got " . ( $unchanged ? 'unchanged' : ( $has_h1 ? 'still has h1' : 'h1 removed OK' ) ); }
    echo "$sym  {$note}\n     IN:  " . substr( $input, 0, 80 ) . "\n     OUT: " . substr( $out, 0, 80 ) . "\n\n";
}

echo "\n=== Test B: cuisine fallback ===\n\n";
foreach ( $cases_cuisine as [ $body, $country, $existing, $expected, $note ] ) {
    $out = detect_cuisine_fallback( $body, $country, $existing );
    $ok = ( $out === $expected );
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    if ( $ok ) { $pass++; } else { $fail++; $failures[] = "[cuisine] {$note}: expected " . var_export( $expected, true ) . " got " . var_export( $out, true ); }
    echo "$sym  {$note}: country=$country existing=" . var_export( $existing, true ) . " → " . var_export( $out, true ) . " (expected " . var_export( $expected, true ) . ")\n";
}

echo "\n=== Test C: category regex with pasty ===\n\n";
foreach ( $cases_category as [ $body, $heading, $existing, $expected, $note ] ) {
    $out = detect_category_with_pasty( $body, $heading, $existing );
    $ok = ( $out === $expected );
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    if ( $ok ) { $pass++; } else { $fail++; $failures[] = "[category] {$note}: expected " . var_export( $expected, true ) . " got " . var_export( $out, true ); }
    echo "$sym  {$note}: → " . var_export( $out, true ) . " (expected " . var_export( $expected, true ) . ")\n";
}

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";

if ( $fail > 0 ) {
    echo "\nFAILURE DETAILS:\n";
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
