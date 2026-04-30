<?php

namespace SEOBetter;

/**
 * v1.5.216.26 — SEOBetter Score 0-100 composite (Phase 1 item 7).
 *
 * The "GEO Score" already shipped (in `_seobetter_geo_score` post meta) is a
 * weighted average of 14-15 individual checks. That number is correct but
 * opaque — a user looking at "GEO 78" can't tell whether the 22 missing points
 * came from weak SEO foundations, missing Princeton-backed AI signals, poor
 * extractability for LLM citation, missing schema coverage, or international
 * gaps.
 *
 * SEOBetter Score 0-100 is a re-aggregation of those same checks into the
 * **5-layer + 6-vector optimization framework** documented in
 * SEO-GEO-AI-GUIDELINES.md and the /seobetter skill:
 *
 *   Layer 1 — SEO Foundation:     readability, keyword_density, freshness, bluf_header
 *   Layer 2 — AI Citation Quality: citations, expert_quotes, factual_density,
 *                                  entity_usage, core_eeat (Princeton §1 boosts)
 *   Layer 3 — Extractability:     island_test, section_openings, tables, lists, humanizer
 *   Layer 4 — Schema Coverage:    derived from `_seobetter_schema` post meta
 *                                 (count + completeness of @graph entries)
 *   Layer 6 — International:      international_signals (when country is non-Western-default)
 *
 * Layer 5 (article design / visual) is intentionally NOT scored — per
 * `feedback_layer5_not_scored.md`, it's a visual-only layer; scoring it would
 * conflate aesthetic with SEO/AI quality.
 *
 * Why this ships now (Phase 1 item 7 of locked plan):
 * Item 20 (Recent Articles dashboard column) and the Dashboard restructure
 * (item 19) both surface this composite score alongside the existing GEO
 * Score. Without the composite, users can't see WHICH layer is weak — only
 * that the overall number is low. The composite gives them the same
 * information in actionable buckets.
 *
 * Tier gating: ALL tiers see the score (per locked tier matrix in
 * pro-features-ideas.md §2). The action-item suggestions per layer are
 * left to future work (Phase 2).
 */
class Score_Composite {

    /**
     * Layer composition — which GEO checks roll up into which layer.
     * Keep in sync with SEO-GEO-AI-GUIDELINES.md §1 (Layer framing) +
     * the 5-layer + 6-vector optimization framework in /seobetter skill.
     */
    private const LAYER_CHECKS = [
        'seo_foundation' => [ 'readability', 'keyword_density', 'freshness', 'bluf_header' ],
        'ai_citation'    => [ 'citations', 'expert_quotes', 'factual_density', 'entity_usage', 'core_eeat' ],
        'extractability' => [ 'island_test', 'section_openings', 'tables', 'lists', 'humanizer' ],
    ];

    /**
     * Composite weights when all 4 default layers are scored.
     * AI Citation Quality (Layer 2) gets the highest weight — Princeton's
     * research shows statistics +40%, quotations +41%, citations +30% are
     * the top correlates for AI citation, dwarfing other factors.
     * Sum = 100.
     */
    private const WEIGHTS_DEFAULT = [
        'seo_foundation' => 25,
        'ai_citation'    => 30,
        'extractability' => 25,
        'schema'         => 20,
    ];

    /**
     * Composite weights when international layer is active. International
     * signals (Layer 6) get 20% — pulled proportionally from the other 4.
     * Sum = 100.
     */
    private const WEIGHTS_INTERNATIONAL = [
        'seo_foundation' => 20,
        'ai_citation'    => 25,
        'extractability' => 20,
        'schema'         => 15,
        'international'  => 20,
    ];

    /**
     * Compute the SEOBetter Score composite from existing scoring data.
     *
     * @param array    $score_data  Output of GEO_Analyzer::analyze() — must contain ['checks' => [...]]
     * @param int|null $post_id     Optional — used to read schema coverage from `_seobetter_schema` meta
     * @return array{score:int,grade:string,layers:array<string,?int>,weights:array<string,int>}
     */
    public static function compute( array $score_data, ?int $post_id = null ): array {
        $checks = is_array( $score_data['checks'] ?? null ) ? $score_data['checks'] : [];

        $layers = [
            'seo_foundation' => self::layer_avg( $checks, self::LAYER_CHECKS['seo_foundation'] ),
            'ai_citation'    => self::layer_avg( $checks, self::LAYER_CHECKS['ai_citation'] ),
            'extractability' => self::layer_avg( $checks, self::LAYER_CHECKS['extractability'] ),
            'schema'         => self::compute_schema_score( $post_id ),
        ];

        $international = null;
        if ( isset( $checks['international_signals']['score'] ) ) {
            $international = (int) $checks['international_signals']['score'];
            $layers['international'] = $international;
        }

        $weights = $international !== null ? self::WEIGHTS_INTERNATIONAL : self::WEIGHTS_DEFAULT;

        $sum    = 0;
        $total  = 0;
        foreach ( $weights as $key => $w ) {
            $val = $layers[ $key ] ?? null;
            if ( $val === null ) {
                continue;
            }
            $sum   += $val * $w;
            $total += $w;
        }
        $composite = $total > 0 ? (int) round( $sum / $total ) : 0;

        return [
            'score'   => $composite,
            'grade'   => self::grade( $composite ),
            'layers'  => $layers,
            'weights' => $weights,
        ];
    }

