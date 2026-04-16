<?php

namespace SEOBetter;

/**
 * Content Injector — Inject-only fixes that NEVER edit existing content.
 *
 * Each method APPENDS or INSERTS new elements into the article
 * without touching any existing text. Uses real sources from
 * the Vercel research API — zero hallucinated URLs.
 *
 * v1.5.63 exception: `simplify_readability()` is the ONLY method that
 * rewrites existing content. It runs a second AI pass on sections
 * whose Flesch-Kincaid grade > 9, explicitly instructing the model
 * to preserve all facts, statistics, citations, and expert quotes
 * while breaking long sentences and swapping complex words. This is
 * gated behind explicit user consent (Check Readability → Rewrite
 * button) because it's the only path to reliably hit grade 6-8
 * (prompts alone land at grade 11-13).
 */
class Content_Injector {

    /**
     * v1.5.65 — REWRITTEN. Add inline citation anchor links + References
     * section using the topic-filtered Citation_Pool.
     *
     * Previous behavior called Trend_Researcher::research() directly,
     * bypassing the v1.5.62 Citation_Pool topical relevance filter. That's
     * why articles about raw dog food kept getting "Veganism — Wikipedia"
     * and 4 random dev.to posts injected as references. User reported the
     * same bug 3 times; the root cause was this method not using the pool.
     *
     * New behavior:
     *   1. Build the citation pool via Citation_Pool::build() which applies
     *      the topical relevance filter (title/slug must contain a content
     *      token from the keyword).
     *   2. Append a numbered References section to the markdown (reuses
     *      Citation_Pool::append_references_section which is the same helper
     *      assemble_final uses — preview and inject-fix stay in sync).
     *   3. Inject inline [N] anchor superscripts into the body at the end of
     *      the first N sentences containing a statistic, a named entity, or
     *      a strong factual claim. Each [N] is a clickable anchor link to
     *      the matching #ref-N entry in the References section.
     */
    public static function inject_citations( string $content, string $keyword, array $existing_pool = [] ): array {
        // v1.5.76 — use existing pool from original generation if available.
        // The original generation builds the pool during the trends step with
        // full context (keyword + category + country + Vercel research). The
        // inject button only has the keyword, so its fresh pool is weaker.
        // User reported "it worked during generation but Add Citations says
        // 0 sources" — because the button rebuilt from scratch.
        if ( ! empty( $existing_pool ) ) {
            $pool = $existing_pool;
        } else {
            // Fallback: build fresh pool (less context, may be weaker)
            $pool = Citation_Pool::build( $keyword );
        }

        // v1.5.66 — fallback. If the topical filter returns empty, fall back
        // to direct research sources + a lenient keyword-in-title check so
        // the button still does something useful instead of hard-failing.
        // This prevents the "Retry" red state when the cached pool is thin.
        if ( empty( $pool ) ) {
            $research = Trend_Researcher::research( $keyword );
            $raw_sources = is_array( $research['sources'] ?? null ) ? $research['sources'] : [];
            $stopwords = [ 'the','and','for','how','what','why','when','where','your','with','from','best','top','safely','guide','2024','2025','2026','2027' ];
            $tokens = array_filter(
                array_map(
                    fn( $t ) => preg_replace( '/[^\w]/', '', strtolower( $t ) ),
                    explode( ' ', $keyword )
                ),
                fn( $t ) => strlen( $t ) >= 4 && ! in_array( $t, $stopwords, true )
            );
            $pool = [];
            foreach ( $raw_sources as $s ) {
                if ( ! is_array( $s ) || empty( $s['url'] ) ) continue;
                $title = strtolower( $s['title'] ?? '' );
                $slug = strtolower( (string) wp_parse_url( $s['url'], PHP_URL_PATH ) );
                $haystack = $title . ' ' . $slug;
                $matches = false;
                foreach ( $tokens as $t ) {
                    if ( str_contains( $haystack, $t ) ) { $matches = true; break; }
                }
                if ( ! $matches ) continue;
                // Skip known noise domains
                $host = strtolower( (string) wp_parse_url( $s['url'], PHP_URL_HOST ) );
                if ( preg_match( '/^(dev\.to|lemmy\.|en\.wikipedia\.org\/wiki\/Veganism)/', $host ) ) continue;
                $pool[] = [
                    'url'         => $s['url'],
                    'title'       => $s['title'] ?? wp_parse_url( $s['url'], PHP_URL_HOST ),
                    'source_name' => $s['source_name'] ?? wp_parse_url( $s['url'], PHP_URL_HOST ),
                ];
                if ( count( $pool ) >= 8 ) break;
            }
        }

        if ( empty( $pool ) ) {
            return [
                'success' => false,
                'error'   => 'No topically-relevant citations available for this keyword. Both the Citation Pool and the fallback research sources returned zero entries after filtering.',
            ];
        }

        // Append the References section using the shared helper. If References
        // already exists in the content (from assemble_final's preview path),
        // it will be rebuilt with the pool. The helper handles both cases.
        $injected = Citation_Pool::append_references_section( $content, $pool );

        // Count how many references were actually added
        if ( preg_match_all( '/^\d+\.\s+\[[^\]]+\]\(https?:\/\//m', $injected, $ref_matches ) ) {
            $ref_count = count( $ref_matches[0] );
        } else {
            $ref_count = 0;
        }

        if ( $ref_count === 0 ) {
            return [
                'success' => false,
                'error'   => 'References section built but contained zero entries.',
            ];
        }

        // v1.5.65 — Inline [N] anchor injection. Find sentences in the body
        // containing statistics, percentages, years, or strong named entities,
        // and append a clickable [N] superscript that jumps to #ref-N in the
        // References section. Caps at the number of references available.
        $max_anchors = min( $ref_count, 8 );
        $injected = self::inject_inline_citation_anchors( $injected, $max_anchors );

        return [
            'success' => true,
            'content' => $injected,
            'added'   => $ref_count . ' citations added with inline anchor links',
            'type'    => 'citations',
        ];
    }

