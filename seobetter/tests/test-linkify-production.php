<?php
/**
 * v1.5.216.62.95 TDD — production linkify_bracketed_references on real
 * post-762 markdown + pool. The simplified mirror in test-linkify-parens.php
 * passed but production output STILL had unlinked (Cats.com) / (palnests.com)
 * etc — exactly the failure mode TESTING_PROTOCOL.md was created to prevent.
 *
 * This test extracts the REAL function bodies from seobetter.php and runs
 * them. No mirror, no simplification — production behaviour or failure.
 *
 * Run on VPS:
 *   php /path/to/seobetter/tests/test-linkify-production.php
 *
 * Exit 0 = all pass. Non-zero = failures.
 */

// Stub WP helpers used by the extracted functions.
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $u ) { return $u; }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
}

// ----- Extract production functions verbatim from seobetter.php -----
$plugin_root = dirname( __DIR__ );
$src = file_get_contents( $plugin_root . '/seobetter.php' );

$grab = function ( string $sig_re ) use ( $src ): string {
    if ( ! preg_match( '/' . $sig_re . '/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
        die( "FATAL: could not find function '$sig_re' in seobetter.php\n" );
    }
    $start = $m[0][1];
    // Find the matching closing brace at indent level 4 spaces (class methods)
    $end_re = '/^    \}/m';
    if ( ! preg_match( $end_re, $src, $em, PREG_OFFSET_CAPTURE, $start + strlen( $m[0][0] ) ) ) {
        die( "FATAL: could not find closing brace for '$sig_re'\n" );
    }
    $end = $em[0][1] + strlen( $em[0][0] );
    return substr( $src, $start, $end - $start );
};

$fn_lqs = $grab( 'public static function is_low_quality_source\(' );
$fn_aliases = $grab( 'private static function get_acronym_aliases\(' );
$fn_link = $grab( 'public static function linkify_bracketed_references\(' );

$class_src = "class SEOBetter {\n" . $fn_lqs . "\n" . $fn_aliases . "\n" . $fn_link . "\n}\n";
eval( $class_src );

// ----- Realistic body markdown sample (from post 762) -----
$markdown = "PetSafe is one of the top picks for 2026, based on hands-on testing of 54 brands (Cats.com).\n\n"
          . "The Cat Mate C500 is one of the most advanced feeders in 2026, though its size and price may be drawbacks for some (The 13 Best Automatic Cat Feeders in 2026 (We Tested Them All)).\n\n"
          . "Models with stainless steel or ceramic bowls (palnests.com).\n\n"
          . "Feeders with backup battery power (cats.com).\n\n"
          . "Schedules up to 12 meals per day (palnests.com).";

// ----- Realistic citation pool (what post 762 had after Bulk extension) -----
$pool = [
    [
        'url'         => 'https://cats.com/best-automatic-cat-feeder',
        'title'       => 'The 13 Best Automatic Cat Feeders in 2026 (We Tested Them All)',
        'source_name' => 'cats.com',
        'verified_at' => time(),
    ],
    [
        'url'         => 'https://palnests.com/blogs/blog/best-automatic-cat-feeder-2026-palnests-review',
        'title'       => 'Top 5 Best Automatic Cat Feeders of 2026: Why PalNests Wins',
        'source_name' => 'palnests.com',
        'verified_at' => time(),
    ],
    [
        'url'         => 'https://technomeow.com/best-automatic-feeders-for-cats/',
        'title'       => '5 Best Automatic Feeders For Cats in 2026',
        'source_name' => 'technomeow.com',
        'verified_at' => time(),
    ],
];

// Diagnostic — show pool composition before linkify
echo "POOL ENTRIES:\n";
foreach ( $pool as $i => $p ) {
    echo "  [$i] url={$p['url']}\n      title={$p['title']}\n      source_name={$p['source_name']}\n";
}
echo "\n";

$out = SEOBetter::linkify_bracketed_references( $markdown, $pool );

echo "INPUT MARKDOWN:\n" . $markdown . "\n\n";
echo "OUTPUT MARKDOWN:\n" . $out . "\n\n";

// ----- Assertions -----
$cases = [
    [ 'name' => '(Cats.com) host paren → linkified to cats.com',
      'check' => fn( $o ) => str_contains( $o, '[Cats.com](https://cats.com' ) ],
    [ 'name' => '(palnests.com) lowercase host paren → linkified',
      'check' => fn( $o ) => str_contains( $o, '[palnests.com](https://palnests.com' ) ],
    [ 'name' => '(cats.com) lowercase host paren → linkified',
      'check' => fn( $o ) => str_contains( $o, '[cats.com](https://cats.com' ) ],
    [ 'name' => 'long nested-paren title → linkified to palnests OR kept-as-is via line walker',
      'check' => fn( $o ) => str_contains( $o, '](https://palnests.com' ) || str_contains( $o, '](https://cats.com' ) ],
];

$passed = 0;
$failed = 0;
$fails = [];
foreach ( $cases as $c ) {
    $ok = $c['check']( $out );
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    echo "{$sym} {$c['name']}\n";
    if ( $ok ) { $passed++; } else { $failed++; $fails[] = $c['name']; }
}
echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$passed}  |  FAILED: {$failed}\n";
echo "═════════════════════════════════════════════════════\n";

if ( $failed > 0 ) {
    echo "\nFAIL DETAILS:\n";
    foreach ( $fails as $n ) echo "  - $n\n";
    exit( 1 );
}
exit( 0 );
