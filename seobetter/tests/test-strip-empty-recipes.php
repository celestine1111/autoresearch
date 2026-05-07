<?php
/**
 * v1.5.216.62.108 TDD — strip_unsourced_recipes misses keyword-prefixed headings.
 *
 * Found via post 788 audit: AI generated 5 recipe-section headings but only
 * 2 had full content (ingredients <ul> + instructions <ol> + Inspired-by
 * attribution). Recipes 4 & 5 were 50-70w stubs with no list/source. Recipe 3
 * was missing entirely. Empty stubs polluted the rendered article.
 *
 * Root cause: `strip_unsourced_recipes` regex `^recipe\s*\d|^recipe:` only
 * matches headings that START with "Recipe". Post 788's headings emitted by
 * the AI follow the v62.104 prompt template exactly — "Recipe N: [Creative
 * Name]" — but the AI added the keyword prefix ("Homemade Cornish Pasty
 * Recipe 4: Olive Magazine's Flaky Delight"). The regex requires the line
 * to start with "Recipe" so this slipped through unstripped.
 *
 * Fix v62.108: relax the regex to match "Recipe N:" / "Recipe N —" anywhere
 * in the heading, with a word-boundary anchor:
 *   /\brecipe\s*\d|\brecipe\s*:/i
 *
 * RED-PHASE MIRROR — current v62.107 logic. Returns input unchanged when
 * heading is "Homemade ... Recipe 4: ...". Assertions FAIL.
 *
 * Run on VPS: php tests/test-strip-empty-recipes.php
 */

// GREEN MIRROR (v62.108) — broadened regex matches "Recipe N" anywhere in heading.
function strip_unsourced_recipes_mirror( string $markdown ): string {
    $parts = preg_split( '/^(##?\s+.+)$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE );
    $result = '';
    $stripped_count = 0;
    for ( $i = 0; $i < count( $parts ); $i++ ) {
        $part = $parts[ $i ];
        if ( preg_match( '/^##?\s+(.+)$/m', $part, $heading_match ) ) {
            $heading = $heading_match[1];
            $body = $parts[ $i + 1 ] ?? '';
            // v62.108 — broadened: \brecipe\s*\d matches "Recipe N" anywhere in heading
            // (was ^recipe\s*\d which missed "Homemade Cornish Pasty Recipe 4: ...").
            $is_recipe = preg_match( '/\brecipe\s*\d|\brecipe\s*:/i', trim( $heading ) )
                || ( preg_match( '/###\s*ingredients/i', $body ) && preg_match( '/###\s*instructions/i', $body ) );
            if ( $is_recipe ) {
                $has_source = preg_match( '/Inspired by\s*\[([^\]]+)\]\(https?:\/\/[^)]+\)/', $body )
                    || preg_match( '/Inspired by.{0,60}https?:\/\//', $body )
                    || preg_match( '/Source:\s*https?:\/\//', $body );
                if ( ! $has_source ) {
                    $stripped_count++;
                    $i++;
                    continue;
                }
            }
            $result .= $part;
        } else {
            $result .= $part;
        }
    }
    if ( strlen( $result ) < strlen( $markdown ) * 0.3 ) return $markdown;
    return $result;
}

// ----- Test cases -----

// Empty Recipe 4 with keyword-prefixed heading (post 788 case)
$post788_recipe_4_empty = "## Homemade Cornish Pasty Recipe 4: Olive Magazine's Flaky Delight\n\n"
    . "This is a brief intro paragraph about a flaky pasty. About 50 words of prose with no ingredients list or instructions. The AI ran out of context for this section and just wrote a stub.";

// Empty Recipe 5 with keyword-prefixed heading (post 788 case)
$post788_recipe_5_empty = "## Homemade Cornish Pasty Recipe 5: Great British Chefs' Heritage Bake\n\n"
    . "Another intro stub about a heritage pasty. No ingredients, no instructions, no Inspired-by attribution.";

// Full Recipe 1 (should be KEPT)
$post788_recipe_1_full = "## Homemade Cornish Pasty Recipe 1: Traditional Cornish Classic\n\n"
    . "Prep Time: 30 minutes\nCook Time: 50 minutes\n\n"
    . "### Ingredients\n- 500g beef skirt\n- 250g potato\n- 1 onion\n\n"
    . "### Instructions\n1. Roll the pastry\n2. Add filling\n3. Bake\n\n"
    . "Inspired by [BBC Good Food](https://www.bbcgoodfood.com/recipes/cornish-pasty)";

// Non-recipe section (should be KEPT regardless)
$key_takeaways = "## Key Takeaways\n- Pasties are a classic British dish\n- Beef skirt is the traditional filling";

// Compose multi-section markdown
$full_markdown = "# Homemade Cornish Pasty Recipe\n\n"
    . $key_takeaways . "\n\n"
    . $post788_recipe_1_full . "\n\n"
    . $post788_recipe_4_empty . "\n\n"
    . $post788_recipe_5_empty . "\n\n"
    . "## What Ingredients to Avoid\n\nDon't use raw eggs in filling.";

$out = strip_unsourced_recipes_mirror( $full_markdown );

// ----- Assertions for v62.108 contract -----
$cases = [
    [ 'Recipe 4 (empty stub) is STRIPPED from output',
      fn() => ! str_contains( $out, "Recipe 4: Olive Magazine" ) ],
    [ 'Recipe 5 (empty stub) is STRIPPED from output',
      fn() => ! str_contains( $out, "Recipe 5: Great British Chefs" ) ],
    [ 'Recipe 1 (full) is KEPT',
      fn() => str_contains( $out, "Recipe 1: Traditional Cornish Classic" ) ],
    [ 'Key Takeaways is KEPT',
      fn() => str_contains( $out, "Key Takeaways" ) ],
    [ 'What Ingredients to Avoid is KEPT',
      fn() => str_contains( $out, "What Ingredients to Avoid" ) ],
];

$pass = 0; $fail = 0; $failures = [];
echo "=== v62.108 — strip_unsourced_recipes catches keyword-prefixed headings ===\n\n";
foreach ( $cases as [ $note, $check ] ) {
    $ok = (bool) $check();
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    if ( $ok ) { $pass++; } else { $fail++; $failures[] = $note; }
    echo "$sym  {$note}\n";
}

echo "\n=== Output preview ===\n";
echo $out . "\n\n";

echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";
if ( $fail > 0 ) {
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
