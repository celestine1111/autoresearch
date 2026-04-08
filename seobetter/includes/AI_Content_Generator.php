<?php

namespace SEOBetter;

/**
 * AI Content Generator.
 *
 * Generates GEO-optimized articles from a keyword using the user's own AI API key.
 * Implements the article creation protocol (v2026.4):
 * - BLUF header (Key Takeaways, 3 bullets)
 * - 40-60 word citation rule per section
 * - Island Test (no pronoun starts)
 * - Factual density (3+ stats per 1000 words)
 * - Expert quotes (2+ per article)
 * - Inline citations (5+ per article)
 * - Tables for comparisons
 * - JSON-LD schema at the end
 * - Markdown output for token efficiency
 *
 * Pro feature only.
 */
class AI_Content_Generator {

    /**
     * Generate a full GEO-optimized article from a keyword.
     *
     * Works for both free and pro users:
     * - With BYOK (own API key): unlimited, uses their provider
     * - Without key (Cloud): free = 5/month, pro = unlimited
     */
    public function generate( string $keyword, array $options = [] ): array {
        // Check generation allowance
        $gen_check = License_Manager::can_generate();
        if ( ! $gen_check['allowed'] ) {
            return [ 'success' => false, 'error' => $gen_check['message'] ];
        }

        $word_count = $options['word_count'] ?? 2000;
        $tone = $options['tone'] ?? 'authoritative';
        $audience = $options['audience'] ?? 'general';
        $domain = $options['domain'] ?? 'general';
        $primary_keyword = $keyword;
        $secondary_keywords = $options['secondary_keywords'] ?? [];
        $lsi_keywords = $options['lsi_keywords'] ?? [];

        $system_prompt = $this->build_system_prompt();

        // Fetch recent trends to inject fresh data
        $recent_trends = $this->fetch_recent_trends( $primary_keyword );

        // For long articles (1500+), chain multiple requests:
        // 1. Generate outline
        // 2. Generate each section separately
        // 3. Combine into full article
        if ( $word_count >= 1500 ) {
            $content = $this->generate_chained( $primary_keyword, $word_count, $tone, $audience, $domain, $secondary_keywords, $lsi_keywords, $system_prompt, $recent_trends );
        } else {
            $content = $this->generate_single( $primary_keyword, $word_count, $tone, $audience, $domain, $secondary_keywords, $lsi_keywords, $system_prompt, $recent_trends );
        }

        if ( is_array( $content ) && isset( $content['success'] ) && ! $content['success'] ) {
            return $content; // Error from generation
        }

        // Auto-insert stock images
        $image_inserter = new Stock_Image_Inserter();
        $content = $image_inserter->insert_images( $content, $primary_keyword );

        // Post-process: convert Markdown to visually formatted WordPress content
        $editor_mode = $options['editor_mode'] ?? 'auto';
        $formatter = new Content_Formatter();
        $html = $formatter->format( $content, $editor_mode, [
            'accent_color' => $options['accent_color'] ?? '#764ba2',
        ] );

        // Run GEO analysis on the generated content
        $analyzer = new GEO_Analyzer();
        $score = $analyzer->analyze( $html, $keyword );

        // Generate 5 headline variations
        $headlines = $this->generate_headlines( $keyword, wp_strip_all_tags( $html ) );

        // Generate meta tags
        $meta = $this->generate_meta_tags( $keyword, wp_strip_all_tags( $html ) );

        return [
            'success'    => true,
            'content'    => $html,
            'markdown'   => $content,
            'keyword'    => $keyword,
            'geo_score'  => $score['geo_score'],
            'grade'      => $score['grade'],
            'word_count' => str_word_count( wp_strip_all_tags( $html ) ),
            'model_used' => 'chained',
            'suggestions' => $score['suggestions'],
            'headlines'  => $headlines,
            'meta'       => $meta,
        ];
    }

