<?php

namespace SEOBetter;

/**
 * GEO Content Optimizer.
 *
 * Applies GEO optimization methods proven by research (KDD 2024):
 * - Quotation Addition (+41% visibility)
 * - Statistics Addition (+30% visibility)
 * - Cite Sources (+28% visibility)
 * - Fluency Optimization (+27% visibility)
 * - Authoritative tone adjustment
 * - Domain-specific strategy selection
 *
 * Requires an AI API connection for content enrichment.
 */
class GEO_Optimizer {

    /**
     * Domain-to-method mapping based on GEO research Table 3.
     * Each domain has its top 3 performing GEO methods.
     */
    private const DOMAIN_STRATEGIES = [
        'ecommerce'      => [ 'statistics', 'citations', 'fluency' ],
        'law_government' => [ 'statistics', 'citations', 'authoritative' ],
        'health'         => [ 'fluency', 'citations', 'statistics' ],
        'history'        => [ 'quotations', 'authoritative', 'citations' ],
        'science'        => [ 'authoritative', 'statistics', 'technical_terms' ],
        'business'       => [ 'fluency', 'statistics', 'citations' ],
        'technology'     => [ 'technical_terms', 'statistics', 'citations' ],
        'education'      => [ 'fluency', 'quotations', 'easy_to_understand' ],
        'society'        => [ 'quotations', 'statistics', 'citations' ],
        'opinion'        => [ 'statistics', 'quotations', 'authoritative' ],
        'debate'         => [ 'authoritative', 'statistics', 'quotations' ],
        'general'        => [ 'statistics', 'quotations', 'citations' ],
    ];

    /**
     * GEO methods that DON'T work (from research).
     */
    private const BLOCKED_METHODS = [ 'keyword_stuffing' ];

    /**
     * Optimize content using specified GEO methods.
     *
     * @param string $content The content to optimize.
     * @param array  $methods GEO methods to apply.
     * @param string $domain  Content domain for strategy selection.
     * @return array Optimization results.
     */
    public function optimize( string $content, array $methods = [], string $domain = 'general' ): array {
        // Auto-select methods if none specified
        if ( empty( $methods ) ) {
            $methods = $this->get_domain_strategy( $domain );
        }

        // Filter out blocked methods
        $methods = array_diff( $methods, self::BLOCKED_METHODS );

        $results = [
            'original_content'  => $content,
            'optimized_content' => $content,
            'methods_applied'   => [],
            'warnings'          => [],
        ];

        // Check for keyword stuffing attempts
        if ( in_array( 'keyword_stuffing', $methods, true ) ) {
            $results['warnings'][] = 'Keyword stuffing HURTS GEO visibility by -8%. This method has been blocked.';
        }

        foreach ( $methods as $method ) {
            $applied = $this->apply_method( $results['optimized_content'], $method );
            if ( $applied['changed'] ) {
                $results['optimized_content'] = $applied['content'];
                $results['methods_applied'][] = [
                    'method'           => $method,
                    'expected_boost'   => $this->get_expected_boost( $method ),
                    'changes_made'     => $applied['changes'],
                ];
            }
        }

        // Run post-optimization analysis
        $analyzer = new GEO_Analyzer();
        $results['before_score'] = $analyzer->analyze( $content )['geo_score'];
        $results['after_score']  = $analyzer->analyze( $results['optimized_content'] )['geo_score'];
        $results['improvement']  = $results['after_score'] - $results['before_score'];

        return $results;
    }

    /**
     * Get recommended GEO strategy for a content domain.
     */
    public function get_domain_strategy( string $domain ): array {
        return self::DOMAIN_STRATEGIES[ $domain ] ?? self::DOMAIN_STRATEGIES['general'];
    }

    /**
     * Detect the content domain from text.
     */
    public function detect_domain( string $content ): string {
        $text = strtolower( wp_strip_all_tags( $content ) );

        $domain_keywords = [
            'law_government' => [ 'law', 'legal', 'court', 'regulation', 'legislation', 'government', 'policy', 'statute', 'compliance' ],
            'health'         => [ 'health', 'medical', 'clinical', 'patient', 'treatment', 'diagnosis', 'symptoms', 'disease', 'therapy' ],
            'history'        => [ 'history', 'historical', 'century', 'era', 'ancient', 'civilization', 'dynasty', 'war', 'revolution' ],
            'science'        => [ 'research', 'study', 'experiment', 'hypothesis', 'scientific', 'laboratory', 'data', 'analysis', 'methodology' ],
            'business'       => [ 'business', 'market', 'revenue', 'company', 'startup', 'investment', 'profit', 'enterprise', 'roi' ],
            'technology'     => [ 'software', 'algorithm', 'api', 'database', 'cloud', 'programming', 'ai', 'machine learning', 'framework' ],
            'education'      => [ 'education', 'learning', 'student', 'curriculum', 'teaching', 'school', 'academic', 'course', 'training' ],
            'society'        => [ 'society', 'social', 'community', 'cultural', 'demographic', 'population', 'inequality', 'diversity' ],
        ];

        $scores = [];
        foreach ( $domain_keywords as $domain => $keywords ) {
            $scores[ $domain ] = 0;
            foreach ( $keywords as $keyword ) {
                $scores[ $domain ] += substr_count( $text, $keyword );
            }
        }

        arsort( $scores );
        $top = array_key_first( $scores );

        return $scores[ $top ] >= 3 ? $top : 'general';
    }

