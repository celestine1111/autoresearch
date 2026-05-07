<?php
/**
 * v1.5.216.62.108 TDD — ItemList includes non-product H2 sections.
 *
 * Found via post 790 audit (best british gardening tools 2026 — buying_guide):
 * ItemList @graph node had 7 itemListElement entries, but 2 of them were
 * NOT actual products:
 *   position 1: "Best British Gardening Tools 2026"          (article title H2)
 *   position 2: "1. Spear & Jackson Razorsharp Advance Secateurs"  ✓ real product
 *   position 3: "2. Bulldog Premier Digging Spade"           ✓
 *   position 4: "3. Kent & Stowe Stainless Steel Border Fork" ✓
 *   position 5: "4. Niwaki GR Pro Secateurs"                 ✓
 *   position 6: "5. Haws The Warley Fall Watering Can"       ✓
 *   position 7: "What to Look For When Buying ..."           ✗ buying-criteria, NOT a product
 *
 * Plus `numberOfItems: None` (missing). Should equal itemListElement count.
 *
 * Root cause: Schema_Generator::generate_itemlist_schema() includes EVERY H2
 * minus a small skip-regex (line 2252). It does not enforce the numbered
 * "N. Product Name" pattern that buying_guide / listicle templates require.
 *
 * Fix v62.108: for listicle / buying_guide content_type, ONLY include H2s
 * matching `^\d+[.)\s]+\w+` (numbered "1. Foo" / "2. Bar" pattern). Other
 * content types keep current generic-skip behaviour. Also set numberOfItems.
 *
 * RED-PHASE MIRROR — current v62.107 logic. Returns title + criteria H2s.
 *
 * Run on VPS: php tests/test-itemlist-filter.php
 */

// Mirror — generate ItemList items from H2 list (RED state, v62.107 logic).
function generate_itemlist_items_v107( array $h2_headings, string $content_type ): array {
    $items = [];
    $position = 1;
    foreach ( $h2_headings as $name ) {
        if ( preg_match( '/^(introduction|conclusion|faq|frequently asked|summary|final thoughts|key takeaway|pros|cons|reference|quick comparison)/i', $name ) ) {
            continue;
        }
        $items[] = [ '@type' => 'ListItem', 'position' => $position++, 'name' => $name ];
        if ( $position > 30 ) break;
    }
    return $items;
}

// v108 GREEN — for listicle/buying_guide, only numbered "N. Foo" headings.
function generate_itemlist_items_v108( array $h2_headings, string $content_type ): array {
    $is_numbered_list_type = in_array( $content_type, [ 'listicle', 'buying_guide' ], true );
    $items = [];
    $position = 1;
    foreach ( $h2_headings as $name ) {
        $name_trimmed = trim( $name );
        if ( $is_numbered_list_type ) {
            // Strict — must start with digit + dot/paren/space + word
            if ( ! preg_match( '/^\d+[.)\s]+\w/', $name_trimmed ) ) continue;
        } else {
            if ( preg_match( '/^(introduction|conclusion|faq|frequently asked|summary|final thoughts|key takeaway|pros|cons|reference|quick comparison)/i', $name_trimmed ) ) continue;
        }
        $items[] = [ '@type' => 'ListItem', 'position' => $position++, 'name' => $name_trimmed ];
        if ( $position > 30 ) break;
    }
    return $items;
}

// ----- Test cases — post 790 H2 list verbatim -----
$post790_h2s = [
    'Best British Gardening Tools 2026',                                // article title (NOT a product)
    'Key Takeaways',                                                    // generic — skip
    '1. Spear & Jackson Razorsharp Advance Secateurs',                  // ✓ product 1
    '2. Bulldog Premier Digging Spade',                                 // ✓ product 2
    '3. Kent & Stowe Stainless Steel Border Fork',                      // ✓ product 3
    '4. Niwaki GR Pro Secateurs',                                       // ✓ product 4
    '5. Haws The Warley Fall Watering Can',                             // ✓ product 5
    'What to Look For When Buying the Best British Gardening Tools 2026', // buying-criteria (NOT a product)
    'FAQ',                                                              // generic — skip
];