    /**
     * v1.5.65 — Walk the body text and append clickable [N] anchors to
     * sentences containing factual claims (statistics, percentages, years,
     * or proper-noun-heavy phrases). Each [N] links to #ref-N in the
     * References section below. Max N anchors injected.
     *
     * Skips: Key Takeaways, FAQ, References sections. Only injects in
     * main content sections.
     */
    private static function inject_inline_citation_anchors( string $markdown, int $max_anchors ): string {
        if ( $max_anchors <= 0 ) return $markdown;

        // Split at the References heading — we only inject in the content
        // BEFORE References, never into the References list itself.
        $parts = preg_split( '/(\n##\s*References\s*\n)/i', $markdown, 2, PREG_SPLIT_DELIM_CAPTURE );
        if ( count( $parts ) < 3 ) {
            // No References section — nothing to anchor to. Return as-is.
            return $markdown;
        }
        $body = $parts[0];
        $refs_separator = $parts[1];
        $refs_content = $parts[2];

        // Find candidate sentences: contains a statistic (\d+%), a year
        // (20\d\d), a dollar amount, or a "N-N%" range. Also accept sentences
        // with named entities (2+ consecutive capitalized words).
        $candidates = [];
        if ( preg_match_all( '/([^.\n!?]{20,200}(?:\d{1,3}\s*%|\d+[\.,]\d+\s*%|\$\d[\d,]*|\b(?:19|20)\d{2}\b|[A-Z][a-z]+\s+[A-Z][a-z]+)[^.\n!?]{0,100}[.!?])/', $body, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[0] as $m ) {
                $sentence = $m[0];
                $offset = $m[1];
                // Skip if already has a [N] anchor
                if ( preg_match( '/\[\d+\]/', $sentence ) ) continue;
                // Skip if inside a code block, heading, list item, or table
                $line_start = strrpos( substr( $body, 0, $offset ), "\n" );
                $line_start = $line_start === false ? 0 : $line_start + 1;
                $line_prefix = substr( $body, $line_start, 5 );
                if ( str_starts_with( ltrim( $line_prefix ), '#' ) ) continue;
                if ( str_starts_with( ltrim( $line_prefix ), '|' ) ) continue;
                if ( preg_match( '/^[-*+]\s/', ltrim( $line_prefix ) ) ) continue;
                // Skip if inside a Key Takeaways or FAQ section — check backward for nearest H2
                $preceding = substr( $body, 0, $offset );
                if ( preg_match_all( '/\n##\s+([^\n]+)/', $preceding, $h2_matches ) ) {
                    $last_h2 = end( $h2_matches[1] );
                    if ( preg_match( '/key\s*takeaway|faq|frequently|reference/i', $last_h2 ) ) continue;
                }
                $candidates[] = [ 'text' => $sentence, 'offset' => $offset ];
                if ( count( $candidates ) >= $max_anchors ) break;
            }
        }

        if ( empty( $candidates ) ) return $markdown;

        // Inject anchors from the END of the body backward so earlier offsets
        // stay valid after each injection (appending shifts later offsets).
        $anchor_num = count( $candidates );
        for ( $i = count( $candidates ) - 1; $i >= 0; $i-- ) {
            $c = $candidates[ $i ];
            $sentence = $c['text'];
            $end_offset = $c['offset'] + strlen( $sentence );
            // Append [N](#ref-N) before the final punctuation if possible,
            // otherwise after.
            $last_char = substr( $sentence, -1 );
            if ( in_array( $last_char, [ '.', '!', '?' ], true ) ) {
                $inject_at = $end_offset - 1;
                $anchor = ' [' . $anchor_num . '](#ref-' . $anchor_num . ')';
                $body = substr( $body, 0, $inject_at ) . $anchor . substr( $body, $inject_at );
            }
            $anchor_num--;
        }

        // Add HTML id anchors to the References list entries so the [N]
        // links actually jump. Convert `1. [title](url)` to
        // `1. <span id="ref-1"></span>[title](url)`.
        // This is markdown — the HTML span survives through format_hybrid
        // because inline HTML is allowed in markdown.
        $refs_content = preg_replace_callback(
            '/^(\d+)\.\s+(\[)/m',
            function( $m ) {
                return $m[1] . '. <span id="ref-' . $m[1] . '"></span>' . $m[2];
            },
            $refs_content
        );

        return $body . $refs_separator . $refs_content;
    }

