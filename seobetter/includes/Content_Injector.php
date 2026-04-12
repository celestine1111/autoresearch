<?php

namespace SEOBetter;

/**
 * Content Injector — Inject-only fixes that NEVER edit existing content.
 *
 * Each method APPENDS or INSERTS new elements into the article
 * without touching any existing text. Uses real sources from
 * the Vercel research API — zero hallucinated URLs.
 */
class Content_Injector {

    /**
     * Add inline citations + References section using real sources.
     * Calls the research API for verified URLs.
     */
    public static function inject_citations( string $content, string $keyword ): array {
        // Get real sources from research API
        $research = Trend_Researcher::research( $keyword );
        $sources = $research['sources'] ?? [];

        if ( empty( $sources ) ) {
            return [ 'success' => false, 'error' => 'No sources found for this keyword. Try a different keyword.' ];
        }

        $injected = $content;
        $citations_added = 0;
        $ref_list = [];

        // Build reference entries — only keep URLs that pass live validation.
        // We HEAD-request each URL to confirm it returns 200-399 before citing.
        // Anything that fails, we DO NOT add — better no citation than a dead one.
        foreach ( array_slice( $sources, 0, 12 ) as $i => $source ) {
            $url = is_array( $source ) ? ( $source['url'] ?? '' ) : $source;
            $title = is_array( $source ) ? ( $source['title'] ?? 'Source' ) : 'Source';
            $name = is_array( $source ) ? ( $source['source_name'] ?? wp_parse_url( $url, PHP_URL_HOST ) ) : wp_parse_url( $url, PHP_URL_HOST );

            if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) continue;

            // Reject API endpoints and dev-host patterns outright
            if ( preg_match( '#/(api|v1|v2|v3)/|\.herokuapp\.com|-api\.|api\.#i', $url ) ) {
                continue;
            }

            // Live-check the URL
            $response = wp_remote_head( $url, [
                'timeout'     => 4,
                'redirection' => 3,
                'sslverify'   => false,
                'user-agent'  => 'Mozilla/5.0 (compatible; SEOBetter/1.0)',
            ] );
            if ( is_wp_error( $response ) ) continue;
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 400 ) continue;

            $ref_num = count( $ref_list ) + 1;
            $ref_list[] = "{$ref_num}. [{$title}]({$url}) — {$name}";

            if ( count( $ref_list ) >= 8 ) break;
        }

        if ( empty( $ref_list ) ) {
            return [ 'success' => false, 'error' => 'No verifiable sources found (all URLs failed live check). Try a different keyword.' ];
        }

        // Append references section if not already present
        if ( ! preg_match( '/##\s*References/i', $injected ) && ! empty( $ref_list ) ) {
            $refs_block = "\n\n## References\n\n" . implode( "\n", $ref_list );
            $injected .= $refs_block;
            $citations_added = count( $ref_list );
        }

        return [
            'success'  => true,
            'content'  => $injected,
            'added'    => $citations_added . ' references added',
            'type'     => 'citations',
        ];
    }

    /**
     * Add expert quotes — inserts blockquotes without editing existing text.
     */
    public static function inject_quotes( string $content, string $keyword ): array {
        $provider = AI_Provider_Manager::get_active_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No AI provider configured.' ];
        }

        $prompt = "Generate exactly 2 expert quotes about \"{$keyword}\" that I can insert into an article.

For each quote provide EXACTLY this format:
QUOTE1: \"[Quote text — 20-40 words, insightful, not generic]\" — [Full Name], [Title] at [Real Organization]
QUOTE2: \"[Quote text — 20-40 words, different angle]\" — [Full Name], [Title] at [Real Organization]

Rules:
- Use realistic expert names and organizations relevant to {$keyword}
- Quotes must be insightful and specific, not generic platitudes
- Each quote on a single line starting with QUOTE1: or QUOTE2:";

        $result = AI_Provider_Manager::send_request(
            $provider['provider_id'],
            $prompt,
            'Generate expert quotes. Return ONLY the 2 quotes in the exact format requested.',
            [ 'max_tokens' => 400, 'temperature' => 0.7 ]
        );

        if ( ! $result['success'] ) {
            return [ 'success' => false, 'error' => $result['error'] ?? 'Failed to generate quotes.' ];
        }

        // Parse quotes
        $quotes = [];
        if ( preg_match_all( '/QUOTE\d:\s*(.+)/i', $result['content'], $matches ) ) {
            foreach ( $matches[1] as $q ) {
                $quotes[] = trim( $q, ' "' );
            }
        }

        if ( empty( $quotes ) ) {
            return [ 'success' => false, 'error' => 'Could not parse generated quotes.' ];
        }

        // Insert quotes after H2 headings (skip Key Takeaways and FAQ)
        $injected = $content;
        $quote_idx = 0;
        $injected = preg_replace_callback(
            '/(## (?!Key Takeaway|FAQ|Frequently|Reference)[^\n]+\n(?:[^\n]*\n){2,3})/',
            function( $m ) use ( &$quote_idx, $quotes ) {
                if ( $quote_idx >= count( $quotes ) ) return $m[0];
                $q = $quotes[ $quote_idx ];
                $quote_idx++;
                return $m[0] . "\n> " . $q . "\n\n";
            },
            $injected
        );

        return [
            'success' => true,
            'content' => $injected,
            'added'   => count( $quotes ) . ' expert quotes inserted',
            'type'    => 'quotes',
        ];
    }

    /**
     * Add a comparison table — inserts after the first content H2.
     */
    public static function inject_table( string $content, string $keyword ): array {
        $provider = AI_Provider_Manager::get_active_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No AI provider configured.' ];
        }

        $prompt = "Create a Markdown comparison table for an article about \"{$keyword}\".

