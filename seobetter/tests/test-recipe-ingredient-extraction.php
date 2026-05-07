<?php
/**
 * v1.5.216.62.97 TDD — Recipe ingredient extraction nutrition-pollution bug.
 *
 * Found via post 769 audit (cornish pasty Recipe / GB / 1500w under v62.96):
 * Schema_Generator::build_recipe() returned a recipeIngredient array
 * containing nutrition facts and yield indicators instead of real
 * ingredients:
 *
 *   recipeIngredient[0] = "(Nutrition for one serving)"
 *   recipeIngredient[1] = "Servings Per Recipe: 6"
 *   recipeIngredient[2] = "Calories: 558"
 *   recipeIngredient[3] = "Total Carbohydrate: 51g (19%)"
 *   ...
 *
 * Root cause: the v1.5.145 nutrition skip filter at Schema_Generator.php
 * line ~1287-1293 only catches lines that START with a digit
 * (e.g. "27g Fat"). It misses:
 *   - "(Nutrition for one serving)"  — wrap-paren header
 *   - "Servings Per Recipe: 6"        — yield indicator (should be EXTRACTED to recipeYield, not just skipped)
 *   - "Calories: 558"                  — macro:value form (digit at end, not start)
 *   - "Total Carbohydrate: 51g (19%)"  — full-word "Carbohydrate" (regex only matches "carbs?")
 *   - "Total Sugars: 3g"               — full-word "Sugars" (regex matches "sugar" but anchored at start)
 *   - "Saturated Fat: 12g"             — leading word "Saturated" (anchored differently)
 *   - "Trans Fat: 0g"                  — leading word "Trans"
 *   - "Cholesterol: 100mg"             — colon form
 *   - "Sodium: 600mg"                  — colon form
 *   - "Daily Value (DV): X%"           — "Daily Value" prefix
 *
 * Fix: extend the per-item skip-detector to handle both directions
 * (digit-first AND macro-first colon-form), wrap-paren nutrition headers,
 * "Daily Value" / "Per Serving" / "Servings Per Recipe" prefixes.
 *
 * Run on VPS: php /path/to/seobetter/tests/test-recipe-ingredient-extraction.php
 * Exit 0 = all pass. Non-zero = failures.
 */

// ----- Production helper under test (mirrored from Schema_Generator.php
//       v62.97 build_recipe() — the per-item nutrition-skip detector) -----
//
// The test mirrors the contract: given an <li> text, return true if the line
// is nutrition / yield / serving-info (i.e. NOT an ingredient).
// In RED phase this mirror reflects the v62.96 logic and FAILS on the post-769
// strings. In GREEN phase Schema_Generator.php gets the patterns added below
// AND this mirror is updated to match — both must be in sync.

// GREEN-PHASE MIRROR — v62.98 production logic. Mirrors the extended
// nutrition-pollution filter from Schema_Generator::build_recipe() lines
// ~1287-1310. Keep this in lock-step with production.
function is_nutrition_or_yield_line( string $item ): bool {
    // v1.5.145 — Skip nutritional data (line starts with digit + unit + macro)
    if ( preg_match( '/^\d+\s*(g|mg|mcg|kcal|cal|%)?\s*(fat|carbs?|protein|calories?|cal|kcal|sodium|fiber|fibre|sugar|cholesterol|saturated|iron|calcium|potassium|vitamin|total\s+fat|total\s+sugar|trans\s+fat|dietary\s+fiber)/i', $item ) ) return true;
    if ( preg_match( '/\b(calories?|kcal)\s*(\(|per\s)/i', $item ) ) return true;
    if ( preg_match( '/^(total\s+)?(fat|carbs?|protein|sodium|sugar|fiber)\b/i', $item ) ) return true;
    if ( preg_match( '/^(nutrition|per\s+serving|serving\s+size|daily\s+value)/i', $item ) ) return true;
    if ( preg_match( '/^[\d\s.,%]+$/', $item ) ) return true;

    // v1.5.216.62.98 — extended filter (post 769 bug fix)
    // (a) Wrap-paren nutrition header
    if ( preg_match( '/^\s*\(\s*(nutrition|per\s+serving|serving\s+size|daily\s+value|nutritional\s+info)/i', $item ) ) return true;
    // (b) "Servings Per Recipe: N" — yield indicator
    if ( preg_match( '/^servings?\s+per\s+recipe\b/i', $item ) ) return true;
    // (c) Macro-first colon form: "Calories: 558", "Total Carbohydrate: 51g (19%)", etc.
    // v62.99 — added trailing s? to plural-able alternatives (Total Sugars, Total Carbs)
    if ( preg_match(
        '/^(calories?|kcal|cholesterol|sodium|potassium|iron|calcium|vitamin\s+\w+|protein|' .
        'total\s+(?:carbohydrates?|carbs?|fats?|sugars?|fibers?|fibres?)|dietary\s+(?:fibers?|fibres?)|' .
        'saturated\s+fat|trans\s+fat|monounsaturated\s+fat|polyunsaturated\s+fat|' .
        'sugars?|carbohydrates?|fats?)\s*[:.]/i',
        $item
    ) ) return true;
    // (d) "Daily Value" / "(DV)" reference
    if ( preg_match( '/^daily\s+value\b|\(\s*dv\s*\)/i', $item ) ) return true;

    return false;
}

