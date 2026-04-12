<?php

namespace SEOBetter;

/**
 * 5-Part Content Ranking Framework.
 *
 * Scaffolding that maps the existing async generation pipeline onto the
 * 5-part framework from SEO-GEO-AI-GUIDELINES §28:
 *
 *   Step 1 — Topic Selection via Competitor Analysis
 *   Step 2 — Keyword Research Protocol
 *   Step 3 — Keyword Intent Grouping
 *   Step 4 — Research-First Writing
 *   Step 5 — Quality Gate + Schema
 *
 * This class doesn't reimplement generation — it's a thin phase tracker
 * that the content generator can call to log which framework phases
 * have been completed for a given keyword/post, expose phase results
 * for admin UI progress display, and run the quality gate check
 * (Step 5) before allowing publish.
 *
 * The framework is opt-in: calling `run_pipeline()` executes all 5
 * phases synchronously and returns a report. The async Async_Generator
 * equivalent goes through the same phases incrementally and records
 * their status to post meta via `mark_phase_complete()`.
 *
 * Post meta keys written:
 *
 *   _seobetter_framework_phase   — current phase (1-5) or 'complete'
 *   _seobetter_framework_report  — phase-by-phase JSON report
 *   _seobetter_quality_gate      — 'passed' / 'failed' / 'pending'
 */
class Content_Ranking_Framework {

    /** Minimum GEO score required to pass Step 5 Quality Gate. */
    private const QUALITY_GATE_MIN_SCORE = 60;

    /**
     * Run all 5 phases for a keyword and return a phase report.
     *
     * This is the synchronous entry point — suitable for CLI or tests.
     * For real article generation, Async_Generator handles the same
     * phases incrementally via mark_phase_complete().
     */
    public function run_pipeline( string $keyword, array $options = [] ): array {
        $report = [
            'keyword' => $keyword,
            'started_at' => current_time( 'mysql' ),
            'phases' => [],
            'passed' => false,
        ];

        // Step 1 — Topic Selection
        $report['phases'][1] = $this->phase_topic_selection( $keyword, $options );
        if ( ! $report['phases'][1]['passed'] ) {
            $report['failed_at'] = 1;
            return $report;
        }

        // Step 2 — Keyword Research
        $report['phases'][2] = $this->phase_keyword_research( $keyword, $options );

        // Step 3 — Intent Grouping
        $report['phases'][3] = $this->phase_intent_grouping( $keyword );

        // Step 4 — Research-First Writing (status only — actual writing
        // happens in Async_Generator)
        $report['phases'][4] = $this->phase_research_first_writing( $keyword, $options );

        // Step 5 — Quality Gate (runs only after generation)
        if ( ! empty( $options['content'] ) ) {
            $report['phases'][5] = $this->phase_quality_gate(
                $options['content'],
                $keyword,
                $options['content_type'] ?? ''
            );
            $report['passed'] = $report['phases'][5]['passed'];
        } else {
            $report['phases'][5] = [
                'passed' => false,
                'status' => 'pending',
                'detail' => 'Quality gate runs after content is generated',
            ];
        }

        $report['completed_at'] = current_time( 'mysql' );
        return $report;
    }

    /**
     * Record that a specific phase has completed for a post.
     * Called by Async_Generator at each step boundary.
     */
    public function mark_phase_complete( int $post_id, int $phase, array $data = [] ): void {
        update_post_meta( $post_id, '_seobetter_framework_phase', $phase );

        $existing = get_post_meta( $post_id, '_seobetter_framework_report', true );
        $report = $existing ? json_decode( $existing, true ) : [ 'phases' => [] ];
        if ( ! is_array( $report ) ) {
            $report = [ 'phases' => [] ];
        }
        $report['phases'][ $phase ] = array_merge(
            [ 'completed_at' => current_time( 'mysql' ), 'passed' => true ],
            $data
        );
        update_post_meta( $post_id, '_seobetter_framework_report', wp_json_encode( $report ) );
    }

    /**
     * Run the quality gate standalone on saved content.
     * Used by the save-draft endpoint and by bulk auditors.
     *
     * @return array { passed: bool, score: int, reason: string }
     */
    public function quality_gate( string $content, string $keyword, string $content_type = '' ): array {
        $analyzer = new GEO_Analyzer();
        $result = $analyzer->analyze( $content, $keyword, $content_type );

        $passed = $result['geo_score'] >= self::QUALITY_GATE_MIN_SCORE;

        // Also run CORE-EEAT veto check
        $auditor = new CORE_EEAT_Auditor();
        $audit = $auditor->audit( $content, '', $keyword, $content_type );

        if ( ! empty( $audit['veto'] ) ) {
            $passed = false;
        }

        return [
            'passed'      => $passed,
            'score'       => $result['geo_score'],
            'grade'       => $result['grade'],
            'veto_hit'    => (bool) ( $audit['veto'] ?? false ),
            'vetoes'      => $audit['vetoes'] ?? [],
            'core_eeat'   => $audit['normalized'] ?? 0,
            'min_score'   => self::QUALITY_GATE_MIN_SCORE,
            'reason'      => $passed
                ? sprintf( 'GEO %d/100, CORE-EEAT %d/100 — passes quality gate', $result['geo_score'], $audit['normalized'] ?? 0 )
                : ( ! empty( $audit['veto'] )
                    ? 'BLOCKED by VETO items: ' . implode( ', ', array_column( $audit['vetoes'], 'id' ) )
                    : sprintf( 'Score %d below minimum %d', $result['geo_score'], self::QUALITY_GATE_MIN_SCORE )
                ),
            'suggestions' => $result['suggestions'] ?? [],
        ];
    }

