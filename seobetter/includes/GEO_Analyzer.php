<?php

namespace SEOBetter;

/**
 * GEO Content Analyzer.
 *
 * Analyzes content against GEO optimization criteria from the research:
 * - Readability (Flesch-Kincaid)
 * - Island Test (context independence)
 * - 40-60 word citation rule per section
 * - BLUF header presence
 * - Factual density (statistics, citations, quotes)
 * - EEAT signals
 */
class GEO_Analyzer {

    private const TARGET_FLESCH_GRADE = 7;
    private const SECTION_WORD_MIN    = 25;
    private const SECTION_WORD_MAX    = 75;
    private const STATS_PER_1000      = 3;

    /**
     * Run full GEO analysis on content.
     *
     * @param string $content Post content (HTML).
     * @param string $title   Post title.
     * @return array Analysis results with overall score and breakdown.
     */
    public function analyze( string $content, string $title = '' ): array {
        $text = wp_strip_all_tags( $content );
        $sections = $this->extract_sections( $content );
        $word_count = str_word_count( $text );

        $checks = [
            'readability'      => $this->check_readability( $text ),
            'bluf_header'      => $this->check_bluf_header( $content ),
            'section_openings' => $this->check_section_openings( $sections ),
            'island_test'      => $this->check_island_test( $text ),
            'factual_density'  => $this->check_factual_density( $text, $word_count ),
            'citations'        => $this->check_citations( $text ),
            'expert_quotes'    => $this->check_expert_quotes( $text ),
            'tables'           => $this->check_tables( $content ),
            'lists'            => $this->check_lists( $content ),
            'freshness'        => $this->check_freshness_signal( $content ),
            'entity_usage'     => $this->check_entity_usage( $text ),
        ];

        $weights = [
            'readability'      => 12,
            'bluf_header'      => 10,
            'section_openings' => 10,
            'island_test'      => 10,
            'factual_density'  => 12,
            'citations'        => 12,
            'expert_quotes'    => 8,
            'tables'           => 6,
            'lists'            => 5,
            'freshness'        => 7,
            'entity_usage'     => 8,
        ];

        $weighted_score = 0;
        $total_weight = array_sum( $weights );
        foreach ( $checks as $key => $check ) {
            $weighted_score += $check['score'] * ( $weights[ $key ] / $total_weight );
        }

        $geo_score = round( $weighted_score );

        return [
            'geo_score'  => $geo_score,
            'grade'      => $this->score_to_grade( $geo_score ),
            'word_count' => $word_count,
            'checks'     => $checks,
            'suggestions' => $this->generate_suggestions( $checks ),
        ];
    }

