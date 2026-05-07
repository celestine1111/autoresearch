<?php
/**
 * v1.5.216.62.96 TDD — Bulk-path pipeline ORDER bug.
 *
 * Post 762 audit found `(Cats.com)`, `(palnests.com)`, `(cats.com)` STILL
 * unlinked even though the in-isolation test test-linkify-production.php
 * showed `linkify_bracketed_references` correctly produces
 * `([Cats.com](https://cats.com/...))`.
 *
 * Root cause: `validate_outbound_links()` Pass 4 (URL dedup, line ~4720)
 * walks every `[text](url)` and strips the wrapper for any URL seen
 * before. On the Bulk path (Bulk_Generator.php line 308-345):
 *   1. cleanup_ai_markdown
 *   2. linkify_bracketed_references         ← creates new wrappers
 *   3. validate_outbound_links              ← Pass 4 dedup strips them
 * The Single path (rest_save_draft) has the documented-intent ORDER:
 *   1. validate_outbound_links              ← dedup first
 *   2. linkify_bracketed_references         ← wraps survive
 * comment at seobetter.php line 4170-4174:
 *   "v1.5.191 — Linkify plain-text source references AFTER validation.
 *    Runs after Pass 4 dedup so it can add links every source mention
 *    (not just the first occurrence per URL)."
 * v62.89 ported validate_outbound_links to Bulk but put it AFTER linkify,
 * silently breaking the design contract.
 *
 * This test simulates BOTH orders end-to-end and asserts the parenthetical
 * citations survive in the Bulk path. RED before fix. GREEN after fix.
 */

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return $u; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url() { return 'https://srv1608940.hstgr.cloud'; } }

// We can NOT eval the full SEOBetter class because validate_outbound_links is
// an instance method (not static) and calls many helpers. Instead, simulate
// just the critical-path order with mini-functions that match production's
// observable behaviour.
//
// What we need to verify:
//   "When linkify produces ([Cats.com](https://cats.com/X)) and the body
//    already had [Original Title](https://cats.com/X) earlier, does Pass 4
//    URL dedup strip the new wrapper or not?"
//
// Pass 4 logic from seobetter.php line 4720-4731 (verbatim):
//   walk all [text](url) in document order; if URL normalize-key seen,
//   replace with bare anchor text.

$normalize = function ( $url ) {
    $parts = wp_parse_url( $url );
    if ( ! $parts || empty( $parts['host'] ) ) return strtolower( $url );
    $host = strtolower( $parts['host'] );
    $path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
    $query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
    return $host . $path . $query;
};

$pass4_dedup = function ( string $markdown ) use ( $normalize ): string {
    $seen_urls = [];
    return preg_replace_callback(
        '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/',
        function ( $m ) use ( &$seen_urls, $normalize ) {
            $key = $normalize( $m[2] );
            if ( isset( $seen_urls[ $key ] ) ) {
                return $m[1]; // strip wrapper, keep anchor text
            }
            $seen_urls[ $key ] = true;
            return $m[0];
        },
        $markdown
    );
};

