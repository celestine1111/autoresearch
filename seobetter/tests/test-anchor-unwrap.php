<?php
/**
 * Standalone test — verify v1.5.216.62.78 bad-anchor unwrap logic.
 *
 * Run on the VPS:
 *   ssh user@srv1608940.hstgr.cloud
 *   php /path/to/wp-content/plugins/seobetter/tests/test-anchor-unwrap.php
 *
 * Exit 0 = all pass. Non-zero = failures (see output).
 *
 * NO WordPress dependency — pure regex + closure behavior test.
 * If this passes but bugs still ship in production, the issue is
 * elsewhere in the pipeline (OPcache / different code path / etc).
 */

// ----- The check_anchor_quality closure from seobetter.php v62.78 -----

$check_anchor_quality = function ( string $anchor ) {
    $clean = trim( strip_tags( $anchor ) );
    $alphanum = preg_replace( '/[^a-z0-9]/i', '', $clean );
    $letters_only = preg_replace( '/[^a-z]/i', '', $clean );
    $unique_letters = count( array_unique( str_split( strtolower( $letters_only ) ) ) );

    if ( preg_match( '/^[\d.,\s\'"\x{2018}-\x{201F}″"&#;]+$/u', $clean ) ) return false;
    if ( strlen( $alphanum ) <= 3 ) return false;
    if ( preg_match( '/^(?:wikipedia|wiki|link|here|this|that|site|source|page|article|read|more|click|see|view|details|info)$/i', trim( $clean, ' .,;:!?' ) ) ) return false;
    if ( $unique_letters < 4 ) return false;
    return true;
};

// ----- Test cases: [input_anchor, expected_pass (true=keep link, false=unwrap)] -----

$cases = [
    // Should unwrap (false expected)
    [ 'M4, 2024',     false, 'mixed letter+digit fragment, 1 unique letter' ],
    [ '2024',         false, 'pure numeric year' ],
    [ '2023',         false, 'pure numeric year' ],
    [ '14',           false, 'too short (≤3 alphanum)' ],
    [ '14"',          false, 'too short with quote' ],
    [ '9530',         false, 'pure numeric model number' ],
    [ 'M4',           false, 'too short, 1 letter' ],
    [ 'Wikipedia',    false, 'generic single word' ],
    [ 'here',         false, 'generic single word' ],
    [ '2-tb',         false, 'too few unique letters' ],

    // v62.79 — multi-word generic phrases (regex extended to catch these)
    [ 'click here',   false, 'v62.79 generic 2-word phrase' ],
    [ 'read more',    false, 'v62.79 generic 2-word phrase' ],
    [ 'see more',     false, 'v62.79 generic 2-word phrase' ],
    [ 'view all',     false, 'v62.79 generic 2-word phrase' ],
    [ 'view more',    false, 'v62.79 generic 2-word phrase' ],
    [ 'view details', false, 'v62.79 generic 2-word phrase' ],
    [ 'learn more',   false, 'v62.79 generic 2-word phrase' ],

    // v62.79 — unique-letter threshold tightened from <4 to <5 (catches spec fragments)
    [ '10-core',      false, 'v62.79: 4 unique letters (c,o,r,e) — spec fragment, not source name' ],
    [ '4K HDR',       false, 'v62.79: 4 unique letters (k,h,d,r) — spec fragment, not source name' ],
    [ 'M.2 SSD',      false, '3 unique letters (m,s,d)' ],

    // Should KEEP (true expected) — real source names, ≥5 unique letters
    [ 'NanoReview',          true,  '8 unique letters' ],
    [ 'wired.com',           true,  '8 unique letters' ],
    [ 'Dell XPS 15',         true,  '6 unique letters (d,e,l,x,p,s)' ],
    [ 'MacBook Pro M4',      true,  'real product name' ],
    [ 'Tom\'s Hardware',     true,  'real source name' ],
    [ 'TechRadar',           true,  'real source name' ],
    [ 'Bank of Canada',      true,  'real institution name (caveat: still subject to v62.75 institution-attribution ban via different filter)' ],
];

// ----- Run each case -----

$passed = 0;
$failed = 0;
$failures = [];

