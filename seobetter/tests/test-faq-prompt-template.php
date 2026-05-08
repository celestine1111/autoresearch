<?php
/**
 * v1.5.216.62.114b TDD — faq_page prose-template enforcement.
 *
 * Found via post 821 (home solar panel installation uk / GB / faq_page) audit:
 * AI emitted only 5 Q&A pairs against a template that asks for "10-15".
 * Word count came out at 775/2000 — directly driven by the short Q&A count.
 *
 * The pre-v62.114 template guidance was soft:
 *   "Collection of Q&A pairs. Questions phrased exactly as users search.
 *    Direct answers in the first sentence. Vary answer lengths."
 *
 * Nothing in the guidance enforced the 10-15 minimum, the per-answer word
 * range, or the "questions phrased as users TYPE in search". So the AI
 * defaulted to ~5 broad Q&A pairs and called it done.
 *
 * v62.114b strengthens the guidance to:
 *   - MANDATORY: minimum 10 Q&A pairs (target 12-15) — non-compliant below 10
 *   - Each H3 ends in "?" (no bundled questions)
 *   - 50-100 words per answer
 *   - Answer-first sentence
 *   - Cover 12-15 top search queries (cost/maintenance/safety/comparison/locale)
 *   - Topic Introduction short (60-100w)
 *   - No Key Takeaways box (Q&A pairs ARE the takeaways)
 *
 * This test parses the production template at
 * Async_Generator.php line ~1086 and asserts each rule appears in the
 * guidance string. Drift between this test + production is a fail.
 */

// Read the production faq_page template directly from the source file.
// Avoids loading the full plugin (no WordPress runtime in PHP unit tests).
$src = file_get_contents( __DIR__ . '/../includes/Async_Generator.php' );

// Extract the faq_page template line (single-line entry in the prose array).
if ( ! preg_match( "/'faq_page'\s*=>\s*\[\s*'sections'\s*=>\s*'([^']+)'\s*,\s*'guidance'\s*=>\s*'(.+?)'\s*,\s*'schema'\s*=>\s*'FAQPage'\s*\]/s", $src, $m ) ) {
    echo "✗  Could NOT extract faq_page template from Async_Generator.php — TDD test setup failed\n";
    exit( 1 );
}

$sections = $m[1];
$guidance = $m[2];

$pass = 0; $fail = 0; $failures = [];

echo "=== v62.114b — faq_page prose-template enforcement ===\n\n";

// Test A: sections still names "10-15 Question and Answer Pairs"
$ok_a = ( strpos( $sections, '10-15 Question and Answer Pairs' ) !== false );
$sym = $ok_a ? "\u{2713}" : "\u{2717}";
if ( $ok_a ) $pass++; else { $fail++; $failures[] = '[A] sections names 10-15 Q&A'; }
echo "$sym  sections string contains '10-15 Question and Answer Pairs'\n";

// Test B: guidance has MANDATORY 10-pair minimum (case-insensitive)
$ok_b = ( stripos( $guidance, 'MINIMUM 10' ) !== false ) && ( stripos( $guidance, 'MANDATORY' ) !== false );
$sym = $ok_b ? "\u{2713}" : "\u{2717}";
if ( $ok_b ) $pass++; else { $fail++; $failures[] = '[B] guidance has MANDATORY MINIMUM 10'; }
echo "$sym  guidance has MANDATORY + MINIMUM 10 (hard floor)\n";

// Test C: guidance specifies 50-100 word answer range
$ok_c = ( strpos( $guidance, '50-100 words' ) !== false );
$sym = $ok_c ? "\u{2713}" : "\u{2717}";
if ( $ok_c ) $pass++; else { $fail++; $failures[] = '[C] 50-100 word answer range'; }
echo "$sym  guidance specifies 50-100 words per answer\n";

// Test D: guidance specifies each H3 ends in "?"
$ok_d = ( strpos( $guidance, 'ending in "?"' ) !== false );
$sym = $ok_d ? "\u{2713}" : "\u{2717}";
if ( $ok_d ) $pass++; else { $fail++; $failures[] = '[D] H3 ends in ?'; }
echo "$sym  guidance specifies each H3 ends in \"?\"\n";

// Test E: guidance forbids bundled questions
$ok_e = ( strpos( $guidance, 'NEVER bundle' ) !== false );
$sym = $ok_e ? "\u{2713}" : "\u{2717}";
if ( $ok_e ) $pass++; else { $fail++; $failures[] = '[E] no bundled questions'; }
echo "$sym  guidance forbids bundling two questions in one heading\n";

// Test F: guidance asks for answer-first sentence (snippet-friendly)
$ok_f = ( stripos( $guidance, 'first sentence' ) !== false );
$sym = $ok_f ? "\u{2713}" : "\u{2717}";
if ( $ok_f ) $pass++; else { $fail++; $failures[] = '[F] answer-first sentence rule'; }
echo "$sym  guidance asks for direct answer in first sentence\n";

// Test G: guidance specifies natural-search question phrasing
$ok_g = ( stripos( $guidance, 'as users would type' ) !== false ) || ( stripos( $guidance, 'as users type' ) !== false ) || ( stripos( $guidance, 'phrased exactly' ) !== false );
$sym = $ok_g ? "\u{2713}" : "\u{2717}";
if ( $ok_g ) $pass++; else { $fail++; $failures[] = '[G] natural-search phrasing rule'; }
echo "$sym  guidance asks for natural search phrasing\n";

// Test H: NO Key Takeaways box (FAQ pairs ARE the takeaways)
$ok_h = ( stripos( $guidance, 'NO Key Takeaways' ) !== false );
$sym = $ok_h ? "\u{2713}" : "\u{2717}";
if ( $ok_h ) $pass++; else { $fail++; $failures[] = '[H] no Key Takeaways box'; }
echo "$sym  guidance forbids Key Takeaways box\n";

// Test I: schema is still FAQPage
$ok_i = ( strpos( $src, "'schema' => 'FAQPage'" ) !== false );
$sym = $ok_i ? "\u{2713}" : "\u{2717}";
if ( $ok_i ) $pass++; else { $fail++; $failures[] = '[I] FAQPage schema'; }
echo "$sym  schema mapping is FAQPage\n";

// Test J: target range 12-15 mentioned (the upper end of 10-15 hard floor)
$ok_j = ( strpos( $guidance, '12-15' ) !== false );
$sym = $ok_j ? "\u{2713}" : "\u{2717}";
if ( $ok_j ) $pass++; else { $fail++; $failures[] = '[J] target 12-15 explicit'; }
echo "$sym  guidance names target 12-15 pairs (above 10 hard floor)\n";

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";
if ( $fail > 0 ) {
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
