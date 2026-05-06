<?php
/**
 * Standalone test — verify v1.5.216.62.80 references-builder fallback chain
 * correctly skips empty title strings (not just null/missing).
 *
 * Pre-fix: line 2233 used `?? ?? ??` which only catches null. Pool entries
 * with `title => ''` (empty string) bypassed every fallback and rendered
 * as `<a>EMPTY</a>` in the References list (Bug B from v62.79 audit:
 * 2 wired.com refs in the MacBook Pro Comparison article).
 *
 * Run on the VPS:
 *   php /path/to/wp-content/plugins/seobetter/tests/test-references-builder.php
 *
 * Exit 0 = all pass.
 */

// ----- Mirror of the v62.80 production fallback logic -----
// Keep in sync with seobetter.php::rest_save_draft() line ~2233.
function pick_reference_title( array $entry ): string {
    foreach ( [ 'title', 'source_name' ] as $key ) {
        $candidate = trim( (string) ( $entry[ $key ] ?? '' ) );
        if ( $candidate !== '' ) return $candidate;
    }
    $url = (string) ( $entry['url'] ?? '' );
    if ( $url === '' ) return 'Source';
    $host = function_exists( 'wp_parse_url' )
        ? @wp_parse_url( $url, PHP_URL_HOST )
        : @parse_url( $url, PHP_URL_HOST );
    return is_string( $host ) && $host !== '' ? $host : $url;
}

// ----- Test cases -----
$cases = [
    // [entry, expected_title, note]
    [
        [ 'url' => 'https://wired.com/story/x/', 'title' => '', 'source_name' => '' ],
        'wired.com',
        'v62.80: empty title AND empty source_name → fall back to host',
    ],
    [
        [ 'url' => 'https://wired.com/story/x/', 'title' => null, 'source_name' => null ],
        'wired.com',
        'null title and source_name → fall back to host',
    ],
    [
        [ 'url' => 'https://wired.com/story/x/', 'title' => 'Best Laptops 2026' ],
        'Best Laptops 2026',
        'real title kept',
    ],
    [
        [ 'url' => 'https://wired.com/story/x/', 'title' => '', 'source_name' => 'Wired' ],
        'Wired',
        'empty title falls through to source_name',
    ],
    [
        [ 'url' => 'https://wired.com/story/x/', 'title' => '   ' ],
        'wired.com',
        'whitespace-only title is trimmed and falls through',
    ],
    [
        [ 'url' => '', 'title' => '' ],
        'Source',
        'no url → "Source" placeholder',
    ],
];

$passed = 0;
$failed = 0;
$failures = [];

foreach ( $cases as [ $entry, $expected, $note ] ) {
    $actual = pick_reference_title( $entry );
    $status = $actual === $expected ? '✓' : '✗';
    if ( $actual === $expected ) {
        $passed++;
    } else {
        $failed++;
        $failures[] = [ 'entry' => $entry, 'expected' => $expected, 'actual' => $actual, 'note' => $note ];
    }
    echo sprintf( "%s  %-40s  %s\n", $status, '"' . $expected . '"', $note );
}

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$passed}  |  FAILED: {$failed}\n";
echo "═════════════════════════════════════════════════════\n";

if ( $failed > 0 ) {
    echo "\nFAILURE DETAILS:\n";
    foreach ( $failures as $f ) {
        echo "  Entry:    " . json_encode( $f['entry'] ) . "\n";
        echo "  Expected: \"{$f['expected']}\"\n";
        echo "  Actual:   \"{$f['actual']}\"\n";
        echo "  Note:     {$f['note']}\n\n";
    }
    exit( 1 );
}

exit( 0 );