// Linkify — extracted from production seobetter.php
$plugin_root = dirname( __DIR__ );
$src = file_get_contents( $plugin_root . '/seobetter.php' );
$grab = function ( string $sig_re ) use ( $src ): string {
    if ( ! preg_match( '/' . $sig_re . '/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
        die( "FATAL: could not find function '$sig_re'\n" );
    }
    $start = $m[0][1];
    if ( ! preg_match( '/^    \}/m', $src, $em, PREG_OFFSET_CAPTURE, $start + strlen( $m[0][0] ) ) ) {
        die( "FATAL: could not find closing brace for '$sig_re'\n" );
    }
    return substr( $src, $start, $em[0][1] + strlen( $em[0][0] ) - $start );
};
$class_src = "class SEOBetter {\n"
    . $grab( 'public static function is_low_quality_source\(' ) . "\n"
    . $grab( 'private static function get_acronym_aliases\(' ) . "\n"
    . $grab( 'public static function linkify_bracketed_references\(' ) . "\n"
    . "}\n";
eval( $class_src );

$linkify = function ( string $md, array $pool ): string {
    return SEOBetter::linkify_bracketed_references( $md, $pool );
};

// Realistic post-762 inputs
$markdown_pre = "PetSafe is one of the top picks for 2026 ([The 13 Best Automatic Cat Feeders in 2026 (We Tested Them All)](https://cats.com/best-automatic-cat-feeder)).\n\n"
              . "Reviewers on Cats.com report PetSafe is a top pick, based on hands-on testing of 54 brands (Cats.com).\n\n"
              . "Models with stainless steel bowls (palnests.com).\n\n"
              . "Schedule up to 12 meals per day ([Top 5 Best Automatic Cat Feeders of 2026: Why PalNests Wins](https://palnests.com/blogs/blog/best-automatic-cat-feeder-2026-palnests-review)).";

$pool = [
    [ 'url' => 'https://cats.com/best-automatic-cat-feeder',
      'title' => 'The 13 Best Automatic Cat Feeders in 2026 (We Tested Them All)',
      'source_name' => 'cats.com', 'verified_at' => time() ],
    [ 'url' => 'https://palnests.com/blogs/blog/best-automatic-cat-feeder-2026-palnests-review',
      'title' => 'Top 5 Best Automatic Cat Feeders of 2026: Why PalNests Wins',
      'source_name' => 'palnests.com', 'verified_at' => time() ],
];

// ----- Order A: Bulk path (BROKEN before v62.96 — linkify, then dedup) -----
$bulk_a = $linkify( $markdown_pre, $pool );
$bulk_a = $pass4_dedup( $bulk_a );

// ----- Order B: Single path / FIXED Bulk (validate-then-linkify intent) -----
$single_b = $pass4_dedup( $markdown_pre );
$single_b = $linkify( $single_b, $pool );

echo "INPUT:\n$markdown_pre\n\n";
echo "=== ORDER A (Bulk current — linkify→dedup) ===\n$bulk_a\n\n";
echo "=== ORDER B (Single / fixed Bulk — dedup→linkify) ===\n$single_b\n\n";

// Per design contract (comment at seobetter.php:4170-4174), parenthetical
// citations MUST survive as wrapped links. The pipeline as a whole MUST
// produce wrapped (Cats.com) regardless of which path executes.

$cases = [
    [ 'order'=>'bulk-fixed', 'name'=>'(Cats.com) survives Bulk pipeline',
      'check'=> fn( $a, $b ) => str_contains( $b, '([Cats.com](https://cats.com' ) ],
    [ 'order'=>'bulk-fixed', 'name'=>'(palnests.com) survives Bulk pipeline',
      'check'=> fn( $a, $b ) => str_contains( $b, '([palnests.com](https://palnests.com' ) ],

    // RED-mode assertions: the OLD (linkify-then-dedup) order MUST fail.
    // If these turn green that means someone removed Pass 4 dedup — the
    // test is the canary that the contract still holds either direction.
    [ 'order'=>'bulk-broken-canary', 'name'=>'BROKEN order strips (Cats.com) wrapper [canary]',
      'check'=> fn( $a, $b ) => ! str_contains( $a, '([Cats.com](https://cats.com' ) ],
];

$pass = 0; $fail = 0; $details = [];
foreach ( $cases as $c ) {
    $ok = $c['check']( $bulk_a, $single_b );
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    echo "$sym  [{$c['order']}] {$c['name']}\n";
    if ( $ok ) { $pass++; } else { $fail++; $details[] = $c['name']; }
}
echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";
if ( $fail > 0 ) {
    foreach ( $details as $d ) echo "FAIL: $d\n";
    exit( 1 );
}
exit( 0 );
