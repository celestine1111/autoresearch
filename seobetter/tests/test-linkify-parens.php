<?php
/**
 * Standalone test — verify v1.5.216.62.94 parenthetical-citation linkify.
 *
 * User-reported on T3 #8 post 757 (cat feeders Buying Guide):
 * Body had unlinked parenthetical citations like (Cats.com), (Top 5 Best
 * Automatic Cat Feeders of 2026: Why PalNests Wins), and (We Tested Them All)
 * that SHOULD have been wrapped as [text](url) links pointing at the
 * matching pool entries (cats.com, palnests.com, etc.).
 *
 * The existing linkify_bracketed_references function had two structural
 * weaknesses for these patterns:
 *   - Long parenthetical (>80 chars) failed the length cap on the regex
 *   - Nested parens like "(... (We Tested Them All))" tripped the
 *     unmatched-paren skip check
 *   - Compact-fallback host match ("cats.com" → compact "catscom") didn't
 *     match parenthetical text "(Cats.com)" which compacts to "catscom"
 *     when the pool's source_name was the bare host (already compact)
 *
 * Run on VPS:
 *   php /path/to/wp-content/plugins/seobetter/tests/test-linkify-parens.php
 *
 * Exit 0 = all pass. Non-zero = failures.
 */

// ----- Minimal mirror of the v62.94 production logic -----
// (Real production logic is in seobetter.php::linkify_bracketed_references.
// This is a SIMPLIFIED version testing only the parenthetical match path —
// real prod has acronym aliases + heavier register logic; this exercises
// the core str_contains + compact-fallback contract.)

function linkify_parens( string $markdown, array $pool ): string {
    if ( empty( $pool ) ) return $markdown;

    $norm = function( string $s ): string {
        $s = strtolower( $s );
        $s = str_replace( [ '—', '–' ], '-', $s );
        $s = preg_replace( '/\s*[.\x{2026}]+$/u', '', $s );
        $s = preg_replace( '/\s+/', ' ', trim( $s ) );
        return $s;
    };
    $compact = function( string $s ): string {
        return preg_replace( '/[^a-z0-9]/', '', strtolower( $s ) );
    };

    // Build lookup
    $lookup = [];
    $lookup_compact = [];
    foreach ( $pool as $entry ) {
        $url    = $entry['url'] ?? '';
        if ( $url === '' ) continue;
        $title  = $norm( $entry['title']       ?? '' );
        $source = $norm( $entry['source_name'] ?? '' );
        if ( strlen( $title ) > 5 ) {
            $lookup[ $title ] = $entry;
            $kc = $compact( $title );
            if ( strlen( $kc ) >= 4 ) $lookup_compact[ $kc ] = $entry;
        }
        if ( strlen( $source ) > 3 ) {
            $lookup[ $source ] = $entry;
            $kc = $compact( $source );
            if ( strlen( $kc ) >= 4 ) $lookup_compact[ $kc ] = $entry;
            $bare = preg_replace( '/\.(com|org|net|io|co|edu|gov)$/i', '', $source );
            if ( $bare !== $source && strlen( $bare ) > 3 ) {
                $lookup[ $bare ] = $entry;
                $bc = $compact( $bare );
                if ( strlen( $bc ) >= 4 ) $lookup_compact[ $bc ] = $entry;
            }
        }
    }

    // v62.94 — Match parentheticals up to 200 chars (was 150) so longer
    // citations like "(Top 5 Best Automatic Cat Feeders of 2026: Why
    // PalNests Wins)" are caught.
    return preg_replace_callback(
        '/\(([^)]{2,200})\)/',
        function ( $match ) use ( $lookup, $lookup_compact, $norm, $compact ) {
            $text = $match[1];
            $text_n = $norm( $text );
            // Skip non-references
            if ( str_contains( $text, 'http' ) ) return $match[0];
            if ( str_contains( $text, '](' ) ) return $match[0];
            if ( preg_match( '/^(e\.g\.|i\.e\.|see |note:)/i', $text ) ) return $match[0];

            // Direct title/source match
            foreach ( $lookup as $key => $entry ) {
                if ( strlen( $key ) > 4 && ( str_contains( $text_n, $key ) || str_contains( $key, $text_n ) ) ) {
                    return '(' . '[' . $text . '](' . $entry['url'] . ')' . ')';
                }
            }

            // v62.94 — compact fallback for host-style parens like (Cats.com).
            $text_compact = $compact( $text );
            if ( strlen( $text_compact ) >= 4 ) {
                foreach ( $lookup_compact as $key_compact => $entry ) {
                    if ( strlen( $key_compact ) >= 4 && (
                        $text_compact === $key_compact ||
                        str_contains( $text_compact, $key_compact ) ||
                        str_contains( $key_compact, $text_compact )
                    ) ) {
                        return '(' . '[' . $text . '](' . $entry['url'] . ')' . ')';
                    }
                }
            }
            return $match[0];
        },
        $markdown
    );
}

