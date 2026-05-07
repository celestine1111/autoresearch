<?php
/**
 * v1.5.216.62.111 — Recipe research diagnostic logger.
 *
 * Writes a rotating JSON log of every recipe-research stage to
 * `wp-content/uploads/seobetter-tests/recipe-research.json` so failures
 * (silent zero results from Tavily, Firecrawl scrape errors, etc.) can be
 * inspected via curl without VPS shell access.
 *
 * Used by Async_Generator::process_step() in the recipe content_type path.
 *
 * The file is HTTP-readable. Don't log secrets (api keys, etc).
 *
 * Test: tests/test-recipe-research-logger.php
 */

namespace SEOBetter;

class Recipe_Research_Logger {
    private const MAX_ENTRIES = 50;

    public static function log_path(): string {
        $upl = wp_upload_dir();
        return rtrim( $upl['basedir'], '/' ) . '/seobetter-tests/recipe-research.json';
    }

    public static function append( string $stage, array $payload ): void {
        try {
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
            file_put_contents(
                $path,
                json_encode( $entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
            );
        } catch ( \Throwable $e ) {
            // Logger must never throw — diagnostic only.
            error_log( 'SEOBetter Recipe_Research_Logger error: ' . $e->getMessage() );
        }
    }
}
