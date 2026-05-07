<?php
/**
 * v1.5.216.62.109 TDD — Content_Injector::inject_quotes admits hallucinated /
 * truncated / mid-sentence quotes from scraped scientific-paper content.
 *
 * Found via post 800 audit (Victoria sponge cake / GB / recipe). Two quotes
 * shipped:
 *
 *   "Traditional cupcakes without any topping, neither frosted nor iced, were
 *    used in this study as a typical baked food of relatively high consumption
 *    ]." — pmc.ncbi.nlm.nih.gov
 *
 *   "TGD), remained unchanged, which supported the results found in sponge
 *    cakes by other authors ]." — pmc.ncbi.nlm.nih.gov
 *
 * Both have:
 *   (a) Orphan citation closing `]` before terminal `.` — leftover from
 *       inline `[N]` citation strip on the source paper.
 *   (b) Quote 2 also opens MID-SENTENCE with a closing-paren acronym
 *       fragment "TGD)," — clearly truncated, not a coherent quote start.
 *
 * Existing v62.62 filters (`Content_Injector::inject_quotes` line ~395-435)
 * check for image-caption echo, 3-word phrase repetition, mid-sentence
 * truncation at END (last char must be terminal punctuation), and trailing
 * function-words. They do NOT check for:
 *   - Orphan `]` before terminal punctuation
 *   - Mid-sentence opening (lowercase / closing paren / bracket / comma at start)
 *
 * Fix v62.109: extend the inline validator closure with two new checks:
 *   (1) reject text matching `\s\]\s*[.!?]\s*$` (orphan citation bracket)
 *   (2) reject text starting with `[a-z]` (lowercase) or `[)\]\}\,]` (paren/comma fragment)
 *
 * RED-PHASE MIRROR — current v62.108 logic. Both post-800 quotes pass through
 * (returned as valid). v62.110 GREEN flips the mirror + production.
 *
 * Run on VPS: php tests/test-quote-sanity.php
 */

// Mirror — current v62.108 quote validator (the inline closure from inject_quotes).
function quote_is_valid_v108( string $text ): bool {
    if ( strlen( $text ) < 30 || strlen( $text ) > 300 ) return false;
    if ( preg_match_all( '/\b(?:Photograph|Photo|Image|Picture|Caption)\s*:\s*[A-Z][a-zA-Z\s]+/i', $text, $img_caps ) ) {
        if ( count( $img_caps[0] ) >= 2 ) return false;
    }
    $words = preg_split( '/\s+/', strtolower( $text ) );
    if ( count( $words ) >= 6 ) {
        $grams = [];
        for ( $i = 0; $i < count( $words ) - 2; $i++ ) {
            $grams[] = $words[ $i ] . ' ' . $words[ $i + 1 ] . ' ' . $words[ $i + 2 ];
        }
        $counts = array_count_values( $grams );
        foreach ( $counts as $g => $n ) {
            if ( $n >= 3 ) return false;
        }
    }
    $last_char = mb_substr( $text, -1 );
    if ( ! in_array( $last_char, [ '.', '!', '?', '"', '\'', '”', '’', ')' ], true ) ) return false;
    if ( preg_match( '/\b(?:the|a|an|and|or|of|to|in|on|for|with|at|by|from)[.\s]*$/i', $text ) ) return false;
    return true;
}