// ----- Test pool — mirrors what would be in citation_pool for the cat feeders article -----
$pool = [
    [ 'url' => 'https://cats.com/best-automatic-cat-feeder', 'title' => 'The 13 Best Automatic Cat Feeders in 2026 (We Tested Them All)', 'source_name' => 'cats.com' ],
    [ 'url' => 'https://palnests.com/blogs/blog/best-automatic-cat-feeder-2026', 'title' => 'Top 5 Best Automatic Cat Feeders of 2026: Why PalNests Wins', 'source_name' => 'palnests.com' ],
    [ 'url' => 'https://technomeow.com/best-automatic-feeders-for-cats/', 'title' => '5 Best Automatic Feeders For Cats in 2026', 'source_name' => 'technomeow.com' ],
];

// ----- Test cases: [input_markdown, expected_substring_in_output, note] -----
$cases = [
    [
        'A claim. (Cats.com)',
        '[Cats.com](https://cats.com/',
        'host-style parenthetical (Cats.com) → linkified',
    ],
    [
        'A finding (Top 5 Best Automatic Cat Feeders of 2026: Why PalNests Wins).',
        '](https://palnests.com/',
        'long parenthetical (50+ chars) matches title → linkified',
    ],
    [
        'Models tested across 13 categories (We Tested Them All).',
        '](https://cats.com/',
        '(We Tested Them All) — substring of cats.com title → linkified via str_contains',
    ],
    [
        'Generic note (e.g. battery life) should stay.',
        '(e.g. battery life)',
        '(e.g. ...) skip-pattern survives — NOT linkified',
    ],
    [
        'Random aside (just a note).',
        '(just a note)',
        'unrelated parenthetical (no pool match) stays unlinked',
    ],
];

$passed = 0;
$failed = 0;
$failures = [];

foreach ( $cases as [ $input, $expect, $note ] ) {
    $actual = linkify_parens( $input, $pool );
    $contains = str_contains( $actual, $expect );
    $status = $contains ? '✓' : '✗';
    if ( $contains ) {
        $passed++;
    } else {
        $failed++;
        $failures[] = [ 'input' => $input, 'expect' => $expect, 'actual' => $actual, 'note' => $note ];
    }
    echo sprintf( "%s  %s\n     IN:  %s\n     OUT: %s\n\n",
        $status,
        $note,
        $input,
        $actual
    );
}

echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$passed}  |  FAILED: {$failed}\n";
echo "═════════════════════════════════════════════════════\n";

if ( $failed > 0 ) {
    echo "\nFAILURE DETAILS:\n";
    foreach ( $failures as $f ) {
        echo "  Input:  {$f['input']}\n";
        echo "  Expect: contains '{$f['expect']}'\n";
        echo "  Actual: {$f['actual']}\n";
        echo "  Note:   {$f['note']}\n\n";
    }
    exit( 1 );
}
exit( 0 );