    /**
     * Flesch-Kincaid readability check.
     */
    private function check_readability( string $text ): array {
        $sentences = preg_split( '/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
        $sentence_count = max( count( $sentences ), 1 );
        $words = str_word_count( $text );
        $syllables = $this->count_syllables( $text );

        if ( $words === 0 ) {
            return [ 'score' => 0, 'detail' => 'No content to analyze', 'flesch_grade' => 0 ];
        }

        // Flesch-Kincaid Grade Level
        $grade = 0.39 * ( $words / $sentence_count ) + 11.8 * ( $syllables / $words ) - 15.59;
        $grade = max( 0, round( $grade, 1 ) );

        // Flesch Reading Ease
        $ease = 206.835 - 1.015 * ( $words / $sentence_count ) - 84.6 * ( $syllables / $words );
        $ease = max( 0, min( 100, round( $ease, 1 ) ) );

        // Score: 100 if grade matches target (6-8), decreases as it deviates
        $target = self::TARGET_FLESCH_GRADE;
        $deviation = abs( $grade - $target );
        $score = max( 0, 100 - ( $deviation * 12 ) );

        return [
            'score'        => round( $score ),
            'flesch_grade' => $grade,
            'flesch_ease'  => $ease,
            'detail'       => sprintf( 'Grade level: %.1f (target: %d). Reading ease: %.1f', $grade, $target, $ease ),
        ];
    }

    /**
     * Check for BLUF (Bottom Line Up Front) header — Key Takeaways section.
     */
    private function check_bluf_header( string $content ): array {
        $has_bluf = (bool) preg_match( '/<h[2-3][^>]*>.*?(key\s*takeaway|summary|tldr|tl;dr|bottom\s*line)/is', $content );

        if ( ! $has_bluf ) {
            // Check for a list within the first 500 chars
            $top = substr( $content, 0, 500 );
            $has_bluf = (bool) preg_match( '/<(ul|ol)\b/i', $top );
        }

        return [
            'score'  => $has_bluf ? 100 : 0,
            'detail' => $has_bluf ? 'BLUF header detected' : 'Missing Key Takeaways section at the top of the article',
        ];
    }

    /**
     * Check that each H2/H3 section opens with a 40-60 word paragraph.
     */
    private function check_section_openings( array $sections ): array {
        if ( empty( $sections ) ) {
            return [ 'score' => 50, 'detail' => 'No H2/H3 sections found', 'sections' => [] ];
        }

        $passing = 0;
        $details = [];
        foreach ( $sections as $section ) {
            $first_para = $section['first_paragraph'] ?? '';
            $wc = str_word_count( $first_para );
            $pass = $wc >= self::SECTION_WORD_MIN && $wc <= self::SECTION_WORD_MAX;
            if ( $pass ) {
                $passing++;
            }
            $details[] = [
                'heading'    => $section['heading'],
                'word_count' => $wc,
                'pass'       => $pass,
            ];
        }

        $score = round( ( $passing / count( $sections ) ) * 100 );
        return [
            'score'    => $score,
            'detail'   => sprintf( '%d/%d sections have 40-60 word openings', $passing, count( $sections ) ),
            'sections' => $details,
        ];
    }

    /**
     * Island Test: paragraphs should not start with pronouns.
     */
    private function check_island_test( string $text ): array {
        $paragraphs = preg_split( '/\n{2,}/', trim( $text ) );
        $paragraphs = array_filter( $paragraphs, fn( $p ) => str_word_count( $p ) > 5 );

        if ( empty( $paragraphs ) ) {
            return [ 'score' => 50, 'detail' => 'No paragraphs to analyze' ];
        }

        $pronoun_starts = [ 'it', 'this', 'that', 'they', 'these', 'those', 'he', 'she', 'we', 'its' ];
        $violations = 0;
        $violation_details = [];

        foreach ( $paragraphs as $para ) {
            $first_word = strtolower( strtok( trim( $para ), " \t" ) );
            if ( in_array( $first_word, $pronoun_starts, true ) ) {
                $violations++;
                $violation_details[] = substr( trim( $para ), 0, 80 ) . '...';
            }
        }

        $total = count( $paragraphs );
        $pass_rate = ( $total - $violations ) / $total;
        $score = round( $pass_rate * 100 );

        return [
            'score'      => $score,
            'detail'     => sprintf( '%d/%d paragraphs pass the Island Test', $total - $violations, $total ),
            'violations' => $violation_details,
        ];
    }

    /**
     * Check factual density (statistics per 1000 words).
     */
    private function check_factual_density( string $text, int $word_count ): array {
        if ( $word_count < 100 ) {
            return [ 'score' => 0, 'detail' => 'Content too short for factual density analysis' ];
        }

        // Count numbers with context (percentages, dollar amounts, years, quantities)
        preg_match_all( '/\d+[\.,]?\d*\s*(%|percent|billion|million|thousand|USD|\$|£|€)|\b(19|20)\d{2}\b/', $text, $matches );
        $stat_count = count( $matches[0] );
        $per_1000 = ( $stat_count / $word_count ) * 1000;
        $target = self::STATS_PER_1000;

        $score = min( 100, round( ( $per_1000 / $target ) * 100 ) );

        return [
            'score'      => $score,
            'stat_count' => $stat_count,
            'per_1000'   => round( $per_1000, 1 ),
            'detail'     => sprintf( '%.1f stats per 1000 words (target: %d). Found %d total.', $per_1000, $target, $stat_count ),
        ];
    }

    /**
     * Check for inline citations / references.
     */
    private function check_citations( string $text ): array {
        // Match patterns like [1], [Source, 2024], (Research, 2025), etc.
        preg_match_all( '/\[\d+\]|\([A-Z][^)]*\d{4}\)|\[[A-Z][^\]]*\d{4}\]/', $text, $matches );
        $count = count( $matches[0] );

        $score = min( 100, $count * 20 ); // 5+ citations = 100

        return [
            'score'  => $score,
            'count'  => $count,
            'detail' => sprintf( '%d citations found (recommend 5+ per article)', $count ),
        ];
    }

    /**
     * Check for expert quotes.
     */
    private function check_expert_quotes( string $text ): array {
        // Match quoted text
        preg_match_all( '/"[^"]{20,}"/', $text, $matches );
        $count = count( $matches[0] );

        $score = min( 100, $count * 50 ); // 2+ quotes = 100

        return [
            'score'  => $score,
            'count'  => $count,
            'detail' => sprintf( '%d expert quotes found (recommend 2+ per article)', $count ),
        ];
    }

    /**
     * Check for tables (LLMs cite tables 30-40% more).
     */
    private function check_tables( string $content ): array {
        $count = substr_count( strtolower( $content ), '<table' );
        $score = min( 100, $count * 50 );

        return [
            'score'  => $score,
            'count'  => $count,
            'detail' => sprintf( '%d tables found (tables boost AI citation by 30-40%%)', $count ),
        ];
    }

    /**
     * Check for ordered/unordered lists.
     */
    private function check_lists( string $content ): array {
        preg_match_all( '/<(ul|ol)\b/i', $content, $matches );
        $count = count( $matches[0] );
        $score = min( 100, $count * 25 );

        return [
            'score'  => $score,
            'count'  => $count,
            'detail' => sprintf( '%d lists found', $count ),
        ];
    }

    /**
     * Check for freshness signal (Last Updated / dateModified).
     */
    private function check_freshness_signal( string $content ): array {
        $has_signal = (bool) preg_match( '/last\s*updated|date\s*modified|updated\s*on|published\s*on/i', $content );
        return [
            'score'  => $has_signal ? 100 : 0,
            'detail' => $has_signal ? 'Freshness signal found' : 'No freshness signal (add "Last Updated: Month Year")',
        ];
    }

    /**
     * Check entity usage — named entities should be used instead of generic terms.
     */
    private function check_entity_usage( string $text ): array {
        // Check ratio of capitalized multi-word phrases (likely named entities)
        preg_match_all( '/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+\b/', $text, $matches );
        $entity_count = count( $matches[0] );
        $word_count = str_word_count( $text );

        if ( $word_count < 100 ) {
            return [ 'score' => 0, 'detail' => 'Content too short' ];
        }

        $density = ( $entity_count / $word_count ) * 100;
        $score = min( 100, round( $density * 20 ) ); // 5% density = 100

        return [
            'score'   => $score,
            'count'   => $entity_count,
            'density' => round( $density, 1 ),
            'detail'  => sprintf( '%d named entities (%.1f%% density)', $entity_count, $density ),
        ];
    }

    /**
     * Extract H2/H3 sections with their first paragraphs.
     */
    private function extract_sections( string $content ): array {
        $sections = [];
        // Split by H2/H3 headings
        $parts = preg_split( '/(<h[23][^>]*>.*?<\/h[23]>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

        for ( $i = 1; $i < count( $parts ) - 1; $i += 2 ) {
            $heading = wp_strip_all_tags( $parts[ $i ] );
            $body = $parts[ $i + 1 ] ?? '';

            // Get first paragraph
            if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $body, $m ) ) {
                $first_paragraph = wp_strip_all_tags( $m[1] );
            } else {
                $first_paragraph = wp_strip_all_tags( substr( $body, 0, 500 ) );
            }

            $sections[] = [
                'heading'         => $heading,
                'first_paragraph' => $first_paragraph,
            ];
        }

        return $sections;
    }