// ----- Companion: yield extraction from "Servings Per Recipe: N" -----
// Tests the recipeYield-fallback path. Production already has two yield
// regexes (yield/serve/serving/makes/portion + serves/makes/yields N).
// Neither catches "Servings Per Recipe: 6" exactly — must add a third.
// GREEN MIRROR — v62.98. Adds "Servings Per Recipe: N" pattern (post 769 case).
function extract_yield_from_body( string $body_text ): string {
    if ( preg_match( '/(?:yield|serve|serving|makes|portion)[\s:]*(\d+\s*(?:serving|piece|treat|cookie|batch|portion|loaf|loaves|slice|roll)[s]?)/i', $body_text, $y1 ) ) {
        return $y1[1];
    }
    if ( preg_match( '/(?:serves|makes|yields?)\s*[\s:]*(\d+)/i', $body_text, $y2 ) ) {
        return $y2[1] . ' servings';
    }
    // v1.5.216.62.98 — "Servings Per Recipe: N" pattern (post 769)
    if ( preg_match( '/servings?\s+per\s+recipe[\s:]*(\d+)/i', $body_text, $y3 ) ) {
        return $y3[1] . ' servings';
    }
    return '';
}

// ----- Test cases -----

$nutrition_lines = [
    // From post 769 ul[2] verbatim (these MUST be detected as nutrition)
    [ '(Nutrition for one serving)',     true,  'wrap-paren header (post 769)' ],
    [ 'Servings Per Recipe: 6',          true,  'yield indicator (post 769)' ],
    [ 'Calories: 558',                   true,  'colon-form calories (post 769)' ],
    [ 'Total Carbohydrate: 51g (19%)',   true,  'colon-form full-word Carbohydrate (post 769)' ],
    [ 'Dietary Fiber: 4g (13%)',         true,  'colon-form Dietary Fiber (post 769)' ],
    [ 'Total Sugars: 3g',                true,  'colon-form Total Sugars (post 769)' ],
    [ 'Protein: 26g (52%)',              true,  'colon-form Protein with DV (post 769)' ],
    [ 'Total Fat: 27g (35%)',            true,  'colon-form Total Fat with DV (post 769)' ],
    [ 'Saturated Fat: 12g',              true,  'colon-form Saturated Fat' ],
    [ 'Trans Fat: 0g',                   true,  'colon-form Trans Fat' ],
    [ 'Cholesterol: 100mg',              true,  'colon-form Cholesterol' ],
    [ 'Sodium: 600mg (26%)',             true,  'colon-form Sodium with DV' ],
    [ 'Iron: 4mg',                       true,  'colon-form Iron' ],
    [ 'Calcium: 50mg',                   true,  'colon-form Calcium' ],
    [ 'Vitamin C: 5mg',                  true,  'colon-form Vitamin C' ],
    [ 'Daily Value (DV) percentages',    true,  'Daily Value reference' ],

    // Already-handled by v62.96 patterns (regression check)
    [ '27g Fat',                         true,  'digit-first macro (v62.96 covered)' ],
    [ '51g Carbs',                       true,  'digit-first carbs (v62.96 covered)' ],
    [ '26g Protein',                     true,  'digit-first protein (v62.96 covered)' ],
    [ 'Calories per serving',            true,  'calories per serving phrasing (v62.96)' ],
    [ '558',                             true,  'pure number (v62.96 covered)' ],
];

