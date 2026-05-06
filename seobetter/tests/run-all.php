<?php
/**
 * Test runner — runs all test-*.php files and writes JSON results
 * to wp-content/uploads/seobetter-tests/results.json so they can be
 * fetched from outside the VPS without SSH.
 *
 * Designed to be invoked from cron every minute on the VPS:
 *
 *   * * * * * /usr/bin/php /home/USER/htdocs/.../wp-content/plugins/seobetter/tests/run-all.php >/dev/null 2>&1
 *
 * Public URL after first run:
 *   https://YOUR-DOMAIN/wp-content/uploads/seobetter-tests/results.json
 *
 * Idempotent — runs all tests, captures stdout + exit code,
 * writes a single JSON document with summary + per-test detail.
 */

$tests_dir   = __DIR__;
$plugin_root = dirname( $tests_dir );
$wp_content  = realpath( $plugin_root . '/../../' );

if ( ! $wp_content || ! is_dir( $wp_content ) ) {
    fwrite( STDERR, "run-all.php: cannot resolve wp-content dir from {$plugin_root}\n" );
    exit( 1 );
}

$out_dir  = $wp_content . '/uploads/seobetter-tests';
$out_file = $out_dir . '/results.json';

if ( ! is_dir( $out_dir ) ) {
    if ( ! @mkdir( $out_dir, 0755, true ) && ! is_dir( $out_dir ) ) {
        fwrite( STDERR, "run-all.php: cannot create {$out_dir}\n" );
        exit( 2 );
    }
}

// Detect plugin version without bootstrapping WP (cron speed)
$version = 'unknown';
$plugin_php = $plugin_root . '/seobetter.php';
if ( is_readable( $plugin_php ) ) {
    $head = file_get_contents( $plugin_php, false, null, 0, 4096 );
    if ( preg_match( "/SEOBETTER_VERSION'\s*,\s*'([^']+)'/", $head, $m ) ) {
        $version = $m[1];
    }
}

$tests   = [];
$passed  = 0;
$failed  = 0;

foreach ( glob( "{$tests_dir}/test-*.php" ) as $test_file ) {
    $base = basename( $test_file, '.php' );
    $name = preg_replace( '/^test-/', '', $base );

    $cmd = escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $test_file ) . ' 2>&1';
    $output = [];
    $exit   = 0;
    $start  = microtime( true );
    exec( $cmd, $output, $exit );
    $duration_ms = (int) ( ( microtime( true ) - $start ) * 1000 );

    $is_pass = ( $exit === 0 );
    if ( $is_pass ) {
        $passed++;
    } else {
        $failed++;
    }

    $tests[] = [
        'name'        => $name,
        'file'        => 'tests/' . basename( $test_file ),
        'pass'        => $is_pass,
        'exit_code'   => $exit,
        'duration_ms' => $duration_ms,
        'output'      => implode( "\n", $output ),
    ];
}

$payload = [
    'timestamp_utc'  => gmdate( 'c' ),
    'plugin_version' => $version,
    'php_version'    => PHP_VERSION,
    'summary'        => [
        'total'  => count( $tests ),
        'passed' => $passed,
        'failed' => $failed,
    ],
    'tests'          => $tests,
];

$json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( $json === false ) {
    fwrite( STDERR, "run-all.php: json_encode failed\n" );
    exit( 3 );
}

if ( file_put_contents( $out_file, $json ) === false ) {
    fwrite( STDERR, "run-all.php: cannot write {$out_file}\n" );
    exit( 4 );
}

echo "Wrote {$out_file} — {$passed}/" . count( $tests ) . " passed\n";
exit( $failed > 0 ? 1 : 0 );
