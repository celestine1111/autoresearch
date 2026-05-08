<?php
/**
 * v1.5.216.62.113 TDD — strip body inline `<strong>` from `<p>` paragraphs.
 *
 * Found via post 816 (Victoria sponge) audit: 7 inline bolds in body.
 * Source: AI emits `**word**` markdown for emphasis mid-paragraph, which
 * `Content_Formatter::inline_markdown()` line 1648 converts to
 * `<strong>word</strong>` in the `<p>` body block. This violates
 * article_design.md "no inline bolds in body".
 *
 * The existing v62.102 callout fix replaced `<strong>` in callout boxes
 * with `<span style="font-weight:700">`, but only for Tip / Note / Warning
 * containers (`<div>`). Mid-paragraph bolds in regular `<p>` text still
 * survive because:
 *   - inline_markdown converts `**X**` → `<strong>X</strong>` (needed by
 *     the definition / highlight pattern detectors at line 1048 + 1060).
 *   - Definition pattern: `^<strong>(...)</strong>\s*[:—-]\s*(.+)$` —
 *     entire paragraph is `<strong>Term</strong>: definition`. Triggers a
 *     styled definition `<div>`. The `<strong>` is consumed.
 *   - Highlight pattern: `^<strong>(...)</strong>[\s.!?]*$` — entire
 *     paragraph is just `<strong>sentence</strong>`. Styled highlight `<div>`.
 *   - Otherwise (mid-paragraph bold like "...the **secret** is timing.") —
 *     `<strong>` survives as inline body bold.
 *
 * Fix v62.113: a post-processing pass that removes `<strong>...</strong>`
 * from inline body content (inside `<p>` tags) but PRESERVES `<strong>`
 * inside styled `<div>` callouts, definition boxes, highlight boxes,
 * footers, and other intentional design uses.
 *
 * Strategy: walk each `<p>...</p>` block in the rendered output. Inside
 * each, replace `<strong>X</strong>` with just `X`. Don't touch anything
 * outside `<p>` blocks.
 */

// Helper under test.
function strip_paragraph_bolds( string $html ): string {
    return preg_replace_callback(
        '/<p\b([^>]*)>(.+?)<\/p>/is',
        function ( $m ) {
            $attrs = $m[1];
            $inner = $m[2];
            // Strip <strong>...</strong> inside the <p> (keep the inner text).
            $inner = preg_replace( '/<strong\b[^>]*>(.+?)<\/strong>/is', '$1', $inner );
            // Also strip <b> tags (alternate bold form).
            $inner = preg_replace( '/<b\b[^>]*>(.+?)<\/b>/is', '$1', $inner );
            return '<p' . $attrs . '>' . $inner . '</p>';
        },
        $html
    );
}

$pass = 0; $fail = 0; $failures = [];

echo "=== v62.113 — strip <strong> from <p> body paragraphs ===\n\n";

// Test A: mid-paragraph bold gets stripped
$in_a = '<p>The secret to a perfect Victoria sponge is to <strong>cream the butter and sugar</strong> until pale and fluffy.</p>';
$out_a = strip_paragraph_bolds( $in_a );
$expected_a = '<p>The secret to a perfect Victoria sponge is to cream the butter and sugar until pale and fluffy.</p>';
$ok_a = ( $out_a === $expected_a );
$sym = $ok_a ? "\u{2713}" : "\u{2717}";
if ( $ok_a ) $pass++; else { $fail++; $failures[] = '[A] mid-paragraph strip'; }
echo "$sym  Mid-paragraph <strong> stripped\n";

// Test B: multiple bolds in one paragraph all stripped
$in_b = '<p>Use <strong>room-temperature butter</strong> and <strong>self-raising flour</strong> for best results.</p>';
$out_b = strip_paragraph_bolds( $in_b );
$ok_b = ( strpos( $out_b, '<strong>' ) === false ) && ( strpos( $out_b, 'room-temperature butter' ) !== false ) && ( strpos( $out_b, 'self-raising flour' ) !== false );
$sym = $ok_b ? "\u{2713}" : "\u{2717}";
if ( $ok_b ) $pass++; else { $fail++; $failures[] = '[B] multi-bold strip'; }
echo "$sym  Multiple <strong> in one paragraph all stripped\n";