$pass = 0; $fail = 0; $failures = [];

echo "=== v62.108 — ItemList filter for buying_guide/listicle ===\n\n";

// Test A — buying_guide should produce ONLY 5 items (the numbered products)
echo "--- buying_guide: expect 5 numbered items, no title/criteria ---\n";
$items = generate_itemlist_items_v108( $post790_h2s, 'buying_guide' );
$ok_count = ( count( $items ) === 5 );
$names = array_column( $items, 'name' );
$ok_no_title = ! in_array( 'Best British Gardening Tools 2026', $names, true );
$ok_no_criteria = ! in_array( 'What to Look For When Buying the Best British Gardening Tools 2026', $names, true );
$ok_has_p1 = in_array( '1. Spear & Jackson Razorsharp Advance Secateurs', $names, true );
$ok_has_p5 = in_array( '5. Haws The Warley Fall Watering Can', $names, true );
$checks = [
    [ 'item count == 5', $ok_count ],
    [ 'article title NOT included', $ok_no_title ],
    [ 'buying-criteria H2 NOT included', $ok_no_criteria ],
    [ 'product 1 (Spear & Jackson) included', $ok_has_p1 ],
    [ 'product 5 (Haws Watering Can) included', $ok_has_p5 ],
];
foreach ( $checks as [ $note, $ok ] ) {
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    if ( $ok ) $pass++; else { $fail++; $failures[] = $note; }
    echo "$sym  {$note}\n";
}
echo "  emitted item names: " . json_encode( $names, JSON_UNESCAPED_UNICODE ) . "\n\n";

// Test B — listicle same behavior
echo "--- listicle: same numbered-only filter ---\n";
$listicle_h2s = [
    'Best British Workout Routines',
    'Key Takeaways',
    '1. The Park Run',
    '2. Strength Training',
    'Conclusion',
];
$items_l = generate_itemlist_items_v108( $listicle_h2s, 'listicle' );
$ok_l_count = ( count( $items_l ) === 2 );
$sym = $ok_l_count ? "\u{2713}" : "\u{2717}";
if ( $ok_l_count ) $pass++; else { $fail++; $failures[] = 'listicle item count != 2'; }
echo "$sym  listicle: 2 numbered items only (got " . count( $items_l ) . ")\n";

// Test C — non-numbered-list types keep current behavior (RED-side regression check)
echo "\n--- pillar_guide: still uses generic-skip filter (NOT numbered-only) ---\n";
$pillar_h2s = [ 'Introduction', 'Chapter 1: Foundations', 'Chapter 2: Tools', 'Chapter 3: Practice', 'Conclusion', 'FAQ' ];
$items_p = generate_itemlist_items_v108( $pillar_h2s, 'pillar_guide' );
$ok_pillar = ( count( $items_p ) === 3 );
$sym = $ok_pillar ? "\u{2713}" : "\u{2717}";
if ( $ok_pillar ) $pass++; else { $fail++; $failures[] = 'pillar_guide should keep 3 chapter H2s'; }
echo "$sym  pillar_guide: 3 chapters (got " . count( $items_p ) . ")\n";

// Test D — RED proof: v62.107 logic includes title and criteria
echo "\n--- v62.107 (RED) proof: title + criteria slip through ---\n";
$items_red = generate_itemlist_items_v107( $post790_h2s, 'buying_guide' );
$names_red = array_column( $items_red, 'name' );
$includes_bug = in_array( 'Best British Gardening Tools 2026', $names_red, true ) && in_array( 'What to Look For When Buying the Best British Gardening Tools 2026', $names_red, true );
$sym = $includes_bug ? "\u{2713}" : "\u{2717}";
if ( $includes_bug ) $pass++; else { $fail++; $failures[] = 'v107 doesnt actually have the bug?'; }
echo "$sym  v62.107 emits BOTH article-title H2 + buying-criteria H2 (RED bug confirmed)\n";

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";
if ( $fail > 0 ) {
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