    /**
     * Generate 5 headline variations for the article.
     * Based on copywriting skill: power words, numbers, emotional triggers, curiosity gaps.
     */
    public function generate_headlines( string $keyword, string $article_text = '' ): array {
        $context = $article_text ? "\n\nArticle summary: " . substr( $article_text, 0, 300 ) : '';
        $prompt = "Generate exactly 5 headline variations for an article about: \"{$keyword}\"{$context}

CRITICAL RULE: Every single headline MUST contain the exact phrase \"{$keyword}\" — no exceptions. If the keyword is multiple words, include ALL words.

Rules:
1. Each headline must be 50-60 characters (for full SERP display)
2. The keyword \"{$keyword}\" must appear in ALL 5 headlines
3. Front-load the keyword (put it in the first half of the headline) in at least 3 of 5
4. Use different headline formulas:
   - #1: Number + \"{$keyword}\" + Benefit (e.g., \"7 Best {$keyword} for [Outcome] in 2026\")
   - #2: How-to + \"{$keyword}\" (e.g., \"How to Choose {$keyword}: Expert Guide\")
   - #3: Question + \"{$keyword}\" (e.g., \"What Are the Best {$keyword}? Guide\")
   - #4: \"{$keyword}\" + Power words (e.g., \"{$keyword}: Essential Guide You Need\")
   - #5: \"{$keyword}\" + Current year (e.g., \"{$keyword} in 2026: What You Must Know\")

Return ONLY the 5 headlines, numbered 1-5, one per line. No explanations.";

        $result = $this->send_ai_request( $prompt, 'You are an expert copywriter who writes headlines that get clicks. Return only the numbered list.', [ 'max_tokens' => 400 ] );

        if ( ! $result['success'] ) {
            return [];
        }

        $lines = explode( "\n", trim( $result['content'] ) );
        $headlines = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( preg_match( '/^\d+[\.\)]\s*(.+)$/', $line, $m ) ) {
                $hl = trim( $m[1], '"\'*' );
                // Only keep headlines that contain the keyword
                if ( stripos( $hl, $keyword ) !== false ) {
                    $headlines[] = $hl;
                }
            }
        }

        // If filtering removed too many, add keyword-prefixed fallbacks
        if ( count( $headlines ) < 3 ) {
            $fallbacks = [
                ucwords( $keyword ) . ': Complete Guide for ' . wp_date( 'Y' ),
                'Best ' . ucwords( $keyword ) . ' — Expert Review ' . wp_date( 'Y' ),
                'How to Choose ' . ucwords( $keyword ) . ': Buyer\'s Guide',
            ];
            foreach ( $fallbacks as $fb ) {
                if ( count( $headlines ) >= 5 ) break;
                $headlines[] = $fb;
            }
        }