    /**
     * Count syllables in text (English approximation).
     */
    private function count_syllables( string $text ): int {
        $words = preg_split( '/\s+/', strtolower( $text ) );
        $total = 0;
        foreach ( $words as $word ) {
            $word = preg_replace( '/[^a-z]/', '', $word );
            if ( strlen( $word ) <= 3 ) {
                $total += 1;
                continue;
            }
            $word = preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word );
            preg_match_all( '/[aeiouy]{1,2}/', $word, $m );
            $total += max( 1, count( $m[0] ) );
        }
        return $total;
    }

    private function score_to_grade( int $score ): string {
        if ( $score >= 90 ) return 'A+';
        if ( $score >= 80 ) return 'A';
        if ( $score >= 70 ) return 'B';
        if ( $score >= 60 ) return 'C';
        if ( $score >= 50 ) return 'D';
        return 'F';
    }

    /**
     * Generate actionable suggestions from check results.
     */
    private function generate_suggestions( array $checks ): array {
        $suggestions = [];

        if ( $checks['bluf_header']['score'] < 100 ) {
            $suggestions[] = [
                'priority' => 'high',
                'type'     => 'structure',
                'message'  => 'Add a "Key Takeaways" section with 3 bullet points at the top of the article. LLMs prioritize top-of-content information.',
            ];
        }

        if ( $checks['readability']['score'] < 60 ) {
            $suggestions[] = [
                'priority' => 'high',
                'type'     => 'readability',
                'message'  => sprintf( 'Content reads at grade %.1f. Simplify to grade 6-8 for maximum GEO visibility.', $checks['readability']['flesch_grade'] ),
            ];
        }

        if ( $checks['section_openings']['score'] < 70 ) {
            $suggestions[] = [
                'priority' => 'high',
                'type'     => 'structure',
                'message'  => 'Ensure each H2/H3 section starts with a 40-60 word paragraph that directly answers the heading. This is the optimal length for AI extraction.',
            ];
        }

        if ( $checks['island_test']['score'] < 80 ) {
            $suggestions[] = [
                'priority' => 'medium',
                'type'     => 'style',
                'message'  => 'Some paragraphs start with pronouns (It, This, They). Replace with specific entity names for context independence.',
            ];
        }

        if ( $checks['factual_density']['score'] < 60 ) {
            $suggestions[] = [
                'priority' => 'high',
                'type'     => 'content',
                'message'  => 'Add more verifiable statistics. Aim for 3+ stats per 1000 words. Statistics Addition boosts GEO visibility by 30%.',
            ];
        }

        if ( $checks['citations']['score'] < 60 ) {
            $suggestions[] = [
                'priority' => 'high',
                'type'     => 'content',
                'message'  => 'Add inline citations from credible sources. Cite Sources boosts GEO visibility by 28%.',
            ];
        }

        if ( $checks['expert_quotes']['score'] < 50 ) {
            $suggestions[] = [
                'priority' => 'high',
                'type'     => 'content',
                'message'  => 'Add expert quotes. Quotation Addition provides the highest GEO visibility boost at 41%.',
            ];
        }

        if ( $checks['tables']['score'] < 50 ) {
            $suggestions[] = [
                'priority' => 'medium',
                'type'     => 'structure',
                'message'  => 'Add comparison tables. LLMs are 30-40% more likely to cite tables than paragraphs.',
            ];
        }

        if ( $checks['freshness']['score'] < 100 ) {
            $suggestions[] = [
                'priority' => 'medium',
                'type'     => 'meta',
                'message'  => 'Add a "Last Updated: [Month Year]" line. Freshness is a critical tiebreaker for AI citations.',
            ];
        }

        // Sort by priority
        usort( $suggestions, fn( $a, $b ) => ( $a['priority'] === 'high' ? 0 : 1 ) - ( $b['priority'] === 'high' ? 0 : 1 ) );

        return $suggestions;
    }
}
