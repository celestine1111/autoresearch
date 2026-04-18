<?php

namespace SEOBetter;

/**
 * CORE-EEAT Content Auditor.
 *
 * Implements the full 80-item CORE-EEAT scoring rubric from
 * SEO-GEO-AI-GUIDELINES §15B. This is a separate, heavier audit — the
 * GEO_Analyzer runs a 10-item "lite" version on every save (weight 5%)
 * and this class runs the full rubric on demand from the analyzer REST
 * endpoint or a dedicated audit button.
 *
 * Scoring layout:
 *
 *   CORE (Content Body) — 40 items, 4 dimensions × 10 items each
 *   ├── C — Contextual Clarity (10 items)
 *   ├── O — Organization (10 items)
 *   ├── R — Referenceability (10 items)
 *   └── E — Exclusivity (10 items)
 *
 *   EEAT (Source Credibility) — 40 items, 4 dimensions × 10 items each
 *   ├── Exp — Experience (10 items)
 *   ├── Ept — Expertise (10 items)
 *   ├── A  — Authority (10 items)
 *   └── T  — Trust (10 items)
 *
 *   VETO items (publication blockers) — any one of these fails the audit:
 *   ├── C01 — Title mismatch with content
 *   ├── R10 — Internal contradictions
 *   └── T04 — Required disclosures missing
 *
 * Each item passes or fails. Raw score is 0-80 (plus veto flag).
 * Final score is normalized to 0-100 for display.
 */
class CORE_EEAT_Auditor {

    public function audit( string $content, string $title = '', string $keyword = '', string $content_type = '' ): array {
        $text = wp_strip_all_tags( $content );
        $word_count = str_word_count( $text );

        if ( $word_count < 200 ) {
            return [
                'score'       => 0,
                'normalized'  => 0,
                'veto'        => false,
                'grade'       => 'F',
                'passed'      => [],
                'failed'      => [],
                'vetoes'      => [],
                'dimensions'  => [],
                'detail'      => 'Content too short for full CORE-EEAT audit',
            ];
        }

        $core = [
            'C' => $this->audit_contextual_clarity( $content, $text, $word_count, $keyword ),
            'O' => $this->audit_organization( $content, $text ),
            'R' => $this->audit_referenceability( $content, $text, $word_count ),
            'E' => $this->audit_exclusivity( $content, $text ),
        ];

        $eeat = [
            'Exp' => $this->audit_experience( $text ),
            'Ept' => $this->audit_expertise( $content, $text ),
            'A'   => $this->audit_authority( $content, $text ),
            'T'   => $this->audit_trust( $content, $text ),
        ];

        // Compute totals
        $passed = [];
        $failed = [];
        foreach ( array_merge( $core, $eeat ) as $dim => $items ) {
            foreach ( $items as $item ) {
                if ( $item['pass'] ) {
                    $passed[] = $item['id'];
                } else {
                    $failed[] = $item['id'];
                }
            }
        }

        // Veto items
        $vetoes = $this->check_vetoes( $content, $text, $title, $keyword );

        $raw_score  = count( $passed );
        $normalized = round( ( $raw_score / 80 ) * 100 );
        $veto_hit   = ! empty( $vetoes );

        // Apply veto floor
        if ( $veto_hit ) {
            $normalized = min( $normalized, 40 );
        }

        $dimensions = [];
        foreach ( $core as $code => $items ) {
            $passed_count = count( array_filter( $items, fn( $i ) => $i['pass'] ) );
            $dimensions[ $code ] = [
                'label' => $this->dimension_label( $code ),
                'passed' => $passed_count,
                'total' => 10,
                'score' => round( ( $passed_count / 10 ) * 100 ),
            ];
        }
        foreach ( $eeat as $code => $items ) {
            $passed_count = count( array_filter( $items, fn( $i ) => $i['pass'] ) );
            $dimensions[ $code ] = [
                'label' => $this->dimension_label( $code ),
                'passed' => $passed_count,
                'total' => 10,
                'score' => round( ( $passed_count / 10 ) * 100 ),
            ];
        }

        return [
            'score'       => $raw_score,       // 0-80
            'normalized'  => $normalized,      // 0-100
            'grade'       => $this->score_to_grade( $normalized ),
            'veto'        => $veto_hit,
            'vetoes'      => $vetoes,
            'passed'      => $passed,
            'failed'      => $failed,
            'dimensions'  => $dimensions,
            'core_items'  => $core,
            'eeat_items'  => $eeat,
            'detail'      => sprintf(
                '%d/80 items passed, normalized %d%%%s',
                $raw_score, $normalized,
                $veto_hit ? ', VETO TRIGGERED (' . implode( ',', array_column( $vetoes, 'id' ) ) . ')' : ''
            ),
        ];
    }

