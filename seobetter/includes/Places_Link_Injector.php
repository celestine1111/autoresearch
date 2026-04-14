<?php
/**
 * Places_Link_Injector — v1.5.29
 *
 * Post-generation pass that walks a local-intent listicle and, for every H2
 * whose heading matches a verified Places Pool entry, injects an address +
 * Google Maps + website + phone meta line immediately below the heading.
 *
 * Mirrors the pattern used by Content_Injector::inject_citations() (which
 * injects URLs for research claims). Same defensive shape, different atom.
 *
 * Why this exists: the Places waterfall fetches real business data (address,
 * website, phone, lat/lon) but the AI writing each listicle section has no
 * obligation to include any of it in the body. Readers see bare H2 headings
 * ("Gelateria X") with no way to find the actual business. This injector
 * enforces a consistent contact line per section, sourced directly from the
 * verified pool so there is zero hallucination risk.
 *
 * Runs AFTER Places_Validator::validate() in Async_Generator::assemble_final()
 * so we only decorate real business sections that survived the validator.
 */

namespace SEOBetter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Places_Link_Injector {

    /**
     * Main entry. Walk the article HTML and inject an address/links meta line
     * under every H2 that matches a pool entry.
     *
     * @param string $html         Assembled article HTML (post-Places_Validator).
     * @param array  $places_pool  Array of place entries from research.js fetchPlacesWaterfall.
     * @return string Cleaned HTML with meta lines injected under matching H2s.
     */
    public static function inject( string $html, array $places_pool ): string {
        if ( empty( $places_pool ) ) {
            return $html;
        }

        // Use a regex to find every H2 opening/content/closing sequence and
        // decorate it. We don't split and rejoin because that would risk
        // double-encoding entities already in the HTML.
        return preg_replace_callback(
            '/(<h2(?:\s[^>]*)?>)(.*?)(<\/h2>)/is',
            function ( $m ) use ( $places_pool ) {
                $heading_html = $m[0];
                $heading_text = wp_strip_all_tags( $m[2] );
                $heading_text = trim( html_entity_decode( $heading_text, ENT_QUOTES, 'UTF-8' ) );

                // Strip listicle numbering like "1. ", "#1: ", etc
                $candidate = preg_replace( '/^(?:#?\d+[\.\):—\-]|no\.\s*\d+\s*[—\-])\s*/i', '', $heading_text );

                // Skip generic headings (FAQ, References, Conclusion, etc)
                if ( self::is_generic_heading( $candidate ) ) {
                    return $heading_html;
                }

                // Try to match against the pool
                $entry = Places_Validator::pool_lookup( $candidate, $places_pool );
                if ( $entry === null ) {
                    return $heading_html;
                }

                $meta_line = self::build_meta_line( $entry );
                if ( $meta_line === '' ) {
                    return $heading_html;
                }

                return $heading_html . "\n" . $meta_line;
            },
            $html
        );
    }

    /**
     * Build the <p class="sb-place-meta"> line for a single pool entry. Only
     * includes fields that are non-empty — if a place has no website we just
     * omit the "Website" link rather than render a broken href.
     */
    private static function build_meta_line( array $entry ): string {
        $name    = trim( (string) ( $entry['name'] ?? '' ) );
        $address = trim( (string) ( $entry['address'] ?? '' ) );
        $website = trim( (string) ( $entry['website'] ?? '' ) );
        $phone   = trim( (string) ( $entry['phone'] ?? '' ) );
        $source  = trim( (string) ( $entry['source'] ?? '' ) );
        $rating  = isset( $entry['rating'] ) ? (float) $entry['rating'] : 0.0;

        if ( $name === '' ) {
            return '';
        }

        $parts = [];

        // Address with pin emoji
        if ( $address !== '' ) {
            $parts[] = '📍 ' . esc_html( $address );
        }

        // Google Maps search URL — public scheme, no API key needed. Always
        // builds a valid URL given the business name (+ address if available).
        $maps_query = trim( $name . ' ' . $address );
        if ( $maps_query !== '' ) {
            $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $maps_query );
            $parts[] = '<a href="' . esc_url( $maps_url ) . '" target="_blank" rel="noopener">View on Google Maps</a>';
        }

        // Business website (optional)
        if ( $website !== '' && filter_var( $website, FILTER_VALIDATE_URL ) ) {
            $parts[] = '<a href="' . esc_url( $website ) . '" target="_blank" rel="noopener">Website</a>';
        }

        // Phone (optional) — clickable tel: link
        if ( $phone !== '' ) {
            $tel = preg_replace( '/[^0-9+]/', '', $phone );
            if ( $tel !== '' ) {
                $parts[] = '<a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $phone ) . '</a>';
            }
        }

        // Rating + provider attribution (optional)
        if ( $rating > 0 && $source !== '' ) {
            $parts[] = '⭐ ' . esc_html( number_format( $rating, 1 ) ) . ' (' . esc_html( $source ) . ')';
        } elseif ( $source !== '' ) {
            $parts[] = '<span style="color:#94a3b8">Verified via ' . esc_html( $source ) . '</span>';
        }

        if ( empty( $parts ) ) {
            return '';
        }

        $content = implode( ' &middot; ', $parts );

        return '<p class="sb-place-meta" style="font-size:13px;color:#475569;margin:4px 0 16px 0;line-height:1.6">'
            . $content . '</p>';
    }

    /**
     * Filter out generic section headings that aren't specific businesses.
     * Same list as Places_Validator uses — kept in sync manually.
     */
    private static function is_generic_heading( string $heading ): bool {
        $lower = strtolower( trim( $heading ) );
        $generic = [
            'conclusion', 'faq', 'references', 'sources', 'introduction', 'summary',
            'key takeaways', 'how to get there', 'best time to visit', 'planning your trip',
            'what to know', 'final thoughts', 'pros and cons', 'overview', 'why we love it',
            'frequently asked questions', 'about this guide', 'disclaimer',
            'what to look for', 'history', 'history and cultural context', 'regional variations',
            'how to find', 'questions to ask', 'how to find quality',
        ];
        if ( in_array( $lower, $generic, true ) ) {
            return true;
        }
        // Too short or too long — not a business name
        if ( strlen( $heading ) < 3 || strlen( $heading ) > 120 ) {
            return true;
        }
        return false;
    }
}
