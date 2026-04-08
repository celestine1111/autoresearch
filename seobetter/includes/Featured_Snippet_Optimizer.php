<?php

namespace SEOBetter;

/**
 * Featured Snippet & Answer Engine Optimizer.
 *
 * Optimizes content for:
 * - Featured snippets (paragraph, list, table, video)
 * - Google AI Overviews
 * - Answer engines (ChatGPT, Perplexity, Gemini)
 * - People Also Ask boxes
 *
 * Based on research: snippets get 35.1% of all clicks.
 * Snippable paragraphs: 40-50 words. Lists: 8+ items with parallel syntax.
 */
class Featured_Snippet_Optimizer {

    private const SNIPPET_WORD_MIN = 40;
    private const SNIPPET_WORD_MAX = 50;
    private const MIN_LIST_ITEMS   = 8;

    /**
     * Analyze content for snippet potential.
     */
    public function analyze( string $content, string $title = '' ): array {
        $checks = [
            'question_headings'   => $this->check_question_headings( $content ),
            'snippable_paragraphs' => $this->check_snippable_paragraphs( $content ),
            'snippable_lists'     => $this->check_snippable_lists( $content ),
            'definition_format'   => $this->check_definition_format( $content, $title ),
            'how_to_format'       => $this->check_how_to_format( $content, $title ),
            'comparison_tables'   => $this->check_comparison_tables( $content ),
            'paa_readiness'       => $this->check_paa_readiness( $content ),
            'ai_extraction'       => $this->check_ai_extraction_readiness( $content ),
        ];

        $total_score = 0;
        $count = 0;
        foreach ( $checks as $check ) {
            $total_score += $check['score'];
            $count++;
        }

        $score = $count > 0 ? round( $total_score / $count ) : 0;

        return [
            'snippet_score' => $score,
            'snippet_type'  => $this->detect_best_snippet_type( $checks ),
            'checks'        => $checks,
            'suggestions'   => $this->generate_suggestions( $checks, $title ),
        ];
    }

    /**
     * Check if headings are formatted as questions (optimal for snippets + PAA).
     */
    private function check_question_headings( string $content ): array {
        preg_match_all( '/<h[2-3][^>]*>(.*?)<\/h[2-3]>/is', $content, $matches );
        $headings = $matches[1] ?? [];

        if ( empty( $headings ) ) {
            return [ 'score' => 0, 'detail' => 'No H2/H3 headings found' ];
        }

        $questions = 0;
        foreach ( $headings as $h ) {
            $text = wp_strip_all_tags( $h );
            if ( preg_match( '/\?$|^(what|how|why|when|where|who|which|can|does|is|are|should|do|will)\b/i', $text ) ) {
                $questions++;
            }
        }

        $ratio = $questions / count( $headings );
        $score = round( $ratio * 100 );

        return [
            'score'     => min( 100, $score ),
            'questions' => $questions,
            'total'     => count( $headings ),
            'detail'    => sprintf( '%d/%d headings are question-format (targets PAA & featured snippets)', $questions, count( $headings ) ),
        ];
    }

    /**
     * Check for snippable paragraphs (40-50 words directly after headings).
     */
    private function check_snippable_paragraphs( string $content ): array {
        // Find paragraphs immediately after H2/H3 headings
        preg_match_all( '/<\/h[2-3]>\s*<p[^>]*>(.*?)<\/p>/is', $content, $matches );
        $paragraphs = $matches[1] ?? [];

        if ( empty( $paragraphs ) ) {
            return [ 'score' => 30, 'detail' => 'No paragraphs found directly after headings' ];
        }

        $snippable = 0;
        foreach ( $paragraphs as $para ) {
            $wc = str_word_count( wp_strip_all_tags( $para ) );
            if ( $wc >= self::SNIPPET_WORD_MIN && $wc <= self::SNIPPET_WORD_MAX ) {
                $snippable++;
            }
        }

        $ratio = $snippable / count( $paragraphs );
        return [
            'score'    => round( $ratio * 100 ),
            'snippable' => $snippable,
            'total'    => count( $paragraphs ),
            'detail'   => sprintf( '%d/%d post-heading paragraphs are snippable (40-50 words)', $snippable, count( $paragraphs ) ),
        ];
    }