// Test C: <p> with attributes preserved
$in_c = '<p class="wp-block-paragraph" style="color:red">Some <strong>bold</strong> text.</p>';
$out_c = strip_paragraph_bolds( $in_c );
$ok_c = strpos( $out_c, 'class="wp-block-paragraph"' ) !== false && strpos( $out_c, '<strong>' ) === false;
$sym = $ok_c ? "\u{2713}" : "\u{2717}";
if ( $ok_c ) $pass++; else { $fail++; $failures[] = '[C] attrs preserved'; }
echo "$sym  <p> attributes preserved when stripping inner <strong>\n";

// Test D: <strong> INSIDE a <div> (callout) is preserved (not in <p>)
$in_d = '<div style="border-left:4px solid blue"><span style="font-weight:700">Tip:</span> Use a non-stick tin.</div>';
$out_d = strip_paragraph_bolds( $in_d );
$ok_d = ( $out_d === $in_d );
$sym = $ok_d ? "\u{2713}" : "\u{2717}";
if ( $ok_d ) $pass++; else { $fail++; $failures[] = '[D] div untouched'; }
echo "$sym  <strong> inside <div> callout untouched (no <p> wrapper)\n";

// Test E: <strong> inside a <footer> for quote attribution stays
$in_e = '<blockquote>Quote text. <footer>&mdash; <strong>Author Name</strong></footer></blockquote>';
$out_e = strip_paragraph_bolds( $in_e );
$ok_e = ( $out_e === $in_e );
$sym = $ok_e ? "\u{2713}" : "\u{2717}";
if ( $ok_e ) $pass++; else { $fail++; $failures[] = '[E] footer untouched'; }
echo "$sym  <strong> inside <footer> (quote attribution) untouched\n";

// Test F: nested <p> inside <li> — only top-level <p> stripping
$in_f = '<ul><li>Item 1</li><li>Item with <strong>bold</strong> word</li></ul>';
$out_f = strip_paragraph_bolds( $in_f );
$ok_f = ( $out_f === $in_f );  // li bolds NOT stripped (only <p> targeted)
$sym = $ok_f ? "\u{2713}" : "\u{2717}";
if ( $ok_f ) $pass++; else { $fail++; $failures[] = '[F] li untouched'; }
echo "$sym  <strong> inside <li> bullet untouched (only <p> targeted)\n";

// Test G: <b> tag (alternate form) also stripped
$in_g = '<p>Here is some <b>bold</b> text.</p>';
$out_g = strip_paragraph_bolds( $in_g );
$ok_g = strpos( $out_g, '<b>' ) === false && strpos( $out_g, 'bold' ) !== false;
$sym = $ok_g ? "\u{2713}" : "\u{2717}";
if ( $ok_g ) $pass++; else { $fail++; $failures[] = '[G] <b> stripped'; }
echo "$sym  <b> tag also stripped\n";

// Test H: empty paragraph or no bolds — unchanged
$in_h = '<p>Just plain text with no formatting.</p>';
$out_h = strip_paragraph_bolds( $in_h );
$ok_h = ( $out_h === $in_h );
$sym = $ok_h ? "\u{2713}" : "\u{2717}";
if ( $ok_h ) $pass++; else { $fail++; $failures[] = '[H] no-bold unchanged'; }
echo "$sym  Plain <p> with no bolds unchanged\n";

// Test I: real-world post-816 sample
$in_i = '<p>According to BBC Good Food, <strong>creaming the butter</strong> is the most important step. Always use <strong>room-temperature ingredients</strong> for the lightest texture.</p>';
$out_i = strip_paragraph_bolds( $in_i );
$count_strong = preg_match_all( '/<strong\b/i', $out_i );
$ok_i = ( $count_strong === 0 );
$sym = $ok_i ? "\u{2713}" : "\u{2717}";
if ( $ok_i ) $pass++; else { $fail++; $failures[] = '[I] post-816 sample'; }
echo "$sym  Real-world post-816 sample: 0 <strong> remaining\n";

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";
if ( $fail > 0 ) {
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