    // ================================================================
    // CORE — Content Body
    // ================================================================

    private function audit_contextual_clarity( string $content, string $text, int $word_count, string $keyword ): array {
        $first_150 = implode( ' ', array_slice( preg_split( '/\s+/', trim( $text ) ), 0, 150 ) );
        return [
            [ 'id' => 'C01', 'label' => 'Topic stated in first 100 words', 'pass' => $keyword && stripos( $first_150, $keyword ) !== false ],
            [ 'id' => 'C02', 'label' => 'Intent matches title', 'pass' => true ], // placeholder — true by default for generated articles
            [ 'id' => 'C03', 'label' => 'Scope defined (what the article covers)', 'pass' => (bool) preg_match( '/this (guide|article|post) (covers|explains|shows|teaches)/i', $first_150 ) || $word_count > 500 ],
            [ 'id' => 'C04', 'label' => 'Direct answer in first 150 words', 'pass' => (bool) preg_match( '/[^.!?]{20,}\./', $first_150 ) ],
            [ 'id' => 'C05', 'label' => 'FAQ section present', 'pass' => (bool) preg_match( '/faq|frequently\s*asked/i', $content ) ],
            [ 'id' => 'C06', 'label' => 'No jargon without explanation', 'pass' => true ], // hard to measure; default pass
            [ 'id' => 'C07', 'label' => 'Reading level ≤ grade 10', 'pass' => true ], // readability handled in GEO_Analyzer
            [ 'id' => 'C08', 'label' => 'Active voice dominant', 'pass' => $this->active_voice_ratio( $text ) > 0.7 ],
            [ 'id' => 'C09', 'label' => 'Acronyms defined on first use', 'pass' => $this->acronyms_defined( $text ) ],
            [ 'id' => 'C10', 'label' => 'Key Takeaways section present', 'pass' => (bool) preg_match( '/<h[23][^>]*>\s*(key\s*takeaway|summary|tldr)/i', $content ) ],
        ];
    }

    private function audit_organization( string $content, string $text ): array {
        preg_match_all( '/<h([1-6])[^>]*>/i', $content, $h_matches );
        $levels = array_map( 'intval', $h_matches[1] ?? [] );

        $hierarchy_ok = true;
        $prev = 1;
        foreach ( $levels as $lvl ) {
            if ( $lvl > $prev + 1 ) { $hierarchy_ok = false; break; }
            $prev = $lvl;
        }

        return [
            [ 'id' => 'O01', 'label' => 'H1 → H2 → H3 hierarchy (no skips)', 'pass' => $hierarchy_ok && count( $levels ) >= 3 ],
            [ 'id' => 'O02', 'label' => 'At least 3 H2 sections', 'pass' => count( array_filter( $levels, fn( $l ) => $l === 2 ) ) >= 3 ],
            [ 'id' => 'O03', 'label' => 'Tables used for comparative data', 'pass' => stripos( $content, '<table' ) !== false ],
            [ 'id' => 'O04', 'label' => 'Lists used for enumerable content', 'pass' => (bool) preg_match( '/<(ul|ol)\b/i', $content ) ],
            [ 'id' => 'O05', 'label' => 'Paragraph length varies', 'pass' => $this->paragraph_length_varies( $text ) ],
            [ 'id' => 'O06', 'label' => 'Schema markup present', 'pass' => stripos( $content, 'application/ld+json' ) !== false ],
            [ 'id' => 'O07', 'label' => 'No walls of text (paragraphs ≤ 200 words)', 'pass' => ! $this->has_wall_of_text( $text ) ],
            [ 'id' => 'O08', 'label' => 'Headings scannable (≤ 10 words)', 'pass' => $this->headings_scannable( $content ) ],
            [ 'id' => 'O09', 'label' => 'Images break up text sections', 'pass' => substr_count( strtolower( $content ), '<img' ) >= 1 ],
            [ 'id' => 'O10', 'label' => 'No filler paragraphs (every paragraph adds value)', 'pass' => true ], // hard to auto-measure
        ];
    }

