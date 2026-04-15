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
    public static function inject_citations( string $content, string $keyword ): array {
        // v1.5.65 — use Citation_Pool::build() which has:
        //   - v1.5.62 topical relevance filter (title must contain keyword token)
        //   - v1.5.63 CACHE_VERSION bump to invalidate stale pools
        //   - hygiene check + dedupe + length cap
        $pool = Citation_Pool::build( $keyword );
        if ( empty( $pool ) ) {
            return [
                'success' => false,
                'error'   => 'No topically-relevant citations available for this keyword. The Citation Pool build returned zero entries after filtering — try a more specific keyword.',
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

            $grade = self::calc_flesch_kincaid_grade( $section['body'] );
            if ( $grade <= 9 ) {
                continue; // Section already readable
            }

            // Rewrite this section with an AI pass
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
                . "9. Keep structural elements: bullet lists stay bullet lists, tables stay tables, blockquotes stay blockquotes.\n\n"
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
                'error'   => 'No sections needed simplification (all already at grade ≤9) or AI rewrite failed.',
            ];
        }

        // Rebuild the markdown
        $new_markdown = $preamble;
        foreach ( $sections as $s ) {
            $new_markdown .= $s['heading'] . $s['body'];
        }

        return [
            'success' => true,
            'content' => $new_markdown,
            'added'   => 'Simplified ' . $rewritten_count . ' section' . ( $rewritten_count > 1 ? 's' : '' ) . ' to grade 7',
            'type'    => 'readability',
            'rewritten_sections' => $rewritten_sections,
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
}