foreach ( $cases as [ $anchor, $expected, $note ] ) {
    $actual = $check_anchor_quality( $anchor );
    $status = $actual === $expected ? '✓' : '✗';
    if ( $actual === $expected ) {
        $passed++;
    } else {
        $failed++;
        $failures[] = [
            'anchor'   => $anchor,
            'expected' => $expected ? 'KEEP link' : 'UNWRAP',
            'actual'   => $actual ? 'KEEP link' : 'UNWRAP',
            'note'     => $note,
        ];
    }
    echo sprintf( "%s  %-25s  %s -> %s  (%s)\n",
        $status,
        '"' . $anchor . '"',
        $expected ? 'KEEP' : 'UNWRAP',
        $actual ? 'KEEP' : 'UNWRAP',
        $note
    );
}

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$passed}  |  FAILED: {$failed}\n";
echo "═════════════════════════════════════════════════════\n";

if ( $failed > 0 ) {
    echo "\nFAILURE DETAILS:\n";
    foreach ( $failures as $f ) {
        echo "  Anchor: \"{$f['anchor']}\"\n";
        echo "    Expected: {$f['expected']}\n";
        echo "    Actual:   {$f['actual']}\n";
        echo "    Note:     {$f['note']}\n\n";
    }
    exit( 1 );
}

// ----- Test the regex against full markdown / HTML inputs -----

echo "\n--- Regex pass/fail on full markdown/HTML link patterns ---\n\n";

$regex_cases = [
    // Markdown form
    [ 'markdown', '[M4, 2024](https://nanoreview.net/foo)', 'M4, 2024',       'M4, 2024' ],
    [ 'markdown', '[2023](https://nanoreview.net/foo)',     '2023',           '2023' ],
    [ 'markdown', '[NanoReview](https://nanoreview.net/x)', 'NanoReview',     '[NanoReview](https://nanoreview.net/x)' ],
    // HTML form (post-Content_Formatter)
    [ 'html',     '<a href="https://nanoreview.net/foo" target=_blank rel="noopener nofollow">M4, 2024</a>', 'M4, 2024', 'M4, 2024' ],
    [ 'html',     '<a href="https://nanoreview.net/x" target=_blank>NanoReview</a>',                          'NanoReview', '<a href="https://nanoreview.net/x" target=_blank>NanoReview</a>' ],
    // Markdown link inside parens (linkify-output shape: `(M4, 2024)` → `([M4, 2024](url))`)
    [ 'markdown-in-parens', '([M4, 2024](https://nanoreview.net/foo))', 'M4, 2024', '(M4, 2024)' ],
];

foreach ( $regex_cases as [ $mode, $input, $anchor_text, $expected_output ] ) {
    if ( $mode === 'markdown' || $mode === 'markdown-in-parens' ) {
        $output = preg_replace_callback(
            '/(?<!!)\[([^\]]{1,40})\]\((https?:\/\/[^)]+)\)/',
            function ( $m ) use ( $check_anchor_quality ) {
                return $check_anchor_quality( $m[1] ) ? $m[0] : trim( $m[1] );
            },
            $input
        );
    } else { // html
        $output = preg_replace_callback(
            '/<a\s[^>]*href="(https?:\/\/[^"]+)"[^>]*>([^<]{1,40})<\/a>/i',
            function ( $m ) use ( $check_anchor_quality ) {
                return $check_anchor_quality( $m[2] ) ? $m[0] : trim( $m[2] );
            },
            $input
        );
    }

    $status = $output === $expected_output ? '✓' : '✗';
    if ( $output !== $expected_output ) {
        $failed++;
        $failures[] = [ 'input' => $input, 'expected' => $expected_output, 'actual' => $output ];
    } else {
        $passed++;
    }
    echo sprintf( "%s  [%s]\n", $status, $mode );
    echo sprintf( "    INPUT:    %s\n", $input );
    echo sprintf( "    EXPECTED: %s\n", $expected_output );
    echo sprintf( "    ACTUAL:   %s\n\n", $output );
}

echo "═════════════════════════════════════════════════════\n";
echo " FINAL  PASSED: {$passed}  |  FAILED: {$failed}\n";
echo "═════════════════════════════════════════════════════\n";

exit( $failed > 0 ? 1 : 0 );
