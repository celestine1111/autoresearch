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
 *
 * Added in v1.5.11 (guideline §5A, §4B, §15B integration):
 * - Keyword density check (0.5-1.5% target, per SEO plugin compatibility)
 * - Banned-word / humanizer post-check (Tier 1 + Tier 2 AI word detection)
 * - CORE-EEAT lite scoring (top 10 items from the 80-item rubric)
 */
class GEO_Analyzer {

    private const TARGET_FLESCH_GRADE = 7;
    private const SECTION_WORD_MIN    = 25;
    private const SECTION_WORD_MAX    = 75;
    private const STATS_PER_1000      = 3;

    /** Tier 1 banned words — immediate AI red flags (SEO-GEO-AI-GUIDELINES §4B). */
    private const TIER1_BANNED_WORDS = [
        'delve', 'tapestry', 'landscape', 'paradigm', 'leverage', 'harness',
        'navigate', 'realm', 'embark', 'myriad', 'plethora', 'multifaceted',
        'groundbreaking', 'revolutionize', 'synergy', 'ecosystem', 'resonate',
        'streamline', 'testament', 'pivotal', 'cornerstone', 'game-changer',
        'nestled', 'breathtaking', 'stunning', 'seamless', 'vibrant', 'renowned',
    ];

    /** Tier 2 banned words — fine alone, 3+ in one article is a tell. */
    private const TIER2_BANNED_WORDS = [
        'robust', 'cutting-edge', 'innovative', 'comprehensive', 'nuanced',
        'compelling', 'transformative', 'bolster', 'underscore', 'evolving',
        'fostering', 'imperative', 'intricate', 'overarching', 'unprecedented',
        'profound', 'showcasing', 'garner', 'crucial', 'vital',
    ];

    /**
     * Run full GEO analysis on content.
     *
     * @param string $content      Post content (HTML).
     * @param string $keyword_or_title  Focus keyword (preferred) or post title (fallback).
     *                             Used for keyword density scoring in §5A.
     * @param string $content_type 21 content types affect which checks are relaxed.
     * @return array Analysis results with overall score and breakdown.
     */
    public function analyze( string $content, string $keyword_or_title = '', string $content_type = '', string $language = 'en', string $country = '' ): array {
        $text = wp_strip_all_tags( $content );
        $sections = $this->extract_sections( $content );
        // v1.5.206d — language-aware word count. CJK (ja/zh/ko) and Thai have
        // no inter-word spaces; str_word_count() returns 0 on them and every
        // downstream "word_count >= X" check fails. count_words_lang() uses
        // character-count heuristic (~2 chars per word) for those scripts
        // and falls back to str_word_count() for Latin-script languages.
        $word_count = self::count_words_lang( $text, $language );

        // v1.5.204 — §3.1A Genre Override gating for structural checks.
        //
        // Three checks (bluf_header, section_openings, freshness) were designed
        // against the §3.1 DEFAULT profile (blog_post / how_to / listicle / etc.)
        // which requires Key Takeaways + Last Updated + 40-60 word section openings.
        // Seven content types follow §3.1A GENRE OVERRIDES and legitimately skip
        // these structural elements by design — penalising them is unfair and was
        // producing artificially-low scores on correctly-crafted articles (notably
        // personal_essay scoring in the B/C range despite being research-backed
        // per NYT Modern Love, Longreads, Project Write Now, Google E-E-A-T 2025).
        //
        // Per-check skip lists (content types that skip by design):
        //
        //   bluf_header      — types without Key Takeaways:
        //                      news_article, press_release, personal_essay,
        //                      live_blog, interview, recipe
        //
        //   section_openings — types where "40-60 word direct answer" doesn't fit:
        //                      recipe, faq_page, live_blog, interview,
        //                      glossary_definition, personal_essay (v1.5.204 added)
        //
        //   freshness        — types that use dateline or genre-appropriate signal
        //                      instead of "Last Updated":
        //                      news_article, press_release, personal_essay,
        //                      live_blog, interview, recipe
        //
        // Skipped checks return score 100 with a detail string explaining why —
        // the type is NOT penalised; its structure is correctly genre-appropriate.
        // Opinion is NOT in any skip list (it is a HYBRID profile — keeps Key
        // Takeaways + FAQ + References per §3.1A row, so default checks apply).
        //
        // See SEO-GEO-AI-GUIDELINES.md §3.1A for the authoritative override table.
        $skip_bluf_types      = [ 'news_article', 'press_release', 'personal_essay', 'live_blog', 'interview', 'recipe' ];
        $skip_opener_types    = [ 'recipe', 'faq_page', 'live_blog', 'interview', 'glossary_definition', 'personal_essay' ];
        $skip_freshness_types = [ 'news_article', 'press_release', 'personal_essay', 'live_blog', 'interview', 'recipe' ];

        $skip_bluf      = in_array( $content_type, $skip_bluf_types, true );
        $skip_openers   = in_array( $content_type, $skip_opener_types, true );
        $skip_freshness = in_array( $content_type, $skip_freshness_types, true );

        $checks = [
            'readability'      => $this->check_readability( $text ),
            'bluf_header'      => $skip_bluf
                ? [ 'score' => 100, 'detail' => 'BLUF header check skipped — content type "' . $content_type . '" uses a §3.1A genre override (no Key Takeaways by design)' ]
                : $this->check_bluf_header( $content, $language ),
            'section_openings' => $skip_openers
                ? [ 'score' => 100, 'detail' => 'Section opener check skipped — content type "' . $content_type . '" uses a §3.1A genre override (section form does not fit 40-60 word direct-answer pattern)', 'sections' => [] ]
                : $this->check_section_openings( $sections, $language ),
            'island_test'      => $this->check_island_test( $text ),
            'factual_density'  => $this->check_factual_density( $text, $word_count ),
            // v1.5.72 — pass raw HTML ($content) not stripped text ($text).
            // v1.5.68 changed check_citations to count only real <a href> links,
            // but it was still receiving wp_strip_all_tags output which has NO
            // HTML tags. Citations was scoring 0 on every article. Same fix for
            // expert_quotes — needs HTML to find <blockquote> and styled quotes.
            'citations'        => $this->check_citations( $content ),
            'expert_quotes'    => $this->check_expert_quotes( $content ),
            'tables'           => $this->check_tables( $content ),
            'lists'            => $this->check_lists( $content ),
            'freshness'        => $skip_freshness
                ? [ 'score' => 100, 'detail' => 'Freshness signal check skipped — content type "' . $content_type . '" uses a dateline or genre-appropriate signal instead of "Last Updated" (§3.1A genre override)' ]
                : $this->check_freshness_signal( $content, $language ),
            'entity_usage'     => $this->check_entity_usage( $text ),
            // v1.5.11 additions — guideline §5A, §4B, §15B
            'keyword_density'  => $this->check_keyword_density( $content, $text, $keyword_or_title, $word_count ),
            'humanizer'        => $this->check_humanizer( $text, $word_count ),
            'core_eeat'        => $this->check_core_eeat( $content, $text ),
        ];

        // Weights sum to 100 — keyword_density is critical (SEO plugin compatibility),
        // humanizer is a quality signal, core_eeat is Google E-E-A-T alignment.
        // Reduced other weights proportionally to fit the 3 new checks.
        $weights = [
            'readability'      => 10,   // was 12
            'bluf_header'      => 8,    // was 10
            'section_openings' => 8,    // was 10
            'island_test'      => 8,    // was 10
            'factual_density'  => 10,   // was 12
            'citations'        => 10,   // was 12
            'expert_quotes'    => 6,    // was 8
            'tables'           => 5,    // was 6
            'lists'            => 4,    // was 5
            'freshness'        => 6,    // was 7
            'entity_usage'     => 6,    // was 8
            'keyword_density'  => 10,   // NEW — SEO plugin compatibility (§5A)
            'humanizer'        => 4,    // NEW — AI tell detection (§4B)
            'core_eeat'        => 5,    // NEW — E-E-A-T lite (§15B)
        ];

        // v1.5.206d — 15th check: International signals (Layer 6).
        // Country-gated + non-regressive: only added when country is set and
        // NOT a Western-default market. When active, adds 6% weight from a
        // new check without modifying the existing 14 weights — total grows
        // to 106 and normalisation happens in the weighted_score loop
        // (sum of weights becomes the divisor). Western-default / empty-country
        // articles are byte-identical to the pre-v1.5.206d scoring rubric.
        $western_default = [ 'US', 'GB', 'AU', 'CA', 'NZ', 'IE' ];
        $country_upper   = strtoupper( trim( $country ) );
        $international_active = $country_upper && ! in_array( $country_upper, $western_default, true );
        if ( $international_active ) {
            $checks['international_signals']  = $this->check_international_signals( $content, $language, $country_upper );
            $weights['international_signals'] = 6;
        }

        $weighted_score = 0;
        $total_weight = array_sum( $weights );
        foreach ( $checks as $key => $check ) {
            $weighted_score += $check['score'] * ( $weights[ $key ] / $total_weight );
        }

        $geo_score = round( $weighted_score );

        // v1.5.23 — Local-places grounding sentinel. Detects local-intent
        // keywords (e.g. "best gelato shops in Lucignano Italy") whose
        // generated article has no specific addresses or map URLs, indicating
        // the LLM invented businesses that don't exist. When this fires we
        // floor the geo_score at 40 so the user sees a red flag and
        // regenerates. Added to $checks but NOT in $weights — it's a
        // sentinel that caps the final score, not a proportional deduction.
        $local_places_check = $this->check_local_places_grounding( $content, $text, $keyword_or_title, $content_type );
        $checks['local_places'] = $local_places_check;
        if ( $local_places_check['score'] === 0 ) {
            // Catastrophic — cap the final score at 40 (F grade) so the user
            // is forced to regenerate instead of shipping a hallucinated article
            $geo_score = min( $geo_score, 40 );
        }

        return [
            'geo_score'  => $geo_score,
            'grade'      => $this->score_to_grade( $geo_score ),
            'word_count' => $word_count,
            'checks'     => $checks,
            'suggestions' => $this->generate_suggestions( $checks ),
        ];
    }

