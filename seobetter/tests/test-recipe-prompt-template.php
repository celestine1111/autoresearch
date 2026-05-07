<?php
/**
 * v1.5.216.62.103 TDD — recipe prose-template enforcement gaps.
 *
 * Found via post 774 + 777 audits (under v62.99-102):
 *   - prepTime / cookTime / totalTime / recipeYield often MISSING from
 *     the schema because the AI doesn't write explicit "Prep Time: X
 *     minutes" / "Servings: N" markers in the body. Schema_Generator
 *     can only extract what AI wrote.
 *   - Country=GB articles get US-style measurements (cups, tbsp, oz)
 *     and US English spelling (flavor, color) because the AI sources
 *     from US recipe sites without locale instructions.
 *   - Per user direction: GB / AU / NZ all use UK English spelling.
 *
 * Fix v62.103: extend Async_Generator::build_recipe_template() to:
 *   1. Inject explicit "Prep Time / Cook Time / Total Time / Servings"
 *      requirement into the guidance for EACH recipe section.
 *   2. Take a $country parameter; for GB / AU / NZ append:
 *      "Use UK English spelling (flavour, colour, organise, recognise).
 *       Prefer UK/AU/NZ recipe sources where available
 *       (BBC Good Food, Mary Berry, Jamie Oliver, ABC, taste.com.au)."
 *   3. For GB / AU / NZ also add: "Ingredients in metric (grams, ml,
 *      celsius) NOT cups/oz/fahrenheit."
 *
 * RED-PHASE MIRROR — current v62.102 behavior. NO Prep/Cook/Total/Servings
 * markers in guidance string. NO country-specific locale instructions.
 *
 * GREEN PHASE: when Async_Generator::build_recipe_template gets the
 * v62.103 changes, this mirror gets the SAME changes in lock-step.
 *
 * Run on VPS: php tests/test-recipe-prompt-template.php
 * Exit 0 = pass. Non-zero = fail.
 */

// GREEN MIRROR (v62.104) — matches Async_Generator::build_recipe_template post-fix.
// Adds Prep/Cook/Total/Servings markers + UK locale enforcement for GB/AU/NZ/IE.
function build_recipe_template_mirror( int $source_count, string $country = '' ): array {
    $count = max( 0, min( 5, $source_count ) );

    if ( $count === 0 ) {
        return [
            'sections' => 'Key Takeaways, Why This Matters, What to Look For, References',
            'guidance' => 'No verified recipe data. Write informational article.',
            'schema'   => 'Article',
        ];
    }

    $recipe_sections = [];
    for ( $i = 1; $i <= $count; $i++ ) {
        $recipe_sections[] = "Recipe {$i}: [Creative Name]";
    }
    $sections = 'Key Takeaways, Why This Matters, Quick Comparison Table, '
        . implode( ', ', $recipe_sections )
        . ', What Ingredients to Avoid, Pros and Cons, FAQ, References';

    // v62.104 — Mandatory time + yield markers in EACH recipe section so
    // Schema_Generator can extract prepTime/cookTime/totalTime/recipeYield.
    $guidance = "Write EXACTLY {$count} recipe(s) — one per real source. "
        . "ABSOLUTE RULE: Every recipe MUST come from REAL RECIPE DATA. Do NOT invent. "
        . "Each recipe MUST start with these EXACT markers on separate lines (so the schema "
        . "generator can extract them): \"Prep Time: X minutes\", \"Cook Time: Y minutes\", "
        . "\"Total Time: Z minutes\", \"Servings: N\". Use the times stated in the source recipe; "
        . "if unstated, estimate sensibly. "
        . "Each recipe MUST have: H2 name, then the four markers above, then "
        . "Ingredients (### Ingredients bullet list), Instructions (### Instructions numbered list), "
        . "Storage Notes. Attribution: \"Inspired by [Source Name](source_url)\" at end of each recipe.";

    // v62.104 — Country-locale enforcement (GB / AU / NZ / IE all use UK English).
    $uk_locale_countries = [ 'GB', 'AU', 'NZ', 'IE' ];
    $uk_recipe_sites = [
        'GB' => 'BBC Good Food, Mary Berry, Jamie Oliver, Olive Magazine, Delicious Magazine, Great British Chefs',
        'AU' => 'taste.com.au, ABC Everyday, Good Food (SMH/Age), recipes.com.au',
        'NZ' => 'recipes.co.nz, Stuff Food, Edmonds, Chelsea Sugar',
        'IE' => 'rte.ie/lifestyle, Bord Bia, Donal Skehan',
    ];
    $country_upper = strtoupper( $country );
    if ( in_array( $country_upper, $uk_locale_countries, true ) ) {
        $sites = $uk_recipe_sites[ $country_upper ] ?? $uk_recipe_sites['GB'];
        $guidance .= " LOCALE ({$country_upper}): use UK English spelling throughout (flavour, colour, "
            . "organise, recognise, centre — NOT flavor / color / organize / center). Use metric "
            . "measurements: grams, millilitres, celsius (NOT cups, tablespoons, ounces, fahrenheit). "
            . "Prefer recipe sources from {$sites} over US sites where the source data permits.";
    }

    return [
        'sections' => $sections,
        'guidance' => $guidance,
        'schema'   => 'Recipe',
    ];
}