// Mirror — v62.109 validator with new checks.
function quote_is_valid_v109( string $text ): bool {
    if ( ! quote_is_valid_v108( $text ) ) return false;
    // v62.109 (a): reject orphan citation closing bracket before terminal punct.
    // Pattern: any ` ]` / `]` immediately before `.`/`!`/`?`/quote.
    if ( preg_match( '/[\s\w]\s*\]\s*[.!?\'"”’]\s*$/', $text ) ) return false;
    // v62.109 (b): reject mid-sentence opening — must start with capital letter
    // or opening quote/dash. Reject lowercase / closing paren / comma / closing bracket.
    $first = mb_substr( ltrim( $text, '\u{201C}\u{201D}"\'\u{2018}\u{2019}\u{2013}\u{2014}- ' ), 0, 1 );
    if ( $first === '' ) return false;
    if ( ! preg_match( '/^[A-Z]/u', $first ) ) return false;
    // v62.109 (c): reject quotes opening with all-caps acronym followed by closing paren
    // — the "TGD)," fragment pattern.
    if ( preg_match( '/^[A-Z]{2,8}\s*[)\]\}]/', ltrim( $text, '\u{201C}\u{201D}"\'\u{2018}\u{2019}\u{2013}\u{2014}- ' ) ) ) return false;
    return true;
}

// ----- Test cases -----

// Hallucinated quotes from post 800 — MUST reject under v62.109
$bad_quotes = [
    'Traditional cupcakes without any topping, neither frosted nor iced, were used in this study as a typical baked food of relatively high consumption ].',
    'TGD), remained unchanged, which supported the results found in sponge cakes by other authors ].',
    'tgd), remained unchanged, which supported the results found in sponge cakes by other authors.',  // lowercase-acronym variant
    ', this finding aligns with previous research on baked good stability over time and storage conditions.',  // comma-opening fragment
    ') the sample was held at room temperature for a controlled period and analyzed for moisture content.',  // closing-paren-opening fragment
];

// Coherent quotes — MUST keep under v62.109
$good_quotes = [
    'A perfectly baked Victoria sponge has a light, fluffy texture and a delicate sweetness that pairs beautifully with whipped cream and fresh berries.',
    '"Use room-temperature butter for the lightest possible cake," advises Mary Berry in her classic guide to British baking.',
    'According to BBC Good Food, the secret to a tender crumb is folding the flour gently rather than beating it in.',
    'The Cornish Pasty Association says authentic pasties must contain beef, potato, swede, and onion — never any other filling.',
    'Olive Magazine writes that the Victoria sponge gets its name from Queen Victoria, who reportedly ate a slice of the cake every afternoon with her tea.',
];

$pass = 0; $fail = 0; $failures = [];

echo "=== v62.109 — quote sanity (post 800 hallucinations) ===\n\n";

echo "--- Bad quotes MUST be rejected by v62.109 ---\n";
foreach ( $bad_quotes as $q ) {
    $valid_109 = quote_is_valid_v109( $q );
    $sym = ( ! $valid_109 ) ? "\u{2713}" : "\u{2717}";
    if ( ! $valid_109 ) $pass++; else { $fail++; $failures[] = "[bad-quote-passed] " . substr( $q, 0, 60 ); }
    echo "$sym  v109 rejects: " . substr( $q, 0, 80 ) . "...\n";
}

echo "\n--- Good quotes MUST be kept by v62.109 ---\n";
foreach ( $good_quotes as $q ) {
    $valid_109 = quote_is_valid_v109( $q );
    $sym = $valid_109 ? "\u{2713}" : "\u{2717}";
    if ( $valid_109 ) $pass++; else { $fail++; $failures[] = "[good-quote-rejected] " . substr( $q, 0, 60 ); }
    echo "$sym  v109 keeps:   " . substr( $q, 0, 80 ) . "...\n";
}

echo "\n--- RED proof: v62.108 admits the bad ones ---\n";
$red_count = 0;
foreach ( $bad_quotes as $q ) {
    if ( quote_is_valid_v108( $q ) ) $red_count++;
}
$expected_red = count( $bad_quotes );
$sym = $red_count > 0 ? "\u{2713}" : "\u{2717}";
if ( $red_count > 0 ) $pass++; else { $fail++; $failures[] = 'v108 unexpectedly rejected all bad quotes'; }
echo "$sym  v62.108 lets {$red_count}/" . count( $bad_quotes ) . " bad quotes through (proves bug)\n";

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";
if ( $fail > 0 ) {
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