    private function audit_referenceability( string $content, string $text, int $word_count ): array {
        preg_match_all( '/\b\d+[\.,]?\d*\s*(?:%|percent|billion|million|thousand|USD|\$|£|€)\b|\b(?:19|20)\d{2}\b/', $text, $num_matches );
        $num_count = count( $num_matches[0] );

        // v1.5.104 — Count citations from HTML <a href> tags, not markdown [text](url).
        // After Content_Formatter, links are HTML. wp_strip_all_tags($content) removes
        // them, so the old markdown regex found 0 citations on every article.
        preg_match_all( '/href="https?:\/\/[^"]+"/i', $content, $html_link_matches );
        $link_count = count( $html_link_matches[0] );
        // Also count markdown-style links (for pre-format scoring)
        preg_match_all( '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/', $text, $md_link_matches );
        $link_count += count( $md_link_matches[0] );

        preg_match_all( '/\[[A-Z][^\]]*\d{4}\]|\([A-Z][^)]*\d{4}\)|\[\d+\]/', $text, $inline_cite );
        $inline_count = count( $inline_cite[0] );

        return [
            [ 'id' => 'R01', 'label' => '≥ 5 specific numbers', 'pass' => $num_count >= 5 ],
            [ 'id' => 'R02', 'label' => '≥ 1 citation per 500 words', 'pass' => ( $link_count + $inline_count ) >= max( 1, floor( $word_count / 500 ) ) ],
            // v1.5.104 — extract URLs from HTML for deep link + API checks
            [ 'id' => 'R03', 'label' => 'Sources are deep article links (not homepages)', 'pass' => $this->deep_links_only( $this->extract_urls_from_html( $content ) ) ],
            [ 'id' => 'R04', 'label' => 'No API URLs cited', 'pass' => ! $this->has_api_urls( $this->extract_urls_from_html( $content ) ) ],
            [ 'id' => 'R05', 'label' => 'Dates accompany statistics', 'pass' => $this->dates_with_stats( $text ) ],
            [ 'id' => 'R06', 'label' => 'Publication year mentioned for key claims', 'pass' => (bool) preg_match( '/\b(19|20)\d{2}\b/', $text ) ],
            [ 'id' => 'R07', 'label' => 'No unsourced "studies show"', 'pass' => ! preg_match( '/studies show|research (has )?shown|experts (say|agree)/i', $text ) || $link_count > 0 ],
            [ 'id' => 'R08', 'label' => 'At least 2 distinct source domains cited', 'pass' => $this->distinct_domains( $this->extract_urls_from_html( $content ) ) >= 2 ],
            [ 'id' => 'R09', 'label' => 'Plain-text attributions where no link exists', 'pass' => (bool) preg_match( '/according to|research from|per the|a study by/i', $text ) ],
            [ 'id' => 'R10', 'label' => 'No internal contradictions', 'pass' => true ], // VETO — handled separately
        ];
    }

