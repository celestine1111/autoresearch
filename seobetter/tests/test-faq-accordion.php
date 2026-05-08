<?php
/**
 * v1.5.216.62.114a TDD — wrap H3 Q&A pairs in `<details>` accordion for
 * `content_type === 'faq_page'`.
 *
 * Found via post 821 (home solar panel installation uk / GB / faq_page) audit:
 * 0 accordion classes, 0 `<details>`, 0 `<summary>` — Layer 5 design for
 * faq_page is plain `<h3>Q?</h3><p>A.</p>` markup with no visual differentiation.
 * Per article_design.md row 10 (faq_page) the content-type-status.md claims
 * "✅ accordion Q&A" but `Content_Formatter.php` has no `accordion` /
 * `<details>` / `<summary>` matches at all. Doc/code drift.
 *
 * Fix: a post-processing pass over the rendered HTML that detects every
 *   <h3>Question text ending in ?</h3>
 *   <p>Answer paragraph (and optionally <ul>/<ol> after).</p>
 * pair and wraps the matched block in:
 *   <details class="sb-faq-item" open>
 *     <summary>Question text?</summary>
 *     <div class="sb-faq-answer"><p>Answer.</p></div>
 *   </details>
 *
 * Constraints (per article_design.md no-bold-in-body + Layer 5 spec):
 * - Only fires when content_type === 'faq_page' (other types' inline FAQ
 *   sections keep classic H3+P layout — accordion only when Q&A IS the article).
 * - Must NOT wrap H3 headings that don't end in '?'.
 * - Must preserve <ul>/<ol> answer extensions (some answers list multiple
 *   points after the opening paragraph).
 * - Must NOT touch <h2> or <h4>+ headings.
 * - Must be idempotent (running twice = same output).
 */

// Helper under test.
function wrap_faq_accordion( string $html, string $content_type ): string {
    if ( $content_type !== 'faq_page' ) {
        return $html;
    }

    // Match: <h3>Question?</h3> followed by 1+ block elements (<p>, <ul>, <ol>,
    // <blockquote>) until the next <h2>/<h3>/end-of-string.
    return preg_replace_callback(
        '#<h3\b[^>]*>([^<]*\?\s*)</h3>(.*?)(?=<h[23]\b|$)#is',
        function ( $m ) {
            $question = trim( $m[1] );
            $answer   = trim( $m[2] );
            // Idempotence: skip if already wrapped (caller may run twice).
            if ( strpos( $answer, '</details>' ) !== false ) {
                return $m[0];
            }
            return '<details class="sb-faq-item" open>'
                 . '<summary>' . $question . '</summary>'
                 . '<div class="sb-faq-answer">' . $answer . '</div>'
                 . '</details>';
        },
        $html
    );
}

$pass = 0; $fail = 0; $failures = [];

echo "=== v62.114a — wrap H3 Q&A in <details> for faq_page ===\n\n";

// Test A: simple Q&A wrap when content_type=faq_page
$in_a = '<h3>How long does installation take?</h3><p>About 1-2 days for most homes.</p>';
$out_a = wrap_faq_accordion( $in_a, 'faq_page' );
$ok_a = (
    strpos( $out_a, '<details class="sb-faq-item"' ) !== false &&
    strpos( $out_a, '<summary>How long does installation take?</summary>' ) !== false &&
    strpos( $out_a, '<div class="sb-faq-answer"><p>About 1-2 days for most homes.</p></div>' ) !== false &&
    strpos( $out_a, '</details>' ) !== false
);
$sym = $ok_a ? "\u{2713}" : "\u{2717}";
if ( $ok_a ) $pass++; else { $fail++; $failures[] = '[A] simple Q&A wrap'; }
echo "$sym  Simple H3? + P pair wrapped in <details>\n";

// Test B: NOT faq_page — leave alone
$in_b = '<h3>What is solar power?</h3><p>Energy from sunlight.</p>';
$out_b = wrap_faq_accordion( $in_b, 'blog_post' );
$ok_b = ( $out_b === $in_b );
$sym = $ok_b ? "\u{2713}" : "\u{2717}";
if ( $ok_b ) $pass++; else { $fail++; $failures[] = '[B] non-faq_page untouched'; }
echo "$sym  blog_post content_type — accordion NOT applied\n";

