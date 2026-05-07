<?php
/**
 * v1.5.216.62.108 TDD — featured image leaks as redundant ImageObject when
 * body inline `<img src>` differs from the local-uploaded featured-image URL.
 *
 * Found via post 790 audit (best british gardening tools 2026 — buying_guide):
 *   - Featured image: http://srv1608940.hstgr.cloud/wp-content/uploads/2026/05/pexels-photo-18566999.webp
 *   - Body inline img: https://images.pexels.com/photos/18566999/pexels-photo-18566999.jpeg?auto=compress
 *   - Same Pexels asset 18566999 — re-encoded as .webp on upload, kept as .jpeg in body markdown.
 *   - `Schema_Generator::detect_image_schemas()` line 2659 strict-equality compares
 *     `$src === $featured_url`. Different strings → strict match fails → emits a
 *     redundant ImageObject for the body img representing the SAME source asset
 *     already covered by Article.image.
 *
 * Fix v62.108: extract a "source image identifier" (basename without extension /
 * Pexels photo ID) from BOTH URLs and dedupe. If the body img and featured image
 * share the same source identifier, skip emitting the body img as a separate
 * ImageObject — Article.image already represents it.
 *
 * RED-PHASE MIRROR — current v62.107 strict-equality. Returns true (skip) only
 * when URLs are byte-identical. Fails the new dedup-by-basename cases.
 */

// Helper under test — extract source image identifier.
// Used by Schema_Generator to compare an inline body img URL with the
// featured-image URL even when one is local-uploaded `.webp` and the other
// is remote pexels `.jpeg`.
function image_source_id( string $url ): string {
    if ( $url === '' ) return '';
    // Pexels: /photos/NNNN/pexels-photo-NNNN.jpeg → "pexels-photo-NNNN"
    if ( preg_match( '#/photos/(\d+)/(pexels-photo-\d+)#', $url, $m ) ) {
        return $m[2];
    }
    // Generic: take the basename without extension and any query string
    // /uploads/2026/05/pexels-photo-18566999.webp → "pexels-photo-18566999"
    $path = parse_url( $url, PHP_URL_PATH );
    if ( ! $path ) return '';
    $base = basename( $path );
    // Strip extension
    $base = preg_replace( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?)$/i', '', $base );
    // Strip WordPress size suffixes like -1200x630
    $base = preg_replace( '/-\d+x\d+$/', '', $base );
    return $base;
}

// Strict-equality test (the v62.107 logic) — should fail when URLs differ.
function v107_match( string $src, string $featured_url ): bool {
    return $src === $featured_url;
}

// Basename-equality test (the v62.108 logic) — should match same-source pairs.
function v108_match( string $src, string $featured_url ): bool {
    $a = image_source_id( $src );
    $b = image_source_id( $featured_url );
    return $a !== '' && $a === $b;
}

// ----- Test cases — pairs that ARE same-source (should match=true post-v108) -----
$same_source = [
    [
        'https://images.pexels.com/photos/18566999/pexels-photo-18566999.jpeg?auto=compress&cs=tinysrgb&fit=crop&h=627&w=1200',
        'http://srv1608940.hstgr.cloud/wp-content/uploads/2026/05/pexels-photo-18566999.webp',
        'post 790 case — pexels remote vs local webp re-encode'
    ],
    [
        'https://images.pexels.com/photos/3971211/pexels-photo-3971211.jpeg',
        'https://srv1608940.hstgr.cloud/wp-content/uploads/2026/05/pexels-photo-3971211-1200x630.webp',
        'pexels with WP size suffix'
    ],
    [
        'https://srv1608940.hstgr.cloud/wp-content/uploads/2026/05/foo-1200x630.jpg',
        'https://srv1608940.hstgr.cloud/wp-content/uploads/2026/05/foo.jpg',
        'same image, with and without WP size suffix'
    ],
];

// ----- Test cases — pairs that are different-source (should match=false) -----
$diff_source = [
    [
        'https://images.pexels.com/photos/3971211/pexels-photo-3971211.jpeg',
        'https://images.pexels.com/photos/18566999/pexels-photo-18566999.jpeg',
        'two different pexels photos'
    ],
    [
        'https://srv1608940.hstgr.cloud/wp-content/uploads/2026/05/cornish-pasty.jpg',
        'https://srv1608940.hstgr.cloud/wp-content/uploads/2026/05/beef-stew.jpg',
        'two different uploads'
    ],
];

$pass = 0; $fail = 0; $failures = [];

echo "=== v62.108 — image-source-id basename dedup (post 790 fix) ===\n\n";

echo "--- Same-source pairs (v62.108 must match=true) ---\n";
foreach ( $same_source as [ $a, $b, $note ] ) {
    $matched = v108_match( $a, $b );
    $sym = $matched ? "\u{2713}" : "\u{2717}";
    if ( $matched ) { $pass++; } else { $fail++; $failures[] = "[same-source] {$note}"; }
    echo "$sym  {$note}\n";
    echo "    a → " . image_source_id( $a ) . "\n";
    echo "    b → " . image_source_id( $b ) . "\n";
}

echo "\n--- Different-source pairs (v62.108 must match=false) ---\n";
foreach ( $diff_source as [ $a, $b, $note ] ) {
    $matched = v108_match( $a, $b );
    $sym = ( ! $matched ) ? "\u{2713}" : "\u{2717}";
    if ( ! $matched ) { $pass++; } else { $fail++; $failures[] = "[diff-source false-pos] {$note}"; }
    echo "$sym  {$note}\n";
}

echo "\n--- Sanity: v62.107 strict-equality fails on same-source pairs (RED proof) ---\n";
$red_proof = ! v107_match( $same_source[0][0], $same_source[0][1] );
$sym = $red_proof ? "\u{2713}" : "\u{2717}";
if ( $red_proof ) $pass++; else { $fail++; $failures[] = "v107 strict match unexpectedly succeeded"; }
echo "$sym  v62.107 strict equality fails on post-790 case (proves bug exists)\n";

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";

if ( $fail > 0 ) {
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