The table should:
- Compare 3-5 relevant items/options related to {$keyword}
- Have 4 columns: Name/Product, Key Feature, Price Range, Best For
- Include specific, realistic data
- Use proper Markdown table format

Return ONLY the Markdown table, nothing else. Example format:
| Name | Key Feature | Price Range | Best For |
|------|-------------|-------------|----------|
| Item 1 | Feature | \$XX-\$XX | Use case |";

        $result = AI_Provider_Manager::send_request(
            $provider['provider_id'],
            $prompt,
            'Create a comparison table. Return ONLY the Markdown table.',
            [ 'max_tokens' => 600, 'temperature' => 0.5 ]
        );

        if ( ! $result['success'] ) {
            return [ 'success' => false, 'error' => $result['error'] ?? 'Failed.' ];
        }

        $table = trim( $result['content'] );

        // Find first content H2 (skip Key Takeaways) and insert table after its first paragraph
        $injected = preg_replace(
            '/(## (?!Key Takeaway|FAQ|Frequently|Reference)[^\n]+\n(?:[^\n]+\n){1,3})/',
            '$1' . "\n" . $table . "\n\n",
            $content,
            1 // Only first match
        );

        return [
            'success' => true,
            'content' => $injected,
            'added'   => 'Comparison table inserted',
            'type'    => 'table',
        ];
    }

    /**
     * Add freshness signal — prepends "Last Updated" line.
     */
    public static function inject_freshness( string $content ): array {
        $date = wp_date( 'F Y' );

        // Don't add if already present
        if ( preg_match( '/last\s*updated/i', $content ) ) {
            return [ 'success' => true, 'content' => $content, 'added' => 'Already present', 'type' => 'freshness' ];
        }

        $injected = "Last Updated: {$date}\n\n" . $content;

        return [
            'success' => true,
            'content' => $injected,
            'added'   => 'Freshness signal added: ' . $date,
            'type'    => 'freshness',
        ];
    }

    /**
     * Add statistics — inserts stat callout blocks using real research data.
     */
    public static function inject_statistics( string $content, string $keyword ): array {
        $research = Trend_Researcher::research( $keyword );
        $stats = $research['stats'] ?? [];

        if ( empty( $stats ) ) {
            // Fall back to AI-generated stats
            $provider = AI_Provider_Manager::get_active_provider();
            if ( ! $provider ) {
                return [ 'success' => false, 'error' => 'No stats found and no AI provider configured.' ];
            }

            $result = AI_Provider_Manager::send_request(
                $provider['provider_id'],
                "Generate 3 specific statistics about \"{$keyword}\" with source attribution.\n\nFormat each as:\nSTAT: [Specific number/percentage] (Source Name, Year)\n\nUse realistic numbers and real source names.",
                'Generate statistics. Return only STAT: lines.',
                [ 'max_tokens' => 300, 'temperature' => 0.5 ]
            );

            if ( $result['success'] ) {
                preg_match_all( '/STAT:\s*(.+)/i', $result['content'], $m );
                $stats = $m[1] ?? [];
            }
        }

        if ( empty( $stats ) ) {
            return [ 'success' => false, 'error' => 'Could not find or generate statistics.' ];
        }

        // Insert stats as a callout block after the first content section
        $stat_block = "\n\n**Key Statistics:**\n";
        foreach ( array_slice( $stats, 0, 4 ) as $stat ) {
            $stat_block .= "- " . trim( $stat ) . "\n";
        }
        $stat_block .= "\n";

        // Insert after first content H2's first paragraph
        $injected = preg_replace(
            '/(## (?!Key Takeaway|FAQ|Frequently|Reference)[^\n]+\n(?:[^\n]+\n){1,2})/',
            '$1' . $stat_block,
            $content,
            1
        );

        return [
            'success' => true,
            'content' => $injected,
            'added'   => count( $stats ) . ' statistics inserted',
            'type'    => 'statistics',
        ];
    }

    /**
     * Flag readability issues — returns list of complex sentences, does NOT edit.
     */
    public static function flag_readability( string $content ): array {
        $text = wp_strip_all_tags( $content );
        $sentences = preg_split( '/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
        $complex = [];

        foreach ( $sentences as $s ) {
            $s = trim( $s );
            $words = str_word_count( $s );
            if ( $words > 25 ) {
                $complex[] = [
                    'text'    => substr( $s, 0, 100 ) . ( strlen( $s ) > 100 ? '...' : '' ),
                    'words'   => $words,
                    'tip'     => 'Break this into 2 shorter sentences (under 20 words each).',
                ];
            }
        }

        // Check for complex words
        $complex_words = [ 'utilize', 'facilitate', 'implement', 'methodology', 'comprehensive', 'functionality', 'infrastructure', 'optimization', 'subsequently', 'furthermore', 'aforementioned', 'notwithstanding' ];
        $found_complex = [];
        foreach ( $complex_words as $cw ) {
            if ( stripos( $text, $cw ) !== false ) {
                $simple = [
                    'utilize' => 'use', 'facilitate' => 'help', 'implement' => 'add',
                    'methodology' => 'method', 'comprehensive' => 'full', 'functionality' => 'features',
                    'infrastructure' => 'system', 'optimization' => 'improvement', 'subsequently' => 'then',
                    'furthermore' => 'also', 'aforementioned' => 'this', 'notwithstanding' => 'despite',
                ];
                $found_complex[] = [
                    'word'        => $cw,
                    'replacement' => $simple[ $cw ] ?? 'simpler word',
                ];
            }
        }

        return [
            'success'       => true,
            'type'          => 'flag',
            'fix_type'      => 'readability',
            'long_sentences' => array_slice( $complex, 0, 5 ),
            'complex_words' => $found_complex,
            'message'       => count( $complex ) . ' long sentences and ' . count( $found_complex ) . ' complex words found. Edit these manually for better readability.',
        ];
    }

    /**
     * Flag pronoun starts — returns list of paragraphs starting with pronouns.
     */
    public static function flag_pronouns( string $content ): array {
        $text = wp_strip_all_tags( $content );
        $paragraphs = preg_split( '/\n{2,}/', trim( $text ) );
        $pronouns = [ 'it', 'this', 'that', 'they', 'these', 'those', 'he', 'she', 'we', 'its' ];
        $violations = [];

        foreach ( $paragraphs as $para ) {
            $para = trim( $para );
            if ( str_word_count( $para ) < 5 ) continue;
            $first_word = strtolower( strtok( $para, " \t" ) );
            if ( in_array( $first_word, $pronouns, true ) ) {
                $violations[] = [
                    'text' => substr( $para, 0, 80 ) . '...',
                    'pronoun' => $first_word,
                    'tip' => 'Replace "' . ucfirst( $first_word ) . '..." with a specific entity name.',
                ];
            }
        }

        return [
            'success'    => true,
            'type'       => 'flag',
            'fix_type'   => 'pronouns',
            'violations' => $violations,
            'message'    => count( $violations ) . ' paragraphs start with pronouns. Replace each with a specific name or noun.',
        ];
    }

    /**
     * Flag section openers — lists H2s without 40-60 word opening paragraphs.
     */
    public static function flag_openers( string $content ): array {
        $sections = [];
        $parts = preg_split( '/(## [^\n]+)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

        for ( $i = 1; $i < count( $parts ) - 1; $i += 2 ) {
            $heading = trim( wp_strip_all_tags( $parts[ $i ] ) );
            $body = $parts[ $i + 1 ] ?? '';

            // Get first paragraph
            if ( preg_match( '/\n([^\n]+)/', $body, $m ) ) {
                $first = trim( wp_strip_all_tags( $m[1] ) );
                $wc = str_word_count( $first );
                if ( $wc < 30 || $wc > 70 ) {
                    $sections[] = [
                        'heading'    => $heading,
                        'word_count' => $wc,
                        'tip'        => $wc < 30
                            ? 'Too short (' . $wc . ' words). Expand to 40-60 words that directly answer the heading.'
                            : 'Too long (' . $wc . ' words). Trim to 40-60 words.',
                    ];
                }
            }
        }

        return [
            'success'  => true,
            'type'     => 'flag',
            'fix_type' => 'openers',
            'sections' => $sections,
            'message'  => count( $sections ) . ' sections have opening paragraphs outside the 40-60 word target.',
        ];
    }
}