    /**
     * v1.5.76c — Add expert quotes from REAL research data.
     *
     * Previous behavior (v1.5.0-v1.5.76b): asked the AI to "generate 2
     * expert quotes with realistic names and organizations." This produced
     * 100% hallucinated quotes — fake people, fake titles, fake orgs.
     * If anyone Googled the "expert" name they'd find nothing, destroying
     * E-E-A-T trust. For YMYL topics (pet health) this is especially bad.
     *
     * New behavior: pulls REAL quotes from the Vercel research endpoint
     * (Reddit discussions, Wikipedia definitions, Bluesky/Mastodon posts,
     * HN comments). These are real things real people said, with real
     * source URLs. Formatted as social media citation blockquotes per
     * article_design.md §5.16 so users can review before publishing.
     *
     * Falls back to a clearly-labelled "industry perspective" summary
     * (not a fake quote) only when zero real quotes exist.
     */
    public static function inject_quotes( string $content, string $keyword ): array {
        // Pull real quotes from Vercel research data
        $research = Trend_Researcher::research( $keyword );
        $real_quotes = $research['quotes'] ?? [];

        // Also check for Reddit discussions in trending data
        $trending = $research['trends'] ?? [];

        $quotes = [];
        foreach ( $real_quotes as $q ) {
            if ( ! is_array( $q ) || empty( $q['text'] ) ) continue;
            $text = trim( $q['text'] );
            if ( strlen( $text ) < 20 ) continue;
            // Cap at 200 chars for readability
            if ( strlen( $text ) > 200 ) $text = substr( $text, 0, 197 ) . '...';
            $source = $q['source'] ?? 'Online discussion';
            $url = $q['url'] ?? '';
            // Format as attributed quote with source
            $formatted = "\"{$text}\" — {$source}";
            if ( $url ) {
                $formatted .= " ([source]({$url}))";
            }
            $quotes[] = $formatted;
            if ( count( $quotes ) >= 3 ) break;
        }

        // Fallback: extract quotable snippets from trending discussions
        if ( count( $quotes ) < 2 ) {
            foreach ( $trending as $t ) {
                if ( ! is_string( $t ) ) continue;
                // Trending items look like: "Title text" — 45 upvotes in r/dogs
                if ( preg_match( '/^"(.{20,150})".*?—\s*(.+)$/u', $t, $m ) ) {
                    $quotes[] = "\"{$m[1]}\" — {$m[2]}";
                    if ( count( $quotes ) >= 3 ) break;
                }
            }
        }

        if ( empty( $quotes ) ) {
            return [ 'success' => false, 'error' => 'No real quotes found in research data for this keyword. The research endpoint returned zero quotable content from Reddit, Wikipedia, Bluesky, Mastodon, or HN.' ];
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

        // v1.5.75 — dynamic columns. Previous hard-coded "Price Range" column
        // was empty when the AI didn't know the pricing (e.g. Black Hawk puppy
        // food had "-0" in the price column). Now: AI decides which columns
        // are relevant. Only include columns where every row has real data.
        $prompt = "Create a Markdown comparison table for an article about \"{$keyword}\".

The table should:
- Compare 3-5 relevant items/options related to {$keyword}
- Choose 3-4 columns that make sense for this topic (e.g. Name, Key Feature, Best For, Size, Rating)
- ONLY include a Price column if you know the actual prices. If prices are unknown, use a different useful column instead.
- Include specific, realistic data in EVERY cell. No empty cells, no dashes, no 'N/A'.
- Use proper Markdown table format

Return ONLY the Markdown table, nothing else.";

        $result = AI_Provider_Manager::send_request(
            $provider['provider_id'],
            $prompt,
            'You are a data table generator. Return ONLY a markdown table with | separators and --- header row. No explanation, no prose.',
            [ 'max_tokens' => 800, 'temperature' => 0.5 ]
        );

        if ( ! $result['success'] ) {
            return [ 'success' => false, 'error' => 'Table generation failed: ' . ( $result['error'] ?? 'AI provider returned an error. Check your API key in Settings.' ) ];
        }

        $table = trim( $result['content'] );

        // v1.5.69 — validate that the AI actually returned a markdown table.
        // Previous behavior: if the AI returned prose instead of a table, we'd
        // inject random text into the article and report "Comparison table inserted".
        if ( ! str_contains( $table, '|' ) || ! str_contains( $table, '---' ) ) {
            return [ 'success' => false, 'error' => 'AI did not return a valid markdown table. Try again.' ];
        }

        // Find first content H2 (skip Key Takeaways) and insert table after its first paragraph
        $injected = preg_replace(
            '/(## (?!Key Takeaway|FAQ|Frequently|Reference)[^\n]+\n(?:[^\n]+\n){1,3})/',
            '$1' . "\n" . $table . "\n\n",
            $content,
            1 // Only first match
        );

        // v1.5.69 — detect silent failure: if the regex didn't match any H2
        // with body text, $injected === $content (unchanged). Report error
        // instead of misleading "Comparison table inserted" success.
        if ( $injected === $content ) {
            // Fallback: insert before the FAQ or References section instead
            if ( preg_match( '/(\n## (?:FAQ|Frequently|Reference)[^\n]*\n)/i', $content, $faq_match, PREG_OFFSET_MATCH ) ) {
                $injected = substr( $content, 0, $faq_match[1][1] ) . "\n" . $table . "\n" . substr( $content, $faq_match[1][1] );
            } else {
                // Last resort: append before end
                $injected = $content . "\n\n" . $table . "\n";
            }
        }

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
        // v1.5.62 — line-based preprocessing BEFORE tokenization. v1.5.61
        // stripped URLs but still flagged table rows, list bullets, and
        // heading-run-together paragraphs as "long sentences". Fix: walk
        // the markdown line-by-line and drop any line that is structured
        // content (table row, list item, heading, code block, blockquote).
        // Then tokenize only the surviving prose lines.
        $lines = preg_split( '/\r?\n/', $content );
        $prose_lines = [];
        $in_code_block = false;

        foreach ( $lines as $line ) {
            $trimmed = ltrim( $line );

            // Track fenced code blocks
            if ( str_starts_with( $trimmed, '```' ) ) {
                $in_code_block = ! $in_code_block;
                continue;
            }
            if ( $in_code_block ) continue;

            // Skip structural markdown
            if ( $trimmed === '' ) continue;
            if ( str_starts_with( $trimmed, '#' ) ) continue;                   // headings
            if ( str_starts_with( $trimmed, '|' ) ) continue;                   // table rows
            if ( str_starts_with( $trimmed, '>' ) ) continue;                   // blockquotes
            if ( preg_match( '/^[-*+•]\s/', $trimmed ) ) continue;              // bullet list items
            if ( preg_match( '/^\d+\.\s/', $trimmed ) ) continue;               // numbered list items
            if ( preg_match( '/^---+$/', $trimmed ) ) continue;                 // horizontal rules

            $prose_lines[] = $line;
        }

        $clean = implode( "\n", $prose_lines );

        // Strip markdown images, links, bare URLs, HTML img tags (v1.5.61)
        $clean = preg_replace( '/!\[[^\]]*\]\([^)]*\)/', '', $clean );
        $clean = preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $clean );
        $clean = preg_replace( '/https?:\/\/\S+/', '', $clean );
        $clean = preg_replace( '/\bwww\.\S+/', '', $clean );
        $clean = preg_replace( '/<img[^>]*>/i', '', $clean );

        $text = wp_strip_all_tags( $clean );
        // Drop URL-fragment remnants (key=value)
        $text = preg_replace( '/\b[a-z0-9_-]+=[^\s&]+/i', '', $text );

        $sentences = preg_split( '/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
        $complex = [];

        foreach ( $sentences as $s ) {
            $s = trim( $s );
            // Skip URL remnants, table fragments, markdown leakage
            if ( str_contains( $s, '=' ) || str_contains( $s, '&' ) ) continue;
            if ( str_contains( $s, '|' ) ) continue;
            if ( str_starts_with( $s, '#' ) ) continue;
            // Skip fragments with no real sentence shape — must have 4+ words
            // AND contain at least one lowercase letter (real prose, not ALL CAPS table data)
            $words = str_word_count( $s );
            if ( $words < 4 ) continue;
            if ( ! preg_match( '/[a-z]/', $s ) ) continue;
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

    /**
     * v1.5.61 — Flag keyword placement issues. Shows the user WHICH H2s
     * lack the keyword, WHERE the density is out of range, and whether
     * the first paragraph is missing the keyword.
     *
     * Returns a flag-mode response that the UI renders as an amber panel.
     */
    public static function flag_keyword_placement( string $content, string $keyword ): array {
        $keyword = trim( (string) $keyword );
        if ( $keyword === '' ) {
            return [
                'success'  => true,
                'type'     => 'flag',
                'fix_type' => 'keyword',
                'message'  => 'No focus keyword configured. Add one in the Primary Keyword field before regenerating.',
                'violations' => [],
            ];
        }

        // v1.5.63 — strip markdown syntax BEFORE counting so density
        // matches GEO_Analyzer's HTML-based count. Previously this method
        // counted on raw markdown (which included #, **, [], (), | chars
        // as "words"), producing 3.76% while GEO_Analyzer reported 2.78%
        // on the same article. Now both strip to plain prose first.
        $clean = $content;
        $clean = preg_replace( '/^[#>|]+/m', '', $clean );
        $clean = preg_replace( '/^[-*+]\s/m', '', $clean );
        $clean = preg_replace( '/\*\*([^*]+)\*\*/', '$1', $clean );
        $clean = preg_replace( '/!\[[^\]]*\]\([^)]*\)/', '', $clean );
        $clean = preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $clean );
        $clean = preg_replace( '/`([^`]+)`/', '$1', $clean );
        $text = wp_strip_all_tags( $clean );
        $word_count = max( 1, str_word_count( $text ) );
        $lower_text = strtolower( $text );
        $lower_kw = strtolower( $keyword );
        $kw_count = substr_count( $lower_text, $lower_kw );
        $kw_word_count = max( 1, str_word_count( $keyword ) );
        $density = round( ( $kw_count * $kw_word_count / $word_count ) * 100, 2 );

        // H2 analysis — which H2s contain the keyword (or a close variant)?
        preg_match_all( '/^##\s*(.+)$/m', $content, $h2_matches );
        $h2s = $h2_matches[1] ?? [];
        $kw_tokens = array_filter( preg_split( '/\s+/', $lower_kw ), fn( $t ) => strlen( $t ) >= 4 );
        $h2_with_kw = 0;
        $h2_without_kw = [];
        foreach ( $h2s as $h2 ) {
            $lower_h2 = strtolower( wp_strip_all_tags( $h2 ) );
            $matches_exact = str_contains( $lower_h2, $lower_kw );
            $matches_variant = false;
            if ( ! $matches_exact && ! empty( $kw_tokens ) ) {
                foreach ( $kw_tokens as $t ) {
                    if ( str_contains( $lower_h2 , $t ) ) { $matches_variant = true; break; }
                }
            }
            if ( $matches_exact || $matches_variant ) {
                $h2_with_kw++;
            } else {
                $h2_without_kw[] = [
                    'text' => trim( $h2 ),
                    'tip'  => 'Rewrite this H2 to include "' . $keyword . '" or a variant (e.g. one of: ' . implode( ', ', array_slice( $kw_tokens, 0, 3 ) ) . ').',
                ];
            }
        }
        $h2_coverage = count( $h2s ) > 0 ? round( ( $h2_with_kw / count( $h2s ) ) * 100 ) : 0;

        // Density violations
        $violations = [];
        if ( $density < 0.5 ) {
            $target = max( 1, round( 0.75 * $word_count / ( 100 * $kw_word_count ) ) );
            $violations[] = [
                'text' => 'Density ' . $density . '% — too low (target 0.5-1.5%).',
                'tip'  => 'Add ~' . ( $target - $kw_count ) . ' more mentions of "' . $keyword . '" across the article to reach 0.75%.',
            ];
        } elseif ( $density > 1.5 ) {
            $target = max( 1, round( 1.0 * $word_count / ( 100 * $kw_word_count ) ) );
            $violations[] = [
                'text' => 'Density ' . $density . '% — too high, risks keyword stuffing penalty (target 0.5-1.5%).',
                'tip'  => 'Rewrite ~' . ( $kw_count - $target ) . ' mentions as pronouns, variations, or synonyms to drop density to ~1%.',
            ];
        }
        if ( $h2_coverage < 30 ) {
            $violations[] = [
                'text' => 'Only ' . $h2_coverage . '% of H2s contain the keyword or a variant (target 30%+).',
                'tip'  => 'Rewrite ' . max( 1, ceil( count( $h2s ) * 0.3 ) - $h2_with_kw ) . ' H2 headings to include the keyword phrase.',
            ];
        }

        // First-paragraph check — does the intro paragraph contain the keyword?
        $first_para = '';
        if ( preg_match( '/(?<=\n\n|\A)([^\n#]{40,})/', $text, $fp_match ) ) {
            $first_para = $fp_match[1];
        }
        if ( $first_para && ! str_contains( strtolower( $first_para ), $lower_kw ) ) {
            $violations[] = [
                'text' => 'First paragraph does not contain the exact keyword phrase.',
                'tip'  => 'Add "' . $keyword . '" naturally to the first sentence of the intro. AIOSEO and Yoast both check this specifically.',
            ];
        }

        return [
            'success'    => true,
            'type'       => 'flag',
            'fix_type'   => 'keyword',
            'violations' => $violations,
            'sections'   => array_slice( $h2_without_kw, 0, 5 ),
            'density'    => $density,
            'h2_coverage' => $h2_coverage,
            'message'    => sprintf(
                'Keyword "%s": density %.2f%% (target 0.5-1.5%%), %d of %d H2s contain it (target 30%%+), %d violations flagged.',
                $keyword,
                $density,
                $h2_with_kw,
                count( $h2s ),
                count( $violations )
            ),
        ];
    }

    /**
     * v1.5.61 — Flag humanizer violations (Tier 1 + Tier 2 AI red-flag words).
     * Mirrors GEO_Analyzer::check_humanizer()'s word list.
     */
    public static function flag_humanizer( string $content ): array {
        $text = strtolower( wp_strip_all_tags( $content ) );
        $tier1 = [
            'delve', 'tapestry', 'landscape', 'paradigm', 'leverage', 'harness',
            'navigate', 'realm', 'embark', 'myriad', 'plethora', 'multifaceted',
            'groundbreaking', 'revolutionize', 'synergy', 'ecosystem', 'resonate',
            'streamline', 'testament', 'pivotal', 'cornerstone', 'game-changer',
            'nestled', 'breathtaking', 'stunning', 'seamless', 'vibrant', 'renowned',
        ];
        $tier2 = [
            'robust', 'cutting-edge', 'innovative', 'comprehensive', 'nuanced',
            'compelling', 'transformative', 'bolster', 'underscore', 'evolving',
            'fostering', 'imperative', 'intricate', 'overarching', 'unprecedented',
            'profound', 'showcasing', 'garner', 'crucial', 'vital',
        ];
        $violations = [];
        $tier1_count = 0;
        $tier2_count = 0;
        foreach ( $tier1 as $w ) {
            $c = substr_count( $text, $w );
            if ( $c > 0 ) {
                $tier1_count += $c;
                $violations[] = [
                    'text' => '"' . $w . '" (Tier 1 AI tell) — appears ' . $c . ' time' . ( $c > 1 ? 's' : '' ),
                    'tip'  => 'Replace every instance. Tier 1 words are instant AI red flags for Google Helpful Content.',
                ];
            }
        }
        foreach ( $tier2 as $w ) {
            $c = substr_count( $text, $w );
            if ( $c >= 3 ) {
                $tier2_count += $c;
                $violations[] = [
                    'text' => '"' . $w . '" (Tier 2) — appears ' . $c . ' times (3+ = AI tell)',
                    'tip'  => 'Replace 2+ instances. Tier 2 words are fine alone but 3+ in one article looks machine-generated.',
                ];
            }
        }

        return [
            'success'     => true,
            'type'        => 'flag',
            'fix_type'    => 'humanizer',
            'violations'  => array_slice( $violations, 0, 10 ),
            'tier1_count' => $tier1_count,
            'tier2_count' => $tier2_count,
            'message'     => sprintf(
                'Found %d Tier-1 violations and %d Tier-2 violations. Rewrite each flagged word for more natural prose.',
                $tier1_count,
                $tier2_count
            ),
        ];
    }

    /**
     * v1.5.61 — Flag CORE-EEAT gaps. Reports which of the 10 rubric items
     * failed so the user knows exactly what to add (first-hand voice,
     * tradeoffs, named entities, table, etc).
     */
    public static function flag_core_eeat( string $content ): array {
        $text = wp_strip_all_tags( $content );
        $word_count = str_word_count( $text );
        $missing = [];

        // C1 — Direct answer in first 150 words
        $first_150 = implode( ' ', array_slice( preg_split( '/\s+/', trim( $text ) ), 0, 150 ) );
        if ( ! preg_match( '/[^.!?]{20,}\./', $first_150 ) ) {
            $missing[] = [ 'text' => 'C1: No direct answer in the first 150 words.', 'tip' => 'Write a 20-50 word declarative statement at the top that directly answers the article title.' ];
        }
        // C2 — FAQ
        if ( ! preg_match( '/faq|frequently\s*asked/i', $content ) ) {
            $missing[] = [ 'text' => 'C2: No FAQ section.', 'tip' => 'Add an "## FAQ" H2 with 3-5 question-answer pairs.' ];
        }
        // O2 — Table
        if ( stripos( $content, '<table' ) === false && strpos( $content, '|---' ) === false ) {
            $missing[] = [ 'text' => 'O2: No comparison table.', 'tip' => 'Add a 3-5 row markdown table comparing options. Click "Add Comparison Table" to auto-insert.' ];
        }
        // R1 — 5+ specific numbers
        preg_match_all( '/\b\d+[\.,]?\d*\s*(?:%|percent|billion|million|thousand|USD|\$|kg|lb|mg|km|mi|hours?|days?|years?)\b|\b(?:19|20)\d{2}\b/i', $text, $num_matches );
        if ( count( $num_matches[0] ) < 5 ) {
            $missing[] = [ 'text' => 'R1: Only ' . count( $num_matches[0] ) . ' specific numbers (target 5+).', 'tip' => 'Add more statistics with specific percentages, dollar amounts, or years. Click "Add Statistics".' ];
        }
        // E1 — First-hand language
        if ( ! preg_match( '/\b(we (found|tested|tried|discovered|learned)|in our (test|experience|review)|i\'ve (used|tried|tested)|my experience|from our testing)\b/i', $text ) ) {
            $missing[] = [ 'text' => 'E1: No first-hand experience phrases.', 'tip' => 'Rewrite 1-2 sentences with "we tested", "in our experience", or "we found" — signals real experience to Google.' ];
        }
        // Exp1 — Practical examples
        if ( ! preg_match( '/\b(for example|for instance|such as|e\.g\.|consider)\b/i', $text ) ) {
            $missing[] = [ 'text' => 'Exp1: No practical examples.', 'tip' => 'Add "For example, ..." or "Consider, ..." to at least one section.' ];
        }
        // A1 — Named entities
        preg_match_all( '/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,3}\b/', $text, $entity_matches );
        if ( count( $entity_matches[0] ) < 3 ) {
            $missing[] = [ 'text' => 'A1: Only ' . count( $entity_matches[0] ) . ' named entities (target 3+).', 'tip' => 'Replace generic nouns with specific names: "The RSPCA" not "animal welfare", "Dr. Karen Becker" not "a vet".' ];
        }
        // T1 — Tradeoffs
        if ( ! preg_match( '/\b(however|but|though|while|limitation|drawback|caveat|tradeoff|trade.off|downside|weakness)\b/i', $text ) ) {
            $missing[] = [ 'text' => 'T1: No tradeoff/limitation acknowledgment.', 'tip' => 'Add one sentence with "however" or "drawback" — signals balanced perspective.' ];
        }

        return [
            'success'    => true,
            'type'       => 'flag',
            'fix_type'   => 'core_eeat',
            'violations' => array_slice( $missing, 0, 10 ),
            'message'    => count( $missing ) . ' of 10 CORE-EEAT rubric items are missing. Fix each for a +10 score boost per item.',
        ];
    }

    /**
     * v1.5.63 — Post-generation readability rewriter. Splits the markdown
     * into H2 sections, measures Flesch-Kincaid grade per section, and if
     * grade > 9 runs a single AI pass per section with an explicit grade-7
     * target. Preserves all facts, numbers, citations, named entities, and
     * structural elements (lists, tables, code). Only rewrites prose.
     *
     * Returns an inject-mode response with the rewritten markdown. The
     * caller (rest_inject_fix) re-formats and re-scores. Cost: ~$0.02 per
     * over-complex section (1-4 sections per article typically = $0.02-0.08).
     */
    public static function simplify_readability( string $markdown ): array {
        $provider = AI_Provider_Manager::get_active_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No AI provider configured.' ];
        }

        // v1.5.65 — measure the article's grade BEFORE rewriting so the
        // success message can show the actual improvement.
        $grade_before = self::calc_flesch_kincaid_grade( $markdown );

        // Split at H2 boundaries
        $parts = preg_split( '/(^##\s[^\n]+$)/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( count( $parts ) < 3 ) {
            return [ 'success' => false, 'error' => 'No sections to simplify.' ];
        }

        $preamble = $parts[0];
        $sections = [];
        for ( $i = 1; $i < count( $parts ); $i += 2 ) {
            $sections[] = [
                'heading' => $parts[ $i ],
                'body'    => $parts[ $i + 1 ] ?? '',
            ];
        }

        // Sections we protect from rewrites (structural, not prose)
        $protected_pattern = '/key\s*takeaway|faq|frequently|reference|quick\s*comparison|at\s*a\s*glance/i';

        $rewritten_count = 0;
        $rewritten_sections = [];

        foreach ( $sections as $idx => $section ) {
            if ( preg_match( $protected_pattern, $section['heading'] ) ) {
                continue;
            }

            // v1.5.67 — lowered threshold from > 9 to > 8. User tested with
            // grade 10.7 article and said "im not sure if it changes the
            // article the score does not change". Likely only 1-2 sections
            // were above the previous 9 threshold, leaving the bulk of the
            // article untouched. Grade 8 threshold rewrites more sections
            // which should drop the article average closer to the 7 target.
            $grade = self::calc_flesch_kincaid_grade( $section['body'] );
            if ( $grade <= 8 ) {
                continue; // Section already readable enough
            }

            // Rewrite this section with an AI pass
            // v1.5.70 — added rules 10-11 and tightened rule 9 to stop
            // the AI converting markdown list syntax (- item) to Unicode
            // bullet characters (• item). Content_Formatter only recognises
            // dash/asterisk/plus list markers; • becomes an unstyled paragraph.
            $prompt = "Rewrite the following article section to Flesch-Kincaid grade 7. Target grade 6-8.\n\n"
                . "RULES:\n"
                . "1. Break any sentence over 18 words into two shorter sentences.\n"
                . "2. Replace multi-syllable words with simpler ones: 'use' not 'utilize', 'help' not 'facilitate', 'show' not 'demonstrate', 'most' not 'the majority of', 'about' not 'regarding', 'start' not 'commence'.\n"
                . "3. Write to ONE reader using 'you' / 'your'. Not 'pet owners' or 'readers'.\n"
                . "4. Active voice only.\n"
                . "5. PRESERVE EVERY FACT: names, numbers, percentages, years, citation URLs, expert quotes, organization names, bullet lists, tables.\n"
                . "6. PRESERVE EVERY MARKDOWN LINK [text](url) exactly as written. Do not invent new URLs.\n"
                . "7. PRESERVE the H2 heading line exactly as provided.\n"
                . "8. Keep roughly the same word count (±10%). Don't pad. Don't summarize.\n"
                . "9. Keep structural elements EXACTLY in markdown format: `- item` lists stay as `- item`, tables stay as `| col |` tables, blockquotes stay as `> text`.\n"
                . "10. NEVER convert list markers to bullet characters (•, ●, ◦). Use ONLY `- ` (dash space) for unordered lists and `1. ` for ordered lists.\n"
                . "11. NEVER convert markdown to HTML. Output pure markdown only — no <ul>, <li>, <table>, <p> tags.\n\n"
                . "EXAMPLES — WRITE LIKE THIS:\n"
                . "  ✅ \"Raw feeding works for many dogs. Start small. Mix one spoonful into the usual food for three days.\"\n"
                . "  ✅ \"Most vets agree that gradual change is safer. Watch your dog's stool. Firm means good.\"\n\n"
                . "NOT LIKE THIS:\n"
                . "  ❌ \"The implementation of a raw feeding protocol necessitates a gradual transition phase, during which pet owners must carefully monitor gastrointestinal responses.\"\n\n"
                . "SECTION TO REWRITE:\n\n"
                . $section['heading'] . "\n"
                . $section['body'] . "\n\n"
                . "Output ONLY the rewritten section (heading + body). No explanation, no commentary. Start with the heading line.";

            $result = AI_Provider_Manager::send_request(
                $provider['provider_id'],
                $prompt,
                'You are a readability editor. Rewrite text to Flesch-Kincaid grade 7 while preserving every fact, citation, and structural element.',
                [ 'max_tokens' => 2500, 'temperature' => 0.4 ]
            );

            if ( $result['success'] && ! empty( $result['content'] ) ) {
                $new_content = trim( $result['content'] );

                // v1.5.70 — post-processing: fix AI list corruption.
                // Models frequently convert `- item` to `• item` or `● item`
                // despite explicit prompt rules. Convert back to markdown.
                $new_content = preg_replace( '/^[•●◦▪▸►]\s*/m', '- ', $new_content );
                // Also strip any HTML tags the AI may have introduced
                $new_content = preg_replace( '/<\/?(ul|ol|li|p|br|div)[^>]*>/i', '', $new_content );

                // Safety: new content must still contain the heading
                if ( str_contains( $new_content, trim( $section['heading'] ) ) ) {
                    // Split the rewritten output back into heading + body
                    $heading_pos = strpos( $new_content, trim( $section['heading'] ) );
                    $rest = substr( $new_content, $heading_pos + strlen( trim( $section['heading'] ) ) );
                    $sections[ $idx ]['body'] = "\n" . ltrim( $rest ) . "\n\n";
                    $rewritten_count++;
                    $rewritten_sections[] = trim( $section['heading'] );
                }
            }
        }

        if ( $rewritten_count === 0 ) {
            return [
                'success' => false,
                'error'   => 'No sections needed simplification (all already at grade ≤8) or AI rewrite failed.',
            ];
        }

        // Rebuild the markdown
        $new_markdown = $preamble;
        foreach ( $sections as $s ) {
            $new_markdown .= $s['heading'] . $s['body'];
        }

        // v1.5.67 — measure AFTER rewrite to show the actual grade delta
        // in the success message. User reported "the score does not change"
        // because the old message ("Simplified N sections to grade 7") was
        // generic and didn't show proof of improvement.
        $grade_after = self::calc_flesch_kincaid_grade( $new_markdown );
        $delta_msg = sprintf(
            'Simplified %d section%s: Grade %s → %s',
            $rewritten_count,
            $rewritten_count > 1 ? 's' : '',
            number_format( $grade_before, 1 ),
            number_format( $grade_after, 1 )
        );

        return [
            'success' => true,
            'content' => $new_markdown,
            'added'   => $delta_msg,
            'type'    => 'readability',
            'rewritten_sections' => $rewritten_sections,
            'grade_before' => $grade_before,
            'grade_after'  => $grade_after,
        ];
    }

    /**
     * v1.5.67 — AI-powered keyword density optimizer. Converts the former
     * flag-mode Check Keyword Placement button into an inject-mode auto-fix.
     *
     * User reported: "when i click check keyword placement it gives this
     * results, im not sure what it does to the article if not nothing do
     * you edit this manually?" — the old flag mode was confusing. New
     * behavior: rewrite mentions using an AI pass. Replaces 30-40% of
     * exact-phrase keyword occurrences with pronouns, variations, or
     * synonyms. Target: drop density from >2% → 0.8-1.2%.
     */
    public static function optimize_keyword_placement( string $markdown, string $keyword, int $depth = 0 ): array {
        $keyword = trim( (string) $keyword );
        if ( $keyword === '' ) {
            return [ 'success' => false, 'error' => 'No focus keyword configured.' ];
        }

        // Measure current density for the success message
        $lower_md = strtolower( wp_strip_all_tags( $markdown ) );
        $word_count = max( 1, str_word_count( $lower_md ) );
        $kw_count_before = substr_count( $lower_md, strtolower( $keyword ) );
        $kw_word_count = max( 1, str_word_count( $keyword ) );
        $density_before = round( ( $kw_count_before * $kw_word_count / $word_count ) * 100, 2 );

        // Only run if density is actually too high
        if ( $density_before <= 1.5 ) {
            return [
                'success' => false,
                'error'   => 'Keyword density is already ' . $density_before . '% (within the 0.5-1.5% target). No rewrite needed.',
            ];
        }

        $provider = AI_Provider_Manager::get_active_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No AI provider configured.' ];
        }

        // Target drop: from current density down to ~1.0%. Roughly 30-50% of
        // exact-phrase mentions need to become variations.
        $target_count = max( 1, (int) round( 1.0 * $word_count / ( 100 * $kw_word_count ) ) );
        $mentions_to_rewrite = max( 2, $kw_count_before - $target_count );

        // v1.5.70 — much more explicit prompt. Previous version said "rewrite
        // about N mentions" and the AI only did half. New version says "the
        // output MUST contain at most N exact mentions" which gives the AI
        // a concrete, checkable target. Also lists WHICH mentions to keep.
        $keep_in_intro = 1;
        $keep_in_h2s = min( 2, max( 0, $target_count - $keep_in_intro ) );
        $max_total = $target_count;

        $prompt = "Rewrite the following article to DRASTICALLY reduce keyword density.\n\n"
            . "FOCUS KEYWORD: \"{$keyword}\"\n"
            . "CURRENT: {$kw_count_before} exact-phrase mentions ({$density_before}% density)\n"
            . "TARGET: At most {$max_total} exact-phrase mentions (~1.0% density)\n"
            . "YOU MUST REMOVE: at least {$mentions_to_rewrite} of the {$kw_count_before} mentions\n\n"
            . "WHICH MENTIONS TO KEEP (exact phrase \"{$keyword}\"):\n"
            . "- {$keep_in_intro} mention in the first paragraph (SEO plugins check this)\n"
            . "- {$keep_in_h2s} mention(s) in H2 headings\n"
            . "- ALL OTHER mentions must be replaced with variations, pronouns, or natural rewording\n\n"
            . "REPLACEMENT STRATEGIES:\n"
            . "- Pronouns: \"this brand\", \"the product\", \"it\", \"these formulas\"\n"
            . "- Shortened: drop one word (\"the dog food\", \"the brand\")\n"
            . "- Natural variants: rearrange words (\"dog food from Pure Life\")\n"
            . "- Category noun: \"the kibble\", \"the formula\", \"this option\"\n\n"
            . "RULES:\n"
            . "1. PRESERVE the overall structure: H1, H2, H3 headings, paragraph breaks, bullet lists (as `- item`), tables, markdown links, image syntax.\n"
            . "2. PRESERVE every fact, number, percentage, year, citation URL, expert quote.\n"
            . "3. PRESERVE every markdown link `[text](url)` exactly.\n"
            . "4. PRESERVE the Key Takeaways, FAQ, References sections AS-IS (do NOT change their content).\n"
            . "5. Do NOT add or delete sentences. Only rewrite the keyword phrase within existing sentences.\n"
            . "6. Keep the same word count (±5%).\n"
            . "7. NEVER use bullet characters (•). Use `- ` (dash space) for list items.\n\n"
            . "VERIFICATION: Count the exact phrase \"{$keyword}\" in your output. If it appears more than {$max_total} times, you have not removed enough. Go back and replace more.\n\n"
            . "ARTICLE:\n\n"
            . $markdown . "\n\n"
            . "Output the full rewritten article in Markdown. No explanation, no commentary.";

        $result = AI_Provider_Manager::send_request(
            $provider['provider_id'],
            $prompt,
            'You are an SEO editor. Rewrite articles to reduce keyword stuffing while preserving every fact, citation, structural element, and markdown link exactly.',
            [ 'max_tokens' => 6000, 'temperature' => 0.3 ]
        );

        if ( ! $result['success'] || empty( $result['content'] ) ) {
            return [ 'success' => false, 'error' => 'AI rewrite failed: ' . ( $result['error'] ?? 'no content returned' ) ];
        }

        $new_markdown = trim( $result['content'] );

        // Safety: the new content must contain roughly the same number of H2
        // headings as the original. If it dropped more than 20% of H2s, the
        // AI botched the rewrite — don't apply.
        $h2_before = preg_match_all( '/^##\s/m', $markdown, $m1 );
        $h2_after  = preg_match_all( '/^##\s/m', $new_markdown, $m2 );
        if ( $h2_before > 0 && $h2_after < floor( $h2_before * 0.8 ) ) {
            return [
                'success' => false,
                'error'   => 'AI rewrite returned structurally incomplete output (' . $h2_after . ' of ' . $h2_before . ' H2 headings preserved). Rewrite rejected for safety.',
            ];
        }

        // v1.5.70 — post-processing: fix any bullet corruption from the AI
        $new_markdown = preg_replace( '/^[•●◦▪▸►]\s*/m', '- ', $new_markdown );

        // Re-measure density after rewrite
        $lower_new = strtolower( wp_strip_all_tags( $new_markdown ) );
        $new_word_count = max( 1, str_word_count( $lower_new ) );
        $kw_count_after = substr_count( $lower_new, strtolower( $keyword ) );
        $density_after = round( ( $kw_count_after * $kw_word_count / $new_word_count ) * 100, 2 );

        // v1.5.71 — auto-retry with depth guard (max 2 passes total).
        // First pass often only gets halfway (8% → 5%) because the AI is
        // conservative. Second pass with partially-reduced text finishes the
        // job. Depth guard prevents infinite recursion.
        if ( $density_after > 1.5 && $depth < 1 ) {
            $retry = self::optimize_keyword_placement( $new_markdown, $keyword, $depth + 1 );
            if ( $retry['success'] ) {
                $retry_density = $retry['density_after'] ?? $density_after;
                $passes = $depth + 2; // depth 0 = pass 1, retry = pass 2
                $retry['added'] = sprintf(
                    'Keyword density %s%% → %s%% → %s%% (%d passes)',
                    $density_before,
                    $density_after,
                    $retry_density,
                    $passes
                );
                if ( $retry_density > 1.5 ) {
                    $retry['added'] .= '. ⚠️ Still above 1.5% — manual edits needed.';
                }
                $retry['density_before'] = $density_before;
                return $retry;
            }
        }

        $still_high = $density_after > 1.5;
        $added_msg = sprintf(
            'Keyword density %s%% → %s%% (rewrote %d mentions as variations)',
            $density_before,
            $density_after,
            max( 0, $kw_count_before - $kw_count_after )
        );
        if ( $still_high ) {
            $added_msg .= sprintf(
                '. ⚠️ Still above 1.5%% — click again or manually replace %d more mentions.',
                max( 1, $kw_count_after - $target_count )
            );
        }

        return [
            'success' => true,
            'content' => $new_markdown,
            'added'   => $added_msg,
            'type'    => 'keyword',
            'density_before' => $density_before,
            'density_after'  => $density_after,
        ];
    }