// Test C: H3 NOT ending in '?' — leave alone (it's a regular subheading)
$in_c = '<h3>Solar Panel Maintenance</h3><p>Clean panels twice a year.</p>';
$out_c = wrap_faq_accordion( $in_c, 'faq_page' );
$ok_c = ( $out_c === $in_c );
$sym = $ok_c ? "\u{2713}" : "\u{2717}";
if ( $ok_c ) $pass++; else { $fail++; $failures[] = '[C] non-question H3 untouched'; }
echo "$sym  H3 without trailing '?' — accordion NOT applied\n";

// Test D: multiple Q&A in series — each wrapped independently
$in_d = '<h3>Q1?</h3><p>A1.</p><h3>Q2?</h3><p>A2.</p><h3>Q3?</h3><p>A3.</p>';
$out_d = wrap_faq_accordion( $in_d, 'faq_page' );
$count_details = substr_count( $out_d, '<details class="sb-faq-item"' );
$ok_d = ( $count_details === 3 );
$sym = $ok_d ? "\u{2713}" : "\u{2717}";
if ( $ok_d ) $pass++; else { $fail++; $failures[] = '[D] 3 Q&As wrapped independently'; }
echo "$sym  3 sequential Q&As → 3 separate <details> blocks\n";

// Test E: Q&A with <ul> answer (multi-point answer)
$in_e = '<h3>What are the benefits?</h3><p>Several reasons:</p><ul><li>Save money</li><li>Reduce emissions</li></ul>';
$out_e = wrap_faq_accordion( $in_e, 'faq_page' );
$ok_e = (
    strpos( $out_e, '<details' ) !== false &&
    strpos( $out_e, '<ul><li>Save money</li><li>Reduce emissions</li></ul>' ) !== false &&
    strpos( $out_e, '<summary>What are the benefits?</summary>' ) !== false
);
$sym = $ok_e ? "\u{2713}" : "\u{2717}";
if ( $ok_e ) $pass++; else { $fail++; $failures[] = '[E] <ul> answer preserved inside accordion'; }
echo "$sym  <p>+<ul> answer block wrapped into single <details>\n";

// Test F: H2 heading does NOT trigger accordion (only H3? does)
$in_f = '<h2>Main Section</h2><p>Intro text.</p><h3>Why use solar?</h3><p>Cost savings.</p>';
$out_f = wrap_faq_accordion( $in_f, 'faq_page' );
$ok_f = (
    strpos( $out_f, '<h2>Main Section</h2>' ) !== false &&  // h2 untouched
    strpos( $out_f, '<details' ) !== false &&  // h3? wrapped
    substr_count( $out_f, '<details' ) === 1
);
$sym = $ok_f ? "\u{2713}" : "\u{2717}";
if ( $ok_f ) $pass++; else { $fail++; $failures[] = '[F] H2 untouched, H3? wrapped'; }
echo "$sym  H2 untouched (only H3? becomes accordion)\n";

// Test G: idempotence — running twice produces same output
$in_g = '<h3>Question?</h3><p>Answer.</p>';
$once = wrap_faq_accordion( $in_g, 'faq_page' );
$twice = wrap_faq_accordion( $once, 'faq_page' );
$ok_g = ( $once === $twice );
$sym = $ok_g ? "\u{2713}" : "\u{2717}";
if ( $ok_g ) $pass++; else { $fail++; $failures[] = '[G] idempotent'; }
echo "$sym  Running twice produces same output (idempotent)\n";

// Test H: real-world post-821 sample (5 H3? Q&A pairs, simulated)
$in_h = '<h2>Home Solar Panel Installation UK: Question and Answer Guide</h2>'
      . '<h3>How much does home solar panel installation cost in the UK?</h3>'
      . '<p>The average cost is £5,000 to £8,000 for a typical 4kW system.</p>'
      . '<h3>Are there government grants or loans for solar panels?</h3>'
      . '<p>You can get help through the ECO4 scheme.</p>'
      . '<h3>How long does installation take?</h3>'
      . '<p>Usually 1-2 days for most homes.</p>';
$out_h = wrap_faq_accordion( $in_h, 'faq_page' );
$count_details_h = substr_count( $out_h, '<details class="sb-faq-item"' );
$ok_h = ( $count_details_h === 3 ) && ( strpos( $out_h, '<h2>Home Solar Panel' ) !== false );
$sym = $ok_h ? "\u{2713}" : "\u{2717}";
if ( $ok_h ) $pass++; else { $fail++; $failures[] = '[H] post-821 sample'; }
echo "$sym  Real-world post-821 sample: 3 <details> + h2 preserved\n";

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";
if ( $fail > 0 ) {
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