// ----- v62.103 contract: 6 assertions on the guidance string -----

$cases = [
    // Test A — MARKERS that must appear in guidance for every country
    [ 'guidance contains "Prep Time" requirement',
      fn() => str_contains( build_recipe_template_mirror( 3, 'GB' )['guidance'], 'Prep Time' ) ],
    [ 'guidance contains "Cook Time" requirement',
      fn() => str_contains( build_recipe_template_mirror( 3, 'GB' )['guidance'], 'Cook Time' ) ],
    [ 'guidance contains "Total Time" requirement',
      fn() => str_contains( build_recipe_template_mirror( 3, 'GB' )['guidance'], 'Total Time' ) ],
    [ 'guidance contains "Servings" requirement',
      fn() => str_contains( build_recipe_template_mirror( 3, 'GB' )['guidance'], 'Servings' ) ],

    // Test B — UK locale guidance for GB / AU / NZ
    [ 'GB: guidance contains "UK English"',
      fn() => str_contains( strtolower( build_recipe_template_mirror( 3, 'GB' )['guidance'] ), 'uk english' ) ],
    [ 'AU: guidance contains "UK English"',
      fn() => str_contains( strtolower( build_recipe_template_mirror( 3, 'AU' )['guidance'] ), 'uk english' ) ],
    [ 'NZ: guidance contains "UK English"',
      fn() => str_contains( strtolower( build_recipe_template_mirror( 3, 'NZ' )['guidance'] ), 'uk english' ) ],
    [ 'GB: guidance mentions metric (grams or ml or celsius)',
      fn() => preg_match( '/\b(gram|metric|celsius|°c)/i', build_recipe_template_mirror( 3, 'GB' )['guidance'] ) === 1 ],
    [ 'GB: guidance mentions UK recipe site (BBC Good Food OR Mary Berry OR Jamie Oliver)',
      fn() => preg_match( '/(BBC Good Food|Mary Berry|Jamie Oliver|olivemagazine|deliciousmagazine|greatbritishchefs)/i', build_recipe_template_mirror( 3, 'GB' )['guidance'] ) === 1 ],

    // Test C — US locale (negative — guidance should NOT push UK English)
    [ 'US: guidance does NOT contain "UK English"',
      fn() => ! str_contains( strtolower( build_recipe_template_mirror( 3, 'US' )['guidance'] ), 'uk english' ) ],
    [ 'US: guidance does NOT push metric-only',
      fn() => ! preg_match( '/grams\s+NOT\s+cups|metric\s+ONLY/i', build_recipe_template_mirror( 3, 'US' )['guidance'] ) ],

    // Test D — empty country defaults gracefully (no UK locale, no error)
    [ 'empty country: guidance returns valid string',
      fn() => is_string( build_recipe_template_mirror( 3, '' )['guidance'] ) && strlen( build_recipe_template_mirror( 3, '' )['guidance'] ) > 100 ],
];

$pass = 0; $fail = 0; $failures = [];

echo "=== v62.103 — recipe prompt template enforcement ===\n\n";
foreach ( $cases as [ $note, $check ] ) {
    $ok = (bool) $check();
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    if ( $ok ) { $pass++; } else { $fail++; $failures[] = $note; }
    echo "{$sym}  {$note}\n";
}

echo "\n=== Sample guidance outputs ===\n\n";
foreach ( [ 'GB', 'AU', 'NZ', 'US', 'IN', '' ] as $c ) {
    $g = build_recipe_template_mirror( 3, $c )['guidance'];
    echo "country=" . ( $c ?: '(empty)' ) . ":\n  " . substr( $g, 0, 240 ) . "...\n\n";
}

echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";

if ( $fail > 0 ) {
    echo "\nFAILURES:\n";
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