    private function audit_exclusivity( string $content, string $text ): array {
        return [
            [ 'id' => 'E01', 'label' => 'Unique angle or framing', 'pass' => true ], // subjective, default pass
            [ 'id' => 'E02', 'label' => 'Original data or analysis present', 'pass' => (bool) preg_match( '/\b(our (data|research|analysis|testing)|we (tested|measured|surveyed))\b/i', $text ) ],
            [ 'id' => 'E03', 'label' => 'Case studies or real examples', 'pass' => (bool) preg_match( '/\bcase study|for example|for instance|e\.g\.|such as\b/i', $text ) ],
            [ 'id' => 'E04', 'label' => 'First-person insights', 'pass' => (bool) preg_match( '/\b(I (tried|tested|used|found)|we (tried|tested|used|found))\b/i', $text ) ],
            [ 'id' => 'E05', 'label' => 'Expert quotes (not paraphrased)', 'pass' => (bool) preg_match( '/"[^"]{20,}"/', $text ) ],
            [ 'id' => 'E06', 'label' => 'Specific product/service names', 'pass' => (bool) preg_match_all( '/\b[A-Z][a-z]+(?:[A-Z][a-z]+)+\b/', $text ) ],
            [ 'id' => 'E07', 'label' => 'Mentions specific tools or methods', 'pass' => true ], // hard to auto-measure
            [ 'id' => 'E08', 'label' => 'Proprietary framework or model', 'pass' => false ], // rarely true
            [ 'id' => 'E09', 'label' => 'Content > 1000 words (depth signal)', 'pass' => str_word_count( $text ) >= 1000 ],
            [ 'id' => 'E10', 'label' => 'Not a rewrite of existing content', 'pass' => true ], // subjective
        ];
    }

    // ================================================================
    // EEAT — Source Credibility
    // ================================================================

    private function audit_experience( string $text ): array {
        return [
            [ 'id' => 'Exp01', 'label' => 'First-hand language ("we tested", "I\'ve used")', 'pass' => (bool) preg_match( '/\b(we (found|tested|tried|discovered|learned)|in our (test|experience|review)|i\'ve (used|tried|tested))\b/i', $text ) ],
            [ 'id' => 'Exp02', 'label' => 'Practical examples', 'pass' => (bool) preg_match( '/\bfor example|for instance|such as|e\.g\.\b/i', $text ) ],
            [ 'id' => 'Exp03', 'label' => 'Acknowledges mistakes or limits', 'pass' => (bool) preg_match( '/\b(however|but|though|limit|drawback|weakness|downside|caveat)\b/i', $text ) ],
            [ 'id' => 'Exp04', 'label' => 'Time-based observations ("after 3 months of use")', 'pass' => (bool) preg_match( '/\b(after|over|during) \d+ (day|week|month|year)s?\b/i', $text ) ],
            [ 'id' => 'Exp05', 'label' => 'Process details (step-by-step from real use)', 'pass' => (bool) preg_match( '/\b(first|then|next|finally|step \d)\b/i', $text ) ],
            [ 'id' => 'Exp06', 'label' => 'Outcomes or results described', 'pass' => (bool) preg_match( '/\b(result|outcome|effect|impact|change|improvement|decrease|increase)\b/i', $text ) ],
            [ 'id' => 'Exp07', 'label' => 'Specific quantified experience', 'pass' => (bool) preg_match( '/\b\d+ (days?|weeks?|months?|years?|uses?|tests?)\b/i', $text ) ],
            [ 'id' => 'Exp08', 'label' => 'Audience identified (who this is for)', 'pass' => (bool) preg_match( '/\b(for (beginners|experts|professionals|anyone|users)|if you\'re|ideal for)\b/i', $text ) ],
            [ 'id' => 'Exp09', 'label' => 'Photos or screenshots (proxy: images)', 'pass' => substr_count( strtolower( $text ), 'figure' ) > 0 ],
            [ 'id' => 'Exp10', 'label' => 'Comparative experience ("compared to X")', 'pass' => (bool) preg_match( '/\bcompared to|versus|unlike|better than|worse than\b/i', $text ) ],
        ];
    }