    /**
     * v1.5.23 — Local-places grounding sentinel check.
     *
     * Detects articles generated for local-intent keywords (e.g. "best
     * gelato shops in Lucignano Italy") that have no specific addresses
     * or map URLs — meaning the LLM probably invented businesses.
     *
     * Returns score 0 (catastrophic) if the sentinel fires, 100 otherwise.
     * When score is 0, the analyze() method floors the final geo_score at
     * 40 so the user sees a red flag and regenerates.
     *
     * @param string $content    The generated HTML/markdown
     * @param string $text       Plain-text version (wp_strip_all_tags)
     * @param string $keyword    The primary keyword used for generation
     * @param string $content_type The content type slug (listicle, buying_guide, etc)
     */
    private function check_local_places_grounding( string $content, string $text, string $keyword, string $content_type ): array {
        // Only applies to listicle / buying_guide / review content types
        // (the ones that typically name specific businesses)
        $risky_types = [ 'listicle', 'buying_guide', 'review', 'comparison' ];
        if ( ! in_array( $content_type, $risky_types, true ) ) {
            return [ 'score' => 100, 'detail' => 'Not a local-business listicle — sentinel does not apply' ];
        }

        // Detect local intent in the keyword — matches the same regexes used
        // in cloud-api/api/research.js::detectLocalIntent()
        $kw = trim( $keyword );
        $is_local = false;
        if ( preg_match( '/\bin\s+[A-Z][\w\s,\'-]+(?:\s+\d{4})?$/i', $kw ) ) $is_local = true;
        elseif ( preg_match( '/^(?:best|top|greatest|finest)\s+.+?\s+(?:in|near|around)\s+[A-Z]\w+/i', $kw ) ) $is_local = true;
        elseif ( preg_match( '/\b(?:near\s*me|nearby|local)\b/i', $kw ) ) $is_local = true;
        elseif ( preg_match( '/^(?:what\'?s?|which|where)\s+.*?(?:best|top).+?\s+(?:in|near|at)\s+[A-Z]\w+/i', $kw ) ) $is_local = true;

        if ( ! $is_local ) {
            return [ 'score' => 100, 'detail' => 'Keyword is not local-intent — sentinel does not apply' ];
        }

        // Local-intent article — check for real-world grounding markers
        // 1. OSM or Google Maps URLs (verified pool-sourced links)
        $has_map_urls = (bool) preg_match( '#(openstreetmap\.org|maps\.google\.com|goo\.gl/maps|google\.com/maps)#i', $content );

        // 2. Specific address markers (street types in multiple languages,
        //    postcodes, or explicit "address:" labels)
        $address_patterns = [
            '/\b\d+\s+[A-Z][a-z]+\s+(?:Street|St|Avenue|Ave|Road|Rd|Lane|Ln|Boulevard|Blvd|Drive|Dr|Way|Place|Pl)\b/i',
            '/\bVia\s+[A-Z]/', // Italian
            '/\bRue\s+[A-Z]/', // French
            '/\bCalle\s+[A-Z]/', // Spanish
            '/\b[A-Z][a-z]+straße\b/', // German
            '/\bPiazza\s+[A-Z]/', // Italian square
            '/\b\d{4,5}\s+[A-Z]/', // Postcode followed by city
            '/<strong>Address:<\/strong>/i',
            '/\baddress:\s*[A-Z0-9]/i',
        ];
        $has_addresses = false;
        foreach ( $address_patterns as $p ) {
            if ( preg_match( $p, $text ) ) {
                $has_addresses = true;
                break;
            }
        }

        // If it's a local-intent listicle with neither map URLs nor addresses,
        // the AI almost certainly fabricated the businesses.
        if ( ! $has_map_urls && ! $has_addresses ) {
            return [
                'score'  => 0,
                'detail' => 'CRITICAL: Local-intent listicle has no OpenStreetMap/Google Maps URLs and no specific addresses — the businesses may be fabricated. Regenerate the article; the cloud-api/research.js OSM Places lookup should populate REAL LOCAL PLACES data into the prompt. If this article is a legitimate listicle, add at least one verifiable map URL or street address per business.',
                'is_local' => true,
                'has_map_urls' => false,
                'has_addresses' => false,
            ];
        }

        return [
            'score'  => 100,
            'detail' => sprintf( 'Local-intent article grounded: %s %s', $has_map_urls ? 'has map URLs' : 'no map URLs', $has_addresses ? '+ has addresses' : '+ no addresses' ),
            'is_local' => true,
            'has_map_urls' => $has_map_urls,
            'has_addresses' => $has_addresses,
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
     * v1.5.206d — Language-aware word count helper.
     *
     * PHP's str_word_count() is Latin-script only and returns 0 for
     * Japanese/Chinese/Korean/Thai text (which has no inter-word spaces).
     * This helper uses a character-count heuristic for those scripts:
     * ~2 chars per word is the standard CJK approximation used by WP's
     * own wp_word_count (via WP_Multibyte_Patch) and major CJK editors.
     */
    public static function count_words_lang( string $text, string $language = 'en' ): int {
        $base = strtolower( substr( $language, 0, 2 ) );
        if ( in_array( $base, [ 'ja', 'zh', 'ko', 'th' ], true ) ) {
            $stripped = preg_replace( '/\s+/u', '', $text );
            $chars    = mb_strlen( $stripped ?? '' );
            return (int) round( $chars / 2 );
        }
        // v1.5.216.57 — language-agnostic Unicode counter for non-CJK scripts.
        // Without this, str_word_count() — which is locale-dependent and ASCII-
        // first — undercounts Cyrillic/Arabic/Hebrew/Greek/Devanagari posts.
        if ( preg_match_all( '/[\p{L}\p{N}]+/u', $text, $m ) ) {
            return count( $m[0] );
        }
        return str_word_count( $text );
    }

    /**
     * Check for BLUF (Bottom Line Up Front) header — Key Takeaways section.
     *
     * v1.5.206d — language-aware. When $language ≠ 'en', also accept the
     * localized Key Takeaways label (e.g. '重要なポイント' for Japanese,
     * '핵심 요약' for Korean, 'Ключевые выводы' for Russian, etc.) inside
     * the H2/H3 detection regex, not just the English patterns.
     */
    private function check_bluf_header( string $content, string $language = 'en' ): array {
        $has_bluf = (bool) preg_match( '/<h[2-3][^>]*>.*?(key\s*takeaway|summary|tldr|tl;dr|bottom\s*line)/is', $content );

        if ( ! $has_bluf && $language !== 'en' ) {
            $label = Localized_Strings::get( 'key_takeaways', $language );
            if ( $label ) {
                $escaped = preg_quote( $label, '/' );
                $has_bluf = (bool) preg_match( '/<h[2-3][^>]*>.*?' . $escaped . '/isu', $content );
            }
        }

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
     *
     * v1.5.206d — language-aware word count via count_words_lang() so
     * Japanese/Chinese/Korean/Thai sections are measured by character
     * heuristic instead of str_word_count() returning 0.
     */
    private function check_section_openings( array $sections, string $language = 'en' ): array {
        if ( empty( $sections ) ) {
            return [ 'score' => 50, 'detail' => 'No H2/H3 sections found', 'sections' => [] ];
        }

        $passing = 0;
        $details = [];
        foreach ( $sections as $section ) {
            $first_para = $section['first_paragraph'] ?? '';
            $wc = self::count_words_lang( $first_para, $language );
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
     * v1.5.68 — Check for REAL clickable citations (not plain-text
     * attribution patterns).
     *
     * Previous behavior counted patterns like `(Source, 2024)` and `[1]`
     * as citations which technically satisfied the GEO rubric but
     * HALLUCINATED the score. AIOSEO / Yoast / Google only count actual
     * `<a href>` elements. A 2000-word article with 11 plain-text
     * attributions and zero real links was showing Citations = 100
     * even though no user could click anything. User reported: "There
     * are still no citations anywhere... it is hallucinating as there
     * are no citations at all in article or at the footer."
     *
     * New counting:
     *   1. Real markdown links `[text](url)` — counted as citations (primary)
     *   2. Real HTML `<a href>` tags — counted as citations (primary)
     *   3. Plain-text attribution patterns `(Source, 2024)` — counted
     *      SEPARATELY and shown in the detail text for transparency but
     *      DO NOT contribute to the score
     *
     * Score is based ONLY on real links so the Analyze & Improve panel
     * correctly flags articles with no clickable sources.
     */
    private function check_citations( string $content ): array {
        // v1.5.72 — receives raw HTML ($content), not stripped text.
        // v1.5.75 — simplified and more robust detection. Counts any
        // <a href="http..."> in the HTML. Previous regex was too strict
        // with quote matching and failed on some esc_url() outputs.

        // Count HTML links — the primary signal. Match any <a with href containing http
        preg_match_all( '/href\s*=\s*["\']https?:\/\//i', $content, $html_links );
        $html_count = count( $html_links[0] );

        // Also count markdown links in case content is raw markdown
        preg_match_all( '/(?<!!)\[[^\]]+\]\(https?:\/\/[^)]+\)/', $content, $md_links );
        $md_count = count( $md_links[0] );

        $real_link_count = max( $md_count, $html_count );

        // Plain-text attribution patterns — diagnostic only, not scored.
        $text = wp_strip_all_tags( $content );
        preg_match_all( '/\[\d+\]|\([A-Z][^)]*\d{4}\)|\[[A-Z][^\]]*\d{4}\]/', $text, $plain_matches );
        $plain_count = count( $plain_matches[0] );

        $score = min( 100, $real_link_count * 20 ); // 5+ real links = 100

        $detail_parts = [];
        $detail_parts[] = sprintf( '%d clickable link%s', $real_link_count, $real_link_count === 1 ? '' : 's' );
        if ( $plain_count > 0 ) {
            $detail_parts[] = sprintf( '%d plain-text attribution%s (not counted — AIOSEO/Yoast need real <a href>)', $plain_count, $plain_count === 1 ? '' : 's' );
        }
        $detail_parts[] = 'target 5+';

        return [
            'score'           => $score,
            'count'           => $real_link_count, // UI reads this — only real links
            'plain_text_count' => $plain_count,     // diagnostic field
            'detail'          => implode( ', ', $detail_parts ),
        ];
    }

    /**
     * v1.5.72 — Check for expert quotes in HTML content.
     *
     * Now receives raw HTML (not stripped text) so it can detect:
     * 1. <blockquote> tags (injected by Content_Formatter for quotes)
     * 2. Smart-quoted text "..." (U+201C/U+201D — what AI models output)
     * 3. Straight-quoted text "..." (legacy)
     * 4. Attribution patterns: — Name, Title (em dash + proper noun)
     */
    private function check_expert_quotes( string $content ): array {
        $count = 0;

        // Count <blockquote> tags — Content_Formatter wraps expert quotes in these
        preg_match_all( '/<blockquote[\s>]/i', $content, $bq_matches );
        $count += count( $bq_matches[0] );

        // Count smart-quoted text (20+ chars) — what AI models actually output
        $text = wp_strip_all_tags( $content );
        preg_match_all( '/[\x{201C}"][^\x{201D}"]{20,}[\x{201D}"]/u', $text, $quote_matches );
        $count += count( $quote_matches[0] );

        // Dedupe: if blockquotes also have quoted text inside, cap the double-count
        $count = min( $count, max( count( $bq_matches[0] ), count( $quote_matches[0] ) ) * 2 );
        // But at minimum count the larger of the two
        $count = max( $count, count( $bq_matches[0] ), count( $quote_matches[0] ) );

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
     *
     * v1.5.206d — language-aware. When $language ≠ 'en', also accept the
     * localized "Last Updated" label (e.g. '最終更新日' for Japanese,
     * 'Последнее обновление' for Russian) — otherwise every international
     * article scores 0 on freshness even when Content_Injector correctly
     * prepended the translated label.
     */
    private function check_freshness_signal( string $content, string $language = 'en' ): array {
        $has_signal = (bool) preg_match( '/last\s*updated|date\s*modified|updated\s*on|published\s*on/i', $content );

        if ( ! $has_signal && $language !== 'en' ) {
            $label = Localized_Strings::get( 'last_updated', $language );
            if ( $label && mb_stripos( $content, $label ) !== false ) {
                $has_signal = true;
            }
        }

        return [
            'score'  => $has_signal ? 100 : 0,
            'detail' => $has_signal ? 'Freshness signal found' : 'No freshness signal (add "Last Updated: Month Year")',
        ];
    }

    /**
     * v1.5.206d — Layer 6 International Signals check (15th weighted check).
     *
     * Country-gated: only added to the rubric when the target country is
     * set and NOT a Western-default market (US/GB/AU/CA/NZ/IE). Western-
     * default articles never include this check — total weight stays 100.
     *
     * Scores 3 signals visible in the post content:
     *   1. Article language matches the target country's primary language
     *      (e.g. JP→ja, CN→zh, KR→ko, RU→ru, DE→de). If the user picked
     *      Target Country = Japan but kept Language = English, signal 1
     *      fails — that's valid guidance ("consider writing in Japanese for
     *      full Baidu/Yandex/Naver retrieval eligibility").
     *   2. Localized freshness label present — confirms the v1.5.206d
     *      i18n path fired (Content_Injector emitted the translated
     *      "Last Updated" label).
     *   3. At least one regional authority citation matching the target
     *      country — confirms the v1.5.206b whitelist let through a
     *      region-appropriate source (Baidu Baike, TASS, Naver Knowledge,
     *      etc.) or the ja.wikipedia.org / es.wikipedia.org family.
     *
     * Score: (signals_present / 3) × 100.
     */
    private function check_international_signals( string $content, string $language, string $country ): array {
        $signals = [];
        $country = strtoupper( trim( $country ) );

        // Signal 1: language matches country's primary language.
        $country_to_lang = [
            'CN' => 'zh', 'TW' => 'zh', 'HK' => 'zh',
            'JP' => 'ja',
            'KR' => 'ko',
            'RU' => 'ru', 'BY' => 'ru',
            'DE' => 'de', 'AT' => 'de', 'CH' => 'de',
            'FR' => 'fr', 'BE' => 'fr',
            'ES' => 'es',
            'IT' => 'it',
            'BR' => 'pt', 'PT' => 'pt',
            'IN' => 'hi',
            'SA' => 'ar', 'AE' => 'ar', 'EG' => 'ar',
            'MX' => 'es', 'AR' => 'es',
        ];
        $expected_lang = $country_to_lang[ $country ] ?? '';
        $base_lang     = strtolower( substr( $language, 0, 2 ) );
        $lang_match    = $expected_lang && $base_lang === $expected_lang;
        if ( $lang_match ) {
            $signals[] = 'language-match';
        }

        // Signal 2: localized freshness label in body (requires non-English language).
        if ( $language !== 'en' ) {
            $label = Localized_Strings::get( 'last_updated', $language );
            if ( $label && mb_stripos( $content, $label ) !== false ) {
                $signals[] = 'localized-freshness';
            }
        }

        // Signal 3: at least one regional citation domain in outbound URLs.
        $country_to_regional_hosts = [
            'CN' => [ 'baidu.com', 'zhihu.com', 'zh.wikipedia.org', 'people.com.cn', 'xinhuanet.com', 'chinadaily.com.cn', '36kr.com' ],
            'JP' => [ 'nhk.or.jp', 'asahi.com', 'mainichi.jp', 'nikkei.com', 'yomiuri.co.jp', 'ja.wikipedia.org', 'kotobank.jp', 'yahoo.co.jp', 'tabelog.com' ],
            'KR' => [ 'ko.wikipedia.org', 'naver.com', 'chosun.com', 'donga.com', 'hani.co.kr', 'joongang.co.kr', 'yna.co.kr' ],
            'RU' => [ 'ru.wikipedia.org', 'yandex.ru', 'ria.ru', 'tass.ru', 'rbc.ru', 'lenta.ru', 'habr.com' ],
            'DE' => [ 'de.wikipedia.org', 'spiegel.de', 'faz.net', 'zeit.de', 'sueddeutsche.de', 'welt.de', 'tagesschau.de' ],
            'AT' => [ 'de.wikipedia.org', 'derstandard.at', 'diepresse.com' ],
            'CH' => [ 'de.wikipedia.org', 'nzz.ch', 'srf.ch' ],
            'FR' => [ 'fr.wikipedia.org', 'lemonde.fr', 'lefigaro.fr', 'liberation.fr', 'leparisien.fr' ],
            'BE' => [ 'fr.wikipedia.org', 'lemonde.fr', 'lesoir.be' ],
            'ES' => [ 'es.wikipedia.org', 'elpais.com', 'elmundo.es' ],
            'IT' => [ 'it.wikipedia.org', 'corriere.it', 'repubblica.it', 'lastampa.it' ],
            'BR' => [ 'pt.wikipedia.org', 'globo.com', 'folha.uol.com.br', 'uol.com.br', 'estadao.com.br' ],
            'PT' => [ 'pt.wikipedia.org', 'publico.pt', 'expresso.pt' ],
            'IN' => [ 'hi.wikipedia.org', 'thehindu.com', 'indianexpress.com', 'timesofindia.indiatimes.com', 'ndtv.com' ],
            'SA' => [ 'ar.wikipedia.org', 'aljazeera.net', 'alarabiya.net' ],
            'AE' => [ 'ar.wikipedia.org', 'gulfnews.com', 'thenationalnews.com', 'aljazeera.net' ],
            'EG' => [ 'ar.wikipedia.org', 'aljazeera.net', 'alarabiya.net' ],
            'MX' => [ 'es.wikipedia.org', 'reforma.com', 'eluniversal.com.mx' ],
            'AR' => [ 'es.wikipedia.org', 'clarin.com', 'lanacion.com.ar', 'infobae.com' ],
        ];
        $regional_hosts = $country_to_regional_hosts[ $country ] ?? [];
        if ( $regional_hosts ) {
            preg_match_all( '/href\s*=\s*["\']https?:\/\/([^\/"\']+)/i', $content, $host_matches );
            $hosts = array_map( 'strtolower', $host_matches[1] ?? [] );
            foreach ( $hosts as $host ) {
                foreach ( $regional_hosts as $needle ) {
                    if ( $host === $needle || substr( $host, - ( strlen( $needle ) + 1 ) ) === '.' . $needle ) {
                        $signals[] = 'regional-citation';
                        break 2;
                    }
                }
            }
        }

        // Total possible signals: 3 (lang-match, localized-freshness, regional-citation).
        // Score proportionally.
        $score  = (int) round( ( count( $signals ) / 3 ) * 100 );
        $detail = sprintf(
            'Country=%s, language=%s. Signals present: %s',
            $country ?: '—',
            $language,
            empty( $signals ) ? 'none (regional citations / localized freshness / language-match missing)' : implode( ', ', $signals )
        );

        return [
            'score'   => $score,
            'signals' => $signals,
            'detail'  => $detail,
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
     * Keyword density check — guideline §5A.
     *
     * SEO plugins (AIOSEO, Yoast, RankMath) flag articles that don't hit their
     * keyword density windows. This check measures:
     *
     *   1. Primary keyword density (0.5%-1.5% target, 100 pts)
     *   2. Percentage of H2 headings containing the keyword (≥30% target)
     *   3. Keyword in first 150 chars (intro rule)
     *
     * All three are blended into a single score.
     */
    private function check_keyword_density( string $content, string $text, string $keyword, int $word_count ): array {
        $keyword = trim( (string) $keyword );
        if ( $keyword === '' || $word_count < 100 ) {
            return [
                'score'  => 0,
                'detail' => 'Keyword density not analyzed (no keyword or content too short)',
            ];
        }

        // Count exact-phrase occurrences (case-insensitive)
        $lower_text = strtolower( $text );
        $lower_kw   = strtolower( $keyword );
        $kw_count   = substr_count( $lower_text, $lower_kw );
        $density    = $word_count > 0 ? ( $kw_count / max( 1, $word_count / count( explode( ' ', $keyword ) ) ) ) * 100 : 0;

        // Proper density: (keyword occurrences × keyword word count) / total words × 100
        $kw_word_count = max( 1, str_word_count( $keyword ) );
        $density       = ( $kw_count * $kw_word_count / $word_count ) * 100;

        // Density component (0-100) — 100 at 0.5-1.5%, scales off outside
        if ( $density >= 0.5 && $density <= 1.5 ) {
            $density_score = 100;
        } elseif ( $density > 0 && $density < 0.5 ) {
            $density_score = round( $density / 0.5 * 100 ); // 0.3% = 60
        } elseif ( $density > 1.5 && $density <= 2.5 ) {
            $density_score = max( 40, round( 100 - ( ( $density - 1.5 ) * 60 ) ) );
        } elseif ( $density > 2.5 ) {
            $density_score = 0; // keyword stuffing
        } else {
            $density_score = 0;
        }

        // v1.5.62 — H2 coverage now matches exact phrase OR any 4+ char
        // content token from the keyword. Previously this counted only
        // exact phrase matches, which on the live Mudgee test gave "14%"
        // while the new Content_Injector::flag_keyword_placement() gave
        // "62.5%" for the same article (because it counts variants).
        // Same UI showing both numbers was confusing. Now both methods
        // agree on variant-token counting, which is what AIOSEO actually
        // honors for H2 coverage.
        $stopwords = [ 'the','and','for','how','what','why','when','where','which','who','with','from','your','their','best','top','safely','guide','2024','2025','2026','2027' ];
        $kw_tokens = array_filter(
            array_map(
                fn( $t ) => preg_replace( '/[^\w]/', '', strtolower( $t ) ),
                explode( ' ', $keyword )
            ),
            fn( $t ) => strlen( $t ) >= 4 && ! in_array( $t, $stopwords, true )
        );

        preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $content, $h2_matches );
        $h2_total   = count( $h2_matches[1] ?? [] );
        $h2_with_kw = 0;
        if ( $h2_total > 0 ) {
            foreach ( $h2_matches[1] as $h2 ) {
                $h2_plain = strtolower( wp_strip_all_tags( $h2 ) );
                // Exact phrase match first (still counts)
                if ( str_contains( $h2_plain, $lower_kw ) ) {
                    $h2_with_kw++;
                    continue;
                }
                // Variant-token match: any 4+ char content token from keyword
                foreach ( $kw_tokens as $t ) {
                    if ( str_contains( $h2_plain, $t ) ) {
                        $h2_with_kw++;
                        break;
                    }
                }
            }
        }
        $h2_coverage = $h2_total > 0 ? ( $h2_with_kw / $h2_total ) : 0;
        // 30% H2 coverage = 100, scales linearly below and plateaus above
        $h2_score = min( 100, round( ( $h2_coverage / 0.30 ) * 100 ) );

        // Intro-paragraph keyword component (0-100) — must be in first 150 chars of plain text
        $intro_slice = substr( $lower_text, 0, 150 );
        $intro_score = strpos( $intro_slice, $lower_kw ) !== false ? 100 : 0;

        // Blend: density 50%, H2 coverage 30%, intro 20%
        $score = round( $density_score * 0.5 + $h2_score * 0.3 + $intro_score * 0.2 );

        return [
            'score'       => $score,
            'density'     => round( $density, 2 ),
            'count'       => $kw_count,
            'h2_total'    => $h2_total,
            'h2_with_kw'  => $h2_with_kw,
            'h2_coverage' => round( $h2_coverage * 100 ),
            'intro_match' => $intro_score === 100,
            'detail'      => sprintf(
                'Density %.2f%% (%d×), %d/%d H2s contain keyword (%d%%), intro: %s',
                $density, $kw_count, $h2_with_kw, $h2_total,
                round( $h2_coverage * 100 ),
                $intro_score === 100 ? 'yes' : 'no'
            ),
        ];
    }

    /**
     * Humanizer / anti-AI writing pattern check — guideline §4B.
     *
     * Scans the text for Tier 1 banned words (immediate red flags) and Tier 2
     * banned words (fine alone, 3+ in an article is a tell). Scores:
     *
     *   - 100 if zero Tier 1 words and ≤2 Tier 2 words
     *   - Drops 15 per Tier 1 word found
     *   - Drops 10 per Tier 2 word beyond the allowed 2
     *   - Drops 10 per banned pattern detected ("Not only X, but also Y", etc.)
     *
     * The goal is post-generation validation — the prompt already tells the AI
     * to avoid these, but drift happens and we need a mechanical backstop.
     */
    private function check_humanizer( string $text, int $word_count ): array {
        if ( $word_count < 100 ) {
            return [ 'score' => 0, 'detail' => 'Content too short for humanizer check' ];
        }

        $lower = strtolower( $text );
        $tier1_hits = [];
        $tier2_hits = [];

        foreach ( self::TIER1_BANNED_WORDS as $word ) {
            // Word boundary match to avoid false positives (e.g. "landscape" in "landscapes")
            if ( preg_match_all( '/\b' . preg_quote( $word, '/' ) . '\b/i', $lower, $m ) ) {
                $tier1_hits[] = [ 'word' => $word, 'count' => count( $m[0] ) ];
            }
        }
        foreach ( self::TIER2_BANNED_WORDS as $word ) {
            if ( preg_match_all( '/\b' . preg_quote( $word, '/' ) . '\b/i', $lower, $m ) ) {
                $tier2_hits[] = [ 'word' => $word, 'count' => count( $m[0] ) ];
            }
        }

        // Banned pattern detection
        $pattern_hits = [];
        $patterns = [
            'not_only'   => '/\bnot only\b.*?\bbut also\b/i',
            'at_its_core' => '/\bat its core\b/i',
            'ever_evolving' => '/\bever.evolving\b/i',
            'in_todays'   => '/\bin today\'?s (fast.paced|digital|modern) (world|landscape|era)\b/i',
            'delve_into' => '/\bdelve into\b/i',
            'dive_in'    => '/\b(let\'?s |we\'?ll )dive in\b/i',
            'future_bright' => '/\bfuture (looks bright|is bright)\b/i',
            'serves_as'  => '/\bserves as\b/i',
        ];
        foreach ( $patterns as $name => $regex ) {
            if ( preg_match( $regex, $text ) ) {
                $pattern_hits[] = $name;
            }
        }

        $tier1_count = array_sum( array_column( $tier1_hits, 'count' ) );
        $tier2_count = array_sum( array_column( $tier2_hits, 'count' ) );
        $pattern_count = count( $pattern_hits );

        $score = 100;
        $score -= $tier1_count * 15;
        $score -= max( 0, $tier2_count - 2 ) * 10; // tolerate 2 Tier 2 words
        $score -= $pattern_count * 10;
        $score = max( 0, $score );

        return [
            'score'         => $score,
            'tier1_count'   => $tier1_count,
            'tier2_count'   => $tier2_count,
            'pattern_count' => $pattern_count,
            'tier1_words'   => array_slice( array_column( $tier1_hits, 'word' ), 0, 5 ),
            'tier2_words'   => array_slice( array_column( $tier2_hits, 'word' ), 0, 5 ),
            'patterns'      => $pattern_hits,
            'detail'        => sprintf(
                '%d Tier-1 AI words, %d Tier-2 AI words, %d banned patterns',
                $tier1_count, $tier2_count, $pattern_count
            ),
        ];
    }

    /**
     * CORE-EEAT lite scoring — top 10 items from the 80-item rubric (§15B).
     *
     * This is a quick proxy for Google's E-E-A-T signals. The full 80-item
     * CORE-EEAT benchmark is too heavy to run on every save, so we extract
     * the 10 highest-signal items and score each as 10 points.
     *
     * 10 items (1 point each × 10):
     *
     *  C1. Direct answer in first 150 words
     *  C2. FAQ section present
     *  O1. Heading hierarchy (H1 → H2 → H3, no jumps)
     *  O2. Tables for comparative data
     *  R1. Specific numbers (≥5 precise figures)
     *  R2. At least 1 citation per 500 words
     *  E1. First-hand language ("we found", "in our tests", "I've used")
     *  Exp1. Practical examples (preg: /for example|for instance|such as|e\.g\./)
     *  A1. Named experts/organizations (≥3 proper-noun entities)
     *  T1. Acknowledges limitations or tradeoffs (preg: /however|but|though|while|limit|drawback|caveat/)
     */
    private function check_core_eeat( string $content, string $text ): array {
        $word_count = str_word_count( $text );
        if ( $word_count < 200 ) {
            return [ 'score' => 0, 'detail' => 'Content too short for E-E-A-T analysis' ];
        }

        $score = 0;
        $details = [];

        // C1 — Direct answer in first 150 words
        $first_150 = implode( ' ', array_slice( preg_split( '/\s+/', trim( $text ) ), 0, 150 ) );
        // A direct answer has at least one declarative sentence ending in a period
        if ( preg_match( '/[^.!?]{20,}\./', $first_150 ) ) {
            $score += 10;
            $details[] = 'C1:answer';
        }

        // C2 — FAQ section present
        if ( preg_match( '/faq|frequently\s*asked/i', $content ) ) {
            $score += 10;
            $details[] = 'C2:faq';
        }

        // O1 — Heading hierarchy (H1 → H2 → H3, no skipping levels)
        preg_match_all( '/<h([1-6])[^>]*>/i', $content, $h_matches );
        $levels = array_map( 'intval', $h_matches[1] ?? [] );
        $hierarchy_ok = true;
        $prev_level = 1;
        foreach ( $levels as $lvl ) {
            if ( $lvl > $prev_level + 1 ) {
                $hierarchy_ok = false;
                break;
            }
            $prev_level = $lvl;
        }
        if ( $hierarchy_ok && count( $levels ) >= 3 ) {
            $score += 10;
            $details[] = 'O1:hierarchy';
        }

        // O2 — At least one table
        if ( stripos( $content, '<table' ) !== false ) {
            $score += 10;
            $details[] = 'O2:table';
        }

        // R1 — At least 5 specific numbers (percentages, dollar amounts, years, quantities)
        preg_match_all( '/\b\d+[\.,]?\d*\s*(?:%|percent|billion|million|thousand|USD|\$|£|€|kg|lb|mg|km|mi|hours?|minutes?|days?|years?)\b|\b(?:19|20)\d{2}\b/i', $text, $num_matches );
        if ( count( $num_matches[0] ) >= 5 ) {
            $score += 10;
            $details[] = 'R1:numbers';
        }

        // R2 — At least 1 citation per 500 words (inline [Source, Year] or linked)
        preg_match_all( '/\[[A-Z][^\]]*\d{4}\]|\([A-Z][^)]*\d{4}\)|\[\d+\]/', $text, $cite_matches );
        $cite_count = count( $cite_matches[0] );
        // Also count pool-linked markdown (images excluded)
        preg_match_all( '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/', $text, $link_matches );
        $cite_count += count( $link_matches[0] );
        if ( $cite_count >= max( 1, floor( $word_count / 500 ) ) ) {
            $score += 10;
            $details[] = 'R2:citations';
        }

        // E1 — First-hand language (Experience signal)
        if ( preg_match( '/\b(we (found|tested|tried|discovered|learned)|in our (test|experience|review)|i\'ve (used|tried|tested)|my experience|from our testing)\b/i', $text ) ) {
            $score += 10;
            $details[] = 'E1:firsthand';
        }

        // Exp1 — Practical examples
        if ( preg_match( '/\b(for example|for instance|such as|e\.g\.|consider|take [a-z]+ as an example)\b/i', $text ) ) {
            $score += 10;
            $details[] = 'Exp1:examples';
        }

        // A1 — Named experts or organizations (3+ proper-noun phrases)
        preg_match_all( '/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,3}\b/', $text, $entity_matches );
        if ( count( $entity_matches[0] ) >= 3 ) {
            $score += 10;
            $details[] = 'A1:entities';
        }

        // T1 — Acknowledges limitations / tradeoffs
        if ( preg_match( '/\b(however|but|though|while|limitation|drawback|caveat|tradeoff|trade.off|downside|weakness)\b/i', $text ) ) {
            $score += 10;
            $details[] = 'T1:tradeoffs';
        }

        return [
            'score'   => $score,
            'details' => $details,
            'detail'  => sprintf( '%d/10 CORE-EEAT items passed', count( $details ) ),
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

        // v1.5.24 — Local places grounding sentinel (highest priority — shipped
        // first so it appears at the top of the suggestions list). Triggered
        // when a local-intent listicle/buying_guide/review/comparison article
        // has no verified addresses or map URLs, meaning the LLM probably
        // invented businesses.
        if ( ! empty( $checks['local_places'] ) && ( $checks['local_places']['score'] ?? 100 ) === 0 ) {
            $suggestions[] = [
                'priority' => 'high',
                'type'     => 'local_places',
                'message'  => 'This local-business article has no verified addresses or map URLs — the businesses may be fabricated. Configure free Foursquare + HERE API keys in Settings → Integrations for reliable coverage of small cities worldwide. For truly remote places, add a Google Places key (free $200/month credit). Regenerate after adding keys.',
            ];
        }

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

        // Keyword density (§5A) — SEO plugin compatibility
        if ( ! empty( $checks['keyword_density'] ) && $checks['keyword_density']['score'] < 60 ) {
            $kd = $checks['keyword_density'];
            if ( isset( $kd['density'] ) && $kd['density'] < 0.3 ) {
                $msg = sprintf( 'Keyword density is %.2f%% — below the 0.5%% minimum. AIOSEO/Yoast will flag this. Use the keyword 2-3 more times naturally.', $kd['density'] );
            } elseif ( isset( $kd['density'] ) && $kd['density'] > 2.5 ) {
                $msg = sprintf( 'Keyword density is %.2f%% — above the 1.5%% target. This looks like keyword stuffing and REDUCES AI visibility by 9%%. Rewrite a few mentions as variations.', $kd['density'] );
            } elseif ( isset( $kd['h2_coverage'] ) && $kd['h2_coverage'] < 30 ) {
                $msg = sprintf( 'Only %d%% of H2 headings contain the keyword. Target 30%%+. Rename 1-2 H2s to include the keyword or a close variant.', $kd['h2_coverage'] );
            } elseif ( isset( $kd['intro_match'] ) && ! $kd['intro_match'] ) {
                $msg = 'Keyword missing from first 150 characters of content. SEO plugins check the intro paragraph — move the keyword to the first sentence.';
            } else {
                $msg = 'Keyword placement needs work — check density, H2 coverage, and intro paragraph.';
            }
            $suggestions[] = [
                'priority' => 'high',
                'type'     => 'keyword',
                'message'  => $msg,
            ];
        }

        // Humanizer (§4B) — AI tell detection
        if ( ! empty( $checks['humanizer'] ) && $checks['humanizer']['score'] < 70 ) {
            $hm = $checks['humanizer'];
            $tier1 = $hm['tier1_words'] ?? [];
            $patterns = $hm['patterns'] ?? [];
            if ( ! empty( $tier1 ) ) {
                $msg = sprintf( 'Remove Tier-1 AI red-flag words: %s. These are instant AI tells and hurt Google Helpful Content scoring.', implode( ', ', array_slice( $tier1, 0, 5 ) ) );
            } elseif ( ! empty( $patterns ) ) {
                $msg = 'Rewrite banned AI patterns (found: ' . implode( ', ', $patterns ) . '). See guideline §4B for natural alternatives.';
            } else {
                $msg = sprintf( 'Too many Tier-2 AI words (%d found, max 2 allowed). Pick different vocabulary.', $hm['tier2_count'] ?? 0 );
            }
            $suggestions[] = [
                'priority' => 'high',
                'type'     => 'humanizer',
                'message'  => $msg,
            ];
        }

        // CORE-EEAT lite (§15B)
        if ( ! empty( $checks['core_eeat'] ) && $checks['core_eeat']['score'] < 70 ) {
            $ceeat = $checks['core_eeat'];
            $passed = $ceeat['details'] ?? [];
            $all_items = [ 'C1:answer', 'C2:faq', 'O1:hierarchy', 'O2:table', 'R1:numbers', 'R2:citations', 'E1:firsthand', 'Exp1:examples', 'A1:entities', 'T1:tradeoffs' ];
            $missing = array_diff( $all_items, $passed );
            $labels = [
                'C1:answer'    => 'direct answer in first 150 words',
                'C2:faq'       => 'FAQ section',
                'O1:hierarchy' => 'proper heading hierarchy (no skipped levels)',
                'O2:table'     => 'at least one comparison table',
                'R1:numbers'   => '5+ specific numbers',
                'R2:citations' => '1 citation per 500 words',
                'E1:firsthand' => 'first-hand experience language ("we tested", "in our review")',
                'Exp1:examples' => 'practical examples ("for example", "such as")',
                'A1:entities'  => '3+ named experts or organizations',
                'T1:tradeoffs' => 'acknowledge tradeoffs ("however", "while", "drawback")',
            ];
            $missing_labels = array_slice( array_map( fn( $k ) => $labels[ $k ] ?? $k, $missing ), 0, 3 );
            $suggestions[] = [
                'priority' => 'medium',
                'type'     => 'eeat',
                'message'  => 'CORE-EEAT gaps: missing ' . implode( ', ', $missing_labels ) . '. Adding these boosts Google Helpful Content scoring.',
            ];
        }

        // Sort by priority
        usort( $suggestions, fn( $a, $b ) => ( $a['priority'] === 'high' ? 0 : 1 ) - ( $b['priority'] === 'high' ? 0 : 1 ) );

        return $suggestions;
    }

    /**
     * v1.5.208 — Term Coverage check (Competitive Content Brief, BM25-based).
     *
     * Counts how many of the top BM25 terms from the Competitive Content
     * Brief (see SEO-GEO-AI-GUIDELINES.md §28.1) appear in the article.
     *
     * IMPORTANT — this check is REPORTING-ONLY and does NOT contribute to
     * the §6 14-check GEO scoring rubric. Reasons:
     *   (1) The §6 rubric weights are already tuned + published; changing
     *       them would destabilize scores on every article already saved.
     *   (2) This check is fed by the Content_Ranking_Framework phase 5
     *       quality-gate report (§28.5) which is ALREADY how new
     *       cross-cutting quality signals are surfaced.
     *   (3) Per §1, keyword-coverage as a RUBRIC weight would risk
     *       incentivizing stuffing (-9% visibility). Keeping it as a gate
     *       rather than a score avoids this.
     *
     * Decision documented in SEO-GEO-AI-GUIDELINES.md §28.5 update.
     *
     * @param string $content Rendered HTML (main article body).
     * @param array|null $brief Content-brief payload from /api/research (keyed
     *                          by content_brief on the results payload).
     * @return array { score: int 0-100, matched: int, total: int, missing_terms: string[], detail: string }
     */
    public function check_term_coverage( string $content, ?array $brief ): array {
        if ( ! is_array( $brief ) || empty( $brief['terms'] ) ) {
            return [
                'score'         => 0,
                'matched'       => 0,
                'total'         => 0,
                'missing_terms' => [],
                'detail'        => 'No competitive brief available (Serper/Firecrawl unavailable or zero SERP results scraped).',
            ];
        }

        // Lowercase plain-text haystack for case-insensitive substring match.
        // Uses mb_strtolower for CJK/Cyrillic/Greek safety.
        $haystack = mb_strtolower( wp_strip_all_tags( $content ) );

        // Only score against the top 20 most-distinctive terms. The brief
        // itself may return up to 50 for Pro — we score against a consistent
        // top-20 slice so Free/Pro articles score on the same scale.
        $top_terms = array_slice( $brief['terms'], 0, 20 );
        $matched   = [];
        $missing   = [];
        foreach ( $top_terms as $entry ) {
            $term = isset( $entry['term'] ) ? mb_strtolower( (string) $entry['term'] ) : '';
            if ( $term === '' ) continue;
            if ( mb_strpos( $haystack, $term ) !== false ) {
                $matched[] = $term;
            } else {
                $missing[] = $term;
            }
        }

        $total = count( $top_terms );
        $count_matched = count( $matched );
        $score = $total > 0 ? (int) round( ( $count_matched / $total ) * 100 ) : 0;

        return [
            'score'         => $score,
            'matched'       => $count_matched,
            'total'         => $total,
            'missing_terms' => array_slice( $missing, 0, 10 ),
            'detail'        => sprintf(
                '%d of %d competitor-distinctive concepts present (%d%%).',
                $count_matched, $total, $score
            ),
        ];
    }
}
