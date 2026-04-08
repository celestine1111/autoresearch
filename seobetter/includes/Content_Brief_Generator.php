<?php

namespace SEOBetter;

/**
 * Content Brief Generator.
 *
 * Generates shareable content briefs with target keywords, heading structure,
 * competitor gaps, and GEO requirements.
 */
class Content_Brief_Generator {

    /**
     * Generate a content brief for a keyword.
     *
     * Returns a flat array that the view can access directly:
     * $brief['title'], $brief['secondary_keywords'] (array), etc.
     */
    public function generate( string $keyword, array $options = [] ): array {
        $gen_check = License_Manager::can_generate();
        if ( ! $gen_check['allowed'] ) {
            return [ 'success' => false, 'error' => $gen_check['message'] ];
        }

        $audience = $options['audience'] ?? 'general';
        $domain = $options['domain'] ?? 'general';
        $word_count = $options['word_count'] ?? 2000;

        $prompt = "Create a detailed SEO content brief for the keyword: \"{$keyword}\"

Target audience: {$audience}
Content domain: {$domain}
Target word count: {$word_count}

You MUST return the brief in this EXACT format with these EXACT labels. Do not skip any section:

TITLE: [50-60 char article title with keyword front-loaded]

SECONDARY_KEYWORDS: [5-8 related search phrases, comma-separated]

LSI_KEYWORDS: [5-8 semantic/related terms, comma-separated]

INTENT_TYPE: [informational or commercial or transactional]

INTENT_DESCRIPTION: [1-2 sentences explaining what the searcher wants]

DIFFICULTY: [low or medium or high]

CONTENT_TYPE: [guide or comparison or review or how-to or listicle]

OUTLINE:
H2: Key Takeaways
H2: [Question-format heading for section 1]
- [Subpoint 1]
- [Subpoint 2]
H2: [Question-format heading for section 2]
- [Subpoint 1]
- [Subpoint 2]
H2: [Question-format heading for section 3]
- [Subpoint 1]
- [Subpoint 2]
H2: [Section 4 heading]
- [Subpoint 1]
H2: Frequently Asked Questions
H2: References

REQUIRED_ELEMENTS:
- Include 3+ statistics with (Source, Year) — suggest specific stats to research
- Include 2+ expert quotes from credentialed professionals
- Include 5+ inline citations in [Source, Year] format
- Include at least 1 comparison table (suggest what to compare)
- Include Key Takeaways with 3 bullet points at the top
- Write at Flesch-Kincaid grade 6-8 reading level
- Every H2 section must open with a 40-60 word answer paragraph
- Never start paragraphs with pronouns (It, This, They)
- Include Last Updated date for freshness signal
- Include FAQ section with 3-5 Q&A pairs

COMPETITOR_1: [domain.com] — [what they cover well and their word count]
COMPETITOR_2: [domain.com] — [what they cover well and their word count]
COMPETITOR_3: [domain.com] — [what they cover well and their word count]

CONTENT_GAP_1: [specific topic/angle competitors miss]
CONTENT_GAP_2: [specific topic/angle competitors miss]
CONTENT_GAP_3: [specific topic/angle competitors miss]";

        $system = 'You are an expert SEO content strategist. Return the brief in the EXACT format requested with all labels. Be specific — use real competitor domains, suggest real statistics to research, and provide concrete actionable headings. Every field must be filled.';

        $provider = AI_Provider_Manager::get_active_provider();
        $request_options = [ 'max_tokens' => 3000, 'temperature' => 0.5 ];

        if ( $provider ) {
            $result = AI_Provider_Manager::send_request( $provider['provider_id'], $prompt, $system, $request_options );
        } else {
            $result = Cloud_API::generate( $prompt, $system, $request_options );
        }

        if ( ! $result['success'] ) {
            return $result;
        }

        $content = $result['content'];

        // Parse into flat structure matching the view's expectations
        $brief = [
            'success'             => true,
            'keyword'             => $keyword,
            'raw'                 => $content,
            'title'               => '',
            'secondary_keywords'  => [],
            'lsi_keywords'        => [],
            'intent_type'         => '',
            'intent_description'  => '',
            'difficulty'          => '',
            'content_type'        => '',
            'outline'             => [],
            'required_elements'   => [],
            'competitors'         => [],
            'content_gap'         => [],
        ];

        // Title
        if ( preg_match( '/^TITLE:\s*(.+)$/mi', $content, $m ) ) {
            $brief['title'] = trim( $m[1], ' "\'*' );
        }

        // Secondary keywords → array
        if ( preg_match( '/^SECONDARY_KEYWORDS:\s*(.+)$/mi', $content, $m ) ) {
            $brief['secondary_keywords'] = array_map( 'trim', explode( ',', $m[1] ) );
        }

        // LSI keywords → array
        if ( preg_match( '/^LSI_KEYWORDS:\s*(.+)$/mi', $content, $m ) ) {
            $brief['lsi_keywords'] = array_map( 'trim', explode( ',', $m[1] ) );
        }

        // Intent
        if ( preg_match( '/^INTENT_TYPE:\s*(.+)$/mi', $content, $m ) ) {
            $brief['intent_type'] = trim( $m[1] );
        }
        if ( preg_match( '/^INTENT_DESCRIPTION:\s*(.+)$/mi', $content, $m ) ) {
            $brief['intent_description'] = trim( $m[1] );
        }

        // Difficulty & content type
        if ( preg_match( '/^DIFFICULTY:\s*(.+)$/mi', $content, $m ) ) {
            $brief['difficulty'] = trim( $m[1] );
        }
        if ( preg_match( '/^CONTENT_TYPE:\s*(.+)$/mi', $content, $m ) ) {
            $brief['content_type'] = trim( $m[1] );
        }

        // Outline → array of ['heading' => '', 'subheadings' => []]
        if ( preg_match( '/OUTLINE:\s*\n([\s\S]*?)(?=\nREQUIRED|$)/i', $content, $outline_block ) ) {
            $current_section = null;
            foreach ( explode( "\n", $outline_block[1] ) as $line ) {
                $line = trim( $line );
                if ( preg_match( '/^H[1-3]:\s*(.+)$/i', $line, $hm ) ) {
                    if ( $current_section ) {
                        $brief['outline'][] = $current_section;
                    }
                    $current_section = [ 'heading' => trim( $hm[1] ), 'subheadings' => [] ];
                } elseif ( preg_match( '/^[-*]\s+(.+)$/', $line, $sm ) && $current_section ) {
                    $current_section['subheadings'][] = trim( $sm[1] );
                }
            }
            if ( $current_section ) {
                $brief['outline'][] = $current_section;
            }
        }

        // Required elements → flat array of strings
        if ( preg_match( '/REQUIRED_ELEMENTS:\s*\n([\s\S]*?)(?=\nCOMPETITOR|$)/i', $content, $req_block ) ) {
            foreach ( explode( "\n", $req_block[1] ) as $line ) {
                $line = trim( $line );
                if ( preg_match( '/^[-*]\s+(.+)$/', $line, $rm ) ) {
                    $brief['required_elements'][] = trim( $rm[1] );
                }
            }
        }

        // Competitors → array of ['domain' => '', 'analysis' => '']
        if ( preg_match_all( '/^COMPETITOR_\d+:\s*(.+?)\s*[—\-]\s*(.+)$/mi', $content, $comp_matches, PREG_SET_ORDER ) ) {
            foreach ( $comp_matches as $cm ) {
                $brief['competitors'][] = [
                    'domain'   => trim( $cm[1], ' []' ),
                    'analysis' => trim( $cm[2] ),
                ];
            }
        }

        // Content gaps → array of strings
        if ( preg_match_all( '/^CONTENT_GAP_\d+:\s*(.+)$/mi', $content, $gap_matches ) ) {
            $brief['content_gap'] = array_map( 'trim', $gap_matches[1] );
        }

        return $brief;
    }

    /**
     * Export brief as plain text.
     */
    public function export_text( array $brief ): string {
        if ( empty( $brief['raw'] ) ) {
            return '';
        }

        $header = "CONTENT BRIEF — " . strtoupper( $brief['keyword'] ?? '' ) . "\n";
        $header .= "Generated: " . wp_date( 'F j, Y' ) . "\n";
        $header .= "Site: " . home_url() . "\n";
        $header .= str_repeat( '=', 60 ) . "\n\n";

        return $header . $brief['raw'];
    }
}