    /**
     * Apply a single GEO method to content.
     *
     * These are local (non-AI) transformations. For AI-powered enrichment,
     * the REST API endpoint delegates to the configured AI provider.
     */
    private function apply_method( string $content, string $method ): array {
        return match ( $method ) {
            'statistics'      => $this->enhance_statistics_markers( $content ),
            'quotations'      => $this->enhance_quotation_markers( $content ),
            'citations'       => $this->enhance_citation_markers( $content ),
            'fluency'         => $this->enhance_fluency( $content ),
            'authoritative'   => $this->enhance_authoritative( $content ),
            'easy_to_understand' => $this->enhance_readability_markers( $content ),
            'technical_terms' => $this->enhance_technical_terms( $content ),
            'bluf_header'     => $this->add_bluf_placeholder( $content ),
            'freshness'       => $this->add_freshness_signal( $content ),
            'island_test'     => $this->fix_island_test_violations( $content ),
            default           => [ 'content' => $content, 'changed' => false, 'changes' => [] ],
        };
    }

    /**
     * Mark locations where statistics should be added.
     */
    private function enhance_statistics_markers( string $content ): array {
        $changes = [];

        // Check if content lacks statistics
        preg_match_all( '/\d+[\.,]?\d*\s*(%|percent|billion|million)/', $content, $matches );
        if ( count( $matches[0] ) < 3 ) {
            $changes[] = 'Content needs more verifiable statistics (current: ' . count( $matches[0] ) . ', recommended: 3+ per 1000 words)';
        }

        return [
            'content' => $content,
            'changed' => ! empty( $changes ),
            'changes' => $changes,
        ];
    }

    /**
     * Mark locations where expert quotations should be added.
     */
    private function enhance_quotation_markers( string $content ): array {
        $changes = [];

        preg_match_all( '/"[^"]{20,}"/', $content, $matches );
        if ( count( $matches[0] ) < 2 ) {
            $changes[] = 'Add expert quotes from credentialed sources. Quotation Addition provides +41% GEO visibility boost.';
        }

        return [
            'content' => $content,
            'changed' => ! empty( $changes ),
            'changes' => $changes,
        ];
    }

    /**
     * Mark locations where citations should be added.
     */
    private function enhance_citation_markers( string $content ): array {
        $changes = [];

        preg_match_all( '/\[\d+\]|\([A-Z][^)]*\d{4}\)/', $content, $matches );
        if ( count( $matches[0] ) < 5 ) {
            $changes[] = 'Add inline citations with attributions (e.g., "[Entity] Research, 2025"). Current: ' . count( $matches[0] ) . ', target: 5+';
        }

        return [
            'content' => $content,
            'changed' => ! empty( $changes ),
            'changes' => $changes,
        ];
    }

    /**
     * Add a freshness signal to content.
     */
    private function add_freshness_signal( string $content ): array {
        if ( preg_match( '/last\s*updated/i', $content ) ) {
            return [ 'content' => $content, 'changed' => false, 'changes' => [] ];
        }

        $date = wp_date( 'F Y' );
        $signal = '<p><em>Last Updated: ' . esc_html( $date ) . '</em></p>';
        $content = $signal . "\n" . $content;

        return [
            'content' => $content,
            'changed' => true,
            'changes' => [ 'Added freshness signal: Last Updated: ' . $date ],
        ];
    }

    /**
     * Add BLUF header placeholder.
     */
    private function add_bluf_placeholder( string $content ): array {
        if ( preg_match( '/key\s*takeaway/i', $content ) ) {
            return [ 'content' => $content, 'changed' => false, 'changes' => [] ];
        }

        $bluf = "<h2>Key Takeaways</h2>\n<ul>\n<li>[Takeaway 1 — summarize the core answer]</li>\n<li>[Takeaway 2 — key supporting point]</li>\n<li>[Takeaway 3 — actionable insight]</li>\n</ul>\n\n";
        $content = $bluf . $content;

        return [
            'content' => $content,
            'changed' => true,
            'changes' => [ 'Added Key Takeaways placeholder at the top' ],
        ];
    }

    /**
     * Fix Island Test violations (paragraphs starting with pronouns).
     */
    private function fix_island_test_violations( string $content ): array {
        $changes = [];
        $pronoun_starts = [ 'It ', 'This ', 'That ', 'They ', 'These ', 'Those ', 'He ', 'She ', 'We ' ];

        foreach ( $pronoun_starts as $pronoun ) {
            if ( strpos( $content, '<p>' . $pronoun ) !== false ) {
                $changes[] = 'Paragraph starts with "' . trim( $pronoun ) . '" — replace with a specific entity name';
            }
        }

        return [
            'content' => $content,
            'changed' => ! empty( $changes ),
            'changes' => $changes,
        ];
    }

    // Placeholder methods for AI-powered optimization (requires API key)

    private function enhance_fluency( string $content ): array {
        return [ 'content' => $content, 'changed' => false, 'changes' => [ 'Fluency optimization requires AI API connection' ] ];
    }

    private function enhance_authoritative( string $content ): array {
        return [ 'content' => $content, 'changed' => false, 'changes' => [ 'Authoritative tone adjustment requires AI API connection' ] ];
    }

    private function enhance_readability_markers( string $content ): array {
        return [ 'content' => $content, 'changed' => false, 'changes' => [ 'Readability simplification requires AI API connection' ] ];
    }

    private function enhance_technical_terms( string $content ): array {
        return [ 'content' => $content, 'changed' => false, 'changes' => [ 'Technical terms enhancement requires AI API connection' ] ];
    }

    /**
     * Get expected visibility boost for a GEO method.
     */
    private function get_expected_boost( string $method ): string {
        return match ( $method ) {
            'quotations'      => '+41%',
            'statistics'      => '+30%',
            'citations'       => '+28%',
            'fluency'         => '+27%',
            'technical_terms' => '+18%',
            'authoritative'   => '+10%',
            'easy_to_understand' => '+14%',
            default           => 'varies',
        };
    }
}