    /**
     * Check lists for snippet readiness (8+ items, parallel syntax).
     */
    private function check_snippable_lists( string $content ): array {
        preg_match_all( '/<(ol|ul)[^>]*>(.*?)<\/\1>/is', $content, $matches );

        if ( empty( $matches[2] ) ) {
            return [ 'score' => 30, 'detail' => 'No lists found. Add ordered/bulleted lists for list snippet eligibility.' ];
        }

        $good_lists = 0;
        foreach ( $matches[2] as $list_content ) {
            preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $list_content, $li_matches );
            $items = count( $li_matches[1] ?? [] );
            if ( $items >= self::MIN_LIST_ITEMS ) {
                $good_lists++;
            }
        }

        $has_good_lists = $good_lists > 0;
        return [
            'score'  => $has_good_lists ? 100 : 50,
            'detail' => $has_good_lists
                ? "{$good_lists} lists have 8+ items (Google shows 'More items' link driving clicks)"
                : 'Lists have fewer than 8 items. Add more items so Google shows "More items" CTA.',
        ];
    }

    /**
     * Check for definition-style snippets ("What is X" format).
     */
    private function check_definition_format( string $content, string $title ): array {
        $is_definition = preg_match( '/^what\s+(is|are)\b/i', $title );
        if ( ! $is_definition ) {
            return [ 'score' => 50, 'detail' => 'Not a definition-style query' ];
        }

        // Check for a concise definition paragraph in first 500 chars
        $top = substr( $content, 0, 1000 );
        preg_match( '/<p[^>]*>(.*?)<\/p>/is', $top, $para );
        $first_para = wp_strip_all_tags( $para[1] ?? '' );
        $wc = str_word_count( $first_para );

        $has_definition = $wc >= 30 && $wc <= 60;
        return [
            'score'  => $has_definition ? 100 : 40,
            'detail' => $has_definition
                ? 'Definition paragraph is well-sized for featured snippet extraction'
                : "First paragraph is {$wc} words (target: 30-60 for definition snippets)",
        ];
    }

    /**
     * Check for how-to structured content.
     */
    private function check_how_to_format( string $content, string $title ): array {
        $is_howto = preg_match( '/^how\s+to\b/i', $title );
        if ( ! $is_howto ) {
            return [ 'score' => 50, 'detail' => 'Not a how-to query' ];
        }

        // Check for ordered lists (steps)
        preg_match_all( '/<ol[^>]*>(.*?)<\/ol>/is', $content, $matches );
        $has_ordered = ! empty( $matches[1] );

        return [
            'score'  => $has_ordered ? 100 : 30,
            'detail' => $has_ordered
                ? 'How-to content has ordered list steps (eligible for HowTo snippet)'
                : 'How-to content missing ordered list. Add numbered steps for HowTo snippet eligibility.',
        ];
    }

    /**
     * Check for comparison tables.
     */
    private function check_comparison_tables( string $content ): array {
        $table_count = substr_count( strtolower( $content ), '<table' );

        return [
            'score'  => $table_count > 0 ? 100 : 30,
            'count'  => $table_count,
            'detail' => $table_count > 0
                ? "{$table_count} comparison table(s) found (eligible for table snippets)"
                : 'No tables found. Add comparison tables — LLMs cite tables 30-40% more often.',
        ];
    }

    /**
     * Check People Also Ask readiness.
     */
    private function check_paa_readiness( string $content ): array {
        // PAA answers are typically 40-60 words with a direct answer format
        preg_match_all( '/<h[2-3][^>]*>(.*?)<\/h[2-3]>\s*<p[^>]*>(.*?)<\/p>/is', $content, $matches );

        $ready = 0;
        for ( $i = 0; $i < count( $matches[1] ); $i++ ) {
            $heading = wp_strip_all_tags( $matches[1][ $i ] );
            $answer = wp_strip_all_tags( $matches[2][ $i ] );

            $is_question = preg_match( '/\?|^(what|how|why|when|where|who|which|can|does|is|are)\b/i', $heading );
            $wc = str_word_count( $answer );

            if ( $is_question && $wc >= 30 && $wc <= 60 ) {
                $ready++;
            }
        }

        return [
            'score'  => min( 100, $ready * 25 ),
            'count'  => $ready,
            'detail' => "{$ready} Q&A pairs ready for People Also Ask (question heading + 30-60 word answer)",
        ];
    }

    /**
     * Check AI extraction readiness (for Google AI Overviews, ChatGPT, Perplexity).
     */
    private function check_ai_extraction_readiness( string $content ): array {
        $text = wp_strip_all_tags( $content );
        $score = 0;
        $signals = [];

        // 1. Modular self-contained sections
        preg_match_all( '/<h[2-3][^>]*>/i', $content, $h_matches );
        $sections = count( $h_matches[0] );
        if ( $sections >= 3 ) {
            $score += 20;
            $signals[] = "{$sections} structured sections";
        }

        // 2. Statistics/data points
        preg_match_all( '/\d+[\.,]?\d*\s*(%|percent|billion|million)/', $text, $stat_matches );
        if ( count( $stat_matches[0] ) >= 3 ) {
            $score += 20;
            $signals[] = count( $stat_matches[0] ) . ' statistics';
        }

        // 3. Citations/references
        preg_match_all( '/\[\d+\]|\([A-Z][^)]*\d{4}\)/', $text, $cite_matches );
        if ( count( $cite_matches[0] ) >= 2 ) {
            $score += 20;
            $signals[] = count( $cite_matches[0] ) . ' citations';
        }

        // 4. Expert quotes
        preg_match_all( '/"[^"]{20,}"/', $text, $quote_matches );
        if ( count( $quote_matches[0] ) >= 1 ) {
            $score += 20;
            $signals[] = count( $quote_matches[0] ) . ' expert quotes';
        }

        // 5. Schema markup present
        if ( stripos( $content, 'application/ld+json' ) !== false ) {
            $score += 20;
            $signals[] = 'JSON-LD schema detected';
        }

        return [
            'score'   => min( 100, $score ),
            'signals' => $signals,
            'detail'  => $score >= 60
                ? 'Content is well-structured for AI extraction (' . implode( ', ', $signals ) . ')'
                : 'Improve AI extraction readiness: add more statistics, citations, and expert quotes.',
        ];
    }

    private function detect_best_snippet_type( array $checks ): string {
        $types = [
            'paragraph' => ( $checks['snippable_paragraphs']['score'] ?? 0 ) + ( $checks['definition_format']['score'] ?? 0 ),
            'list'      => ( $checks['snippable_lists']['score'] ?? 0 ) + ( $checks['how_to_format']['score'] ?? 0 ),
            'table'     => ( $checks['comparison_tables']['score'] ?? 0 ) * 2,
        ];

        arsort( $types );
        return array_key_first( $types );
    }

    private function generate_suggestions( array $checks, string $title ): array {
        $suggestions = [];

        if ( ( $checks['question_headings']['score'] ?? 0 ) < 50 ) {
            $suggestions[] = [
                'priority' => 'high',
                'message'  => 'Convert headings to question format (What is, How to, Why does). Questions target both featured snippets and People Also Ask boxes.',
            ];
        }

        if ( ( $checks['snippable_paragraphs']['score'] ?? 0 ) < 50 ) {
            $suggestions[] = [
                'priority' => 'high',
                'message'  => 'Write 40-50 word direct-answer paragraphs after each heading. 90% of featured snippets are paragraph format.',
            ];
        }

        if ( ( $checks['ai_extraction']['score'] ?? 0 ) < 60 ) {
            $suggestions[] = [
                'priority' => 'high',
                'message'  => 'Boost AI citation potential: add verifiable statistics (+30%), expert quotes (+41%), and inline citations (+28%).',
            ];
        }

        if ( ( $checks['comparison_tables']['score'] ?? 0 ) < 50 ) {
            $suggestions[] = [
                'priority' => 'medium',
                'message'  => 'Add a comparison table. Table snippets are increasingly common and LLMs cite tables 30-40% more.',
            ];
        }

        if ( ( $checks['paa_readiness']['score'] ?? 0 ) < 50 ) {
            $suggestions[] = [
                'priority' => 'medium',
                'message'  => 'Add FAQ-style Q&A pairs (question heading + 30-60 word answer) for People Also Ask visibility.',
            ];
        }

        return $suggestions;
    }
}