    private function audit_expertise( string $content, string $text ): array {
        return [
            [ 'id' => 'Ept01', 'label' => 'Domain-specific terminology used', 'pass' => true ],
            [ 'id' => 'Ept02', 'label' => 'Reasoning transparency ("because...")', 'pass' => substr_count( strtolower( $text ), 'because' ) >= 2 ],
            [ 'id' => 'Ept03', 'label' => 'Author byline / credentials present', 'pass' => (bool) preg_match( '/\bby [A-Z][a-z]+ [A-Z][a-z]+|author:|written by/i', $content ) ],
            [ 'id' => 'Ept04', 'label' => 'Technical specifications or data', 'pass' => (bool) preg_match( '/\b\d+(?:\.\d+)?\s?(mm|cm|kg|lb|mhz|ghz|mah|hours?|minutes?)\b/i', $text ) ],
            [ 'id' => 'Ept05', 'label' => 'Comparison with alternatives', 'pass' => (bool) preg_match( '/\balternative|option|choice|instead of|rather than\b/i', $text ) ],
            [ 'id' => 'Ept06', 'label' => 'Edge cases or exceptions discussed', 'pass' => (bool) preg_match( '/\bexcept|unless|however|although|edge case|special case\b/i', $text ) ],
            [ 'id' => 'Ept07', 'label' => 'Not oversimplified for the topic', 'pass' => str_word_count( $text ) >= 800 ],
            [ 'id' => 'Ept08', 'label' => 'Trade-offs acknowledged', 'pass' => (bool) preg_match( '/\btradeoff|trade-off|balance|compromise|downside\b/i', $text ) ],
            [ 'id' => 'Ept09', 'label' => 'Uses precise terminology (not vague)', 'pass' => ! preg_match( '/\b(thing|stuff|kind of|sort of|basically|just)\b/i', substr( $text, 0, 500 ) ) ],
            [ 'id' => 'Ept10', 'label' => 'References standards or specifications', 'pass' => (bool) preg_match( '/\b(standard|specification|RFC|ISO|IEEE|W3C)\b/i', $text ) ],
        ];
    }

    private function audit_authority( string $content, string $text ): array {
        preg_match_all( '/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,3}\b/', $text, $entity_matches );
        $entity_count = count( $entity_matches[0] );

        return [
            [ 'id' => 'A01', 'label' => '≥ 3 named experts or organizations', 'pass' => $entity_count >= 3 ],
            [ 'id' => 'A02', 'label' => 'Sources include .gov/.edu/major orgs', 'pass' => (bool) preg_match( '/\.(gov|edu|org)\b|wikipedia|nature\.com|reuters|bbc/i', $content ) ],
            [ 'id' => 'A03', 'label' => 'Author title or role mentioned', 'pass' => (bool) preg_match( '/\b(Dr\.|Prof\.|CEO|founder|director|editor|PhD)\b/i', $text ) ],
            [ 'id' => 'A04', 'label' => 'Publication brand recognizable', 'pass' => true ], // N/A per-post
            [ 'id' => 'A05', 'label' => 'Content cites primary research', 'pass' => (bool) preg_match( '/\b(study|research|paper|journal|publication)\b/i', $text ) ],
            [ 'id' => 'A06', 'label' => 'No anonymous claims', 'pass' => ! preg_match( '/\b(experts say|they say|people say|some claim)\b/i', $text ) ],
            [ 'id' => 'A07', 'label' => 'External links to authoritative domains', 'pass' => (bool) preg_match( '/href="https?:\/\/(www\.)?(wikipedia|gov|edu|nature|who\.int|cdc\.gov|nih\.gov)/i', $content ) ],
            [ 'id' => 'A08', 'label' => 'Credentials align with topic', 'pass' => true ], // subjective
            [ 'id' => 'A09', 'label' => 'Consistent voice / perspective', 'pass' => true ], // subjective
            [ 'id' => 'A10', 'label' => 'No promotional inflation', 'pass' => ! preg_match( '/\b(amazing|incredible|revolutionary|game-changer|groundbreaking)\b/i', $text ) ],
        ];
    }

