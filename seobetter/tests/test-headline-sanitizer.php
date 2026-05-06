<?php
/**
 * Standalone test — verify v1.5.216.62.80 headline sanitizer rejects
 * citation-echo titles passed via rest_save_draft.
 *
 * Bug A from v62.79 audit: H1 was "Apple MacBook Pro (2024) 14\" · M4
 * (10-core CPU) vs Dell XPS 15 …" — a versus.com citation source page
 * title echoed verbatim, not a generated comparison headline. Slug
 * percolated to %c2%b7 (middle dot).
 *
 * Sanitizer rules:
 *   - Strip trailing ellipsis (…, ..., truncation indicators)
 *   - Replace middle dots (·) with hyphens (cleaner ASCII slug)
 *   - If trimmed title is empty after sanitizing, fall back to keyword
 *   - If title matches any pool entry's title verbatim, reject and fall back
 *
 * Run on VPS:  php /path/to/.../tests/test-headline-sanitizer.php
 */

// ----- Mirror of the v62.80 production sanitizer logic -----
// Keep in sync with seobetter.php::sanitize_headline() helper.
function sanitize_headline( string $title, string $fallback_keyword, array $pool = [] ): string {
    $title = trim( $title );

    // (1) Reject if title matches any pool entry's title verbatim — citation echo
    foreach ( $pool as $entry ) {
        $entry_title = trim( (string) ( $entry['title'] ?? '' ) );
        if ( $entry_title !== '' && $title === $entry_title ) {
            return ucwords( $fallback_keyword );
        }
    }

    // (2) Strip trailing ellipsis variants
    $title = preg_replace( '/\s*(?:\x{2026}|\.{3,})\s*$/u', '', $title );

    // (3) Replace middle dot interpunct with hyphen
    $title = preg_replace( '/\s*·\s*/u', ' - ', $title );

    // (4) Collapse multi-spaces left over from substitutions
    $title = preg_replace( '/\s+/', ' ', trim( $title ) );

    // (5) Empty after sanitizing → keyword fallback
    if ( $title === '' ) {
        return ucwords( $fallback_keyword );
    }

    return $title;
}

$pool = [
    [ 'url' => 'https://versus.com/x', 'title' => 'Apple MacBook Pro (2024) 14" · M4 (10-core CPU) vs Dell XPS 15 …' ],
    [ 'url' => 'https://nanoreview.net/x', 'title' => 'Dell XPS 15 9530 (2023) vs Apple MacBook Pro 14 (M4, 2024)' ],
];

$cases = [
    // [input_title, fallback_keyword, pool, expected_output, note]
    [
        'Apple MacBook Pro (2024) 14" · M4 (10-core CPU) vs Dell XPS 15 …',
        'macbook pro m4 vs dell xps 15',
        $pool,
        'Macbook Pro M4 Vs Dell Xps 15',
        'v62.80: exact citation echo → fall back to keyword',
    ],
    [
        'MacBook Pro M4 vs Dell XPS 15: Which Wins for Pros?',
        'macbook pro m4 vs dell xps 15',
        $pool,
        'MacBook Pro M4 vs Dell XPS 15: Which Wins for Pros?',
        'real generated headline kept as-is',
    ],
    [
        'Best Laptops 2026 …',
        'best laptops',
        [],
        'Best Laptops 2026',
        'trailing ellipsis stripped',
    ],
    [
        'Best Laptops 2026...',
        'best laptops',
        [],
        'Best Laptops 2026',
        'trailing 3-dot ellipsis stripped',
    ],
    [
        'iPhone 16 · Review',
        'iphone 16 review',
        [],
        'iPhone 16 - Review',
        'middle dot replaced with hyphen',
    ],
    [
        'Apple MacBook Pro · M4 …',
        'macbook pro',
        [],
        'Apple MacBook Pro - M4',
        'middle dot AND trailing ellipsis both cleaned',
    ],
    [
        '   …',
        'home compost bin',
        [],
        'Home Compost Bin',
        'whitespace + ellipsis only → keyword fallback',
    ],
    [
        '',
        'review of x',
        [],
        'Review Of X',
        'empty input → keyword fallback',
    ],
];

$passed = 0;
$failed = 0;
$failures = [];

foreach ( $cases as [ $input, $kw, $pool_arg, $expected, $note ] ) {
    $actual = sanitize_headline( $input, $kw, $pool_arg );
    $status = $actual === $expected ? '✓' : '✗';
    if ( $actual === $expected ) {
        $passed++;
    } else {
        $failed++;
        $failures[] = [ 'input' => $input, 'expected' => $expected, 'actual' => $actual, 'note' => $note ];
    }
    echo sprintf( "%s  IN:  %s\n   OUT: %s\n   (%s)\n\n",
        $status,
        $input === '' ? '(empty)' : $input,
        $actual,
        $note
    );
}

echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$passed}  |  FAILED: {$failed}\n";
echo "═════════════════════════════════════════════════════\n";

if ( $failed > 0 ) {
    echo "\nFAILURE DETAILS:\n";
    foreach ( $failures as $f ) {
        echo "  Input:    \"{$f['input']}\"\n";
        echo "  Expected: \"{$f['expected']}\"\n";
        echo "  Actual:   \"{$f['actual']}\"\n";
        echo "  Note:     {$f['note']}\n\n";
    }
    exit( 1 );
}

exit( 0 );