    /**
     * Average score across the named GEO checks. Skipped checks (score 100
     * with detail "skipped") are included — they correctly indicate "this
     * type doesn't need it" rather than "this is failing".
     *
     * Returns null if no named checks were found in $checks (e.g. if the
     * GEO_Analyzer was an older version pre-dating those checks).
     */
    private static function layer_avg( array $checks, array $check_names ): ?int {
        $sum   = 0;
        $count = 0;
        foreach ( $check_names as $name ) {
            if ( isset( $checks[ $name ]['score'] ) ) {
                $sum += (int) $checks[ $name ]['score'];
                $count++;
            }
        }
        return $count > 0 ? (int) round( $sum / $count ) : null;
    }

    /**
     * Schema coverage score (Layer 4). Reads `_seobetter_schema` post meta
     * (set by sync_seo_plugin_meta() on save) and scores it 0-100 based on:
     *   - Has @graph with at least one node       → 50 base
     *   - @graph has Article-or-equivalent root   → +30
     *   - @graph has BreadcrumbList                → +10
     *   - @graph has FAQPage or HowTo              → +10
     *
     * Caps at 100. Returns null when no post_id supplied (compute() called
     * with no post context — e.g. live preview during generation).
     *
     * Note: this is a coarse coverage check, not a Google Rich Results
     * validity check. Phase 2 may swap this for actual structured-data
     * validation against `structured-data.md` §4 required fields.
     */
    private static function compute_schema_score( ?int $post_id ): ?int {
        if ( ! $post_id ) {
            return null;
        }
        $raw = get_post_meta( $post_id, '_seobetter_schema', true );
        if ( ! is_string( $raw ) || $raw === '' ) {
            return 0;
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) || empty( $decoded['@graph'] ) || ! is_array( $decoded['@graph'] ) ) {
            return 0;
        }
        $types = [];
        foreach ( $decoded['@graph'] as $node ) {
            $t = $node['@type'] ?? null;
            if ( is_array( $t ) ) {
                foreach ( $t as $sub ) {
                    $types[] = (string) $sub;
                }
            } elseif ( is_string( $t ) ) {
                $types[] = $t;
            }
        }
        if ( empty( $types ) ) {
            return 0;
        }

        $score = 50;

        $article_types = [ 'Article', 'NewsArticle', 'BlogPosting', 'TechArticle', 'ScholarlyArticle', 'Recipe', 'HowTo', 'Review', 'Product', 'Event' ];
        $has_article = false;
        foreach ( $types as $t ) {
            if ( in_array( $t, $article_types, true ) ) {
                $has_article = true;
                break;
            }
        }
        if ( $has_article ) {
            $score += 30;
        }
        if ( in_array( 'BreadcrumbList', $types, true ) ) {
            $score += 10;
        }
        if ( in_array( 'FAQPage', $types, true ) || in_array( 'HowTo', $types, true ) ) {
            $score += 10;
        }
        return min( 100, $score );
    }

    /**
     * Map composite 0-100 to letter grade. Same buckets as GEO_Analyzer
     * for visual consistency.
     */
    private static function grade( int $score ): string {
        if ( $score >= 90 ) return 'A';
        if ( $score >= 80 ) return 'B';
        if ( $score >= 70 ) return 'C';
        if ( $score >= 60 ) return 'D';
        return 'F';
    }

    /**
     * Human-readable layer labels — used in metabox + Recent Articles column.
     */
    public static function layer_label( string $layer_key ): string {
        $labels = [
            'seo_foundation' => __( 'SEO Foundation', 'seobetter' ),
            'ai_citation'    => __( 'AI Citation Quality', 'seobetter' ),
            'extractability' => __( 'Extractability', 'seobetter' ),
            'schema'         => __( 'Schema Coverage', 'seobetter' ),
            'international'  => __( 'International Signals', 'seobetter' ),
        ];
        return $labels[ $layer_key ] ?? $layer_key;
    }
}