    /**
     * v1.5.63 — Fast Flesch-Kincaid grade calculation for a text chunk.
     * Mirrors GEO_Analyzer's formula: 0.39 × (words/sentences) + 11.8 × (syllables/words) - 15.59
     */
    private static function calc_flesch_kincaid_grade( string $text ): float {
        // Strip markdown that isn't prose
        $text = preg_replace( '/^[#>|]+/m', '', $text );
        $text = preg_replace( '/^[-*+]\s/m', '', $text );
        $text = preg_replace( '/!\[[^\]]*\]\([^)]*\)/', '', $text );
        $text = preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $text );
        $text = trim( $text );

        if ( $text === '' ) return 0.0;

        $sentences = preg_split( '/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
        $sentence_count = max( 1, count( $sentences ) );
        $words = str_word_count( $text );
        if ( $words === 0 ) return 0.0;

        // Syllable count: approximate by vowel groups
        $syllables = 0;
        foreach ( preg_split( '/\s+/', strtolower( $text ) ) as $word ) {
            $word = preg_replace( '/[^a-z]/', '', $word );
            if ( strlen( $word ) === 0 ) continue;
            $count = max( 1, preg_match_all( '/[aeiouy]+/', $word ) );
            if ( str_ends_with( $word, 'e' ) && $count > 1 ) $count--;
            $syllables += $count;
        }

        $grade = 0.39 * ( $words / $sentence_count ) + 11.8 * ( $syllables / $words ) - 15.59;
        return max( 0, round( $grade, 1 ) );
    }