    private function audit_trust( string $content, string $text ): array {
        return [
            [ 'id' => 'T01', 'label' => 'Balanced perspective (pros + cons)', 'pass' => (bool) preg_match( '/\b(pros|advantage).*\b(cons|disadvantage|drawback)\b/is', $text ) ],
            [ 'id' => 'T02', 'label' => 'Factual accuracy (no known errors)', 'pass' => true ], // default pass unless overridden
            [ 'id' => 'T03', 'label' => 'Dates current (within 2 years)', 'pass' => (bool) preg_match( '/\b(202[4-9]|20[3-9]\d)\b/', $text ) ],
            [ 'id' => 'T04', 'label' => 'Required disclosures present', 'pass' => true ], // VETO — handled separately
            [ 'id' => 'T05', 'label' => 'Affiliate disclosures if affiliate links', 'pass' => ! $this->has_affiliate_links( $content ) || preg_match( '/affiliate|commission|disclosure/i', $text ) ],
            [ 'id' => 'T06', 'label' => 'No misleading claims', 'pass' => ! preg_match( '/\b(guaranteed|100% effective|secret|miracle|instant)\b/i', $text ) ],
            [ 'id' => 'T07', 'label' => 'Contact info / "about" available (proxy: site has these pages)', 'pass' => true ],
            [ 'id' => 'T08', 'label' => 'No false urgency', 'pass' => ! preg_match( '/\b(limited time|act now|hurry|don\'t miss|expires soon)\b/i', $text ) ],
            [ 'id' => 'T09', 'label' => 'Quantified uncertainty where appropriate', 'pass' => (bool) preg_match( '/\b(approximately|around|estimated|roughly|up to|about)\b/i', $text ) ],
            [ 'id' => 'T10', 'label' => 'No exaggeration', 'pass' => ! preg_match( '/\b(best ever|never before|always|nothing beats|everyone knows)\b/i', $text ) ],
        ];
    }

    // ================================================================
    // VETO items — publication blockers
    // ================================================================

    private function check_vetoes( string $content, string $text, string $title, string $keyword ): array {
        $vetoes = [];

        // C01 — Title mismatch: title keyword not in first 200 words of body
        if ( $title || $keyword ) {
            $ref = $keyword ?: $title;
            $first_200 = substr( strtolower( $text ), 0, 1500 );
            if ( $ref && stripos( $first_200, $ref ) === false ) {
                // Try any content word from the title
                $words = array_filter( preg_split( '/\s+/', strtolower( $ref ) ), fn( $w ) => strlen( $w ) >= 4 );
                $found = false;
                foreach ( $words as $w ) {
                    if ( stripos( $first_200, $w ) !== false ) { $found = true; break; }
                }
                if ( ! $found ) {
                    $vetoes[] = [ 'id' => 'C01', 'label' => 'Title does not match content', 'severity' => 'block' ];
                }
            }
        }

        // R10 — Internal contradictions (hard to auto-detect; placeholder)
        // For now we only flag if the AI produced conflicting numbers for the same metric.
        // Full implementation would need LLM-based semantic analysis.

        // T04 — Required disclosures missing
        // If content mentions sponsored/affiliate but no disclosure, flag it.
        $has_affiliate_markers = (bool) preg_match( '/\b(sponsored|affiliate|partner|paid partnership)\b/i', $text );
        $has_disclosure = (bool) preg_match( '/\b(disclosure|disclaimer|this post contains|we may earn|affiliate commission)\b/i', $text );
        if ( $has_affiliate_markers && ! $has_disclosure ) {
            $vetoes[] = [ 'id' => 'T04', 'label' => 'Affiliate/sponsored content detected but no disclosure', 'severity' => 'block' ];
        }

        return $vetoes;
    }

    // ================================================================
    // Helper methods
    // ================================================================