$real_ingredients = [
    [ '1 large egg',                     false, 'real ingredient: egg' ],
    [ '1 teaspoon water',                false, 'real ingredient: water' ],
    [ '2 tablespoons butter, cut into 8 thin slices', false, 'real ingredient: butter' ],
    [ '500g beef skirt, finely diced',   false, 'real ingredient: beef skirt (UK style)' ],
    [ '1 medium swede, peeled and diced',false, 'real ingredient: swede' ],
    [ '2 large potatoes, peeled and diced', false, 'real ingredient: potatoes' ],
    [ '1 medium onion, finely chopped',  false, 'real ingredient: onion' ],
    [ '500g shortcrust pastry',          false, 'real ingredient: pastry' ],
    [ 'salt and pepper to taste',        false, 'real ingredient: seasoning' ],
    [ '1 egg, beaten (for egg wash)',    false, 'real ingredient with paren note' ],
];

$yield_extractions = [
    // [ body_text_snippet, expected_yield, note ]
    [ 'Servings Per Recipe: 6',                          '6 servings', 'BUG: post 769 — v62.96 returns "" → fails. v62.97 must extract.' ],
    [ 'This recipe yields 8 servings of cornish pasty.', '8 servings', 'v62.96 covered (yields N servings)' ],
    [ 'Serves 6 hungry adults.',                          '6 servings', 'v62.96 covered (serves N → defaults to "servings" unit)' ],
];

$passed = 0; $failed = 0;
$failures = [];

echo "=== Test 1: nutrition-line detection (RED if ANY post-769 string fails) ===\n\n";
foreach ( $nutrition_lines as [ $line, $expected, $note ] ) {
    $actual = is_nutrition_or_yield_line( $line );
    $sym = ( $actual === $expected ) ? "\u{2713}" : "\u{2717}";
    if ( $actual === $expected ) { $passed++; } else {
        $failed++;
        $failures[] = "[nutrition-line] {$note}: input " . var_export( $line, true ) . " expected " . var_export( $expected, true ) . " got " . var_export( $actual, true );
    }
    printf( "%s  is_nutrition_or_yield_line(%s) → %s  (%s)\n",
        $sym,
        var_export( $line, true ),
        $actual ? 'NUTRITION' : 'INGREDIENT',
        $note
    );
}

echo "\n=== Test 2: real-ingredient survival (must NOT be detected as nutrition) ===\n\n";
foreach ( $real_ingredients as [ $line, $expected, $note ] ) {
    $actual = is_nutrition_or_yield_line( $line );
    $sym = ( $actual === $expected ) ? "\u{2713}" : "\u{2717}";
    if ( $actual === $expected ) { $passed++; } else {
        $failed++;
        $failures[] = "[real-ingredient] {$note}: input " . var_export( $line, true ) . " expected " . var_export( $expected, true ) . " got " . var_export( $actual, true );
    }
    printf( "%s  is_nutrition_or_yield_line(%s) → %s  (%s)\n",
        $sym,
        var_export( $line, true ),
        $actual ? 'NUTRITION' : 'INGREDIENT',
        $note
    );
}

echo "\n=== Test 3: recipeYield extraction from 'Servings Per Recipe: N' ===\n\n";
foreach ( $yield_extractions as [ $body, $expected ] ) {
    $actual = extract_yield_from_body( $body );
    $sym = ( $actual === $expected ) ? "\u{2713}" : "\u{2717}";
    if ( $actual === $expected ) { $passed++; } else {
        $failed++;
        $failures[] = "[yield] body=" . var_export( $body, true ) . " expected " . var_export( $expected, true ) . " got " . var_export( $actual, true );
    }
    printf( "%s  extract_yield_from_body(%s) → %s  (expected %s)\n",
        $sym,
        var_export( substr( $body, 0, 60 ), true ),
        var_export( $actual, true ),
        var_export( $expected, true )
    );
}

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$passed}  |  FAILED: {$failed}\n";
echo "═════════════════════════════════════════════════════\n";

if ( $failed > 0 ) {
    echo "\nFAILURE DETAILS:\n";
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
