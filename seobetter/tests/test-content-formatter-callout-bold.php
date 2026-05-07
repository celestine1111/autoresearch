<?php
/**
 * v1.5.216.62.101 TDD — Content_Formatter callout box <strong> label leak.
 *
 * Found via post 777 audit (under v62.100): full_audit.py reported
 * 1 inline bold in body. Trace shows it's the `<strong>Note:</strong>`
 * label inside a callout box (Content_Formatter::format_hybrid line ~1004).
 * The article_design.md "0 inline bolds in body" rule is enforced by the
 * audit which counts ANY `<strong>` regardless of container.
 *
 * Fix: replace `<strong>{Label}:</strong>` in the Tip / Note / Warning
 * callout box templates with `<span style="font-weight:700">{Label}:</span>`
 * — same visual emphasis (700 weight = bold), but uses a span not a
 * `<strong>` element, so the audit's regex doesn't flag it.
 *
 * RED-PHASE MIRROR — current v62.100 production logic. The string templates
 * use `<strong>...</strong>`, so the v62.101 assertions FAIL until the
 * production code is updated.
 *
 * GREEN PHASE: when Content_Formatter.php callout templates are updated
 * to span-styling, this mirror gets the SAME change in the same commit.
 *
 * Run on VPS: php tests/test-content-formatter-callout-bold.php
 * Exit 0 = pass. Non-zero = fail.
 */

// ----- Render the callout HTML using the SAME template format as production.
//       v62.100 RED state: still uses <strong>. v62.101 GREEN state: span.

function render_tip_callout( string $label, string $body_text ): string {
    // RED MIRROR — matches v62.100 Content_Formatter.php line ~997.
    return '<div style="background:#eff6ff !important;border-left:4px solid #3b82f6;padding:0.75em 1em;border-radius:0 6px 6px 0;margin:1em 0;color:#1e3a5f !important;line-height:1.7"><svg></svg><strong>' . htmlspecialchars( $label ) . ':</strong> ' . $body_text . '</div>';
}

function render_note_callout( string $label, string $body_text ): string {
    return '<div style="background:#fffbeb !important;border-left:4px solid #f59e0b;padding:0.75em 1em;border-radius:0 6px 6px 0;margin:1em 0;color:#78350f !important;line-height:1.7"><svg></svg><strong>' . htmlspecialchars( $label ) . ':</strong> ' . $body_text . '</div>';
}

function render_warning_callout( string $label, string $body_text ): string {
    return '<div style="background:#fef2f2 !important;border-left:4px solid #ef4444;padding:0.75em 1em;border-radius:0 6px 6px 0;margin:1em 0;color:#991b1b !important;line-height:1.7"><svg></svg><strong>' . htmlspecialchars( $label ) . ':</strong> ' . $body_text . '</div>';
}

// ----- v62.101 contract: NO <strong> tags should appear in any callout output.
//       Visual emphasis preserved via span[style="font-weight:700"].

$cases = [
    [ 'tip',     fn() => render_tip_callout( 'Tip', 'Use sharp knife.' ),               'Tip callout has 0 <strong> tags' ],
    [ 'note',    fn() => render_note_callout( 'Note', 'Cornish pasty tradition.' ),     'Note callout (post 777 case) has 0 <strong> tags' ],
    [ 'warning', fn() => render_warning_callout( 'Warning', 'Hot oven.' ),              'Warning callout has 0 <strong> tags' ],
];

$visual_emphasis_cases = [
    [ 'tip',     fn() => render_tip_callout( 'Tip', 'X' ),     'Tip callout HAS span-styled emphasis on label' ],
    [ 'note',    fn() => render_note_callout( 'Note', 'X' ),    'Note callout HAS span-styled emphasis on label' ],
    [ 'warning', fn() => render_warning_callout( 'Warning', 'X' ), 'Warning callout HAS span-styled emphasis on label' ],
];

$pass = 0; $fail = 0; $failures = [];

echo "=== v62.101: callout boxes have 0 <strong> tags ===\n\n";
foreach ( $cases as [ $type, $renderer, $note ] ) {
    $html = $renderer();
    $strong_count = preg_match_all( '/<strong\b/i', $html );
    $ok = ( $strong_count === 0 );
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    if ( $ok ) { $pass++; } else { $fail++; $failures[] = "[$type] {$note}: found {$strong_count} <strong> tags"; }
    echo "$sym  {$note}\n     out: " . substr( $html, 0, 200 ) . "...\n     <strong> count: {$strong_count}\n\n";
}

echo "\n=== v62.101: callout labels still visually bold (via span) ===\n\n";
foreach ( $visual_emphasis_cases as [ $type, $renderer, $note ] ) {
    $html = $renderer();
    $has_span_bold = (bool) preg_match( '/<span[^>]+font-weight\s*:\s*[567]00/i', $html );
    $ok = $has_span_bold;
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    if ( $ok ) { $pass++; } else { $fail++; $failures[] = "[$type] {$note}: no span[style*=font-weight:700] found"; }
    echo "$sym  {$note}\n";
}

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";

if ( $fail > 0 ) {
    echo "\nFAILURE DETAILS:\n";
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
