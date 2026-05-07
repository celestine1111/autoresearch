<?php
/**
 * v1.5.216.62.111 TDD — Recipe research diagnostic logger.
 *
 * Bug: posts 800 (Victoria sponge) + 806 (Anzac biscuits) shipped with 0
 * recipe sections under v62.110, despite my direct curl to Tavily returning
 * 5 results with 3419-13570 char raw_content per result. The plugin's
 * `Async_Generator::process_step()` recipe-research path silently returns
 * `$usable_recipe_count = 0` when it should return 5.
 *
 * Without VPS shell access we can't read WP debug.log. Need a file-based
 * logger that writes to wp-content/uploads/seobetter-tests/recipe-research.json
 * (already used by tests/run-all.php) so we can curl the log via HTTP.
 *
 * Helper: Recipe_Research_Logger::append( string $stage, array $payload ): void
 *   - stage: 'tavily_call' | 'tavily_response' | 'tavily_fallback' |
 *            'firecrawl_scrape' | 'extract_loop_iter' | 'final_count'
 *   - payload: stage-specific data (keyword, country, result count,
 *              wp_error_message, response_code, body_len, etc.)
 *
 * The helper appends a new entry to the log file (rotating; keeps last 50
 * entries — older ones drop off so the file doesn't grow unbounded). Each
 * entry has timestamp + stage + payload.
 *
 * Run on VPS: php tests/test-recipe-research-logger.php
 */

// Stub WP helpers.
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return [ 'basedir' => sys_get_temp_dir(), 'baseurl' => 'http://example.com' ];
    }
}

// ----- Mirror of Recipe_Research_Logger -----
class Recipe_Research_Logger_Mirror {
    private const MAX_ENTRIES = 50;

    public static function log_path(): string {
        $upl = wp_upload_dir();
        return rtrim( $upl['basedir'], '/' ) . '/seobetter-tests/recipe-research.json';
    }

    public static function append( string $stage, array $payload ): void {
        $path = self::log_path();
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            @mkdir( $dir, 0755, true );
        }
        $entries = [];
        if ( file_exists( $path ) ) {
            $raw = file_get_contents( $path );
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) $entries = $decoded;
        }
        $entries[] = [
            'ts'      => gmdate( 'c' ),
            'stage'   => $stage,
            'payload' => $payload,
        ];
        if ( count( $entries ) > self::MAX_ENTRIES ) {
            $entries = array_slice( $entries, -self::MAX_ENTRIES );
        }
        file_put_contents( $path, json_encode( $entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
    }

    public static function clear(): void {
        $path = self::log_path();
        if ( file_exists( $path ) ) unlink( $path );
    }
}

// ----- Test cases -----

$tmp_path = Recipe_Research_Logger_Mirror::log_path();
Recipe_Research_Logger_Mirror::clear();

$pass = 0; $fail = 0; $failures = [];

echo "=== v62.111 — Recipe_Research_Logger ===\n\n";

// Case 1: append creates file
Recipe_Research_Logger_Mirror::append( 'tavily_call', [ 'keyword' => 'anzac biscuits', 'country' => 'AU' ] );
$exists = file_exists( $tmp_path );
$sym = $exists ? "\u{2713}" : "\u{2717}";
if ( $exists ) $pass++; else { $fail++; $failures[] = 'log file not created'; }
echo "$sym  log file created at $tmp_path\n";

// Case 2: entry has timestamp + stage + payload
$content = file_get_contents( $tmp_path );
$entries = json_decode( $content, true );
$entry = $entries[0] ?? [];
$has_ts = ! empty( $entry['ts'] );
$has_stage = ( $entry['stage'] ?? '' ) === 'tavily_call';
$has_payload = ( $entry['payload']['keyword'] ?? '' ) === 'anzac biscuits';
foreach ( [ [ 'has_ts', $has_ts ], [ 'has_stage', $has_stage ], [ 'has_payload', $has_payload ] ] as [ $note, $ok ] ) {
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    if ( $ok ) $pass++; else { $fail++; $failures[] = $note; }
    echo "$sym  entry has correct {$note}\n";
}

// Case 3: append adds new entries
Recipe_Research_Logger_Mirror::append( 'tavily_response', [ 'count' => 5, 'http_code' => 200 ] );
Recipe_Research_Logger_Mirror::append( 'firecrawl_scrape', [ 'url' => 'https://taste.com.au/x', 'success' => false ] );
$entries = json_decode( file_get_contents( $tmp_path ), true );
$ok_3 = count( $entries ) === 3;
$sym = $ok_3 ? "\u{2713}" : "\u{2717}";
if ( $ok_3 ) $pass++; else { $fail++; $failures[] = '3-entry append'; }
echo "$sym  appends correctly (now {count($entries)} entries)\n";

// Case 4: rotation kicks in past MAX_ENTRIES
Recipe_Research_Logger_Mirror::clear();
for ( $i = 0; $i < 60; $i++ ) {
    Recipe_Research_Logger_Mirror::append( 'iter', [ 'i' => $i ] );
}
$entries = json_decode( file_get_contents( $tmp_path ), true );
$ok_4 = count( $entries ) === 50;
$first_i = $entries[0]['payload']['i'] ?? -1;
$last_i = $entries[49]['payload']['i'] ?? -1;
$ok_first = $first_i === 10;
$ok_last = $last_i === 59;
foreach ( [ [ 'rotation count == 50', $ok_4 ], [ 'oldest dropped (first.i==10)', $ok_first ], [ 'newest kept (last.i==59)', $ok_last ] ] as [ $note, $ok ] ) {
    $sym = $ok ? "\u{2713}" : "\u{2717}";
    if ( $ok ) $pass++; else { $fail++; $failures[] = $note; }
    echo "$sym  {$note}\n";
}

// Cleanup
Recipe_Research_Logger_Mirror::clear();

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo " PASSED: {$pass}  |  FAILED: {$fail}\n";
echo "═════════════════════════════════════════════════════\n";
if ( $fail > 0 ) {
    foreach ( $failures as $f ) echo "  - $f\n";
    exit( 1 );
}
exit( 0 );