    // ================================================================
    // v1.5.78 — OPTIMIZE ALL (single Sonar call + sequential fixes)
    // ================================================================

    /**
     * Make a single Perplexity Sonar call to get all research data:
     * citations (URLs), quotes, statistics, and table data.
     *
     * Returns structured array or null on failure.
     */
    private static function call_sonar_research( string $keyword ): ?array {
        $openrouter_key = AI_Provider_Manager::get_provider_key( 'openrouter' );
        if ( empty( $openrouter_key ) ) {
            // Try legacy settings field
            $settings = get_option( 'seobetter_settings', [] );
            $openrouter_key = $settings['openrouter_api_key'] ?? '';
        }
        if ( empty( $openrouter_key ) ) {
            return null; // No key — caller falls back to existing methods
        }

        $settings = get_option( 'seobetter_settings', [] );
        $model = $settings['sonar_model'] ?? 'perplexity/sonar';

        $prompt = "For an article about \"{$keyword}\", find REAL current data from the web.\n\n"
            . "Return a JSON object with exactly these 4 keys:\n"
            . "{\n"
            . "  \"citations\": [\n"
            . "    {\"url\": \"https://real-article-url\", \"title\": \"Actual Page Title\", \"source_name\": \"domain.com\"},\n"
            . "    ... (5-8 entries. REAL URLs to article pages, NOT homepages)\n"
            . "  ],\n"
            . "  \"quotes\": [\n"
            . "    {\"text\": \"The actual quote or finding\", \"source\": \"Person or Organization Name\", \"url\": \"https://source-page\"},\n"
            . "    ... (2-3 entries. Real statements from real sources)\n"
            . "  ],\n"
            . "  \"statistics\": [\n"
            . "    \"65% of dog owners prefer grain-free options (Pet Food Industry Association, 2025)\",\n"
            . "    ... (3-5 entries. Real numbers with real source names and years)\n"
            . "  ],\n"
            . "  \"table_data\": {\n"
            . "    \"columns\": [\"Name\", \"Key Feature\", \"Best For\"],\n"
            . "    \"rows\": [\n"
            . "      [\"Real Product 1\", \"Real feature\", \"Real use case\"],\n"
            . "      ... (3-5 rows with REAL data. Only include columns where you have real data for every row)\n"
            . "    ]\n"
            . "  }\n"
            . "}\n\n"
            . "CRITICAL RULES:\n"
            . "- Every URL must be a REAL, currently live web page. NEVER invent URLs.\n"
            . "- Every statistic must include a REAL source name and year.\n"
            . "- Table data must contain REAL product/item information, not invented specs.\n"
            . "- Only include a price column if you found actual prices.\n"
            . "- Return ONLY the JSON object. No markdown fences. No explanation.";

        $response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $openrouter_key,
            ],
            'body'    => wp_json_encode( [
                'model'       => $model,
                'messages'    => [
                    [ 'role' => 'system', 'content' => 'You are a factual research assistant. Return structured JSON with real, verifiable web data. Never fabricate URLs, statistics, or quotes.' ],
                    [ 'role' => 'user', 'content' => $prompt ],
                ],
                'max_tokens'  => 3000,
                'temperature' => 0.1,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $content = $data['choices'][0]['message']['content'] ?? '';
        if ( empty( $content ) ) {
            return null;
        }

        // Strip markdown code fences if present
        $content = preg_replace( '/^```(?:json)?\s*\n?/m', '', $content );
        $content = preg_replace( '/\n?```\s*$/m', '', $content );
        $content = trim( $content );

        $parsed = json_decode( $content, true );
        if ( ! is_array( $parsed ) ) {
            return null;
        }

        // Validate structure — each key should be present
        return [
            'citations'  => is_array( $parsed['citations'] ?? null ) ? $parsed['citations'] : [],
            'quotes'     => is_array( $parsed['quotes'] ?? null ) ? $parsed['quotes'] : [],
            'statistics' => is_array( $parsed['statistics'] ?? null ) ? $parsed['statistics'] : [],
            'table_data' => is_array( $parsed['table_data'] ?? null ) ? $parsed['table_data'] : [],
        ];
    }

    /**
     * v1.5.78 — Run ALL inject fixes in one pass.
     *
     * 1. Makes ONE Perplexity Sonar call for research data
     * 2. Injects citations, quotes, statistics, table from Sonar data
     * 3. Runs readability simplification (AI rewrite)
     * 4. Runs keyword density optimization (AI rewrite)
     * 5. Returns the fully optimized markdown
     *
     * Each step checks the score threshold and skips if already passing.
     * Each step has a fallback if Sonar data is missing for that category.
     * A step failure does NOT abort the pipeline — remaining steps still run.
     */
    public static function optimize_all(
        string $markdown,
        string $keyword,
        array  $existing_pool = [],
        array  $scores = []
    ): array {
        @set_time_limit( 120 );

        $steps_run     = [];
        $steps_skipped = [];
        $sonar_used    = false;

        // ---- Step 0: Sonar research call ----
        $sonar = self::call_sonar_research( $keyword );
        if ( $sonar !== null ) {
            $sonar_used = true;
        }

        // ---- Step 1: Citations ----
        $cit_score = $scores['citations']['score'] ?? 0;
        if ( $cit_score < 80 ) {
            try {
                // Merge Sonar URLs into the existing pool
                $merged_pool = $existing_pool;
                if ( $sonar && ! empty( $sonar['citations'] ) ) {
                    foreach ( $sonar['citations'] as $sc ) {
                        if ( empty( $sc['url'] ) ) continue;
                        // Run hygiene check before adding to pool
                        if ( ! Citation_Pool::passes_hygiene_public( $sc['url'] ) ) continue;
                        $merged_pool[] = [
                            'url'         => $sc['url'],
                            'title'       => $sc['title'] ?? '',
                            'source_name' => $sc['source_name'] ?? wp_parse_url( $sc['url'], PHP_URL_HOST ),
                            'verified_at' => time(),
                        ];
                    }
                }
                $result = self::inject_citations( $markdown, $keyword, $merged_pool );
                if ( $result['success'] ) {
                    $markdown = $result['content'];
                    $steps_run[] = 'citations';
                } else {
                    $steps_skipped[] = 'citations: ' . ( $result['error'] ?? 'failed' );
                }
            } catch ( \Throwable $e ) {
                $steps_skipped[] = 'citations: ' . $e->getMessage();
            }
        } else {
            $steps_skipped[] = 'citations: score already ' . $cit_score;
        }

        // ---- Step 2: Expert Quotes ----
        $quote_score = $scores['expert_quotes']['score'] ?? 0;
        if ( $quote_score < 100 ) {
            try {
                if ( $sonar && ! empty( $sonar['quotes'] ) ) {
                    // Use Sonar quotes directly — real sourced quotes
                    $quotes = [];
                    foreach ( $sonar['quotes'] as $q ) {
                        if ( empty( $q['text'] ) ) continue;
                        $text = trim( $q['text'] );
                        if ( strlen( $text ) > 200 ) $text = substr( $text, 0, 197 ) . '...';
                        $source = $q['source'] ?? 'Industry source';
                        $url = $q['url'] ?? '';
                        $formatted = "\"{$text}\" — {$source}";
                        if ( $url ) $formatted .= " ([source]({$url}))";
                        $quotes[] = $formatted;
                        if ( count( $quotes ) >= 3 ) break;
                    }
                    if ( ! empty( $quotes ) ) {
                        $injected = $markdown;
                        $qi = 0;
                        $injected = preg_replace_callback(
                            '/(## (?!Key Takeaway|FAQ|Frequently|Reference)[^\n]+\n(?:[^\n]*\n){2,3})/',
                            function( $m ) use ( &$qi, $quotes ) {
                                if ( $qi >= count( $quotes ) ) return $m[0];
                                $q = $quotes[ $qi++ ];
                                return $m[0] . "\n> " . $q . "\n\n";
                            },
                            $injected
                        );
                        $markdown = $injected;
                        $steps_run[] = 'quotes';
                    } else {
                        $steps_skipped[] = 'quotes: Sonar returned empty quotes';
                    }
                } else {
                    // Fallback to existing method
                    $result = self::inject_quotes( $markdown, $keyword );
                    if ( $result['success'] ) {
                        $markdown = $result['content'];
                        $steps_run[] = 'quotes';
                    } else {
                        $steps_skipped[] = 'quotes: ' . ( $result['error'] ?? 'failed' );
                    }
                }
            } catch ( \Throwable $e ) {
                $steps_skipped[] = 'quotes: ' . $e->getMessage();
            }
        } else {
            $steps_skipped[] = 'quotes: score already ' . $quote_score;
        }

        // ---- Step 3: Statistics ----
        $stat_score = $scores['factual_density']['score'] ?? 0;
        if ( $stat_score < 70 ) {
            try {
                if ( $sonar && ! empty( $sonar['statistics'] ) ) {
                    $stat_block = "\n\n**Key Statistics:**\n";
                    foreach ( array_slice( $sonar['statistics'], 0, 4 ) as $stat ) {
                        $stat_block .= "- " . trim( (string) $stat ) . "\n";
                    }
                    $stat_block .= "\n";
                    $injected = preg_replace(
                        '/(## (?!Key Takeaway|FAQ|Frequently|Reference)[^\n]+\n(?:[^\n]+\n){1,2})/',
                        '$1' . $stat_block,
                        $markdown,
                        1
                    );
                    if ( $injected !== $markdown ) {
                        $markdown = $injected;
                        $steps_run[] = 'statistics';
                    } else {
                        $steps_skipped[] = 'statistics: could not find insertion point';
                    }
                } else {
                    $result = self::inject_statistics( $markdown, $keyword );
                    if ( $result['success'] ) {
                        $markdown = $result['content'];
                        $steps_run[] = 'statistics';
                    } else {
                        $steps_skipped[] = 'statistics: ' . ( $result['error'] ?? 'failed' );
                    }
                }
            } catch ( \Throwable $e ) {
                $steps_skipped[] = 'statistics: ' . $e->getMessage();
            }
        } else {
            $steps_skipped[] = 'statistics: score already ' . $stat_score;
        }

        // ---- Step 4: Comparison Table ----
        $table_score = $scores['tables']['score'] ?? 0;
        if ( $table_score < 50 ) {
            try {
                if ( $sonar && ! empty( $sonar['table_data']['columns'] ) && ! empty( $sonar['table_data']['rows'] ) ) {
                    // Build markdown table from Sonar data
                    $cols = $sonar['table_data']['columns'];
                    $rows = $sonar['table_data']['rows'];
                    $table = '| ' . implode( ' | ', $cols ) . " |\n";
                    $table .= '|' . str_repeat( '---|', count( $cols ) ) . "\n";
                    foreach ( array_slice( $rows, 0, 6 ) as $row ) {
                        // Pad row to match column count
                        while ( count( $row ) < count( $cols ) ) $row[] = '';
                        $table .= '| ' . implode( ' | ', array_slice( $row, 0, count( $cols ) ) ) . " |\n";
                    }
                    // Insert before FAQ/References
                    if ( preg_match( '/(\n## (?:FAQ|Frequently|Reference)[^\n]*\n)/i', $markdown, $m, PREG_OFFSET_MATCH ) ) {
                        $markdown = substr( $markdown, 0, $m[1][1] ) . "\n" . $table . "\n" . substr( $markdown, $m[1][1] );
                        $steps_run[] = 'table';
                    } else {
                        // Fallback: insert after first content H2
                        $injected = preg_replace(
                            '/(## (?!Key Takeaway|FAQ|Frequently|Reference)[^\n]+\n(?:[^\n]+\n){1,3})/',
                            '$1' . "\n" . $table . "\n\n",
                            $markdown,
                            1
                        );
                        if ( $injected !== $markdown ) {
                            $markdown = $injected;
                            $steps_run[] = 'table';
                        } else {
                            $steps_skipped[] = 'table: no insertion point';
                        }
                    }
                } else {
                    // Fallback to AI-generated table
                    $result = self::inject_table( $markdown, $keyword );
                    if ( $result['success'] ) {
                        $markdown = $result['content'];
                        $steps_run[] = 'table';
                    } else {
                        $steps_skipped[] = 'table: ' . ( $result['error'] ?? 'failed' );
                    }
                }
            } catch ( \Throwable $e ) {
                $steps_skipped[] = 'table: ' . $e->getMessage();
            }
        } else {
            $steps_skipped[] = 'table: score already ' . $table_score;
        }

        // ---- Step 5: Simplify Readability (AI rewrite) ----
        $read_score = $scores['readability']['score'] ?? 0;
        if ( $read_score < 70 ) {
            try {
                $result = self::simplify_readability( $markdown );
                if ( $result['success'] ) {
                    $markdown = $result['content'];
                    $steps_run[] = 'readability';
                } else {
                    $steps_skipped[] = 'readability: ' . ( $result['error'] ?? 'not needed' );
                }
            } catch ( \Throwable $e ) {
                $steps_skipped[] = 'readability: ' . $e->getMessage();
            }
        } else {
            $steps_skipped[] = 'readability: score already ' . $read_score;
        }

        // ---- Step 6: Keyword Density (AI rewrite — LAST) ----
        // v1.5.78b — Re-measure density AFTER readability (which often reduces
        // it as a side effect). Only run the density optimizer if still needed.
        // Also pass depth=1 to PREVENT auto-retry inside optimize_all —
        // the auto-retry adds another 10-20s AI call which pushes the total
        // request past PHP timeout on WP Engine (~60s).
        $lower_md = strtolower( wp_strip_all_tags( $markdown ) );
        $wc = max( 1, str_word_count( $lower_md ) );
        $kw_lower = strtolower( $keyword );
        $kw_wc = max( 1, str_word_count( $keyword ) );
        $kw_hits = substr_count( $lower_md, $kw_lower );
        $current_density = round( ( $kw_hits * $kw_wc / $wc ) * 100, 2 );

        if ( $current_density > 1.5 ) {
            try {
                // depth=1 means: run ONE pass only, no auto-retry
                $result = self::optimize_keyword_placement( $markdown, $keyword, 1 );
                if ( $result['success'] ) {
                    $markdown = $result['content'];
                    $steps_run[] = 'keyword (' . $current_density . '% → ' . ( $result['density_after'] ?? '?' ) . '%)';
                } else {
                    $steps_skipped[] = 'keyword: ' . ( $result['error'] ?? 'not needed' );
                }
            } catch ( \Throwable $e ) {
                $steps_skipped[] = 'keyword: ' . $e->getMessage();
            }
        } else {
            $steps_skipped[] = 'keyword: density already ' . $current_density . '%';
        }

        if ( empty( $steps_run ) ) {
            return [
                'success'       => true,
                'content'       => $markdown,
                'steps_run'     => [],
                'steps_skipped' => $steps_skipped,
                'sonar_used'    => $sonar_used,
                'added'         => 'All scores already passing — no optimization needed.',
            ];
        }

        return [
            'success'       => true,
            'content'       => $markdown,
            'steps_run'     => $steps_run,
            'steps_skipped' => $steps_skipped,
            'sonar_used'    => $sonar_used,
            'added'         => count( $steps_run ) . ' fixes applied: ' . implode( ', ', $steps_run )
                . ( $sonar_used ? ' (powered by Perplexity Sonar)' : '' ),
        ];
    }
}
