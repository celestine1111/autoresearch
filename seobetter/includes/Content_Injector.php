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
    public static function inject_citations( string $content, string $keyword, array $existing_pool = [], ?array $sonar_data = null ): array {
        // v1.5.80 — Sonar-first citation sourcing. Perplexity Sonar does
        // a live web search and returns real article URLs with titles.
        // This replaces the fragile DDG scraping + topical filter pipeline.
        $pool = $existing_pool;

        // v1.5.81 — prefer pre-fetched Sonar data from Vercel backend
        $sonar = $sonar_data ?? self::call_sonar_research( $keyword );
        if ( $sonar && ! empty( $sonar['citations'] ) ) {
            foreach ( $sonar['citations'] as $sc ) {
                if ( empty( $sc['url'] ) ) continue;
                if ( ! Citation_Pool::passes_hygiene_public( $sc['url'] ) ) continue;
                $pool[] = [
                    'url'         => $sc['url'],
                    'title'       => $sc['title'] ?? '',
                    'source_name' => $sc['source_name'] ?? wp_parse_url( $sc['url'], PHP_URL_HOST ),
                    'verified_at' => time(),
                ];
            }
        }

        // Fallback: try existing pool or fresh Citation_Pool build
        if ( empty( $pool ) ) {
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

        // v1.5.114 — Named inline source links (Option C). Instead of broken
        // [N](#ref-N) fragment anchors, inject the source name as a clickable
        // link to the actual URL after sentences with factual claims.
        // Example: "72% used memory foam ([Canine Arthritis Resources](url))."
        // This is what Perplexity, Wikipedia, and modern journalism use.
        $max_inline = min( $ref_count, 6 );
        $injected = self::inject_named_source_links( $injected, $pool, $max_inline );

        return [
            'success' => true,
            'content' => $injected,
            'added'   => $ref_count . ' citations added with inline anchor links',
            'type'    => 'citations',
        ];
    }

    /**
     * v1.5.114 — Named inline source links. Finds sentences with factual
     * claims (stats, percentages, years) and appends the source name as a
     * clickable link to the actual URL from the citation pool.
     *
     * Example: "72% used memory foam ([Canine Arthritis Resources](url))."
     *
     * Uses a round-robin assignment: each citation pool entry gets used
     * once before any is reused. Skips Key Takeaways, FAQ, References.
     */
    // v1.5.154 — Public alias for generation-time citation injection
    public static function inject_named_source_links_public( string $markdown, array $pool, int $max_links ): string {
        return self::inject_named_source_links( $markdown, $pool, $max_links );
    }

    private static function inject_named_source_links( string $markdown, array $pool, int $max_links ): string {
        if ( $max_links <= 0 || empty( $pool ) ) return $markdown;

        // Split at References — only inject in body content
        $parts = preg_split( '/(\n##\s*References\s*\n)/i', $markdown, 2, PREG_SPLIT_DELIM_CAPTURE );
        if ( count( $parts ) < 3 ) return $markdown;
        $body = $parts[0];
        $refs_separator = $parts[1];
        $refs_content = $parts[2];

        // Find candidate sentences with factual claims
        $candidates = [];
        if ( preg_match_all( '/([^.\n!?]{20,200}(?:\d{1,3}\s*%|\d+[\.,]\d+\s*%|\$\d[\d,]*|\b(?:19|20)\d{2}\b|[A-Z][a-z]+\s+[A-Z][a-z]+)[^.\n!?]{0,100}[.!?])/', $body, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[0] as $m ) {
                $sentence = $m[0];
                $offset = $m[1];
                // Skip if already has a markdown link
                if ( strpos( $sentence, '](http' ) !== false ) continue;
                // Skip headings, list items, tables
                $line_start = strrpos( substr( $body, 0, $offset ), "\n" );
                $line_start = $line_start === false ? 0 : $line_start + 1;
                $line_prefix = substr( $body, $line_start, 5 );
                if ( str_starts_with( ltrim( $line_prefix ), '#' ) ) continue;
                if ( str_starts_with( ltrim( $line_prefix ), '|' ) ) continue;
                if ( preg_match( '/^[-*+]\s/', ltrim( $line_prefix ) ) ) continue;
                // Skip Key Takeaways, FAQ, References sections
                $preceding = substr( $body, 0, $offset );
                if ( preg_match_all( '/\n##\s+([^\n]+)/', $preceding, $h2_matches ) ) {
                    $last_h2 = end( $h2_matches[1] );
                    // v1.5.120 — Also skip recipe card sections (ingredients, instructions, storage)
                    if ( preg_match( '/key\s*takeaway|faq|frequently|reference|pros|cons|recipe\s*\d|ingredient|instruction|direction|storage|method/i', $last_h2 ) ) continue;
                }
                $candidates[] = [ 'text' => $sentence, 'offset' => $offset ];
                if ( count( $candidates ) >= $max_links ) break;
            }
        }

        if ( empty( $candidates ) ) return $markdown;

        // Build source name from pool entries (round-robin)
        $pool_idx = 0;
        $pool_count = count( $pool );

        // Inject from END backward so offsets stay valid
        for ( $i = count( $candidates ) - 1; $i >= 0; $i-- ) {
            $c = $candidates[ $i ];
            $sentence = $c['text'];
            $end_offset = $c['offset'] + strlen( $sentence );

            // Pick a pool entry (round-robin)
            $entry = $pool[ $pool_idx % $pool_count ];
            $pool_idx++;

            $url = $entry['url'] ?? '';
            $source_name = $entry['source_name'] ?? '';
            if ( empty( $url ) ) continue;

            // Clean source name: remove www., capitalize
            if ( empty( $source_name ) ) {
                $source_name = preg_replace( '/^www\./', '', wp_parse_url( $url, PHP_URL_HOST ) ?? 'Source' );
            }
            $source_name = preg_replace( '/^www\./', '', $source_name );
            // Use title if available and short enough
            $title = $entry['title'] ?? '';
            if ( strlen( $title ) > 5 && strlen( $title ) <= 40 ) {
                $source_name = $title;
            }

            // Inject before final punctuation
            $last_char = substr( $sentence, -1 );
            if ( in_array( $last_char, [ '.', '!', '?' ], true ) ) {
                $inject_at = $end_offset - 1;
                $link = ' ([' . $source_name . '](' . $url . '))';
                $body = substr( $body, 0, $inject_at ) . $link . substr( $body, $inject_at );
            }
        }

        return $body . $refs_separator . $refs_content;
    }

    /**
     * v1.5.65 — LEGACY: Walk the body text and append clickable [N] anchors.
     * Replaced by inject_named_source_links() in v1.5.114.
     * Kept for reference only — no longer called.
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
    public static function inject_quotes( string $content, string $keyword, ?array $sonar_data = null, string $domain = '', string $country = '' ): array {
        // v1.5.96 — TAVILY DIRECT FROM PHP. Zero hallucination. No Vercel dependency.
        //
        // Calls Tavily Search API directly via wp_remote_post(). Gets real
        // search results with raw page content. Extracts real sentences
        // from real pages. Every quote has verified text + source URL.
        //
        // If Tavily key is not configured or returns 0 quotes → SKIP.
        // An article without quotes is better than one with fake quotes.
        //
        // Per SEO-GEO-AI-GUIDELINES.md §15: "Trust is most important signal"

        $quotes = [];

        // v1.5.113 — Shared filters for ALL quote sources.
        // Substantive filter (blocks marketing taglines), e-commerce filter (blocks product listings),
        // junk filter (blocks giveaways/cookies). These protect against bad quotes from any source.
        // v1.5.216.62.44 — added productivity / workplace / career / wellness
        // / business-strategy / finance / tech / education / news vocabulary so
        // articles outside the original pet-product domain (where this regex
        // first ships) can find substantive quotes. Pre-fix a "morning routine
        // for remote workers" article matched zero quote sentences because the
        // regex only knew pet-product / health vocabulary. Pre-fix users had
        // to pick a category whose vocabulary overlapped (Health/Veterinary)
        // even when topical fit was wrong.
        $substantive_re = '/\b(recommend|found|study|studies|research|important|risk|benefit|help|cause|prevent|improve|according|evidence|expert|veterinar|nutriti|health|safe|danger|effective|suggest|show|report|associat|linked|common|require|diet|ingredien|allerg|deficien|formul|diagnos|support|reduce|provide|design|feature|material|quality|comfort|protect|treat|condition|symptom|avoid|consider|choose|suitable|essential|option|compare|test|review|evaluat|measure|perform|assess|durabl|withstand|orthoped|joint|muscle|weight|pressure|temperature|waterproof|washable|productiv|focus|habit|wellness|wellbeing|workplace|burnout|stress|engagement|motivation|workflow|efficien|career|hire|employ|salary|wages|management|leadership|workforce|remote|hybrid|economi|market|invest|growth|revenue|profit|trend|consumer|user|customer|technolog|softwar|platform|algorithm|polic|regulat|govern|election|vote|reform|sustain|climate|emission|energy|innovat|teach|learn|skill|train|develop|cogniti|behav|wellbeing)\b/i';
        $ecommerce_re = '/[\$€£¥]\s*\d|regular\s*price|sale\s*price|add\s*to\s*cart|buy\s*now|free\s*shipping|in\s*stock|out\s*of\s*stock|shop\s*now|view\s*product|checkout|coupon|discount\s*code|promo\s*code/i';
        $junk_re = '/april fool|challenge|giveaway|prize|contest|no.*recall|not.*recall|cookie|privacy|subscribe/i';

        // Helper: validate a single quote against all filters
        $validate_quote = function( $q ) use ( $substantive_re, $ecommerce_re, $junk_re ) {
            if ( ! is_array( $q ) || empty( $q['text'] ) || empty( $q['url'] ) ) return null;
            $text = trim( $q['text'] );
            $url = trim( $q['url'] );
            $source = trim( $q['source'] ?? '' );
            if ( strlen( $text ) < 30 || strlen( $text ) > 300 ) return null;
            if ( ! preg_match( '#^https?://#', $url ) ) return null;
            if ( empty( $source ) ) $source = wp_parse_url( $url, PHP_URL_HOST ) ?? 'Source';
            if ( preg_match( $junk_re, $text ) ) return null;
            if ( preg_match( $ecommerce_re, $text ) ) return null;
            if ( ! preg_match( $substantive_re, $text ) ) return null;
            return [ 'text' => $text, 'url' => $url, 'source' => $source ];
        };

        // v1.5.216.62.13 — Sonar quotes now require authority-domain match.
        //
        // Background: v1.5.113b removed the authority filter from this branch
        // because it produced 0 quotes. v1.5.216.62.13 reinstates it because
        // user audit (2026-05-03) found the Sonar path was returning commercial
        // pet retailer URLs (solfeddogfood.com, primalpetfoods.com,
        // stevesrealfood.com, paleoridge.co.uk) as "expert quote sources" — not
        // hallucinations technically, but commercial brand attributions don't
        // meet the §15B Trust signal bar.
        //
        // The 0-quotes failure mode v1.5.113b warned about is now mitigated by
        // v1.5.216.62.12's authority-domain expansion (2-4× more domains per
        // category) AND by Source 2 (Tavily PHP-direct) which uses the same
        // authority filter as a fallback. If both return 0, we deliberately
        // emit no quote — better no quote than commercial-brand-as-authority.
        $authority_domains = self::get_authority_domains( $domain, $country );
        $is_authority_url = function ( $url ) use ( $authority_domains ) {
            $host = wp_parse_url( $url, PHP_URL_HOST ) ?? '';
            $host = preg_replace( '/^www\./', '', strtolower( $host ) );
            if ( $host === '' ) return false;
            foreach ( $authority_domains as $auth ) {
                $auth_clean = strtolower( trim( $auth ) );
                if ( $host === $auth_clean ) return true;
                if ( str_ends_with( $host, '.' . $auth_clean ) ) return true;
            }
            return false;
        };

        if ( ! empty( $sonar_data['quotes'] ) ) {
            foreach ( $sonar_data['quotes'] as $q ) {
                if ( ! is_array( $q ) || empty( $q['text'] ) || empty( $q['url'] ) ) continue;
                $text = trim( $q['text'] );
                $url = trim( $q['url'] );
                $source = trim( $q['source'] ?? '' );
                if ( strlen( $text ) < 30 || strlen( $text ) > 300 ) continue;
                if ( ! preg_match( '#^https?://#', $url ) ) continue;
                if ( empty( $source ) ) $source = wp_parse_url( $url, PHP_URL_HOST ) ?? 'Source';
                // Block: junk + e-commerce + non-authority URLs (v1.5.216.62.13).
                if ( preg_match( $junk_re, $text ) ) continue;
                if ( preg_match( $ecommerce_re, $text ) ) continue;
                if ( ! $is_authority_url( $url ) ) continue;
                $quotes[] = "\"{$text}\" — [{$source}]({$url})";
                if ( count( $quotes ) >= 3 ) break;
            }
        }

        // Source 2: Direct Tavily call from PHP (only if Source 1 found < 2 quotes)
        // v1.5.216.62.10 — track per-stage counts so the diagnostic can pinpoint
        // exactly which stage drops quotes (e.g. Tavily returns 8 raw, 6 fail
        // substantive_re, 2 fail e-commerce_re, 0 reach final $quotes array).
        $tavily_raw_count      = 0;
        $tavily_passed_count   = 0;
        $tavily_filter_rejects = 0;
        if ( count( $quotes ) < 2 ) {
            $tavily = self::tavily_search_and_extract( $keyword, $domain, $country );
            $tavily_raw_count = count( $tavily['quotes'] ?? [] );
            foreach ( ( $tavily['quotes'] ?? [] ) as $q ) {
                $valid = $validate_quote( $q );
                if ( ! $valid ) {
                    $tavily_filter_rejects++;
                    continue;
                }
                $tavily_passed_count++;
                $quotes[] = "\"{$valid['text']}\" — [{$valid['source']}]({$valid['url']})";
                if ( count( $quotes ) >= 3 ) break;
            }
        }

        if ( empty( $quotes ) ) {
            // v1.5.216.62.10 — richer error so the UI banner shows exactly which
            // stage failed instead of a generic "no verifiable quotes found".
            $reason = sprintf(
                'No verifiable quotes found. Tavily raw=%d, passed substantive/e-commerce filter=%d, rejected=%d. Sonar pool=%d.',
                $tavily_raw_count,
                $tavily_passed_count,
                $tavily_filter_rejects,
                isset( $sonar_data['quotes'] ) && is_array( $sonar_data['quotes'] ) ? count( $sonar_data['quotes'] ) : 0
            );
            return [
                'success' => false,
                'error'   => $reason,
                'tavily_raw_count'      => $tavily_raw_count,
                'tavily_passed_count'   => $tavily_passed_count,
                'tavily_filter_rejects' => $tavily_filter_rejects,
            ];
        }

        // Insert quotes after H2 headings (skip Key Takeaways and FAQ)
        $injected = $content;
        $quote_idx = 0;
        $injected = preg_replace_callback(
            '/(## (?!Key Takeaway|FAQ|Frequently|Reference|Recipe \d|Ingredient|Instruction|Direction|Storage|Method)[^\n]+\n(?:[^\n]*\n){2,3})/',
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
     * v1.5.80 — Sonar-first comparison table. Tries Perplexity Sonar for
     * real product data first. Falls back to AI generation if Sonar has
     * no table data or no OpenRouter key.
     */
    public static function inject_table( string $content, string $keyword, ?array $sonar_data = null ): array {
        // v1.5.81 — prefer pre-fetched Sonar data from Vercel backend
        $sonar = $sonar_data ?? self::call_sonar_research( $keyword );
        if ( $sonar && ! empty( $sonar['table_data']['columns'] ) && ! empty( $sonar['table_data']['rows'] ) ) {
            $cols = $sonar['table_data']['columns'];
            $rows = $sonar['table_data']['rows'];
            $table = '| ' . implode( ' | ', $cols ) . " |\n";
            $table .= '|' . str_repeat( '---|', count( $cols ) ) . "\n";
            foreach ( array_slice( $rows, 0, 6 ) as $row ) {
                while ( count( $row ) < count( $cols ) ) $row[] = '';
                $table .= '| ' . implode( ' | ', array_slice( $row, 0, count( $cols ) ) ) . " |\n";
            }

            // Insert the Sonar table
            $injected = $content;
            if ( preg_match( '/(\n## (?:FAQ|Frequently|Reference)[^\n]*\n)/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
                $injected = substr( $content, 0, $m[1][1] ) . "\n" . $table . "\n" . substr( $content, $m[1][1] );
            } else {
                $injected = preg_replace(
                    '/(## (?!Key Takeaway|FAQ|Frequently|Reference|Recipe \d|Ingredient|Instruction|Direction|Storage|Method)[^\n]+\n(?:[^\n]+\n){1,3})/',
                    '$1' . "\n" . $table . "\n\n",
                    $content,
                    1
                );
            }
            if ( $injected !== $content ) {
                return [
                    'success' => true,
                    'content' => $injected,
                    'added'   => 'Comparison table inserted with real product data',
                    'type'    => 'table',
                ];
            }
        }

        // Fallback: AI-generated table
        $provider = AI_Provider_Manager::get_active_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No AI provider configured and Sonar returned no table data.' ];
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
            '/(## (?!Key Takeaway|FAQ|Frequently|Reference|Recipe \d|Ingredient|Instruction|Direction|Storage|Method)[^\n]+\n(?:[^\n]+\n){1,3})/',
            '$1' . "\n" . $table . "\n\n",
            $content,
            1 // Only first match
        );

        // v1.5.69 — detect silent failure: if the regex didn't match any H2
        // with body text, $injected === $content (unchanged). Report error
        // instead of misleading "Comparison table inserted" success.
        if ( $injected === $content ) {
            // Fallback: insert before the FAQ or References section instead
            if ( preg_match( '/(\n## (?:FAQ|Frequently|Reference)[^\n]*\n)/i', $content, $faq_match, PREG_OFFSET_CAPTURE ) ) {
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
     *
     * v1.5.206d — Now language-aware. When $language is non-English, uses
     * Localized_Strings for the label ("最終更新日" in Japanese, "Последнее
     * обновление" in Russian, etc.) and Localized_Strings::month_year() for
     * the date portion (e.g. "2026年4月" for Japanese instead of "April 2026").
     * Also detects the localized label in the duplicate-check so repeated
     * runs don't double-prepend.
     */
    public static function inject_freshness( string $content, string $language = 'en' ): array {
        $label = Localized_Strings::get( 'last_updated', $language );
        $date  = Localized_Strings::month_year( $language );

        // Don't add if already present. Accept either the English pattern (for
        // backward-compat with existing content) OR the localized label itself.
        if ( preg_match( '/last\s*updated/i', $content ) ) {
            return [ 'success' => true, 'content' => $content, 'added' => 'Already present', 'type' => 'freshness' ];
        }
        if ( $language !== 'en' && $label && mb_stripos( $content, $label ) !== false ) {
            return [ 'success' => true, 'content' => $content, 'added' => 'Already present', 'type' => 'freshness' ];
        }

        $injected = "{$label}: {$date}\n\n" . $content;

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
            '/(## (?!Key Takeaway|FAQ|Frequently|Reference|Recipe \d|Ingredient|Instruction|Direction|Storage|Method)[^\n]+\n(?:[^\n]+\n){1,2})/',
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
     * v1.5.80 — Inject-mode section opener fix. Rewrites short opening
     * paragraphs (< 30 words) to 40-60 words using AI. Per SEO-GEO-AI-
     * GUIDELINES §3.2b: "Every H2/H3 section MUST begin with a paragraph
     * that directly answers the heading question."
     */
    public static function fix_openers( string $markdown, string $keyword ): array {
        $provider = AI_Provider_Manager::get_active_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No AI provider configured.' ];
        }

        // Split at H2 boundaries
        $parts = preg_split( '/(^## [^\n]+$)/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( count( $parts ) < 3 ) {
            return [ 'success' => false, 'error' => 'No sections found.' ];
        }

        $fixed_count = 0;
        $preamble = $parts[0];
        $sections = [];
        for ( $i = 1; $i < count( $parts ); $i += 2 ) {
            $sections[] = [
                'heading' => $parts[ $i ],
                'body'    => $parts[ $i + 1 ] ?? '',
            ];
        }

        // Skip structural sections
        $skip = '/key\s*takeaway|faq|frequently|reference|quick\s*comparison/i';

        foreach ( $sections as $idx => $section ) {
            if ( preg_match( $skip, $section['heading'] ) ) continue;

            // Get first paragraph (non-empty line after heading)
            $lines = explode( "\n", ltrim( $section['body'] ) );
            $first_para = '';
            $first_para_line = -1;
            foreach ( $lines as $li => $line ) {
                $trimmed = trim( $line );
                if ( $trimmed === '' ) continue;
                if ( str_starts_with( $trimmed, '#' ) ) continue;
                if ( str_starts_with( $trimmed, '!' ) ) continue; // images
                $first_para = $trimmed;
                $first_para_line = $li;
                break;
            }

            if ( $first_para_line < 0 ) continue;
            $wc = str_word_count( $first_para );
            if ( $wc >= 30 ) continue; // Already adequate

            // Rewrite this opener to 40-60 words
            $heading_text = trim( str_replace( '##', '', $section['heading'] ) );
            $prompt = "Rewrite this section opener to be 40-60 words. It must directly answer the heading question.\n\n"
                . "HEADING: {$heading_text}\n"
                . "KEYWORD: {$keyword}\n"
                . "CURRENT OPENER ({$wc} words): {$first_para}\n\n"
                . "RULES:\n"
                . "- 40-60 words, directly answering the heading\n"
                . "- Include the keyword \"{$keyword}\" naturally\n"
                . "- Start with a specific fact or claim, NOT with the heading restated\n"
                . "- Do NOT start with a pronoun (It, This, They, These)\n"
                . "- Keep any existing facts, numbers, or citations from the original\n\n"
                . "Return ONLY the rewritten paragraph. No heading, no explanation.";

            $result = AI_Provider_Manager::send_request(
                $provider['provider_id'],
                $prompt,
                'You are a concise SEO editor. Rewrite section openers to 40-60 words that directly answer the heading.',
                [ 'max_tokens' => 200, 'temperature' => 0.4 ]
            );

            if ( $result['success'] && ! empty( $result['content'] ) ) {
                $new_opener = trim( $result['content'] );
                // Strip any accidental heading or markdown the AI added
                $new_opener = preg_replace( '/^##?\s+[^\n]+\n/', '', $new_opener );
                $new_opener = trim( $new_opener );
                $new_wc = str_word_count( $new_opener );
                if ( $new_wc >= 25 && $new_wc <= 80 ) {
                    $lines[ $first_para_line ] = $new_opener;
                    $sections[ $idx ]['body'] = "\n" . implode( "\n", $lines );
                    $fixed_count++;
                }
            }

            // Limit to 4 rewrites to stay within timeout
            if ( $fixed_count >= 4 ) break;
        }

        if ( $fixed_count === 0 ) {
            return [ 'success' => false, 'error' => 'No short section openers found or AI rewrite failed.' ];
        }

        // Rebuild markdown
        $new_markdown = $preamble;
        foreach ( $sections as $s ) {
            $new_markdown .= $s['heading'] . $s['body'];
        }

        return [
            'success' => true,
            'content' => $new_markdown,
            'added'   => $fixed_count . ' section openers expanded to 40-60 words',
            'type'    => 'openers',
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
            $prompt = "Rewrite the following article section to a 5th grade reading level. Every sentence MUST be under 15 words. This is critical.\n\n"
                . "RULES:\n"
                . "1. EVERY sentence must be under 15 words. Split ALL longer sentences. No exceptions.\n"
                . "2. Use only common words a 10-year-old knows. Replace: 'utilize' → 'use', 'facilitate' → 'help', 'demonstrate' → 'show', 'approximately' → 'about', 'requirements' → 'needs', 'formulated' → 'made', 'specifically' → 'just for', 'recommended' → 'best', 'nutritional' → 'food', 'beneficial' → 'good'.\n"
                . "3. Write to ONE reader: 'you' and 'your'. Never 'pet owners' or 'one should'.\n"
                . "4. Active voice only. Never passive.\n"
                . "5. PRESERVE EVERY FACT: names, numbers, percentages, years, citation URLs, expert quotes, organization names, bullet lists, tables.\n"
                . "6. PRESERVE EVERY MARKDOWN LINK [text](url) exactly as written. Do not invent new URLs.\n"
                . "7. PRESERVE the H2 heading line exactly as provided.\n"
                . "8. Keep roughly the same word count (±10%). Don't pad. Don't summarize.\n"
                . "9. Keep structural elements EXACTLY in markdown format: `- item` lists stay as `- item`, tables stay as `| col |` tables, blockquotes stay as `> text`.\n"
                . "10. NEVER convert list markers to bullet characters (•, ●, ◦). Use ONLY `- ` (dash space) for unordered lists and `1. ` for ordered lists.\n"
                . "11. NEVER convert markdown to HTML. Output pure markdown only — no <ul>, <li>, <table>, <p> tags.\n"
                . "12. NEVER use emoji anywhere — no emoji bullets, no emoji in text, no emoji in headings. Pure text only.\n\n"
                . "EXAMPLES — WRITE LIKE THIS:\n"
                . "  GOOD: \"Raw feeding works for many dogs. Start small. Mix one spoonful into the usual food for three days.\"\n"
                . "  GOOD: \"Most vets agree that gradual change is safer. Watch your dog's stool. Firm means good.\"\n\n"
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

        $prompt = "Rewrite the following article. Your ONLY job: reduce the keyword \"{$keyword}\" from {$kw_count_before} mentions to EXACTLY {$max_total} mentions.\n\n"
            . "FOCUS KEYWORD: \"{$keyword}\"\n"
            . "CURRENT: {$kw_count_before} exact-phrase mentions ({$density_before}% density) — THIS IS WAY TOO HIGH\n"
            . "TARGET: EXACTLY {$max_total} mentions of the exact phrase in the ENTIRE article\n"
            . "YOU MUST REPLACE: {$mentions_to_rewrite} of the {$kw_count_before} mentions with different words\n\n"
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
            . "7. NEVER use bullet characters (•) or emoji. Use `- ` (dash space) for list items. No emoji anywhere.\n\n"
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
    /**
     * v1.5.90 — Strip unlinked attributed quotes from markdown.
     *
     * Removes any paragraph that looks like an attributed quote but has
     * no markdown link. Works line-by-line — no Unicode regex issues.
     *
     * Detects: "quote text" — Attribution Name
     * Keeps:  "quote text" — [Attribution Name](https://url)
     *
     * Works for ALL keywords, ALL content types, ALL AI models.
     */
    /**
     * v1.5.216.62.13 — Strip parenthetical attributions on inline statistics
     * when the cited source isn't in the article's verified citation pool.
     *
     * Catches the user-reported hallucination pattern (2026-05-03 audit):
     *   "Over 65% of owners who transition gradually report fewer issues (Steve's Real Food)."
     *   "An estimated 45% of UK pet owners have tried raw food for their dogs in 2026 (SoGlos)."
     *
     * The stat is preserved as plain prose (the number itself may be real); only the
     * unverifiable parenthetical attribution is stripped. Universal: works for ALL
     * keywords, ALL content types, ALL AI models, ALL languages (digit + paren
     * patterns are language-agnostic).
     *
     * @param string $markdown      Article markdown
     * @param array  $citation_pool Verified citation pool URLs (from Citation_Pool::build)
     * @param array  $sonar_data    Sonar/Tavily research data with citations + quotes
     * @return string               Markdown with unverified parentheticals stripped
     */
    public static function strip_unsourced_inline_stats( string $markdown, array $citation_pool = [], ?array $sonar_data = null ): string {
        // Build lookup of verifiable source-name fragments from the article's pool.
        // We compare attribution text loosely — if the pool has akc.org or
        // "American Kennel Club", we accept "(AKC)" or "(American Kennel Club)".
        $verifiable = [];
        foreach ( $citation_pool as $entry ) {
            if ( ! empty( $entry['source_name'] ) ) {
                $verifiable[] = strtolower( trim( $entry['source_name'] ) );
            }
            if ( ! empty( $entry['url'] ) ) {
                $host = wp_parse_url( $entry['url'], PHP_URL_HOST ) ?? '';
                $host = preg_replace( '/^www\./', '', strtolower( $host ) );
                if ( $host !== '' ) {
                    $verifiable[] = $host;
                    // First label of the host (e.g. "akc" from "akc.org") for
                    // acronym-style attributions like "(AKC)".
                    $first = explode( '.', $host )[0] ?? '';
                    if ( strlen( $first ) >= 2 ) $verifiable[] = $first;
                }
            }
        }
        foreach ( ( $sonar_data['citations'] ?? [] ) as $cit ) {
            if ( is_string( $cit ) ) {
                $host = wp_parse_url( $cit, PHP_URL_HOST ) ?? '';
                $host = preg_replace( '/^www\./', '', strtolower( $host ) );
                if ( $host !== '' ) $verifiable[] = $host;
            }
        }
        $verifiable = array_unique( $verifiable );

        $is_verifiable_attribution = function ( string $attribution ) use ( $verifiable ) {
            $needle = strtolower( trim( $attribution ) );
            // Trivial matches always allowed (very generic terms that anyone might use)
            // — these aren't fabricated sources, they're stylistic.
            $generic_allowed = [ 'study', 'research', 'experts', 'veterinarians', 'vets', 'reports', 'data' ];
            foreach ( $generic_allowed as $g ) {
                if ( $needle === $g ) return true;
            }
            // Year-only parentheticals ("(2026)") are dates, not sources — leave them
            if ( preg_match( '/^\d{4}$/', $needle ) ) return true;
            foreach ( $verifiable as $v ) {
                if ( $v === '' ) continue;
                // Exact match
                if ( $needle === $v ) return true;
                // Source name appears within attribution (e.g. attribution "akc.org study"
                // matches verifiable "akc.org" or "akc")
                if ( strlen( $v ) >= 3 && strpos( $needle, $v ) !== false ) return true;
                // Acronym match — common authority orgs that abbreviate
                $acronym = preg_replace( '/[^a-z]/', '', $needle );
                if ( strlen( $acronym ) >= 2 && $acronym === $v ) return true;
            }
            return false;
        };

        // Pattern A: inline stat with parenthetical attribution.
        // "X%" or "N out of M" or "N in M" followed by "(SourceName)"
        $patterns = [
            '/(\b\d{1,3}(?:[.,]\d+)?\s*%[^.\n]*?)\s*\(([A-Z][^)]{1,40})\)/u',
            '/(\b\d+\s+(?:out\s+of|in)\s+\d+[^.\n]*?)\s*\(([A-Z][^)]{1,40})\)/iu',
            '/(\baccording\s+to\s+[A-Z][\w\s]{2,30}[,;]\s+\d[\d.,%]*)/iu', // "According to X, 65%..."
        ];

        $stripped_count = 0;
        foreach ( $patterns as $pattern ) {
            $markdown = preg_replace_callback(
                $pattern,
                function ( $m ) use ( $is_verifiable_attribution, &$stripped_count ) {
                    // Only first 2 patterns have group 2 (attribution); pattern 3 is "According to"
                    if ( ! isset( $m[2] ) ) {
                        // "According to X" — extract source name between "to" and ","
                        if ( preg_match( '/according\s+to\s+([A-Z][\w\s]{2,30})[,;]/iu', $m[0], $a ) ) {
                            $attribution = $a[1];
                            if ( $is_verifiable_attribution( $attribution ) ) return $m[0];
                            // Strip the "According to X," prefix, keep just the stat
                            $stripped_count++;
                            return preg_replace( '/^according\s+to\s+[A-Z][\w\s]{2,30}[,;]\s*/iu', '', $m[0] );
                        }
                        return $m[0];
                    }
                    $attribution = $m[2];
                    if ( $is_verifiable_attribution( $attribution ) ) return $m[0];
                    // Strip the parenthetical, keep the stat as plain prose
                    $stripped_count++;
                    return rtrim( $m[1] );
                },
                $markdown
            );
        }

        if ( $stripped_count > 0 && function_exists( 'error_log' ) ) {
            error_log( sprintf( 'SEOBetter strip_unsourced_inline_stats: removed %d unverified parenthetical attribution(s) from article body', $stripped_count ) );
        }

        return $markdown;
    }

    public static function strip_unlinked_quotes( string $markdown ): string {
        $lines = explode( "\n", $markdown );
        $cleaned = [];
        $skip_next_empty = false;

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );

            // Skip empty lines after a removed quote
            if ( $skip_next_empty && $trimmed === '' ) {
                $skip_next_empty = false;
                continue;
            }
            $skip_next_empty = false;

            // Check if this line is an attributed quote without a link:
            // Must contain a dash character followed by a capitalized word
            // Must NOT contain ]( which indicates a markdown link
            $has_dash_attribution = false;
            foreach ( [ ' — ', ' – ', ' - ' ] as $dash ) {
                if ( strpos( $trimmed, $dash ) !== false ) {
                    $parts = explode( $dash, $trimmed, 2 );
                    $before = trim( $parts[0] );
                    $after = trim( $parts[1] ?? '' );
                    // The part before the dash should end with a quote character
                    // and the part after should be a capitalized name (2+ words or known source)
                    // v1.5.99 — ONLY match actual quote characters, NOT periods/exclamation/question marks.
                    // Previous regex included \.!? which matched normal paragraphs ending with a period
                    // followed by "— Source Name", stripping entire paragraphs and cratering GEO scores.
                    $ends_with_quote = preg_match( '/["\x{201D}\x{201C}\x{2018}\x{2019}\']$/u', $before );
                    // v1.5.96c — also match lowercase hostnames (petcircle.com.au)
                    // and capitalized names (Pet Circle). Previous check only caught [A-Z].
                    $starts_with_name = preg_match( '/^[A-Za-z]/', $after );
                    if ( $ends_with_quote && $starts_with_name && strlen( $before ) > 20 ) {
                        $has_dash_attribution = true;
                        break;
                    }
                }
            }

            if ( $has_dash_attribution ) {
                // Check if it has a markdown link — if so, it's verified, keep it
                if ( strpos( $trimmed, '](' ) !== false ) {
                    $cleaned[] = $line; // Has a link — verified quote, keep
                } else {
                    // No link — hallucinated attribution, strip it
                    $skip_next_empty = true; // Also skip the blank line after
                    continue;
                }
            } else {
                $cleaned[] = $line;
            }
        }

        return implode( "\n", $cleaned );
    }

    /**
     * v1.5.96 — Call Tavily Search API directly from PHP.
     * No Vercel dependency. No timeout issues. Returns real search
     * results with raw page content for quote extraction.
     *
     * @param string $keyword Search query
     * @return array {results: [...], quotes: [{text, source, url}, ...]}
     */
    /**
     * v1.5.108 — Authority domain mapping per category + country.
     * ALL 25 plugin categories covered. Non-commercial sources only:
     * government regulators, university research, professional associations,
     * peer-reviewed journals, independent journalism. No private brands.
     *
     * User sites (GLOBAL, all countries):
     *   mindiampets.com.au → animals, veterinary
     *   mindiam.com → technology
     *
     * See seo-guidelines/authority-domains.md for the full reference.
     */
    private static function get_authority_domains( string $domain, string $country = '' ): array {

        // ---- GLOBAL domains (always included, any country) ----
        // All 25 categories from the plugin Category dropdown
        $global = [
            'general' => [
                'reuters.com', 'apnews.com', 'bbc.com', 'wikipedia.org',
                'ncbi.nlm.nih.gov', 'nature.com',
            ],
            // v1.5.216.62.14 — REMOVED commercial pet sites: petmd.com (Chewy-owned
            // commercial), thesprucepets.com (Dotdash/Meredith editorial content,
            // not authoritative). Per policy: authority list is government regulators,
            // university vet schools, professional associations (AVMA, RCVS, BVA),
            // peer-reviewed journals, independent welfare bodies. No commercial brands
            // even if they have substantive content.
            //
            // merckvetmanual.com retained — published by Merck but explicitly a
            // peer-reviewed clinical reference, written by veterinary specialists.
            'animals' => [
                'ncbi.nlm.nih.gov', 'nature.com', 'sciencedirect.com', 'woah.org',
                'merckvetmanual.com',
                'wsava.org', 'fediaf.org', 'frontiersin.org', 'vetrecord.bmj.com',
                'avmajournals.avma.org', 'wva-online.org', 'icatcare.org', 'fecava.org',
                'mindiampets.com.au',
            ],
            'veterinary' => [
                'ncbi.nlm.nih.gov', 'nature.com', 'sciencedirect.com', 'woah.org',
                'merckvetmanual.com',
                'wsava.org', 'fediaf.org', 'frontiersin.org', 'vetrecord.bmj.com',
                'avmajournals.avma.org', 'bmcvetres.biomedcentral.com', 'wva-online.org', 'fecava.org',
                'mindiampets.com.au',
            ],
            // v1.5.216.62.17 — added top global museums + design bodies
            'art_design' => [
                'moma.org', 'tate.org.uk', 'metmuseum.org', 'nga.gov',
                'designweek.co.uk', 'itsnicethat.com', 'dezeen.com',
                'theartnewspaper.com', 'artforum.com', 'frieze.com',
                'aiga.org', 'core77.com', 'archdaily.com',
                'getty.edu', 'guggenheim.org', 'whitney.org',
                'britishmuseum.org', 'vam.ac.uk', 'royalacademy.org.uk',
                'louvre.fr', 'centrepompidou.fr', 'rijksmuseum.nl',
                'uffizi.it', 'museodelprado.es',
            ],
            'blockchain' => [
                'ethereum.org', 'bitcoin.org', 'coindesk.com', 'theblock.co',
                'arxiv.org', 'mit.edu',
            ],
            'books' => [
                'loc.gov', 'bl.uk', 'theguardian.com', 'nytimes.com',
                'publishersweekly.com', 'kirkusreviews.com',
            ],
            // v1.5.216.62.17 — added top-tier b-school + multilateral data sources
            'business' => [
                'hbr.org', 'reuters.com', 'bloomberg.com', 'mckinsey.com',
                'forbes.com', 'ft.com',
                'oecd.org', 'weforum.org', 'economist.com',
                'knowledge.wharton.upenn.edu', 'sloanreview.mit.edu',
                'knowledge.insead.edu', 'hbswk.hbs.edu',
                'mindiam.com', 'seobetter.com',
            ],
            'cryptocurrency' => [
                'coindesk.com', 'cointelegraph.com', 'decrypt.co', 'theblock.co',
                'ethereum.org', 'arxiv.org',
            ],
            'currency' => [
                'bis.org', 'imf.org', 'ecb.europa.eu', 'reuters.com',
                'bloomberg.com', 'ft.com',
            ],
            'ecommerce' => [
                'digitalcommerce360.com', 'practicalecommerce.com', 'baymard.com',
                'reuters.com', 'hbr.org', 'mindiam.com', 'seobetter.com',
            ],
            // v1.5.216.62.17 — added top universities + research bodies
            'education' => [
                'ncbi.nlm.nih.gov', 'nature.com', 'sciencedirect.com',
                'edutopia.org', 'chronicle.com',
                'harvard.edu', 'mit.edu', 'stanford.edu', 'ox.ac.uk', 'cam.ac.uk',
                'ed.gov', 'ukri.org', 'unesco.org',
            ],
            // v1.5.216.62.44 — Employment / Career / Workplace.
            // The Business category is B2B / corporate-strategy oriented (HBR,
            // McKinsey, Bloomberg). Employment-related topics (remote work,
            // career advice, productivity, workplace wellness, hiring,
            // workplace mental health) need a different authority list:
            // labor statistics agencies, workplace research bodies, peer-
            // reviewed psych/management journals, labor economics outlets.
            'employment' => [
                'bls.gov', 'oecd.org', 'ilo.org',
                'gallup.com', 'pewresearch.org',
                'apa.org', 'shrm.org',
                'hbr.org', 'sloanreview.mit.edu', 'mckinsey.com',
                'who.int', 'cdc.gov',
                'jstor.org', 'sciencedirect.com', 'springer.com',
                'mindiam.com', 'seobetter.com',
            ],
            // v1.5.216.62.17 — added top trade outlets + national academies
            'entertainment' => [
                'variety.com', 'hollywoodreporter.com', 'bfi.org.uk',
                'rottentomatoes.com', 'bbc.com',
                'deadline.com', 'thewrap.com', 'screendaily.com', 'indiewire.com',
                'oscars.org', 'emmys.com', 'bafta.org', 'cesarducinema.fr',
                'nfb.ca', 'screenaustralia.gov.au', 'nzfilm.co.nz',
            ],
            // v1.5.216.62.17 — added UN/IEA/biodiversity bodies
            'environment' => [
                'un.org', 'nature.com', 'nationalgeographic.com', 'wwf.org',
                'ipcc.ch', 'iucn.org',
                'unep.org', 'iea.org', 'iucnredlist.org', 'cbd.int',
                'ramsar.org', 'globalforestwatch.org',
            ],
            // v1.5.216.62.17 — REMOVED investopedia.com (commercial consumer
            // finance content site). Added BIS, ECB, Fed (gov regulators) + OECD.
            'finance' => [
                'reuters.com', 'bloomberg.com', 'ft.com',
                'imf.org', 'worldbank.org',
                'oecd.org', 'bis.org', 'federalreserve.gov',
                'bankofengland.co.uk', 'ecb.europa.eu',
            ],
            'food' => [
                'who.int', 'ncbi.nlm.nih.gov', 'nature.com',
                'fao.org', 'sciencedirect.com',
                // v1.5.216.62.12 additions — global food safety + nutrition
                'efsa.europa.eu', 'codexalimentarius.org', 'jandonline.org', 'ift.org',
            ],
            // v1.5.216.62.17 — added trade bodies + ratings authorities + reference
            'games' => [
                'gamedeveloper.com', 'gdcvault.com', 'eurogamer.net',
                'rockpapershotgun.com', 'arstechnica.com',
                'polygon.com', 'pcgamer.com', 'kotaku.com',
                'esrb.org', 'pegi.info', 'theesa.com',
                'igda.org', 'gdconf.com', 'boardgamegeek.com',
            ],
            'government' => [
                'un.org', 'reuters.com', 'bbc.com', 'apnews.com',
                'transparency.org', 'worldbank.org',
            ],
            'health' => [
                'who.int', 'ncbi.nlm.nih.gov', 'nature.com', 'thelancet.com', 'bmj.com',
                // v1.5.216.62.12 additions — top-tier peer-reviewed + meta-research
                'nejm.org', 'jamanetwork.com', 'cochranelibrary.com',
                'europepmc.org', 'ecdc.europa.eu', 'medrxiv.org',
            ],
            // v1.5.216.62.17 — added IFPI + national rights orgs + heritage bodies
            'music' => [
                'pitchfork.com', 'rollingstone.com', 'bbc.com',
                'nme.com', 'grammy.com',
                'ifpi.org', 'billboard.com', 'riaa.com', 'bpi.co.uk',
                'aria.com.au', 'junoawards.ca', 'sacem.fr', 'gema.de',
                'jasrac.or.jp', 'nhk.or.jp', 'ascap.com', 'bmi.com',
                'ram.ac.uk', 'philharmoniedeparis.fr',
            ],
            // v1.5.216.62.17 — added top global newspapers + wire services
            'news' => [
                'reuters.com', 'apnews.com', 'bbc.com',
                'theguardian.com', 'aljazeera.com',
                'nytimes.com', 'washingtonpost.com', 'ft.com', 'wsj.com',
                'economist.com', 'lemonde.fr', 'spiegel.de', 'asahi.com',
                'afp.com', 'dpa.com', 'kyodonews.jp',
            ],
            // v1.5.216.62.17 — added flagship multi-disciplinary journals + agencies
            'science' => [
                'nature.com', 'science.org', 'ncbi.nlm.nih.gov', 'nasa.gov',
                'scientificamerican.com', 'newscientist.com', 'phys.org',
                'nih.gov', 'nsf.gov', 'noaa.gov', 'usgs.gov', 'energy.gov',
                'pnas.org', 'cell.com', 'aps.org', 'quantamagazine.org',
                'royalsociety.org', 'esa.int', 'jaxa.jp',
            ],
            // v1.5.216.62.17 — added top global sport governing bodies
            'sports' => [
                'olympics.com', 'wada-ama.org', 'bbc.com',
                'fifa.com', 'worldathletics.org', 'uci.org', 'fina.org',
                'paralympic.org', 'fiba.basketball', 'icc-cricket.com',
                'world.rugby', 'usopc.org',
                'reuters.com', 'espn.com',
            ],
            // v1.5.216.62.17 — added top CS research bodies + standards orgs
            'technology' => [
                'ieee.org', 'acm.org', 'arxiv.org', 'nature.com',
                'techcrunch.com', 'arstechnica.com', 'wired.com', 'theverge.com',
                'technologyreview.com', 'spectrum.ieee.org',
                'w3.org', 'nist.gov', 'mit.edu', 'stanford.edu',
                'mindiam.com', 'seobetter.com',
            ],
            // v1.5.216.62.17 — added national transport ministries + safety bureaus
            'transportation' => [
                'icao.int', 'iata.org', 'imo.org', 'reuters.com', 'bbc.com',
                'itf-oecd.org', 'uic.org', 'unece.org',
                'transportation.gov', 'faa.gov', 'nhtsa.gov', 'ntsb.gov',
                'caa.co.uk', 'casa.gov.au', 'tc.gc.ca',
            ],
            // v1.5.216.62.17 — added WTTC + national tourism boards
            'travel' => [
                'unwto.org', 'lonelyplanet.com', 'bbc.com',
                'nationalgeographic.com', 'reuters.com',
                'wttc.org', 'travel.state.gov', 'visitbritain.com', 'australia.com',
                'destinationcanada.com', 'newzealand.com', 'germany.travel',
                'france.fr', 'incredibleindia.gov.in', 'japan.travel',
            ],
            // v1.5.216.62.17 — added national met services + ECMWF/Copernicus
            'weather' => [
                'wmo.int', 'nature.com', 'bbc.com',
                'sciencedaily.com',
                'ecmwf.int', 'copernicus.eu', 'climate.copernicus.eu', 'noaa.gov',
                'weather.gov', 'metoffice.gov.uk', 'bom.gov.au', 'metservice.com',
                'jma.go.jp', 'dwd.de', 'meteofrance.com',
            ],
        ];

        // ---- COUNTRY-SPECIFIC domains ----
        $by_country = [
            'AU' => [
                // v1.5.216.62.12 — added national welfare standards body, accredited
                // vet schools, plus wildlife health authority. Pushes mindiampets.com.au
                // further down the Tavily ranking by giving the AI more authority options.
                'animals' => [
                    'rspca.org.au', 'apvma.gov.au', 'sydney.edu.au', 'unimelb.edu.au',
                    'abc.net.au', 'csiro.au', 'agriculture.gov.au',
                    'aaws.org.au', 'animalmedicinesaustralia.org.au',
                    'adelaide.edu.au', 'jcu.edu.au', 'murdoch.edu.au', 'csu.edu.au',
                    'wildlife.org.au',
                    'mindiampets.com.au',
                ],
                'veterinary' => [
                    'rspca.org.au', 'apvma.gov.au', 'sydney.edu.au', 'unimelb.edu.au',
                    'abc.net.au', 'csiro.au', 'ava.com.au',
                    'aaws.org.au', 'adelaide.edu.au', 'jcu.edu.au', 'murdoch.edu.au',
                    'csu.edu.au', 'wildlife.org.au',
                    'mindiampets.com.au',
                ],
                'health' => [
                    'health.gov.au', 'tga.gov.au', 'nhmrc.gov.au', 'abc.net.au',
                    'sydney.edu.au', 'unimelb.edu.au',
                ],
                'food' => [
                    'foodstandards.gov.au', 'health.gov.au', 'abc.net.au', 'csiro.au',
                ],
                'finance' => [
                    'rba.gov.au', 'asic.gov.au', 'ato.gov.au', 'abc.net.au', 'afr.com',
                ],
                'technology' => [
                    'itnews.com.au', 'abc.net.au', 'csiro.au',
                ],
                'news' => [
                    'abc.net.au', 'sbs.com.au', 'smh.com.au', 'theage.com.au',
                ],
                'environment' => [
                    'environment.gov.au', 'csiro.au', 'abc.net.au', 'bom.gov.au',
                ],
                'education' => [
                    'education.gov.au', 'sydney.edu.au', 'unimelb.edu.au', 'anu.edu.au',
                    'uq.edu.au', 'monash.edu', 'abc.net.au',
                ],
                'business' => [
                    'abc.net.au', 'afr.com', 'asic.gov.au', 'rba.gov.au',
                ],
                'government' => [
                    'aph.gov.au', 'pm.gov.au', 'abs.gov.au', 'abc.net.au',
                ],
            ],
            'US' => [
                // v1.5.216.62.12 — added USDA APHIS, AAHA hospital accreditor,
                // additional accredited vet schools, AAVMC.
                'animals' => [
                    'fda.gov', 'vet.cornell.edu', 'avma.org', 'aspca.org', 'cdc.gov',
                    'nih.gov', 'tufts.edu', 'ucdavis.edu',
                    'aphis.usda.gov', 'nal.usda.gov', 'aaha.org', 'aavmc.org',
                    'humanesociety.org', 'awionline.org', 'morrisanimalfoundation.org',
                    'vet.upenn.edu', 'vet.osu.edu', 'cvm.ncsu.edu', 'vetmed.tamu.edu',
                    'vetmed.wisc.edu', 'fws.gov',
                    'mindiampets.com.au',
                ],
                'veterinary' => [
                    'fda.gov', 'vet.cornell.edu', 'avma.org', 'cdc.gov', 'nih.gov',
                    'tufts.edu', 'ucdavis.edu',
                    'aphis.usda.gov', 'aaha.org', 'aavmc.org',
                    'vet.upenn.edu', 'vet.osu.edu', 'cvm.ncsu.edu', 'vetmed.tamu.edu',
                    'vetmed.wisc.edu',
                    'mindiampets.com.au',
                ],
                'health' => [
                    'nih.gov', 'cdc.gov', 'fda.gov', 'mayoclinic.org', 'clevelandclinic.org',
                    'hopkinsmedicine.org', 'health.harvard.edu', 'medlineplus.gov',
                ],
                'food' => [
                    'fda.gov', 'usda.gov', 'nutrition.gov', 'eatright.org',
                ],
                'finance' => [
                    'sec.gov', 'federalreserve.gov', 'wsj.com', 'cnbc.com',
                    'nerdwallet.com', 'bankrate.com',
                ],
                'technology' => [
                    'nist.gov', 'mit.edu', 'stanford.edu',
                ],
                'news' => [
                    'nytimes.com', 'washingtonpost.com', 'npr.org', 'pbs.org',
                ],
                'government' => [
                    'usa.gov', 'whitehouse.gov', 'congress.gov', 'gao.gov',
                ],
                'education' => [
                    'ed.gov', 'harvard.edu', 'mit.edu', 'stanford.edu',
                ],
            ],
            'GB' => [
                // v1.5.216.62.12 — added DEFRA, RCVS, BSAVA, accredited vet schools,
                // welfare charities (PDSA, Blue Cross, Dogs Trust, Cats Protection).
                'animals' => [
                    'rspca.org.uk', 'bva.co.uk', 'rvc.ac.uk', 'gov.uk', 'bbc.co.uk',
                    'defra.gov.uk', 'rcvs.org.uk', 'bsava.com', 'vmd.defra.gov.uk',
                    'pdsa.org.uk', 'bluecross.org.uk', 'dogstrust.org.uk', 'cats.org.uk',
                    'nottingham.ac.uk', 'liverpool.ac.uk', 'ed.ac.uk', 'bristol.ac.uk',
                    'mindiampets.com.au',
                ],
                'veterinary' => [
                    'rspca.org.uk', 'bva.co.uk', 'rvc.ac.uk', 'gov.uk', 'bbc.co.uk',
                    'rcvs.org.uk', 'bsava.com', 'vmd.defra.gov.uk',
                    'nottingham.ac.uk', 'liverpool.ac.uk', 'ed.ac.uk', 'bristol.ac.uk',
                    'mindiampets.com.au',
                ],
                'health' => [
                    'nhs.uk', 'gov.uk', 'bbc.co.uk', 'nice.org.uk',
                    'ox.ac.uk', 'cam.ac.uk', 'imperial.ac.uk',
                ],
                'food' => [
                    'food.gov.uk', 'nhs.uk', 'bbc.co.uk', 'gov.uk',
                ],
                'finance' => [
                    'bankofengland.co.uk', 'fca.org.uk', 'ft.com', 'bbc.co.uk', 'gov.uk',
                ],
                'news' => [
                    'bbc.co.uk', 'theguardian.com', 'telegraph.co.uk', 'independent.co.uk',
                ],
                'technology' => [
                    'bbc.co.uk', 'theregister.com', 'cam.ac.uk', 'ox.ac.uk',
                ],
                'government' => [
                    'gov.uk', 'parliament.uk', 'bbc.co.uk',
                ],
            ],
            'CA' => [
                'animals' => [
                    'canadianveterinarians.net', 'inspection.canada.ca', 'cbc.ca',
                    'ontariovet.ca', 'uoguelph.ca', 'mindiampets.com.au',
                ],
                'veterinary' => [
                    'canadianveterinarians.net', 'inspection.canada.ca', 'cbc.ca',
                    'uoguelph.ca', 'mindiampets.com.au',
                ],
                'health' => [
                    'canada.ca', 'cihi.ca', 'cbc.ca',
                ],
                'food' => [
                    'inspection.canada.ca', 'canada.ca', 'cbc.ca',
                ],
                'finance' => [
                    'bankofcanada.ca', 'osc.ca', 'cbc.ca', 'globeandmail.com',
                ],
                'news' => [
                    'cbc.ca', 'globalnews.ca', 'thestar.com', 'globeandmail.com',
                ],
            ],
            'NZ' => [
                'animals' => [
                    'spca.nz', 'massey.ac.nz', 'mpi.govt.nz', 'rnz.co.nz', 'mindiampets.com.au',
                ],
                'veterinary' => [
                    'spca.nz', 'massey.ac.nz', 'mpi.govt.nz', 'nzva.org.nz', 'mindiampets.com.au',
                ],
                'health' => [
                    'health.govt.nz', 'medsafe.govt.nz', 'rnz.co.nz',
                ],
                'news' => [
                    'rnz.co.nz', 'stuff.co.nz', 'nzherald.co.nz',
                ],
            ],
            'DE' => [
                'animals' => [ 'tierschutzbund.de', 'tieraerzteverband.de', 'bfr.bund.de', 'mindiampets.com.au' ],
                'health'  => [ 'rki.de', 'bfarm.de', 'gesundheitsinformation.de' ],
                'news'    => [ 'dw.com', 'spiegel.de', 'zeit.de' ],
            ],
            'FR' => [
                'animals' => [ 'spa.asso.fr', 'anses.fr', 'mindiampets.com.au' ],
                'health'  => [ 'has-sante.fr', 'inserm.fr', 'pasteur.fr' ],
                'news'    => [ 'france24.com', 'lemonde.fr' ],
            ],
            'IN' => [
                'animals' => [ 'dahd.nic.in', 'fssai.gov.in', 'mindiampets.com.au' ],
                'health'  => [ 'nhp.gov.in', 'icmr.nic.in', 'aiims.edu' ],
                'news'    => [ 'thehindu.com', 'indianexpress.com', 'ndtv.com' ],
                'finance' => [ 'rbi.org.in', 'sebi.gov.in', 'economictimes.com' ],
                'technology' => [ 'nasscom.in' ],
            ],
            'SG' => [
                'health' => [ 'moh.gov.sg', 'healthhub.sg' ],
                'news'   => [ 'straitstimes.com', 'channelnewsasia.com' ],
                'finance' => [ 'mas.gov.sg' ],
            ],
            'JP' => [
                // v1.5.216.62.12 — Japanese animal health + vet authorities (no
                // animals/vet entries existed before).
                'animals' => [
                    'maff.go.jp', 'env.go.jp', 'jvma-vet.jp', 'niah.naro.go.jp',
                    'vet.u-tokyo.ac.jp', 'mindiampets.com.au',
                ],
                'veterinary' => [
                    'maff.go.jp', 'jvma-vet.jp', 'niah.naro.go.jp',
                    'vet.u-tokyo.ac.jp', 'vmas.jp', 'mindiampets.com.au',
                ],
                'health' => [ 'mhlw.go.jp', 'ncgm.go.jp', 'ncc.go.jp', 'niid.go.jp', 'pmda.go.jp' ],
                'news'   => [ 'japantimes.co.jp', 'nhk.or.jp', 'asahi.com', 'mainichi.jp', 'yomiuri.co.jp' ],
                'technology' => [ 'nikkei.com', 'u-tokyo.ac.jp', 'kyoto-u.ac.jp', 'aist.go.jp', 'nict.go.jp' ],
            ],
            // v1.5.216.62.12 — 6 NEW country blocks. Previously these countries had
            // no Animals/Veterinary lists, so Tavily fell back to global only — which
            // includes user's mindiampets.com.au and ranks it high. Adding native
            // government / vet school / welfare body sources gives the AI proper
            // local authorities to cite for in-country articles.
            'IT' => [
                'animals' => [
                    'salute.gov.it', 'izs.it', 'fnovi.it', 'anmvi.it',
                    'lav.it', 'enpa.it', 'isprambiente.gov.it', 'mindiampets.com.au',
                ],
                'veterinary' => [
                    'salute.gov.it', 'izs.it', 'fnovi.it', 'anmvi.it',
                    'isprambiente.gov.it', 'mindiampets.com.au',
                ],
                'health' => [ 'salute.gov.it', 'iss.it', 'aifa.gov.it', 'humanitas.it', 'unibo.it' ],
                'news'   => [ 'corriere.it', 'repubblica.it', 'lastampa.it', 'ansa.it', 'rai.it' ],
                'finance' => [ 'bancaditalia.it', 'consob.it', 'mef.gov.it', 'agenziaentrate.gov.it', 'istat.it' ],
            ],
            'ES' => [
                'animals' => [
                    'mapa.gob.es', 'miteco.gob.es', 'colvet.es', 'avepa.org',
                    'csic.es', 'ucm.es', 'uab.cat', 'mindiampets.com.au',
                ],
                'veterinary' => [
                    'mapa.gob.es', 'colvet.es', 'avepa.org', 'csic.es',
                    'ucm.es', 'uab.cat', 'mindiampets.com.au',
                ],
                'health' => [ 'sanidad.gob.es', 'isciii.es', 'aemps.gob.es', 'csic.es' ],
                'news'   => [ 'elpais.com', 'elmundo.es', 'lavanguardia.com', 'rtve.es', 'efe.com' ],
                'finance' => [ 'bde.es', 'cnmv.es', 'hacienda.gob.es', 'ine.es' ],
            ],
            'BR' => [
                'animals' => [
                    'gov.br', 'ibama.gov.br', 'cfmv.gov.br', 'embrapa.br',
                    'fmvz.usp.br', 'fiocruz.br', 'mindiampets.com.au',
                ],
                'veterinary' => [
                    'gov.br', 'cfmv.gov.br', 'embrapa.br', 'fmvz.usp.br',
                    'fiocruz.br', 'mindiampets.com.au',
                ],
                'health' => [ 'gov.br', 'fiocruz.br', 'usp.br', 'unifesp.br' ],
                'news'   => [ 'folha.uol.com.br', 'oglobo.globo.com', 'valor.globo.com', 'estadao.com.br' ],
                'finance' => [ 'bcb.gov.br', 'cvm.gov.br', 'ibge.gov.br' ],
            ],
            'MX' => [
                'animals' => [
                    'gob.mx', 'fmvz.unam.mx', 'inecc.gob.mx', 'mindiampets.com.au',
                ],
                'veterinary' => [
                    'gob.mx', 'fmvz.unam.mx', 'fmvz.uady.mx', 'mindiampets.com.au',
                ],
                'health' => [ 'gob.mx', 'insp.mx', 'imss.gob.mx', 'unam.mx' ],
                'news'   => [ 'eluniversal.com.mx', 'reforma.com', 'jornada.com.mx', 'milenio.com' ],
                'finance' => [ 'banxico.org.mx', 'cnbv.gob.mx', 'inegi.org.mx' ],
            ],
            'KR' => [
                'animals' => [
                    'mafra.go.kr', 'qia.go.kr', 'kvma.or.kr', 'me.go.kr',
                    'vet.snu.ac.kr', 'mindiampets.com.au',
                ],
                'veterinary' => [
                    'mafra.go.kr', 'qia.go.kr', 'kvma.or.kr',
                    'vet.snu.ac.kr', 'mindiampets.com.au',
                ],
                'health' => [ 'mohw.go.kr', 'kdca.go.kr', 'snu.ac.kr', 'yonsei.ac.kr' ],
                'news'   => [ 'chosun.com', 'donga.com', 'joongang.co.kr', 'hani.co.kr', 'yna.co.kr' ],
                'finance' => [ 'bok.or.kr', 'fsc.go.kr', 'fss.or.kr', 'kostat.go.kr' ],
            ],
            'CN' => [
                'animals' => [
                    'moa.gov.cn', 'mee.gov.cn', 'caas.cn', 'cau.edu.cn',
                    'forestry.gov.cn', 'mindiampets.com.au',
                ],
                'veterinary' => [
                    'moa.gov.cn', 'caas.cn', 'cau.edu.cn', 'mindiampets.com.au',
                ],
                'health' => [ 'nhc.gov.cn', 'chinacdc.cn', 'cma.org.cn', 'pumc.edu.cn' ],
                'news'   => [ 'xinhuanet.com', 'chinadaily.com.cn', 'people.com.cn', 'caixinglobal.com', 'scmp.com' ],
                'finance' => [ 'pbc.gov.cn', 'csrc.gov.cn', 'mof.gov.cn', 'stats.gov.cn' ],
            ],
            // ── Oceania ──
            'FJ' => [
                'news'   => [ 'fbcnews.com.fj', 'fijitimes.com.fj', 'fijivillage.com' ],
                'health' => [ 'health.gov.fj', 'info.gov.fj' ],
            ],
            // ── North America ──
            'MX' => [
                'news'       => [ 'gob.mx', 'jornada.com.mx', 'proceso.com.mx', 'eluniversal.com.mx' ],
                'health'     => [ 'gob.mx', 'imss.gob.mx', 'salud.gob.mx' ],
                'animals'    => [ 'gob.mx', 'senasica.gob.mx', 'unam.mx', 'mindiampets.com.au' ],
                'finance'    => [ 'banxico.org.mx', 'gob.mx', 'cnbv.gob.mx' ],
                'education'  => [ 'unam.mx', 'ipn.mx', 'sep.gob.mx', 'tec.mx' ],
            ],
            // ── Europe Western ──
            'IE' => [
                'news'       => [ 'rte.ie', 'irishtimes.com', 'thejournal.ie' ],
                'health'     => [ 'gov.ie', 'hse.ie', 'hiqa.ie' ],
                'animals'    => [ 'ispca.ie', 'ucd.ie', 'gov.ie', 'mindiampets.com.au' ],
                'finance'    => [ 'centralbank.ie', 'gov.ie', 'revenue.ie' ],
                'education'  => [ 'tcd.ie', 'ucd.ie', 'ucc.ie' ],
            ],
            'ES' => [
                'news'       => [ 'rtve.es', 'elpais.com', 'elmundo.es' ],
                'health'     => [ 'sanidad.gob.es', 'isciii.es', 'aemps.es' ],
                'animals'    => [ 'mapa.gob.es', 'mindiampets.com.au' ],
                'finance'    => [ 'bde.es', 'cnmv.es', 'hacienda.gob.es' ],
                'education'  => [ 'ucm.es', 'ub.edu', 'uam.es' ],
            ],
            'PT' => [
                'news'   => [ 'rtp.pt', 'publico.pt', 'dn.pt' ],
                'health' => [ 'sns.gov.pt', 'dgs.pt', 'infarmed.pt' ],
            ],
            'IT' => [
                'news'       => [ 'rai.it', 'repubblica.it', 'corriere.it', 'ansa.it' ],
                'health'     => [ 'salute.gov.it', 'iss.it', 'aifa.gov.it' ],
                'animals'    => [ 'izsvenezie.it', 'salute.gov.it', 'mindiampets.com.au' ],
                'finance'    => [ 'bancaditalia.it', 'consob.it', 'mef.gov.it' ],
                'education'  => [ 'unibo.it', 'uniroma1.it', 'polimi.it' ],
            ],
            'NL' => [
                'news'       => [ 'nos.nl', 'nrc.nl', 'volkskrant.nl' ],
                'health'     => [ 'rivm.nl', 'rijksoverheid.nl' ],
                'animals'    => [ 'nvwa.nl', 'wur.nl', 'dierenbescherming.nl', 'mindiampets.com.au' ],
                'finance'    => [ 'dnb.nl', 'afm.nl', 'rijksoverheid.nl' ],
                'education'  => [ 'uu.nl', 'uva.nl', 'tudelft.nl' ],
            ],
            'AT' => [
                'news'   => [ 'orf.at', 'derstandard.at', 'diepresse.com' ],
                'health' => [ 'gesundheit.gv.at', 'sozialministerium.at', 'ages.at' ],
            ],
            'GR' => [
                'news'   => [ 'ert.gr', 'kathimerini.gr', 'tovima.gr' ],
                'health' => [ 'moh.gov.gr', 'eody.gov.gr' ],
            ],
            'CY' => [
                'news'   => [ 'cybc.com.cy', 'philenews.com' ],
                'health' => [ 'moh.gov.cy', 'pio.gov.cy' ],
            ],
            'MT' => [
                'news'   => [ 'tvm.com.mt', 'timesofmalta.com' ],
                'health' => [ 'gov.mt' ],
            ],
            // ── Nordic / Baltic ──
            'SE' => [
                'news'       => [ 'svt.se', 'sr.se', 'dn.se' ],
                'health'     => [ 'folkhalsomyndigheten.se', 'socialstyrelsen.se', '1177.se' ],
                'animals'    => [ 'jordbruksverket.se', 'slu.se', 'mindiampets.com.au' ],
                'finance'    => [ 'riksbank.se', 'fi.se' ],
                'education'  => [ 'uu.se', 'lu.se', 'kth.se', 'ki.se' ],
            ],
            'NO' => [
                'news'       => [ 'nrk.no', 'aftenposten.no', 'vg.no' ],
                'health'     => [ 'fhi.no', 'helsenorge.no', 'helsedirektoratet.no' ],
                'animals'    => [ 'mattilsynet.no', 'nmbu.no', 'mindiampets.com.au' ],
                'finance'    => [ 'norges-bank.no', 'finanstilsynet.no' ],
                'education'  => [ 'uio.no', 'ntnu.no', 'uib.no' ],
            ],
            'DK' => [
                'news'   => [ 'dr.dk', 'politiken.dk', 'berlingske.dk' ],
                'health' => [ 'sst.dk', 'sundhed.dk', 'ssi.dk' ],
            ],
            'FI' => [
                'news'   => [ 'yle.fi', 'hs.fi' ],
                'health' => [ 'thl.fi', 'stm.fi', 'fimea.fi' ],
            ],
            'IS' => [
                'news'   => [ 'ruv.is', 'mbl.is' ],
                'health' => [ 'landlaeknir.is', 'government.is' ],
            ],
            'EE' => [
                'news'   => [ 'err.ee', 'postimees.ee' ],
                'health' => [ 'terviseamet.ee', 'sm.ee' ],
            ],
            'LV' => [
                'news'   => [ 'lsm.lv', 'delfi.lv' ],
                'health' => [ 'vm.gov.lv', 'spkc.gov.lv' ],
            ],
            'LT' => [
                'news'   => [ 'lrt.lt', 'delfi.lt' ],
                'health' => [ 'sam.lrv.lt', 'nvsc.lrv.lt' ],
            ],
            // ── Central / Eastern Europe ──
            'PL' => [
                'news'       => [ 'tvp.pl', 'polskieradio.pl', 'wyborcza.pl' ],
                'health'     => [ 'gov.pl', 'pzh.gov.pl', 'nfz.gov.pl' ],
                'animals'    => [ 'wetgiw.gov.pl', 'sggw.edu.pl', 'mindiampets.com.au' ],
                'finance'    => [ 'nbp.pl', 'knf.gov.pl' ],
                'education'  => [ 'uw.edu.pl', 'uj.edu.pl', 'pw.edu.pl' ],
            ],
            'CZ' => [
                'news'   => [ 'ct24.ceskatelevize.cz', 'irozhlas.cz' ],
                'health' => [ 'mzcr.cz', 'szu.cz' ],
            ],
            'SK' => [
                'news'   => [ 'rtvs.sk', 'sme.sk' ],
                'health' => [ 'health.gov.sk', 'uvzsr.sk' ],
            ],
            'HU' => [
                'news'   => [ 'mtva.hu', 'hvg.hu', 'telex.hu' ],
                'health' => [ 'nnk.gov.hu', 'ogyei.gov.hu' ],
            ],
            'SI' => [
                'news'   => [ 'rtvslo.si', 'delo.si' ],
                'health' => [ 'gov.si', 'nijz.si' ],
            ],
            'HR' => [
                'news'   => [ 'hrt.hr', 'jutarnji.hr' ],
                'health' => [ 'zdravlje.gov.hr', 'hzjz.hr' ],
            ],
            'RS' => [
                'news'   => [ 'rts.rs', 'politika.rs' ],
                'health' => [ 'zdravlje.gov.rs', 'batut.org.rs' ],
            ],
            'BG' => [
                'news'   => [ 'bnt.bg', 'bnr.bg' ],
                'health' => [ 'mh.government.bg' ],
            ],
            'RO' => [
                'news'   => [ 'tvr.ro', 'agerpres.ro' ],
                'health' => [ 'ms.ro', 'insp.gov.ro' ],
            ],
            'UA' => [
                'news'   => [ 'suspilne.media', 'ukrinform.net', 'pravda.com.ua' ],
                'health' => [ 'moz.gov.ua', 'phc.org.ua' ],
            ],
            'TR' => [
                'news'       => [ 'trt.net.tr', 'aa.com.tr', 'hurriyet.com.tr' ],
                'health'     => [ 'saglik.gov.tr', 'titck.gov.tr' ],
                'animals'    => [ 'tarimorman.gov.tr', 'ankara.edu.tr', 'mindiampets.com.au' ],
                'finance'    => [ 'tcmb.gov.tr', 'spk.gov.tr' ],
                'education'  => [ 'ankara.edu.tr', 'boun.edu.tr', 'metu.edu.tr' ],
            ],
            'RU' => [
                'news'   => [ 'tass.ru', 'rbc.ru', 'interfax.ru' ],
                'health' => [ 'minzdrav.gov.ru', 'rospotrebnadzor.ru' ],
            ],
            // ── Asia ──
            'KR' => [
                'news'       => [ 'kbs.co.kr', 'yonhapnews.co.kr', 'hani.co.kr' ],
                'health'     => [ 'mohw.go.kr', 'kdca.go.kr', 'mfds.go.kr' ],
                'animals'    => [ 'animal.go.kr', 'snu.ac.kr', 'mindiampets.com.au' ],
                'finance'    => [ 'bok.or.kr', 'fsc.go.kr' ],
                'education'  => [ 'snu.ac.kr', 'kaist.ac.kr', 'yonsei.ac.kr' ],
            ],
            'CN' => [
                'news'       => [ 'xinhuanet.com', 'chinadaily.com.cn', 'people.com.cn' ],
                'health'     => [ 'nhc.gov.cn', 'chinacdc.cn', 'nmpa.gov.cn' ],
                'animals'    => [ 'moa.gov.cn', 'cau.edu.cn', 'mindiampets.com.au' ],
                'finance'    => [ 'pbc.gov.cn', 'csrc.gov.cn' ],
                'education'  => [ 'pku.edu.cn', 'tsinghua.edu.cn', 'fudan.edu.cn' ],
            ],
            'TW' => [
                'news'      => [ 'pts.org.tw', 'cna.com.tw' ],
                'health'    => [ 'mohw.gov.tw', 'cdc.gov.tw', 'fda.gov.tw' ],
                'education' => [ 'ntu.edu.tw', 'ncku.edu.tw' ],
            ],
            'MY' => [
                'news'      => [ 'bernama.com', 'nst.com.my', 'thestar.com.my' ],
                'health'    => [ 'moh.gov.my', 'npra.gov.my' ],
                'education' => [ 'um.edu.my', 'usm.my' ],
            ],
            'ID' => [
                'news'       => [ 'antaranews.com', 'kompas.com', 'tempo.co' ],
                'health'     => [ 'kemkes.go.id', 'pom.go.id' ],
                'animals'    => [ 'pertanian.go.id', 'ipb.ac.id', 'mindiampets.com.au' ],
                'finance'    => [ 'bi.go.id', 'ojk.go.id' ],
                'education'  => [ 'ui.ac.id', 'ugm.ac.id', 'itb.ac.id' ],
            ],
            'PH' => [
                'news'      => [ 'pna.gov.ph', 'gmanetwork.com', 'inquirer.net' ],
                'health'    => [ 'doh.gov.ph', 'fda.gov.ph' ],
                'education' => [ 'up.edu.ph', 'dlsu.edu.ph' ],
            ],
            'TH' => [
                'news'      => [ 'thaipbs.or.th', 'bangkokpost.com' ],
                'health'    => [ 'moph.go.th', 'fda.moph.go.th' ],
                'education' => [ 'chula.ac.th', 'mahidol.ac.th' ],
            ],
            'VN' => [
                'news'   => [ 'vtv.vn', 'vietnamnet.vn', 'nhandan.vn' ],
                'health' => [ 'moh.gov.vn', 'vncdc.gov.vn' ],
            ],
            'PK' => [
                'news'      => [ 'ptv.gov.pk', 'dawn.com', 'geo.tv' ],
                'health'    => [ 'nhsrc.gov.pk', 'dra.gov.pk' ],
                'education' => [ 'qau.edu.pk', 'lums.edu.pk' ],
            ],
            'BD' => [
                'news'   => [ 'bssnews.net', 'thedailystar.net', 'prothomalo.com' ],
                'health' => [ 'dghs.gov.bd', 'mohfw.gov.bd' ],
            ],
            'LK' => [
                'news'   => [ 'dailynews.lk', 'island.lk' ],
                'health' => [ 'health.gov.lk', 'nmra.gov.lk' ],
            ],
            'NP' => [
                'news'   => [ 'kathmandupost.com', 'risingnepaldaily.com' ],
                'health' => [ 'mohp.gov.np', 'dda.gov.np' ],
            ],
            'KZ' => [
                'news'   => [ 'inform.kz', 'kazinform.kz' ],
                'health' => [ 'gov.kz', 'rcrz.kz' ],
            ],
            // ── Middle East ──
            'IL' => [
                'news'       => [ 'kan.org.il', 'haaretz.com', 'timesofisrael.com' ],
                'health'     => [ 'health.gov.il', 'weizmann.ac.il' ],
                'animals'    => [ 'moag.gov.il', 'mindiampets.com.au' ],
                'finance'    => [ 'boi.org.il', 'isa.gov.il' ],
                'education'  => [ 'huji.ac.il', 'technion.ac.il', 'tau.ac.il' ],
            ],
            'AE' => [
                'news'       => [ 'wam.ae', 'thenationalnews.com', 'gulfnews.com' ],
                'health'     => [ 'mohap.gov.ae', 'dha.gov.ae' ],
                'finance'    => [ 'centralbank.ae', 'sca.gov.ae' ],
            ],
            'SA' => [
                'news'       => [ 'spa.gov.sa', 'arabnews.com' ],
                'health'     => [ 'moh.gov.sa', 'sfda.gov.sa' ],
                'finance'    => [ 'sama.gov.sa', 'cma.org.sa' ],
            ],
            'QA' => [
                'news'   => [ 'aljazeera.com', 'qna.org.qa' ],
                'health' => [ 'moph.gov.qa', 'phcc.gov.qa' ],
            ],
            'EG' => [
                'news'       => [ 'sis.gov.eg', 'ahram.org.eg', 'egypttoday.com' ],
                'health'     => [ 'mohp.gov.eg' ],
                'animals'    => [ 'govs.gov.eg', 'cu.edu.eg', 'mindiampets.com.au' ],
                'finance'    => [ 'cbe.org.eg', 'fra.gov.eg' ],
                'education'  => [ 'cu.edu.eg', 'aucegypt.edu' ],
            ],
            'JO' => [
                'news'   => [ 'petra.gov.jo', 'jordantimes.com' ],
                'health' => [ 'moh.gov.jo', 'jfda.jo' ],
            ],
            // ── Latin America ──
            'BR' => [
                'news'       => [ 'agenciabrasil.ebc.com.br', 'folha.uol.com.br', 'estadao.com.br' ],
                'health'     => [ 'saude.gov.br', 'anvisa.gov.br', 'fiocruz.br' ],
                'animals'    => [ 'embrapa.br', 'usp.br', 'mindiampets.com.au' ],
                'finance'    => [ 'bcb.gov.br', 'cvm.gov.br' ],
                'education'  => [ 'usp.br', 'unicamp.br', 'ufrj.br' ],
            ],
            'AR' => [
                'news'       => [ 'telam.com.ar', 'lanacion.com.ar' ],
                'health'     => [ 'argentina.gob.ar', 'anmat.gob.ar' ],
                'animals'    => [ 'senasa.gob.ar', 'uba.ar', 'mindiampets.com.au' ],
                'finance'    => [ 'bcra.gob.ar', 'cnv.gob.ar' ],
                'education'  => [ 'uba.ar', 'unlp.edu.ar' ],
            ],
            'CL' => [
                'news'      => [ 'tvn.cl', 'biobiochile.cl', 'latercera.com' ],
                'health'    => [ 'minsal.cl', 'ispch.cl' ],
                'finance'   => [ 'bcentral.cl', 'cmfchile.cl' ],
                'education' => [ 'uchile.cl', 'uc.cl' ],
            ],
            'CO' => [
                'news'      => [ 'eltiempo.com', 'elespectador.com' ],
                'health'    => [ 'minsalud.gov.co', 'invima.gov.co' ],
                'finance'   => [ 'banrep.gov.co', 'superfinanciera.gov.co' ],
                'education' => [ 'unal.edu.co', 'uniandes.edu.co' ],
            ],
            'PE' => [
                'news'      => [ 'andina.pe', 'elperuano.pe' ],
                'health'    => [ 'gob.pe', 'ins.gob.pe' ],
                'education' => [ 'unmsm.edu.pe', 'pucp.edu.pe' ],
            ],
            'CR' => [
                'news'   => [ 'nacion.com', 'crhoy.com' ],
                'health' => [ 'ministeriodesalud.go.cr', 'ccss.sa.cr' ],
            ],
            'DO' => [
                'news'   => [ 'diariolibre.com', 'listindiario.com' ],
                'health' => [ 'msp.gob.do', 'sns.gob.do' ],
            ],
            // ── Africa ──
            'ZA' => [
                'news'       => [ 'sabc.co.za', 'news24.com', 'dailymaverick.co.za' ],
                'health'     => [ 'health.gov.za', 'sahpra.org.za', 'nicd.ac.za' ],
                'animals'    => [ 'nspca.co.za', 'up.ac.za', 'dalrrd.gov.za', 'mindiampets.com.au' ],
                'finance'    => [ 'resbank.co.za', 'treasury.gov.za' ],
                'education'  => [ 'uct.ac.za', 'wits.ac.za', 'up.ac.za' ],
            ],
            'NG' => [
                'news'       => [ 'premiumtimesng.com', 'punchng.com', 'guardian.ng' ],
                'health'     => [ 'health.gov.ng', 'nafdac.gov.ng', 'ncdc.gov.ng' ],
                'animals'    => [ 'fmard.gov.ng', 'unn.edu.ng', 'mindiampets.com.au' ],
                'finance'    => [ 'cbn.gov.ng', 'sec.gov.ng' ],
                'education'  => [ 'unilag.edu.ng', 'ui.edu.ng' ],
            ],
            'KE' => [
                'news'       => [ 'nation.africa', 'standardmedia.co.ke', 'the-star.co.ke' ],
                'health'     => [ 'health.go.ke', 'kemri.go.ke' ],
                'animals'    => [ 'kws.go.ke', 'uonbi.ac.ke', 'mindiampets.com.au' ],
                'finance'    => [ 'centralbank.go.ke', 'treasury.go.ke' ],
                'education'  => [ 'uonbi.ac.ke', 'ku.ac.ke' ],
            ],
            'GH' => [
                'news'      => [ 'graphic.com.gh', 'myjoyonline.com' ],
                'health'    => [ 'ghs.gov.gh', 'fdaghana.gov.gh' ],
                'education' => [ 'ug.edu.gh', 'knust.edu.gh' ],
            ],
            'MA' => [
                'news'   => [ 'mapnews.ma', 'hespress.com' ],
                'health' => [ 'sante.gov.ma' ],
            ],
            'TZ' => [
                'news'   => [ 'dailynews.co.tz', 'thecitizen.co.tz' ],
                'health' => [ 'moh.go.tz', 'tmda.go.tz' ],
            ],
            'UG' => [
                'news'   => [ 'monitor.co.ug', 'newvision.co.ug' ],
                'health' => [ 'health.go.ug', 'nda.or.ug' ],
            ],
            'RW' => [
                'news'   => [ 'newtimes.co.rw', 'ktpress.rw' ],
                'health' => [ 'moh.gov.rw', 'rbc.gov.rw' ],
            ],
            'SN' => [
                'news'   => [ 'aps.sn', 'lesoleil.sn' ],
                'health' => [ 'sante.gouv.sn' ],
            ],
        ];

        // Merge: global category domains + country-specific domains
        $result = $global[ $domain ] ?? [];
        $country_upper = strtoupper( $country );
        if ( isset( $by_country[ $country_upper ][ $domain ] ) ) {
            $result = array_merge( $by_country[ $country_upper ][ $domain ], $result );
        }

        // If no specific match, try general/news for the country
        if ( empty( $result ) && isset( $by_country[ $country_upper ]['news'] ) ) {
            $result = $by_country[ $country_upper ]['news'];
        }

        return array_unique( $result );
    }

    public static function tavily_search_and_extract( string $keyword, string $domain = '', string $country = '' ): array {
        $settings = get_option( 'seobetter_settings', [] );
        $api_key = $settings['tavily_api_key'] ?? '';
        if ( empty( $api_key ) ) {
            return [ 'results' => [], 'quotes' => [] ];
        }

        // v1.5.107 — Authority domain targeting per category + country.
        // Restricts to non-commercial sources: govt, uni, research, journalism.
        // Falls back to unrestricted if filtered returns < 2 results.
        $authority_domains = self::get_authority_domains( $domain, $country );
        $tavily_body = [
            'api_key'            => $api_key,
            'query'              => $keyword . ' expert opinion research',
            'include_raw_content' => true,
            'max_results'        => 5,
            'search_depth'       => 'basic',
        ];
        if ( ! empty( $authority_domains ) ) {
            $tavily_body['include_domains'] = $authority_domains;
        }

        $response = wp_remote_post( 'https://api.tavily.com/search', [
            'timeout' => 20,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $tavily_body ),
        ] );

        // v1.5.112 — Two-level fallback for authority domain search:
        // Level 1: retry with simpler query (just keyword) but KEEP authority domains
        // Level 2: if STILL < 2 results, remove domain restriction entirely
        //          (the substantive + e-commerce + keyword-token filters protect
        //          against junk — "mattressmiracle.ca" content won't match "dogs"
        //          or "arthritic" keyword tokens, so it's filtered out naturally)
        if ( ! is_wp_error( $response ) && ! empty( $authority_domains ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $body['results'] ) || count( $body['results'] ) < 2 ) {
                // Level 1: simpler query, same authority domains
                $tavily_body['query'] = $keyword;
                $response = wp_remote_post( 'https://api.tavily.com/search', [
                    'timeout' => 20,
                    'headers' => [ 'Content-Type' => 'application/json' ],
                    'body'    => wp_json_encode( $tavily_body ),
                ] );
                // Level 2: if still < 2, remove domain restriction
                if ( ! is_wp_error( $response ) ) {
                    $body2 = json_decode( wp_remote_retrieve_body( $response ), true );
                    if ( empty( $body2['results'] ) || count( $body2['results'] ) < 2 ) {
                        unset( $tavily_body['include_domains'] );
                        $tavily_body['query'] = $keyword . ' expert guide review';
                        $response = wp_remote_post( 'https://api.tavily.com/search', [
                            'timeout' => 20,
                            'headers' => [ 'Content-Type' => 'application/json' ],
                            'body'    => wp_json_encode( $tavily_body ),
                        ] );
                    }
                }
            }
        }

        if ( is_wp_error( $response ) ) {
            return [ 'results' => [], 'quotes' => [], 'error' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['results'] ) ) {
            return [ 'results' => [], 'quotes' => [] ];
        }

        // Build results array (for citations/sources)
        $results = [];
        foreach ( $body['results'] as $r ) {
            if ( empty( $r['url'] ) ) continue;
            $results[] = [
                'url'         => $r['url'],
                'title'       => $r['title'] ?? '',
                'source_name' => wp_parse_url( $r['url'], PHP_URL_HOST ) ?? '',
            ];
        }

        // Extract REAL quotes from raw_content — no AI, just text extraction
        $quotes = [];
        $key_tokens = array_filter(
            preg_split( '/\s+/', strtolower( $keyword ) ),
            fn( $t ) => strlen( $t ) >= 4
        );
        $seen_texts = [];

        foreach ( $body['results'] as $r ) {
            $raw = $r['raw_content'] ?? '';
            if ( strlen( $raw ) < 500 ) continue;

            $url = $r['url'] ?? '';
            $host = preg_replace( '/^www\./', '', wp_parse_url( $url, PHP_URL_HOST ) ?? '' );

            // Clean markdown artifacts
            $clean = preg_replace( '/\[[^\]]*\]\([^)]*\)/', '', $raw );
            $clean = preg_replace( '/[*_#>]/', '', $clean );
            $clean = preg_replace( '/\s+/', ' ', $clean );

            // Extract sentences (40-220 chars, starts with capital)
            preg_match_all( '/[A-Z][^.!?]{38,218}[.!?]/', substr( $clean, 300 ), $matches );
            $page_count = 0;

            foreach ( ( $matches[0] ?? [] ) as $sentence ) {
                $lower = strtolower( $sentence );
                // Require 2+ keyword tokens (or 1 if <3 tokens)
                $min_tokens = count( $key_tokens ) >= 3 ? 2 : 1;
                $match_count = 0;
                foreach ( $key_tokens as $t ) {
                    if ( strpos( $lower, $t ) !== false ) $match_count++;
                }
                if ( $match_count < $min_tokens ) continue;

                // Skip junk (nav/cookie/UI text)
                if ( preg_match( '/cookie|privacy|subscribe|menu|click|log in|sign up|copyright|read more|img|src=|cdn\.|favicon|breadcrumb/i', $sentence ) ) continue;

                // v1.5.100 — Skip product listing / e-commerce text.
                // Without this, product keywords pull "Regular price €158,95"
                // and Amazon product titles as "expert quotes".
                if ( preg_match( '/[\$€£¥]\s*\d|regular\s*price|sale\s*price|add\s*to\s*cart|buy\s*now|free\s*shipping|in\s*stock|out\s*of\s*stock|add\s*to\s*wishlist|save\s*\d+%|was\s*\$|now\s*\$|shop\s*now|view\s*product|checkout|coupon|discount\s*code|promo\s*code/i', $sentence ) ) continue;

                $trimmed = trim( $sentence );
                if ( strlen( $trimmed ) < 40 || strlen( $trimmed ) > 220 ) continue;

                // v1.5.105 — Require substantive claim/opinion language.
                // Rejects taglines and meta descriptions in favor of expert statements.
                //
                // v1.5.216.62.45 — vocabulary expansion. v62.44 added the
                // productivity / workplace / business / tech / news / finance
                // / education / sustainability vocabulary to the inject_quotes()
                // outer-filter regex but missed THIS inner regex that filters
                // sentences during Tavily extraction itself. With the inner
                // regex still pet-vocabulary-only, every extracted productivity
                // sentence was dropped here BEFORE counting → diagnostic
                // showed `Tavily raw=0` for non-pet topics. Both regexes must
                // stay in sync.
                if ( ! preg_match( '/\b(recommend|found|study|studies|research|important|risk|benefit|help|cause|prevent|improve|according|evidence|expert|veterinar|nutriti|health|safe|danger|effective|suggest|show|report|associat|linked|common|require|diet|ingredien|allerg|deficien|formul|diagnos|productiv|focus|habit|wellness|wellbeing|workplace|burnout|stress|engagement|motivation|workflow|efficien|career|hire|employ|salary|wages|management|leadership|workforce|remote|hybrid|economi|market|invest|growth|revenue|profit|trend|consumer|user|customer|technolog|softwar|platform|algorithm|polic|regulat|govern|election|vote|reform|sustain|climate|emission|energy|innovat|teach|learn|skill|train|develop|cogniti|behav)\b/i', $trimmed ) ) continue;

                // Dedupe
                $key = strtolower( substr( $trimmed, 0, 40 ) );
                if ( isset( $seen_texts[ $key ] ) ) continue;
                $seen_texts[ $key ] = true;

                $quotes[] = [
                    'text'   => $trimmed,
                    'source' => $host,
                    'url'    => $url,
                ];

                $page_count++;
                if ( $page_count >= 2 ) break;
            }
            if ( count( $quotes ) >= 5 ) break;
        }

        return [
            'results' => $results,
            'quotes'  => array_slice( $quotes, 0, 5 ),
        ];
    }

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
    /**
     * v1.5.80 — made public + cached. Every inject button calls this,
     * not just optimize_all. 5-minute transient cache means the first
     * button click hits Sonar, subsequent clicks reuse the result.
     */
    public static function call_sonar_research( string $keyword ): ?array {
        // Check cache first (5 min TTL — covers multiple button clicks)
        $cache_key = 'seobetter_sonar_' . md5( strtolower( trim( $keyword ) ) );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

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
        $result = [
            'citations'  => is_array( $parsed['citations'] ?? null ) ? $parsed['citations'] : [],
            'quotes'     => is_array( $parsed['quotes'] ?? null ) ? $parsed['quotes'] : [],
            'statistics' => is_array( $parsed['statistics'] ?? null ) ? $parsed['statistics'] : [],
            'table_data' => is_array( $parsed['table_data'] ?? null ) ? $parsed['table_data'] : [],
        ];

        // Cache for 5 minutes — covers multiple button clicks on the same article
        set_transient( $cache_key, $result, 300 );

        return $result;
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
        array  $scores = [],
        ?array $sonar_data = null,
        string $domain = '',
        string $country = '',
        string $optimize_mode = 'full',
        string $language = 'en'
    ): array {
        // v1.5.99 — increased from 120 to 300.
        @set_time_limit( 300 );

        $steps_run     = [];
        $steps_skipped = [];
        // v1.5.141 — citations_only mode skips quotes, stats, tables, FAQ
        $citations_only = ( $optimize_mode === 'citations_only' );
        $sonar_used    = false;

        // ---- Step 0: Sonar research data ----
        // v1.5.113c — Re-fetch if Vercel returned empty shell (0 quotes, 0 citations, 0 stats).
        // Previously only re-fetched if $sonar_data === null. But the Vercel backend often
        // returns a valid structure with all-empty arrays, which skips the re-fetch.
        // The PHP-side Sonar call uses the user's OpenRouter key and always returns data.
        $sonar = $sonar_data;
        $sonar_empty = ( $sonar === null
            || ( empty( $sonar['quotes'] ) && empty( $sonar['citations'] ) && empty( $sonar['statistics'] ) ) );
        if ( $sonar_empty ) {
            $sonar = self::call_sonar_research( $keyword );
        }
        if ( $sonar !== null ) {
            $sonar_used = true;
        }

        // ---- Step 1: Citations ----
        // v1.5.147 — In citations_only mode, always run citations regardless of score
        $cit_score = $scores['citations']['score'] ?? 0;
        if ( $cit_score < 80 || $citations_only ) {
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
                    $steps_run[] = 'Citations: ' . ( $result['added'] ?? 'added' );
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
        // v1.5.141 — Skipped in citations_only mode (News, Case Study, Tech, etc.)
        $markdown = self::strip_unlinked_quotes( $markdown );

        if ( ! $citations_only ) {
            try {
                $result = self::inject_quotes( $markdown, $keyword, $sonar, $domain, $country );
                if ( $result['success'] ) {
                    $markdown = $result['content'];
                    $steps_run[] = 'Expert Quotes: ' . ( $result['added'] ?? 'added' );
                } else {
                    $steps_skipped[] = 'quotes: ' . ( $result['error'] ?? 'no verifiable quotes found' );
                }
            } catch ( \Throwable $e ) {
                $steps_skipped[] = 'quotes: ' . $e->getMessage();
            }
        } else {
            $steps_skipped[] = 'quotes: skipped (citations-only mode)';
        }

        // ---- Step 3: Statistics ----
        // v1.5.141 — Skipped in citations_only mode
        $stat_score = $scores['factual_density']['score'] ?? 0;
        if ( $stat_score < 70 && ! $citations_only ) {
            try {
                if ( $sonar && ! empty( $sonar['statistics'] ) ) {
                    // v1.5.105 — Filter out irrelevant junk stats before insertion.
                    // NagerDate holidays, NumberFacts, Quotable random quotes, and
                    // other filler APIs produce stats like "Upcoming US holiday:
                    // Truman Day" which have NOTHING to do with the article topic.
                    $filtered_stats = array_filter( $sonar['statistics'], function( $s ) {
                        $s = (string) $s;
                        // Skip holiday/calendar data
                        if ( preg_match( '/holiday|Nager\.Date|Truman|Memorial Day|Juneteenth|Independence Day|Christmas|Easter|Thanksgiving/i', $s ) ) return false;
                        // Skip random number/trivia facts
                        if ( preg_match( '/Numbers? API|Number fact:|random fact|Open Trivia/i', $s ) ) return false;
                        // Skip random famous quotes (not topic-relevant)
                        if ( preg_match( '/Quotable,|\(Quotable\)/i', $s ) ) return false;
                        // Skip zoo/animal fun facts that are filler
                        if ( preg_match( '/Zoo Animals API|Dog Facts API|Cat Facts|MeowFacts/i', $s ) ) return false;
                        // v1.5.137 — Skip Crossref academic citation counts and government gazette junk
                        if ( preg_match( '/cited \d+ times|doi\.org|Crossref,|Government Gazette|Annual Report \d{4}/i', $s ) ) return false;
                        // Must be at least 20 chars (not just a number)
                        if ( strlen( trim( $s ) ) < 20 ) return false;
                        return true;
                    } );

                    $stat_block = "\n\n**Key Statistics:**\n";
                    foreach ( array_slice( array_values( $filtered_stats ), 0, 4 ) as $stat ) {
                        $stat_block .= "- " . trim( (string) $stat ) . "\n";
                    }
                    $stat_block .= "\n";
                    $injected = preg_replace(
                        '/(## (?!Key Takeaway|FAQ|Frequently|Reference|Recipe \d|Ingredient|Instruction|Direction|Storage|Method)[^\n]+\n(?:[^\n]+\n){1,2})/',
                        '$1' . $stat_block,
                        $markdown,
                        1
                    );
                    if ( $injected !== $markdown ) {
                        $markdown = $injected;
                        $steps_run[] = 'Statistics: ' . count( array_slice( $sonar['statistics'], 0, 4 ) ) . ' real stats added from Sonar';
                    } else {
                        $steps_skipped[] = 'statistics: could not find insertion point';
                    }
                } else {
                    $result = self::inject_statistics( $markdown, $keyword );
                    if ( $result['success'] ) {
                        $markdown = $result['content'];
                        $steps_run[] = 'Statistics: ' . ( $result['added'] ?? 'added' );
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
        // v1.5.141 — Skipped in citations_only mode
        $table_score = $scores['tables']['score'] ?? 0;
        if ( $table_score < 50 && ! $citations_only ) {
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
                    $table_cols = count( $cols );
                    $table_rows_count = count( array_slice( $rows, 0, 6 ) );
                    if ( preg_match( '/(\n## (?:FAQ|Frequently|Reference)[^\n]*\n)/i', $markdown, $m, PREG_OFFSET_CAPTURE ) ) {
                        $markdown = substr( $markdown, 0, $m[1][1] ) . "\n" . $table . "\n" . substr( $markdown, $m[1][1] );
                        $steps_run[] = 'Comparison Table: ' . $table_rows_count . ' rows × ' . $table_cols . ' columns (real data from Sonar)';
                    } else {
                        $injected = preg_replace(
                            '/(## (?!Key Takeaway|FAQ|Frequently|Reference|Recipe \d|Ingredient|Instruction|Direction|Storage|Method)[^\n]+\n(?:[^\n]+\n){1,3})/',
                            '$1' . "\n" . $table . "\n\n",
                            $markdown,
                            1
                        );
                        if ( $injected !== $markdown ) {
                            $markdown = $injected;
                            $steps_run[] = 'Comparison Table: ' . $table_rows_count . ' rows × ' . $table_cols . ' columns (real data from Sonar)';
                        } else {
                            $steps_skipped[] = 'table: no insertion point';
                        }
                    }
                } else {
                    $result = self::inject_table( $markdown, $keyword );
                    if ( $result['success'] ) {
                        $markdown = $result['content'];
                        $steps_run[] = 'Comparison Table: ' . ( $result['added'] ?? 'inserted' );
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

        // ---- Step 4b: Freshness Signal ----
        $fresh_score = $scores['freshness']['score'] ?? 0;
        if ( $fresh_score < 100 ) {
            // v1.5.206d — pass article language so non-English articles get
            // localized "Last Updated" label + localized month/year format.
            $result = self::inject_freshness( $markdown, $language );
            if ( $result['success'] && $result['content'] !== $markdown ) {
                $markdown = $result['content'];
                $steps_run[] = 'Freshness: Added "Last Updated" date';
            }
        }

        // ---- Step 4c: FAQ Section (if missing) ----
        // v1.5.141 — Skipped in citations_only mode
        $has_faq = preg_match( '/##\s*(FAQ|Frequently\s*Asked)/i', $markdown );
        if ( ! $has_faq && ! $citations_only ) {
            try {
                $faq_block = "\n\n## Frequently Asked Questions\n\n";
                if ( $sonar && ! empty( $sonar['faq'] ) ) {
                    // Use Sonar-provided FAQ data if available
                    foreach ( array_slice( $sonar['faq'], 0, 4 ) as $faq ) {
                        $q = trim( $faq['question'] ?? '' );
                        $a = trim( $faq['answer'] ?? '' );
                        if ( $q && $a ) {
                            $faq_block .= "### {$q}\n\n{$a}\n\n";
                        }
                    }
                }

                // If Sonar didn't provide FAQ data, generate from keyword
                if ( substr_count( $faq_block, '###' ) < 3 ) {
                    $kw = $keyword;
                    $faq_block = "\n\n## Frequently Asked Questions\n\n";
                    $faq_block .= "### What should you look for in a {$kw}?\n\n";
                    $faq_block .= "Look for quality materials, good reviews, and features that match your specific needs. Check independent review sites for unbiased opinions.\n\n";
                    $faq_block .= "### How much does a {$kw} cost?\n\n";
                    $faq_block .= "Prices vary widely depending on brand, materials, and features. Budget options start around \$20-30, while premium options can reach \$100 or more.\n\n";
                    $faq_block .= "### Is it worth buying a premium {$kw}?\n\n";
                    $faq_block .= "Premium options often last longer and perform better. Consider your usage frequency and needs before deciding. Sometimes a mid-range option offers the best value.\n\n";
                }

                // Insert before References section if it exists, otherwise append
                if ( preg_match( '/\n## References\b/i', $markdown ) ) {
                    $markdown = preg_replace( '/\n(## References\b)/i', $faq_block . "\n$1", $markdown, 1 );
                } else {
                    $markdown .= $faq_block;
                }
                $steps_run[] = 'FAQ: Added FAQ section (CORE-EEAT C05)';
            } catch ( \Throwable $e ) {
                $steps_skipped[] = 'faq: ' . $e->getMessage();
            }
        } else {
            $steps_skipped[] = 'faq: already present';
        }

        // ---- Step 5: Simplify Readability (AI rewrite) ----
        // v1.5.152 — Skipped in citations_only mode. Also runs BEFORE citations
        // in full mode would be ideal, but changing step order is risky. Instead,
        // after readability rewrite, re-run linkify to restore any stripped links.
        $read_score = $scores['readability']['score'] ?? 0;
        if ( $read_score < 70 && ! $citations_only ) {
            try {
                $result = self::simplify_readability( $markdown );
                if ( $result['success'] ) {
                    $markdown = $result['content'];
                    $steps_run[] = 'Readability: ' . ( $result['added'] ?? 'simplified' );
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
                    $steps_run[] = 'Keyword Density: ' . $current_density . '% → ' . ( $result['density_after'] ?? '?' ) . '%';
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
                ,
        ];
    }
}