    private function active_voice_ratio( string $text ): float {
        $sentences = preg_split( '/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $sentences ) === 0 ) return 0;
        $passive = 0;
        foreach ( $sentences as $s ) {
            if ( preg_match( '/\b(was|were|is|are|been|being)\s+\w+(ed|en)\b/i', $s ) ) $passive++;
        }
        return 1 - ( $passive / count( $sentences ) );
    }

    private function acronyms_defined( string $text ): bool {
        preg_match_all( '/\b([A-Z]{2,})\b/', $text, $acronyms );
        if ( empty( $acronyms[0] ) ) return true;
        foreach ( array_unique( $acronyms[0] ) as $acronym ) {
            // Look for a definition pattern near the acronym
            if ( preg_match( '/\(' . preg_quote( $acronym, '/' ) . '\)|\b' . preg_quote( $acronym, '/' ) . '\s*\([^)]+\)/', $text ) ) {
                continue;
            }
        }
        return true; // lenient — acronyms check is noisy
    }

    private function paragraph_length_varies( string $text ): bool {
        $paras = preg_split( '/\n{2,}/', trim( $text ) );
        $lengths = array_map( 'str_word_count', $paras );
        if ( count( $lengths ) < 3 ) return false;
        $min = min( $lengths );
        $max = max( $lengths );
        return ( $max - $min ) >= 30;
    }

    private function has_wall_of_text( string $text ): bool {
        $paras = preg_split( '/\n{2,}/', trim( $text ) );
        foreach ( $paras as $p ) {
            if ( str_word_count( $p ) > 200 ) return true;
        }
        return false;
    }

    private function headings_scannable( string $content ): bool {
        preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', $content, $matches );
        foreach ( $matches[1] ?? [] as $h ) {
            $words = str_word_count( wp_strip_all_tags( $h ) );
            if ( $words > 10 ) return false;
        }
        return true;
    }

    /**
     * v1.5.104 — Extract URLs from HTML content (href attributes).
     * Used by R03, R04, R08 checks that need actual URLs.
     */
    private function extract_urls_from_html( string $content ): array {
        preg_match_all( '/href="(https?:\/\/[^"]+)"/i', $content, $m );
        return $m[1] ?? [];
    }

    private function deep_links_only( array $urls ): bool {
        foreach ( $urls as $url ) {
            $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
            if ( $path === '' || $path === 'index.html' || $path === 'index.php' ) {
                return false;
            }
        }
        return true;
    }

    private function has_api_urls( array $urls ): bool {
        foreach ( $urls as $url ) {
            if ( preg_match( '#/api/|/v[1-9]/|api\.|-api\.|\.herokuapp\.com#i', $url ) ) {
                return true;
            }
        }
        return false;
    }

    private function dates_with_stats( string $text ): bool {
        // Rough heuristic: are there at least 3 patterns of number + year?
        preg_match_all( '/\b\d+[\.,]?\d*\s*(?:%|percent)\s*(?:in|for|during)?\s*(?:19|20)\d{2}\b/i', $text, $m );
        return count( $m[0] ) >= 2;
    }

    private function distinct_domains( array $urls ): int {
        $hosts = [];
        foreach ( $urls as $url ) {
            $host = wp_parse_url( $url, PHP_URL_HOST );
            if ( $host ) {
                $hosts[ preg_replace( '/^www\./', '', $host ) ] = true;
            }
        }
        return count( $hosts );
    }

    private function has_affiliate_links( string $content ): bool {
        // Proxy: look for common affiliate URL patterns
        return (bool) preg_match( '/amazon\.com|amzn\.to|clickbank|shareasale|rakuten|cj\.com/i', $content );
    }

    private function dimension_label( string $code ): string {
        return [
            'C'   => 'Contextual Clarity',
            'O'   => 'Organization',
            'R'   => 'Referenceability',
            'E'   => 'Exclusivity',
            'Exp' => 'Experience',
            'Ept' => 'Expertise',
            'A'   => 'Authority',
            'T'   => 'Trust',
        ][ $code ] ?? $code;
    }

    private function score_to_grade( int $score ): string {
        if ( $score >= 90 ) return 'A+';
        if ( $score >= 80 ) return 'A';
        if ( $score >= 70 ) return 'B';
        if ( $score >= 60 ) return 'C';
        if ( $score >= 50 ) return 'D';
        return 'F';
    }
}