    // ================================================================
    // Individual phase runners
    // ================================================================

    /**
     * Phase 1 — Topic Selection via Competitor Analysis
     *
     * The plugin uses the Vercel research endpoint as a competitor proxy —
     * real data from Reddit/HN/Wikipedia/DDG/Brave surfaces what
     * competitors actually rank for. We check that at least SOME research
     * data is available for the keyword.
     */
    private function phase_topic_selection( string $keyword, array $options ): array {
        $country = $options['country'] ?? '';
        $domain  = $options['domain'] ?? 'general';

        $research = Trend_Researcher::research( $keyword, $domain, $country );
        $source_count = count( $research['sources'] ?? [] );
        $has_data = $source_count > 0 || ! empty( $research['stats'] );

        return [
            'name'   => 'Topic Selection',
            'passed' => $has_data,
            'sources_found' => $source_count,
            'stats_found'   => count( $research['stats'] ?? [] ),
            'detail' => $has_data
                ? sprintf( '%d sources + %d stats from research pipeline', $source_count, count( $research['stats'] ?? [] ) )
                : 'No research data available — topic may be too obscure or country APIs unavailable',
        ];
    }

    /**
     * Phase 2 — Keyword Research Protocol
     *
     * Validates that the primary keyword is usable:
     *   - At least 2 words (long-tail preference)
     *   - Between 2 and 12 words total
     *   - Not obviously generic ("dog", "food", "seo")
     */
    private function phase_keyword_research( string $keyword, array $options ): array {
        $word_count = str_word_count( $keyword );
        $is_long_tail = $word_count >= 2;
        $is_reasonable_length = $word_count >= 2 && $word_count <= 12;

        $generic = [ 'dog', 'cat', 'food', 'seo', 'marketing', 'business', 'tech', 'news' ];
        $is_generic = in_array( strtolower( trim( $keyword ) ), $generic, true );

        $passed = $is_long_tail && $is_reasonable_length && ! $is_generic;

        return [
            'name'   => 'Keyword Research',
            'passed' => $passed,
            'word_count' => $word_count,
            'is_long_tail' => $is_long_tail,
            'is_generic' => $is_generic,
            'detail' => $passed
                ? sprintf( '%d-word long-tail keyword', $word_count )
                : ( $is_generic
                    ? 'Keyword is too generic — use a long-tail variation'
                    : ( $word_count < 2
                        ? 'Keyword is too short — use 2+ words for better targeting'
                        : 'Keyword is too long — limit to 12 words' ) ),
        ];
    }

    /**
     * Phase 3 — Keyword Intent Grouping (NLP/Semantics)
     *
     * Classifies the keyword into one of 4 search intents:
     *   informational, commercial, transactional, navigational
     *
     * This drives prose template selection in Async_Generator.
     */
    private function phase_intent_grouping( string $keyword ): array {
        $lower = strtolower( $keyword );

        $intent = 'informational'; // default
        $structure = 'detailed guide, FAQ, definitions, step-by-step';

        if ( preg_match( '/\b(buy|price|cost|discount|order|deal|cheap|for sale)\b/', $lower ) ) {
            $intent = 'transactional';
            $structure = 'product focus, pricing, CTAs, schema markup';
        } elseif ( preg_match( '/\b(best|top \d|review|compare|vs|versus|alternative)\b/', $lower ) ) {
            $intent = 'commercial';
            $structure = 'comparison tables, pros/cons, recommendations';
        } elseif ( preg_match( '/^(what|how|why|when|where|which|guide|learn|tutorial)\b/', $lower ) ) {
            $intent = 'informational';
            $structure = 'detailed guide, FAQ, definitions, step-by-step';
        }

        return [
            'name'   => 'Intent Grouping',
            'passed' => true,
            'intent' => $intent,
            'structure' => $structure,
            'detail' => sprintf( 'Classified as %s — structure: %s', $intent, $structure ),
        ];
    }

    /**
     * Phase 4 — Research-First Writing (status check)
     *
     * Placeholder phase — actual writing happens in Async_Generator.
     * This phase just records which generation pipeline will run.
     */
    private function phase_research_first_writing( string $keyword, array $options ): array {
        return [
            'name'   => 'Research-First Writing',
            'passed' => true,
            'pipeline' => 'Async_Generator (trends → outline → sections → headlines → meta → assemble)',
            'detail' => 'Writing phase executed by Async_Generator with real research data injected into every section prompt',
        ];
    }

    /**
     * Phase 5 — Quality Gate + Schema
     *
     * The only phase that can FAIL and block publication. Calls GEO_Analyzer
     * + CORE_EEAT_Auditor veto check.
     */
    private function phase_quality_gate( string $content, string $keyword, string $content_type ): array {
        $result = $this->quality_gate( $content, $keyword, $content_type );

        return array_merge(
            [
                'name'   => 'Quality Gate',
            ],
            $result
        );
    }
}
