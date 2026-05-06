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

// ----- Mirror of the v62.81 production sanitizer logic -----
// Keep in sync with seobetter.php::sanitize_headline() helper.

function brand_caps( string $title ): string {
    $tokens = [
        'macbook' => 'MacBook', 'iphone' => 'iPhone', 'ipad' => 'iPad',
        'airpods' => 'AirPods', 'imac' => 'iMac', 'xps' => 'XPS',
        'cpu' => 'CPU', 'gpu' => 'GPU', 'usb' => 'USB', 'ssd' => 'SSD',
        'ram' => 'RAM', 'hdr' => 'HDR', 'led' => 'LED', 'oled' => 'OLED',
        'lcd' => 'LCD', 'tv' => 'TV', 'pc' => 'PC', 'api' => 'API',
        'ios' => 'iOS', 'macos' => 'macOS',
    ];
    $title = preg_replace_callback(
        '/\b(' . implode( '|', array_keys( $tokens ) ) . ')\b/i',
        function ( $m ) use ( $tokens ) { return $tokens[ strtolower( $m[1] ) ]; },
        $title
    );
    $title = preg_replace( '/\bVs\b/', 'vs', $title );
    return $title;
}

function normalize_for_compare( string $s ): string {
    $s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $s = preg_replace( '/\s*[·–—|‐\-]\s*/u', ' - ', $s );
    $s = preg_replace( '/\s*(?:\x{2026}|\.{3,})\s*$/u', '', $s );
    $s = preg_replace( '/\s+/u', ' ', trim( $s ) );
    return mb_strtolower( $s );
}

function clean_title_universal( string $title ): string {
    $title = trim( $title );
    $title = preg_replace( '/\s*(?:\x{2026}|\.{3,})\s*$/u', '', $title );
    $title = preg_replace( '/\s*·\s*/u', ' - ', $title );
    if ( mb_strlen( $title ) > 50 ) {
        $title = preg_replace(
            '/\s+[·–—|‐\-]\s+[A-Za-z][A-Za-z0-9.\s]{1,40}\s*$/u',
            '',
            $title
        );
    }
    $title = preg_replace( '/\s+/u', ' ', trim( $title ) );
    if ( mb_strlen( $title ) > 60 ) {
        $cut = mb_substr( $title, 0, 60 );
        $last_space = mb_strrpos( $cut, ' ' );
        $title = $last_space !== false && $last_space > 30
            ? mb_substr( $cut, 0, $last_space )
            : $cut;
        $title = rtrim( $title, ' .,;:!?-—–' );
    }
    return $title;
}

function sanitize_headline( string $title, string $fallback_keyword, array $pool = [] ): string {
    $title = trim( $title );
    $normalized_title = normalize_for_compare( $title );
    $is_echo = false;
    foreach ( $pool as $entry ) {
        $entry_title = trim( (string) ( $entry['title'] ?? '' ) );
        if ( $entry_title === '' ) continue;
        if ( normalize_for_compare( $entry_title ) === $normalized_title ) {
            $is_echo = true;
            break;
        }
    }
    if ( $is_echo ) {
        $fallback = clean_title_universal( $fallback_keyword );
        return brand_caps( ucwords( $fallback ) );
    }
    $cleaned = clean_title_universal( $title );
    if ( $cleaned === '' ) {
        $fallback = clean_title_universal( $fallback_keyword );
        return brand_caps( ucwords( $fallback ) );
    }
    return $cleaned;
}

$pool = [
    [ 'url' => 'https://versus.com/x', 'title' => 'Apple MacBook Pro (2024) 14" · M4 (10-core CPU) vs Dell XPS 15 …' ],
    [ 'url' => 'https://nanoreview.net/x', 'title' => 'Dell XPS 15 9530 (2023) vs Apple MacBook Pro 14 (M4, 2024)' ],
    [ 'url' => 'https://tech.hindustantimes.com/x', 'title' => 'Dell Xps 15 2023 vs Macbook Pro 16 Inch M4 Pro 2024 &#8211; HT Tech' ],
];

$cases = [
    // [input_title, fallback_keyword, pool, expected_output, note]
    [
        'Apple MacBook Pro (2024) 14" · M4 (10-core CPU) vs Dell XPS 15 …',
        'macbook pro m4 vs dell xps 15',
        $pool,
        'MacBook Pro M4 vs Dell XPS 15',
        'v62.80 case — exact citation echo (middle dot variant) → keyword fallback w/ brand caps',
    ],
    [
        'Dell Xps 15 2023 vs Macbook Pro 16 Inch M4 Pro 2024 - HT Tech',
        'macbook pro m4 vs dell xps 15',
        $pool,
        'MacBook Pro M4 vs Dell XPS 15',
        'v62.81 case — hyphen vs en-dash variant should still match (normalize-then-compare)',
    ],
    [
        'MacBook Pro M4 vs Dell XPS 15: Which Wins for Pros?',
        'macbook pro m4 vs dell xps 15',
        $pool,
        'MacBook Pro M4 vs Dell XPS 15: Which Wins for Pros?',
        'real generated headline kept as-is (no echo, no junk chars)',
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
        'whitespace + ellipsis only → keyword fallback (no brand tokens)',
    ],
    [
        '',
        'review of x',
        [],
        'Review Of X',
        'empty input → keyword fallback',
    ],
    [
        '',
        'iphone 16 vs ipad pro',
        [],
        'iPhone 16 vs iPad Pro',
        'v62.81 — keyword fallback applies brand_caps (iPhone, iPad, vs lowercase)',
    ],

    // v62.82 — universal pre-emptive cleaning (no pool dependency)
    [
        'Dell XPS 15 7590 vs Apple MacBook Pro 2024 M4 14.2" Liquid …',
        'macbook pro m4 vs dell xps 15',
        [],
        'Dell XPS 15 7590 vs Apple MacBook Pro 2024 M4 14.2" Liquid',
        'v62.82 — long title (>50 chars) keeps content but strips trailing ellipsis',
    ],
    [
        'Apple MacBook Pro 14" Liquid Retina XDR Display 24GB RAM 1TB SSD vs Dell XPS 15 - Pangoly',
        'macbook pro m4 vs dell xps 15',
        [],
        'Apple MacBook Pro 14" Liquid Retina XDR Display',
        'v62.82 — long title with publisher suffix gets stripped + 60-char cap',
    ],
    [
        'Best Laptops 2026: Top 10 Picks for Pros and Creators - TechRadar',
        'best laptops',
        [],
        'Best Laptops 2026: Top 10 Picks for Pros and Creators',
        'v62.82 — long title with " - Publisher" stripped',
    ],
    [
        'How to Cook Rice — A Beginner Guide',
        'how to cook rice',
        [],
        'How to Cook Rice — A Beginner Guide',
        'v62.82 — short title (<50 chars) with em-dash subtitle kept intact (no false positive)',
    ],
    [
        'iPhone 16 - Review',
        'iphone 16 review',
        [],
        'iPhone 16 - Review',
        'v62.82 — short title with " - Word" suffix kept (length under threshold)',
    ],
    [
        'This is an extremely long headline that goes on and on far past sixty characters in length',
        'long keyword phrase here',
        [],
        'This is an extremely long headline that goes on and on far',
        'v62.82 — title >60 chars truncated at last word boundary',
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