        return array_slice( $headlines, 0, 5 );
    }

    /**
     * Generate SEO meta title + description with CTR scoring.
     * Based on meta-tags-optimizer skill.
     */
    public function generate_meta_tags( string $keyword, string $article_text = '' ): array {
        $summary = $article_text ? substr( $article_text, 0, 500 ) : '';
        $prompt = "Generate SEO meta tags for an article about: \"{$keyword}\"

Article summary: {$summary}

Return in this exact format:
TITLE: [50-60 chars, keyword front-loaded, power word included]
DESCRIPTION: [150-160 chars, MUST include the exact phrase \"{$keyword}\", has a call-to-action, reads like an ad]
OG_TITLE: [60-90 chars, slightly more compelling than TITLE, can be longer]

Rules:
- Title MUST be 50-60 characters and contain \"{$keyword}\"
- Description MUST be 150-160 characters and MUST contain the exact phrase \"{$keyword}\"
- Front-load the keyword \"{$keyword}\" in the title (first half)
- Include a number or year if relevant
- Description should create urgency or curiosity
- No clickbait, must be accurate to content
- CRITICAL: The exact phrase \"{$keyword}\" must appear in both TITLE and DESCRIPTION";

        $result = $this->send_ai_request( $prompt, 'You are an SEO meta tag specialist. Return only the requested format.', [ 'max_tokens' => 300 ] );

        if ( ! $result['success'] ) {
            return [ 'title' => '', 'description' => '', 'og_title' => '' ];
        }

        $meta = [ 'title' => '', 'description' => '', 'og_title' => '' ];
        $lines = explode( "\n", $result['content'] );
        foreach ( $lines as $line ) {
            if ( preg_match( '/^TITLE:\s*(.+)$/i', trim( $line ), $m ) ) {
                $meta['title'] = trim( $m[1] );
            } elseif ( preg_match( '/^DESCRIPTION:\s*(.+)$/i', trim( $line ), $m ) ) {
                $meta['description'] = trim( $m[1] );
            } elseif ( preg_match( '/^OG_TITLE:\s*(.+)$/i', trim( $line ), $m ) ) {
                $meta['og_title'] = trim( $m[1] );
            }
        }

        // CTR scoring
        $meta['title_length'] = mb_strlen( $meta['title'] );
        $meta['desc_length'] = mb_strlen( $meta['description'] );
        $meta['title_score'] = $this->score_meta_title( $meta['title'], $keyword );
        $meta['desc_score'] = $this->score_meta_description( $meta['description'], $keyword );

        return $meta;
    }

    /**
     * Generate topic suggestions for a niche.
     * Based on content-strategy + content-gap-analysis skills.
     */
    public function suggest_topics( string $niche, string $audience = '', int $count = 10 ): array {
        $audience_ctx = $audience ? "\nTarget audience: {$audience}" : '';
        $prompt = "Suggest {$count} high-value article topics for the niche: \"{$niche}\"{$audience_ctx}

For each topic provide:
- TOPIC: [article title/keyword]
- INTENT: [informational/commercial/transactional]
- DIFFICULTY: [low/medium/high]
- WHY: [1 sentence on why this topic will drive traffic]

Mix of:
- 4 informational (how-to, guides, explanations)
- 3 commercial (comparisons, reviews, best-of lists)
- 2 transactional (buying guides, product roundups)
- 1 trending/timely topic

Prioritize topics where:
- Search volume is likely high but competition may be low
- AI models (ChatGPT, Perplexity, Google AI Overviews) would cite a well-written article
- The topic can include comparison tables, statistics, and expert quotes (GEO signals)

Return exactly {$count} topics in the format above.";

        $result = $this->send_ai_request( $prompt, 'You are an SEO content strategist specializing in topic research and GEO optimization.', [ 'max_tokens' => 2000 ] );

        if ( ! $result['success'] ) {
            return $result;
        }

        // Parse topics
        $topics = [];
        $current = [];
        foreach ( explode( "\n", $result['content'] ) as $line ) {
            $line = trim( $line );
            if ( preg_match( '/^TOPIC:\s*(.+)$/i', $line, $m ) ) {
                if ( ! empty( $current ) ) $topics[] = $current;
                $current = [ 'topic' => trim( $m[1], '"*' ) ];
            } elseif ( preg_match( '/^INTENT:\s*(.+)$/i', $line, $m ) ) {
                $current['intent'] = trim( $m[1] );
            } elseif ( preg_match( '/^DIFFICULTY:\s*(.+)$/i', $line, $m ) ) {
                $current['difficulty'] = trim( $m[1] );
            } elseif ( preg_match( '/^WHY:\s*(.+)$/i', $line, $m ) ) {
                $current['why'] = trim( $m[1] );
            }
        }
        if ( ! empty( $current ) ) $topics[] = $current;

        return [ 'success' => true, 'topics' => $topics, 'raw' => $result['content'] ];
    }

    /**
     * Generate social media content from an article.
     * Based on social-content skill.
     */
    public function generate_social_content( string $article_text, string $keyword, string $url = '' ): array {
        $summary = substr( wp_strip_all_tags( $article_text ), 0, 1500 );
        $url_line = $url ? "\nArticle URL: {$url}" : '';

        $prompt = "Create social media content from this article about \"{$keyword}\".{$url_line}

Article content:
{$summary}

Generate ALL THREE formats:

=== TWITTER THREAD ===
Write a 5-tweet thread. Tweet 1 is the hook (must stop the scroll). Tweets 2-4 are key insights with stats/quotes from the article. Tweet 5 is the CTA with link.
- Each tweet max 280 chars
- Use line breaks for readability
- Include 1-2 relevant hashtags on tweet 1 and 5 only

=== LINKEDIN POST ===
Write a LinkedIn post (150-300 words).
- Hook line (first line visible before \"see more\")
- 3-4 key insights as short paragraphs
- End with a question to drive comments
- Add 3-5 relevant hashtags at the end

=== INSTAGRAM CAPTION ===
Write an Instagram caption.
- Hook line
- 3 key takeaways with emoji bullets
- CTA (save this post, share with someone who needs this)
- 20-30 relevant hashtags on a separate line

Use the article's statistics, expert quotes, and key facts. Make each piece standalone — someone should get value without clicking the link.";

        $result = $this->send_ai_request( $prompt, 'You are a social media content expert who creates viral, engagement-driving posts from articles. Write in a punchy, direct style.', [ 'max_tokens' => 3000 ] );

        if ( ! $result['success'] ) {
            return $result;
        }

        // Parse the three formats
        $content = $result['content'];
        $social = [ 'twitter' => '', 'linkedin' => '', 'instagram' => '' ];

        if ( preg_match( '/TWITTER\s*THREAD\s*===?\s*\n(.*?)(?====\s*LINKEDIN|$)/is', $content, $m ) ) {
            $social['twitter'] = trim( $m[1] );
        }
        if ( preg_match( '/LINKEDIN\s*POST\s*===?\s*\n(.*?)(?====\s*INSTAGRAM|$)/is', $content, $m ) ) {
            $social['linkedin'] = trim( $m[1] );
        }
        if ( preg_match( '/INSTAGRAM\s*CAPTION\s*===?\s*\n(.*?)$/is', $content, $m ) ) {
            $social['instagram'] = trim( $m[1] );
        }

        // Fallback if parsing failed
        if ( ! $social['twitter'] && ! $social['linkedin'] ) {
            $social['raw'] = $content;
        }

        return [ 'success' => true, 'social' => $social ];
    }

    /**
     * Fetch recent trends for a keyword to inject fresh data into articles.
     * Based on last30days skill.
     */
    /**
     * Fetch recent trends for a keyword.
     * Uses Last30Days real web research if available, otherwise AI fallback.
     */
    public function fetch_recent_trends( string $keyword ): string {
        $research = Trend_Researcher::research( $keyword );
        return $research['for_prompt'] ?? '';
    }

    private function score_meta_title( string $title, string $keyword ): int {
        $score = 0;
        $len = mb_strlen( $title );
        if ( $len >= 50 && $len <= 60 ) $score += 30; elseif ( $len >= 40 && $len <= 70 ) $score += 15;
        if ( stripos( $title, $keyword ) !== false ) $score += 30;
        if ( stripos( $title, $keyword ) === 0 || stripos( $title, $keyword ) <= 5 ) $score += 10; // front-loaded
        if ( preg_match( '/\d/', $title ) ) $score += 10; // has number
        if ( preg_match( '/20[2-3]\d/', $title ) ) $score += 10; // has year
        $power_words = [ 'best', 'ultimate', 'complete', 'essential', 'proven', 'expert', 'top', 'guide', 'review' ];
        foreach ( $power_words as $pw ) { if ( stripos( $title, $pw ) !== false ) { $score += 10; break; } }
        return min( 100, $score );
    }

    private function score_meta_description( string $desc, string $keyword ): int {
        $score = 0;
        $len = mb_strlen( $desc );
        if ( $len >= 150 && $len <= 160 ) $score += 30; elseif ( $len >= 120 && $len <= 170 ) $score += 15;
        if ( stripos( $desc, $keyword ) !== false ) $score += 25;
        if ( preg_match( '/\d/', $desc ) ) $score += 10;
        $cta_words = [ 'learn', 'discover', 'find out', 'get', 'check', 'see', 'read', 'explore', 'compare' ];
        foreach ( $cta_words as $cta ) { if ( stripos( $desc, $cta ) !== false ) { $score += 15; break; } }
        if ( preg_match( '/[.!?]$/', trim( $desc ) ) ) $score += 10; // proper ending
        if ( preg_match( '/\b(free|save|best|top|expert|proven)\b/i', $desc ) ) $score += 10;
        return min( 100, $score );
    }

    /**
     * Single-request generation for short articles.
     */
    private function generate_single( string $keyword, int $word_count, string $tone, string $audience, string $domain, array $secondary, array $lsi, string $system, string $trends = '' ): string|array {
        $prompt = $this->build_user_prompt( $keyword, $word_count, $tone, $audience, $domain, $secondary, $lsi );
        if ( $trends ) {
            $prompt .= "\n\nRECENT DATA TO INCLUDE (integrate naturally as statistics/citations):\n{$trends}";
        }
        $result = $this->send_ai_request( $prompt, $system, [ 'max_tokens' => 4096 ] );

        if ( ! $result['success'] ) {
            return $result;
        }
        return $result['content'];
    }

    /**
     * Chained multi-request generation for long articles (1500+ words).
     * Step 1: Generate a detailed outline with section headings
     * Step 2: Generate each section individually (~400-600 words each)
     * Step 3: Combine into a full article
     */
    private function generate_chained( string $keyword, int $word_count, string $tone, string $audience, string $domain, array $secondary, array $lsi, string $system, string $trends = '' ): string|array {
        $date = wp_date( 'F Y' );
        $kw_context = '';
        if ( ! empty( $secondary ) ) {
            $kw_context .= "\nSecondary keywords: " . implode( ', ', $secondary );
        }
        if ( ! empty( $lsi ) ) {
            $kw_context .= "\nLSI keywords: " . implode( ', ', $lsi );
        }

        $num_sections = max( 4, round( $word_count / 400 ) );
        $words_per_section = round( $word_count / $num_sections );

        // Step 1: Generate outline
        $outline_prompt = "Create an article outline for: \"{$keyword}\"\n{$kw_context}\n\nRequirements:\n- {$num_sections} H2 sections with question-format headings where possible\n- Include a Key Takeaways section at the start\n- Include a FAQ section (3-5 questions) near the end\n- Include a References section at the end\n- Target audience: {$audience}\n- Domain: {$domain}\n- Tone: {$tone}\n\nReturn ONLY the outline as a numbered list of H2 headings, one per line. Example:\n1. Key Takeaways\n2. What Are the Best [Topic]?\n3. How Does [Topic] Compare?\n...\nN. Frequently Asked Questions\nN+1. References";

        $outline_result = $this->send_ai_request( $outline_prompt, 'You are an SEO content strategist. Return only the numbered list of headings.', [ 'max_tokens' => 500 ] );

        if ( ! $outline_result['success'] ) {
            return $outline_result;
        }

        // Parse outline into section headings
        $headings = [];
        $lines = explode( "\n", trim( $outline_result['content'] ) );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( preg_match( '/^\d+[\.\)]\s*(.+)$/', $line, $m ) ) {
                $headings[] = trim( $m[1] );
            }
        }

        if ( count( $headings ) < 3 ) {
            // Fallback: generate as single request
            return $this->generate_single( $keyword, $word_count, $tone, $audience, $domain, $secondary, $lsi, $system );
        }

        // Step 2: Generate each section
        $full_article = "Last Updated: {$date}\n\n# {$keyword}\n\n";

        foreach ( $headings as $i => $heading ) {
            $is_first = ( $i === 0 );
            $is_takeaways = preg_match( '/key\s*takeaway/i', $heading );
            $is_faq = preg_match( '/faq|frequently\s*asked/i', $heading );
            $is_references = preg_match( '/reference/i', $heading );

            if ( $is_takeaways ) {
                $section_prompt = "Write the Key Takeaways section for an article about \"{$keyword}\".\n\nReturn exactly:\n## Key Takeaways\n- [Takeaway 1]\n- [Takeaway 2]\n- [Takeaway 3]\n\nEach takeaway should be 15-25 words summarizing a core insight. Be specific with numbers/facts.";
                $max = 300;
            } elseif ( $is_faq ) {
                $section_prompt = "Write an FAQ section for an article about \"{$keyword}\".\n{$kw_context}\n\nReturn 3-5 question-answer pairs. Each answer should be 40-60 words (optimized for featured snippets and People Also Ask). Format:\n\n## Frequently Asked Questions\n\n### [Question]?\n[Answer paragraph]\n\nNever start answers with pronouns (Island Test).";
                $max = 1500;
            } elseif ( $is_references ) {
                $section_prompt = "Write a References section for an article about \"{$keyword}\". Include 5-8 realistic references with source names and years. Format as a numbered Markdown list.";
                $max = 500;
            } else {
                $trends_inject = ( $trends && $i <= 3 ) ? "\n\nRECENT DATA TO INCLUDE (use 1-2 of these naturally):\n{$trends}" : '';
                $section_prompt = "Write section {$i} of an article about \"{$keyword}\".\n{$kw_context}\n\nSection heading: \"{$heading}\"\nTarget: {$words_per_section} words\nTone: {$tone}\nAudience: {$audience}{$trends_inject}\n\nRULES:\n- Start with ## {$heading}\n- First paragraph MUST be 40-60 words and directly answer the heading\n- Include 1-2 statistics with (Source, Year) attribution\n- Include 1 expert quote if relevant\n- Include inline citations in [Source, Year] format\n- Include a comparison table if this section involves comparing items\n- NEVER start paragraphs with pronouns (It, This, They)\n- Use **Bold** for key entities\n- Use bullet/numbered lists where appropriate\n\nOutput pure Markdown for this section only.";
                $max = 2000;
            }

            $section_result = $this->send_ai_request( $section_prompt, $system, [ 'max_tokens' => $max, 'temperature' => 0.7 ] );

            if ( $section_result['success'] ) {
                $full_article .= trim( $section_result['content'] ) . "\n\n";
            }
        }

        return $full_article;
    }

    /**
     * Send a request to either BYOK provider or Cloud.
     */
    private function send_ai_request( string $prompt, string $system, array $options = [] ): array {
        $provider = AI_Provider_Manager::get_active_provider();
        if ( $provider ) {
            return AI_Provider_Manager::send_request( $provider['provider_id'], $prompt, $system, $options );
        }
        return Cloud_API::generate( $prompt, $system, $options );
    }

    /**
     * Generate an article outline before full generation.
     */
    public function generate_outline( string $keyword, array $options = [] ): array {
        $gen_check = License_Manager::can_generate();
        if ( ! $gen_check['allowed'] ) {
            return [ 'success' => false, 'error' => $gen_check['message'] ];
        }

        $provider = AI_Provider_Manager::get_active_provider();

        $prompt = "Create a detailed SEO article outline for the keyword: \"{$keyword}\"

Return a structured outline with:
1. A compelling title (50-60 chars, keyword front-loaded)
2. Key Takeaways section (3 bullet points)
3. 5-8 H2 sections, each with:
   - Question-format heading where possible (for featured snippets + PAA)
   - 2-3 sub-points to cover
   - Suggested data/statistics to include
4. Suggest 1 comparison table topic
5. Suggest 2 expert quotes to seek
6. Suggest FAQ questions (3-5)

Format as clean Markdown.";

        $system = 'You are an expert SEO content strategist specializing in GEO (Generative Engine Optimization).';
        $request_options = [ 'max_tokens' => 2048, 'temperature' => 0.7 ];

        if ( $provider ) {
            $result = AI_Provider_Manager::send_request( $provider['provider_id'], $prompt, $system, $request_options );
        } else {
            $result = Cloud_API::generate( $prompt, $system, $request_options );
        }

        return $result;
    }

    /**
     * Enhance existing content with GEO optimization.
     */
    public function enhance_content( string $content, array $methods = [] ): array {
        if ( ! License_Manager::can_use( 'geo_optimizer' ) ) {
            return [ 'success' => false, 'error' => 'Content enhancement requires SEOBetter Pro.' ];
        }

        $provider = AI_Provider_Manager::get_active_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No AI provider configured.' ];
        }

        if ( empty( $methods ) ) {
            $optimizer = new GEO_Optimizer();
            $domain = $optimizer->detect_domain( $content );
            $methods = $optimizer->get_domain_strategy( $domain );
        }

        $method_instructions = $this->build_enhancement_instructions( $methods );

        $prompt = "Enhance this article content using these specific GEO optimization methods:\n\n{$method_instructions}\n\nOriginal content:\n\n{$content}\n\nReturn the enhanced content in Markdown format. Preserve the original structure but apply the requested enhancements.";

        $system = "You are an expert content optimizer specializing in Generative Engine Optimization (GEO). Your changes should make content more likely to be cited by AI models (Google AI Overviews, Perplexity, ChatGPT, Gemini, Claude). Never use keyword stuffing — research proves it HURTS visibility by 8%.";

        $result = AI_Provider_Manager::send_request(
            $provider['provider_id'],
            $prompt,
            $system,
            [ 'max_tokens' => 8192, 'temperature' => 0.5 ]
        );

        if ( ! $result['success'] ) {
            return $result;
        }

        $html = $this->markdown_to_html( $result['content'] );

        return [
            'success'   => true,
            'content'   => $html,
            'markdown'  => $result['content'],
            'methods'   => $methods,
        ];
    }

    /**
     * Build the system prompt implementing the article protocol v2026.4.
     */
    private function build_system_prompt(): string {
        return <<<'PROMPT'
You are an expert SEO content writer. Follow these rules exactly:

## READABILITY (MOST IMPORTANT)
- Write at a 6th-8th GRADE reading level
- Use SHORT sentences (under 20 words each)
- Use SIMPLE, everyday words (use "buy" not "purchase", "help" not "facilitate")
- A smart 12-year-old should understand every sentence
- No academic jargon, no complex vocabulary

## WORD COUNT
- Always write the FULL number of words requested
- If asked for 400 words per section, write at least 400
- Being too short is a failure — write more, not less

## STRUCTURE
- Start with ## Key Takeaways (3 bullet points)
- Every H2/H3 section starts with a 40-60 word paragraph answering the heading
- NEVER start paragraphs with pronouns (It, This, They, These, Those)

## EVIDENCE
- 3+ statistics per 1,000 words with (Source Name, Year)
- 2+ expert quotes: "Quote" — Dr. Name, Title (Source, Year)
- 5+ inline citations in [Source, Year] format
- At least 1 comparison table in Markdown

## FORMAT
- GitHub Flavored Markdown
- **Bold** for key terms
- Tables for comparisons
- Bullet/numbered lists
- "Last Updated: [Month Year]" at top
- FAQ section with 3-5 Q&A pairs
- References section at end

## BLOCKED (hurts visibility)
- Keyword stuffing (-8%)
- Starting paragraphs with pronouns
- Complex academic language
PROMPT;
    }

    /**
     * Build the user prompt for article generation.
     */
    private function build_user_prompt( string $keyword, int $word_count, string $tone, string $audience, string $domain, array $secondary_keywords = [], array $lsi_keywords = [] ): string {
        $date = wp_date( 'F Y' );

        $prompt = "Write a comprehensive, GEO-optimized article for the primary keyword: \"{$keyword}\"";

        if ( ! empty( $secondary_keywords ) ) {
            $prompt .= "\n\nSecondary keywords to naturally incorporate throughout the article:\n- " . implode( "\n- ", $secondary_keywords );
        }

        if ( ! empty( $lsi_keywords ) ) {
            $prompt .= "\n\nLSI/semantic keywords to weave in naturally (do NOT stuff):\n- " . implode( "\n- ", $lsi_keywords );
        }

        $prompt .= "

Requirements:
- Target length: {$word_count} words
- Tone: {$tone}
- Target audience: {$audience}
- Content domain: {$domain}
- Current date for freshness signal: {$date}

Follow ALL rules from the Article Protocol v2026.4 in your system instructions. The article must score 80+ on a GEO analysis that checks:
- BLUF header presence (Key Takeaways with 3 bullets at the top)
- 40-60 word section openings after every H2/H3
- Island Test (never start paragraphs with It, This, They, etc.)
- 3+ verifiable statistics per 1000 words with (Source, Year) attribution
- 2+ direct expert quotes with credentials
- 5+ inline citations in [Source, Year] format
- At least 1 comparison table
- \"Last Updated: {$date}\" freshness signal at the top
- Question-format H2/H3 headings (for featured snippets + People Also Ask)
- FAQ section with 3-5 Q&A pairs near the end
- References section at the bottom with linked sources

Output pure GitHub Flavored Markdown.";

        return $prompt;
    }

    /**
     * Build enhancement instructions for specific GEO methods.
     */
    private function build_enhancement_instructions( array $methods ): string {
        $instructions = [];

        $method_map = [
            'statistics'  => 'STATISTICS ADDITION (+30% visibility): Add verifiable statistics with source attribution wherever claims are made. Use format: (Source Name, Year). Target: 3+ per 1000 words.',
            'quotations'  => 'QUOTATION ADDITION (+41% visibility): Add 2+ direct quotes from credentialed experts. Include their title and organization. This provides the HIGHEST visibility boost.',
            'citations'   => 'CITE SOURCES (+28% visibility): Add inline citations in [Source, Year] format throughout. Target: 5+ per article. Add a References section at the end.',
            'fluency'     => 'FLUENCY OPTIMIZATION (+27% visibility): Improve sentence flow, reduce awkward phrasing, ensure smooth transitions between ideas.',
            'authoritative' => 'AUTHORITATIVE TONE (+10% visibility): Make the writing more persuasive and confident. Use active voice, decisive language.',
            'technical_terms' => 'TECHNICAL TERMS (+18% visibility): Add precise industry terminology where appropriate.',
            'easy_to_understand' => 'SIMPLIFY LANGUAGE (+14% visibility): Reduce reading level to grade 6-8. Use shorter sentences and simpler words.',
        ];

        foreach ( $methods as $method ) {
            if ( isset( $method_map[ $method ] ) ) {
                $instructions[] = $method_map[ $method ];
            }
        }

        return implode( "\n\n", $instructions );
    }

    /**
     * Convert Markdown to HTML (basic conversion for WordPress).
     */
    private function markdown_to_html( string $markdown ): string {
        // Headers
        $html = preg_replace( '/^######\s+(.+)$/m', '<h6>$1</h6>', $markdown );
        $html = preg_replace( '/^#####\s+(.+)$/m', '<h5>$1</h5>', $html );
        $html = preg_replace( '/^####\s+(.+)$/m', '<h4>$1</h4>', $html );
        $html = preg_replace( '/^###\s+(.+)$/m', '<h3>$1</h3>', $html );
        $html = preg_replace( '/^##\s+(.+)$/m', '<h2>$1</h2>', $html );
        $html = preg_replace( '/^#\s+(.+)$/m', '<h1>$1</h1>', $html );

        // Bold and italic
        $html = preg_replace( '/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $html );
        $html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
        $html = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $html );

        // Links
        $html = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html );

        // Unordered lists
        $html = preg_replace_callback( '/(?:^- .+\n?)+/m', function ( $matches ) {
            $items = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $matches[0] );
            return '<ul>' . $items . '</ul>';
        }, $html );

        // Ordered lists
        $html = preg_replace_callback( '/(?:^\d+\. .+\n?)+/m', function ( $matches ) {
            $items = preg_replace( '/^\d+\. (.+)$/m', '<li>$1</li>', $matches[0] );
            return '<ol>' . $items . '</ol>';
        }, $html );

        // Blockquotes
        $html = preg_replace( '/^>\s*(.+)$/m', '<blockquote>$1</blockquote>', $html );

        // Simple table conversion
        $html = preg_replace_callback( '/\|(.+)\|\n\|[-| ]+\|\n((?:\|.+\|\n?)+)/m', function ( $matches ) {
            $headers = explode( '|', trim( $matches[1], '| ' ) );
            $rows = explode( "\n", trim( $matches[2] ) );

            $table = '<table><thead><tr>';
            foreach ( $headers as $h ) {
                $table .= '<th>' . trim( $h ) . '</th>';
            }
            $table .= '</tr></thead><tbody>';

            foreach ( $rows as $row ) {
                if ( empty( trim( $row ) ) ) continue;
                $cells = explode( '|', trim( $row, '| ' ) );
                $table .= '<tr>';
                foreach ( $cells as $cell ) {
                    $table .= '<td>' . trim( $cell ) . '</td>';
                }
                $table .= '</tr>';
            }
            $table .= '</tbody></table>';
            return $table;
        }, $html );

        // Paragraphs: wrap non-tagged lines
        $lines = explode( "\n", $html );
        $result = [];
        foreach ( $lines as $line ) {
            $trimmed = trim( $line );
            if ( empty( $trimmed ) ) {
                $result[] = '';
                continue;
            }
            if ( preg_match( '/^<(h[1-6]|ul|ol|li|table|thead|tbody|tr|th|td|blockquote|hr|p)/', $trimmed ) ) {
                $result[] = $trimmed;
            } else {
                $result[] = '<p>' . $trimmed . '</p>';
            }
        }

        return implode( "\n", array_filter( $result ) );
    }
}
