<?php

namespace SEOBetter;

/**
 * Async Article Generator.
 *
 * Breaks article generation into discrete steps processed via AJAX.
 * Each step makes ONE API call, stores the result in a transient,
 * and returns immediately. The browser orchestrates the sequence
 * and shows live progress.
 *
 * This prevents PHP timeout issues with slow models (Opus, etc.)
 * and gives users real-time feedback on generation progress.
 */
class Async_Generator {

    private const TRANSIENT_PREFIX = 'seobetter_job_';
    private const TRANSIENT_TTL = 3600; // 1 hour

    /**
     * Model speed estimates (seconds per API call).
     */
    private const MODEL_SPEEDS = [
        'claude-opus-4-6'          => 90,
        'claude-sonnet-4-6'        => 25,
        'claude-haiku-4-5-20251001' => 15,
        'gpt-4o'                   => 30,
        'gpt-4o-mini'              => 15,
        'gpt-4.1'                  => 30,
        'o3'                       => 60,
        'gemini-2.5-pro'           => 40,
        'gemini-2.5-flash'         => 15,
        'llama-3.3-70b-versatile'  => 20,
        // OpenRouter models (slower due to proxy)
        'anthropic/claude-opus-4'  => 120,
        'anthropic/claude-sonnet-4' => 35,
        'openai/gpt-4o'            => 40,
    ];

    /**
     * Start a new generation job.
     */
    public static function start_job( array $params ): array {
        $gen_check = License_Manager::can_generate();
        if ( ! $gen_check['allowed'] ) {
            return [ 'success' => false, 'error' => $gen_check['message'] ];
        }

        $job_id = 'j' . bin2hex( random_bytes( 8 ) );
        $keyword = sanitize_text_field( $params['keyword'] ?? '' );

        if ( empty( $keyword ) ) {
            return [ 'success' => false, 'error' => 'Keyword is required.' ];
        }

        $word_count = absint( $params['word_count'] ?? 2000 );
        // Content sections + takeaways + FAQ + references
        $content_sections = max( 3, min( 8, round( $word_count / 400 ) ) );
        $num_sections = $content_sections + 3;

        // Build the step queue
        $steps = [ 'trends', 'outline' ];
        for ( $i = 0; $i < $num_sections; $i++ ) {
            $steps[] = 'section_' . $i;
        }
        $steps[] = 'headlines';
        $steps[] = 'meta';
        $steps[] = 'assemble';

        $job = [
            'id'          => $job_id,
            'status'      => 'running',
            'keyword'     => $keyword,
            'options'     => [
                'word_count'         => $word_count,
                'tone'               => sanitize_text_field( $params['tone'] ?? 'authoritative' ),
                'audience'           => sanitize_text_field( $params['audience'] ?? '' ),
                'domain'             => sanitize_text_field( $params['domain'] ?? 'general' ),
                'secondary_keywords' => sanitize_text_field( $params['secondary_keywords'] ?? '' ),
                'lsi_keywords'       => sanitize_text_field( $params['lsi_keywords'] ?? '' ),
                'accent_color'       => sanitize_text_field( $params['accent_color'] ?? '#764ba2' ),
                'country'            => sanitize_text_field( $params['country'] ?? '' ),
                'language'           => sanitize_text_field( $params['language'] ?? 'en' ),
                'content_type'       => sanitize_text_field( $params['content_type'] ?? 'blog_post' ),
            ],
            'steps'       => $steps,
            'current'     => 0,
            'total_steps' => count( $steps ),
            'results'     => [],
            'headings'    => [],
            'article'     => '',
            'created'     => time(),
        ];

        set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TRANSIENT_TTL );

        // Estimate time
        $provider = AI_Provider_Manager::get_active_provider();
        $model = $provider['model'] ?? 'default';
        $per_call = self::MODEL_SPEEDS[ $model ] ?? 30;
        $est_seconds = $per_call * ( count( $steps ) - 1 ); // -1 for assemble (instant)
        $est_minutes = max( 1, round( $est_seconds / 60 ) );

        return [
            'success'      => true,
            'job_id'       => $job_id,
            'total_steps'  => count( $steps ),
            'est_minutes'  => $est_minutes,
            'first_step'   => $steps[0],
        ];
    }

    /**
     * Process the next step in a job.
     */
    public static function process_step( string $job_id ): array {
        $job = get_transient( self::TRANSIENT_PREFIX . $job_id );
        if ( ! $job ) {
            return [ 'success' => false, 'error' => 'Job not found or expired.' ];
        }

        if ( $job['status'] === 'completed' ) {
            return [ 'success' => true, 'done' => true, 'progress' => 100 ];
        }

        // Extend PHP timeout for this request
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        $step_index = $job['current'];
        if ( $step_index >= count( $job['steps'] ) ) {
            $job['status'] = 'completed';
            set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TRANSIENT_TTL );
            return [ 'success' => true, 'done' => true, 'progress' => 100 ];
        }

        $step = $job['steps'][ $step_index ];
        $keyword = $job['keyword'];
        $options = $job['options'];
        $secondary = array_filter( array_map( 'trim', explode( ',', $options['secondary_keywords'] ) ) );
        $lsi = array_filter( array_map( 'trim', explode( ',', $options['lsi_keywords'] ) ) );

        $generator = new AI_Content_Generator();
        $system = self::get_system_prompt( $options['language'] ?? 'en' );
        $result = null;
        $step_label = '';

        try {
            if ( $step === 'trends' ) {
                $step_label = Trend_Researcher::is_available()
                    ? 'Researching real-time trends (Reddit, X, YouTube, Web)...'
                    : 'Researching recent trends...';

                $research = Trend_Researcher::research( $keyword, $options['domain'] ?? 'general', $options['country'] ?? '' );
                $job['results']['trends'] = $research['for_prompt'] ?? '';
                $job['results']['trend_source'] = $research['source'] ?? 'unknown';

                // Detect search intent from keyword to adapt article structure
                $job['results']['intent'] = self::detect_intent( $keyword );

            } elseif ( $step === 'outline' ) {
                $step_label = 'Creating article outline...';
                $outline = self::generate_outline( $keyword, $options, $secondary, $lsi );
                if ( ! $outline['success'] ) {
                    return self::step_error( $job, $job_id, $step_index, $outline['error'] ?? 'Outline generation failed.' );
                }
                $job['headings'] = $outline['headings'];
                $job['results']['outline'] = $outline;

            } elseif ( str_starts_with( $step, 'section_' ) ) {
                $section_idx = (int) str_replace( 'section_', '', $step );
                $headings = $job['headings'];

                if ( empty( $headings ) || ! isset( $headings[ $section_idx ] ) ) {
                    // Skip this section if no heading
                    $job['current'] = $step_index + 1;
                    set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TRANSIENT_TTL );
                    $progress = round( ( $step_index + 1 ) / $job['total_steps'] * 100 );
                    return [ 'success' => true, 'done' => false, 'step' => $step, 'label' => 'Skipping...', 'progress' => $progress ];
                }

                $heading = $headings[ $section_idx ];
                $total_sections = count( $headings );
                $step_label = "Writing section " . ( $section_idx + 1 ) . " of {$total_sections}...";

                $section_content = self::generate_section(
                    $keyword, $heading, $section_idx, $options,
                    $secondary, $lsi, $system,
                    $job['results']['trends'] ?? '',
                    $job['results']['intent'] ?? 'informational'
                );
                $job['results'][ 'section_' . $section_idx ] = $section_content;

            } elseif ( $step === 'headlines' ) {
                $step_label = 'Generating headlines...';
                $article_text = self::assemble_markdown( $job );
                $headlines = $generator->generate_headlines( $keyword, wp_strip_all_tags( $article_text ) );
                $job['results']['headlines'] = $headlines;

            } elseif ( $step === 'meta' ) {
                $step_label = 'Creating meta tags...';
                $article_text = self::assemble_markdown( $job );
                $meta = $generator->generate_meta_tags( $keyword, wp_strip_all_tags( $article_text ) );
                $job['results']['meta'] = $meta;

            } elseif ( $step === 'assemble' ) {
                $step_label = 'Assembling article...';
                // This step is instant — no API call
                $job['article'] = self::assemble_final( $job );
                $job['status'] = 'completed';
            }

        } catch ( \Throwable $e ) {
            return self::step_error( $job, $job_id, $step_index, $e->getMessage() );
        }

        $job['current'] = $step_index + 1;
        $progress = round( ( $step_index + 1 ) / $job['total_steps'] * 100 );

        set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TRANSIENT_TTL );

        return [
            'success'  => true,
            'done'     => $job['status'] === 'completed',
            'step'     => $step,
            'label'    => $step_label,
            'progress' => $progress,
            'current'  => $step_index + 1,
            'total'    => $job['total_steps'],
        ];
    }

    /**
     * Get the final result once all steps complete.
     */
    public static function get_result( string $job_id ): array {
        $job = get_transient( self::TRANSIENT_PREFIX . $job_id );
        if ( ! $job ) {
            return [ 'success' => false, 'error' => 'Job not found or expired.' ];
        }

        if ( $job['status'] !== 'completed' ) {
            return [ 'success' => false, 'error' => 'Job not yet completed.', 'progress' => round( $job['current'] / $job['total_steps'] * 100 ) ];
        }

        return $job['article'];
    }

    /**
     * Detect search intent from keyword to adapt article structure.
     * Returns: informational, commercial, transactional, or navigational.
     */
    private static function detect_intent( string $keyword ): string {
        $kw = strtolower( $keyword );

        // Transactional — user wants to buy/act
        if ( preg_match( '/\b(buy|order|purchase|price|pricing|cost|cheap|affordable|deal|discount|coupon|shop|store|subscribe|download|hire|book|rent)\b/', $kw ) ) {
            return 'transactional';
        }
        // Commercial — user is researching before buying
        if ( preg_match( '/\b(best|top|review|compare|comparison|vs\.?|versus|recommend|rated|alternative|worth|should i)\b/', $kw ) ) {
            return 'commercial';
        }
        // Navigational — user looking for specific brand/site
        if ( preg_match( '/\b(login|sign in|official|website|contact|support|\.com|\.org)\b/', $kw ) ) {
            return 'navigational';
        }
        // Default: informational
        return 'informational';
    }

    /**
     * Get intent-specific structure guidance for the AI.
     */
    private static function get_intent_guidance( string $intent ): string {
        return match ( $intent ) {
            'commercial' => "SEARCH INTENT: Commercial (user is comparing options before buying).\nSTRUCTURE: Include comparison tables with pros/cons, 'Best For' recommendations, specific pricing/features where available. Be balanced and data-driven. Include Product or Review schema signals.",
            'transactional' => "SEARCH INTENT: Transactional (user wants to buy/act now).\nSTRUCTURE: Focus on product details, pricing, features, clear calls-to-action. Be direct and action-oriented. Keep content focused (1000-2000 words). Include purchase-relevant details.",
            'navigational' => "SEARCH INTENT: Navigational (user looking for specific brand/entity).\nSTRUCTURE: Provide direct factual information about the entity. Include official sources. Keep content focused and specific (800-1500 words).",
            default => "SEARCH INTENT: Informational (user wants to learn/understand).\nSTRUCTURE: Comprehensive guide with definitions, step-by-step explanations, expert quotes, statistics, and FAQ. Be thorough and educational.",
        };
    }

    /**
     * Get tone-specific writing guidance so the AI actually changes voice.
     */
    private static function get_tone_guidance( string $tone ): string {
        return match ( $tone ) {
            'conversational' => "TONE: Conversational. Write like you are talking to a friend over coffee. Use contractions (don't, isn't, you'll). Ask rhetorical questions occasionally. Use 'you' and 'your' frequently. Keep sentences short. Imagine explaining this at a dinner party.",
            'professional' => "TONE: Professional. Write in clear, confident business language. Avoid slang and contractions. Use specific data points and named sources. Structure information logically. Write like a senior consultant presenting findings to a client.",
            'educational' => "TONE: Educational. Write like a patient teacher explaining to a curious student. Define terms when first used. Build from simple concepts to complex ones. Use analogies and real-world examples. Anticipate follow-up questions.",
            'journalistic' => "TONE: Journalistic. Write like a reporter covering a beat. Lead with the most important fact. Use short paragraphs (1-3 sentences). Quote real people by name. Present multiple perspectives. Write tight — no wasted words.",
            default => "TONE: Authoritative. Write with confidence and expertise. State facts directly without hedging. Use specific numbers and named sources. Take clear positions on recommendations. Write like an industry expert sharing honest advice.",
        };
    }

    /**
     * Get prose template sections for a content type.
     * Returns section structure guidance for the outline and section generation.
     */
    private static function get_prose_template( string $content_type ): array {
        $templates = [
            'blog_post' => ['sections' => 'Key Takeaways, 3-5 topic sections with H2 headings, FAQ, References', 'guidance' => 'Conversational blog entry. Grab attention with an opening hook. Personal voice allowed. End with a call to action.', 'schema' => 'BlogPosting'],
            'news_article' => ['sections' => 'Key Takeaways, Lede (who/what/when/where/why), Supporting Details, Background Context, What Happens Next, FAQ, References', 'guidance' => 'Inverted pyramid: most important facts first. Neutral third person. Attribute every claim. Short paragraphs.', 'schema' => 'NewsArticle'],
            'opinion' => ['sections' => 'Key Takeaways, Thesis Statement, Supporting Arguments (3 points with evidence), Counterargument, Call to Action, FAQ, References', 'guidance' => 'Argumentative piece with clear stance. First person allowed. Confident tone. Address the strongest counterargument.', 'schema' => 'OpinionNewsArticle'],
            'how_to' => ['sections' => 'Key Takeaways, Why This Matters, What You Will Need, Numbered Steps (each step: action verb + result), Common Problems, Conclusion, FAQ, References', 'guidance' => 'Step-by-step tutorial. Imperative voice (do this, then do that). Clear prerequisites. Each step should be independently actionable.', 'schema' => 'HowTo'],
            'listicle' => ['sections' => 'Key Takeaways, Introduction (selection criteria and why this list), 10 Numbered Items (EACH item gets its own H2 heading numbered 1-10 like "1. Product Name" with 100-200 words per item), Conclusion (overall recommendation), FAQ, References', 'guidance' => 'TOP 10 LIST FORMAT: You MUST create exactly 10 numbered items. Each item gets its OWN H2 heading formatted as "1. Item Name", "2. Item Name" etc. Write 100-200 words per item. Make it scannable — readers skip to items they care about. Include one specific detail or stat per item.', 'schema' => 'Article'],
            'review' => ['sections' => 'Key Takeaways, What It Is and Who It Is For, Key Specs and Features, Hands-on Experience, Pros and Cons, Verdict and Rating, FAQ, References', 'guidance' => 'Honest product evaluation. Evidence-based claims. Include specific measurements and comparisons. Declare a clear verdict.', 'schema' => 'Review'],
            'comparison' => ['sections' => 'Key Takeaways, Quick Overview Table, One H2 Per Comparison Criterion (declare winner per criterion), Overall Verdict, Who Should Pick Which, FAQ, References', 'guidance' => 'Head-to-head comparison. Must include at least one comparison table. Declare a winner per criterion and overall. Be specific with numbers.', 'schema' => 'Article'],
            'buying_guide' => ['sections' => 'Key Takeaways, Quick Picks Table, Individual Product Mini-Reviews (each with H2), What to Look For When Buying, FAQ, References', 'guidance' => 'Best X for Y roundup. Start with a quick picks summary table. Each product gets pros/cons/verdict. End with buying criteria.', 'schema' => 'Article'],
            'pillar_guide' => ['sections' => 'Key Takeaways, Table of Contents, 5-10 Chapter Sections (each a standalone mini-guide with H2), Summary and Next Steps, Further Reading, FAQ, References', 'guidance' => 'Comprehensive evergreen guide. Each chapter should work as a standalone article. Include internal links between chapters. This is the definitive resource on the topic.', 'schema' => 'Article'],
            'case_study' => ['sections' => 'Key Takeaways, Executive Summary, The Challenge, The Solution, Results and Metrics, Client Quote, FAQ, References', 'guidance' => 'Customer success story. Lead with the metric. Problem-solution-result structure. Include specific numbers. End with a direct quote from the client.', 'schema' => 'Article'],
            'interview' => ['sections' => 'Key Takeaways, Introduction (why this person), Short Bio, Q&A Pairs (5-10 questions), Closing Thoughts, References', 'guidance' => 'Q&A format. Questions should be bold H3 headings. Answers should feel natural and conversational. Include follow-up questions.', 'schema' => 'Article'],
            'faq_page' => ['sections' => 'Topic Introduction, 10-15 Question and Answer Pairs, References', 'guidance' => 'Collection of Q&A pairs. Questions phrased exactly as users search. Direct answers in the first sentence. Vary answer lengths.', 'schema' => 'FAQPage'],
            'recipe' => ['sections' => 'Recipe Intro Story, Tips and Substitutions, Ingredients List, Numbered Instructions, Notes and Storage, FAQ, References', 'guidance' => 'Recipe format. Brief personal story (2-3 paragraphs max). Clear ingredient list with measurements. Numbered steps with action verbs. Include prep time, cook time, servings in the intro.', 'schema' => 'Recipe'],
            'tech_article' => ['sections' => 'Key Takeaways, What You Will Build, Prerequisites, Setup, Code Walkthrough (with code blocks), Testing and Verification, Recap and Further Reading, FAQ, References', 'guidance' => 'Developer tutorial. Include code blocks. Explain each step. List prerequisites clearly. Test everything you recommend.', 'schema' => 'TechArticle'],
            'white_paper' => ['sections' => 'Executive Summary, Introduction and Problem Statement, Methodology, Findings, Analysis, Recommendations, Conclusion, References', 'guidance' => 'Authoritative research report. Data-driven. Formal tone. Every claim backed by evidence. Include charts/tables for data visualization.', 'schema' => 'Report'],
            'scholarly_article' => ['sections' => 'Abstract, Introduction, Literature Review, Methods, Results, Discussion, Conclusion, References', 'guidance' => 'Academic paper format. Formal academic tone. Cite all claims. Include methodology details. Discuss limitations.', 'schema' => 'ScholarlyArticle'],
            'live_blog' => ['sections' => 'What We Are Covering, Timestamped Updates (latest first)', 'guidance' => 'Real-time event coverage. Each update gets a timestamp. Latest first. Short punchy updates. Include quotes from participants.', 'schema' => 'LiveBlogPosting'],
            'press_release' => ['sections' => 'Headline, Subheadline, Dateline and Lede Paragraph, Body with Quotes, About Company, Media Contact, References', 'guidance' => 'Corporate announcement. Formal third person. Include at least 2 executive quotes. End with company boilerplate. Keep under 800 words.', 'schema' => 'NewsArticle'],
            'personal_essay' => ['sections' => 'Opening Scene, Tension or Conflict, Reflection, Resolution or Lesson', 'guidance' => 'First-person narrative. Show don\'t tell. Vivid sensory details. Honest personal voice. Build to a realization or lesson learned.', 'schema' => 'BlogPosting'],
            'glossary_definition' => ['sections' => 'One-Sentence Definition, Expanded Explanation, Examples, Related Terms, FAQ, References', 'guidance' => 'What is X? explainer. Lead with the definition. Expand with context. Include practical examples. Link to related terms.', 'schema' => 'Article'],
            'sponsored' => ['sections' => 'Sponsorship Disclosure, Introduction, Body, Sponsor Call to Action, FAQ, References', 'guidance' => 'Paid content. MUST include clear disclosure at the top. Balance informational value with sponsor messaging. Be transparent.', 'schema' => 'AdvertiserContentArticle'],
        ];

        // Shared SEO + humanizer rules appended to all content type guidance
        $shared = ' CRITICAL RULES FOR ALL TYPES: Include 3+ statistics per 1000 words (+40% AI visibility). Include 2+ expert quotes with full attribution (+41% visibility). Include 5+ inline citations as clickable Markdown links using ONLY URLs from the research data (+30% visibility). NEVER invent URLs or page paths — if you mention an organization without a URL from research data, link to their homepage domain only. Every outgoing link must lead to a real page, not a 404. Follow humanizer rules: no AI words (delve, leverage, pivotal, tapestry, landscape), vary sentence rhythm, write like a knowledgeable human with opinions. Apply E-E-A-T: show experience, expertise, authority, and trustworthiness appropriate to this content type.';

        $template = $templates[ $content_type ] ?? $templates['blog_post'];
        $template['guidance'] .= $shared;
        return $template;
    }

    /**
     * Generate the article outline (one API call).
     */
    private static function generate_outline( string $keyword, array $options, array $secondary, array $lsi ): array {
        $total_words = $options['word_count'] ?? 2000;
        // Scale sections to word count: 1000w = 3 content sections, 2000w = 5, 3000w = 7
        $content_sections = max( 3, min( 8, round( $total_words / 400 ) ) );
        // Total sections = content + takeaways + FAQ + references
        $num_sections = $content_sections + 3;
        $kw_context = '';
        if ( ! empty( $secondary ) ) $kw_context .= "\nSecondary keywords: " . implode( ', ', $secondary );
        if ( ! empty( $lsi ) ) $kw_context .= "\nLSI keywords: " . implode( ', ', $lsi );

        // Detect search intent and adapt outline structure
        $intent = self::detect_intent( $keyword );
        $intent_guidance = self::get_intent_guidance( $intent );
        $tone = $options['tone'] ?? 'authoritative';
        $tone_guidance = self::get_tone_guidance( $tone );
        $audience = $options['audience'] ?? 'general';
        $content_type = $options['content_type'] ?? 'blog_post';
        $prose = self::get_prose_template( $content_type );

        $year = wp_date( 'Y' );
        $min_kw_headings = max( 2, round( $content_sections * 0.5 ) );
        $prompt = "Create an article outline for: \"{$keyword}\"\n{$kw_context}\n\n{$intent_guidance}\n{$tone_guidance}\n\nCONTENT TYPE: {$content_type}\nREQUIRED SECTIONS: {$prose['sections']}\nGUIDANCE: {$prose['guidance']}\n\nCURRENT YEAR: {$year}. If any heading references a year, use {$year}.\nTarget audience: {$audience}\nDomain: " . ( $options['domain'] ?? 'general' ) . "\n\nRequirements:\n- Follow the REQUIRED SECTIONS structure above — use those as your H2 headings\n- Adapt the section names to fit the specific keyword naturally\n- KEYWORD IN HEADINGS: At least {$min_kw_headings} of the H2 headings MUST contain the exact phrase \"{$keyword}\" or a very close variant. SEO plugins check this — headings without the keyword get flagged.\n- Make headings sound natural, not like SEO templates\n- Target word count: {$total_words} words\n\nReturn ONLY the numbered list of H2 headings, one per line. No explanations.";

        $result = self::send_request( $prompt, 'You are an SEO content strategist. Return only the numbered list of headings.', [ 'max_tokens' => 500 ] );

        if ( ! $result['success'] ) {
            return $result;
        }

        $headings = [];
        foreach ( explode( "\n", trim( $result['content'] ) ) as $line ) {
            $line = trim( $line );
            if ( preg_match( '/^\d+[\.\)]\s*(.+)$/', $line, $m ) ) {
                $headings[] = trim( $m[1] );
            }
        }

        if ( count( $headings ) < 3 ) {
            return [ 'success' => false, 'error' => 'Could not parse outline. Try again.' ];
        }

        return [ 'success' => true, 'headings' => $headings ];
    }

    /**
     * Generate a single section (one API call).
     */
    private static function generate_section( string $keyword, string $heading, int $index, array $options, array $secondary, array $lsi, string $system, string $trends, string $intent = 'informational' ): string {
        $total_words = $options['word_count'] ?? 2000;
        $num_sections = max( 3, round( $total_words / 400 ) );
        $words_per_section = max( 100, round( ( $total_words * 0.85 ) / $num_sections ) );
        $tone = $options['tone'] ?? 'authoritative';
        $audience = $options['audience'] ?? '';
        $domain = $options['domain'] ?? 'general';
        $intent_guidance = self::get_intent_guidance( $intent );
        $tone_guidance = self::get_tone_guidance( $tone );
        $content_type = $options['content_type'] ?? 'blog_post';
        $prose = self::get_prose_template( $content_type );
        $kw_context = "\nCONTENT TYPE: {$content_type}. {$prose['guidance']}";
        $kw_context .= "\n{$intent_guidance}";
        $kw_context .= "\n{$tone_guidance}";
        if ( $audience ) $kw_context .= "\nTarget audience: {$audience} — write for this specific reader, use their language and concerns";
        if ( $domain && $domain !== 'general' ) $kw_context .= "\nContent domain: {$domain}";
        if ( ! empty( $secondary ) ) $kw_context .= "\nSecondary keywords to include: " . implode( ', ', $secondary );
        if ( ! empty( $lsi ) ) $kw_context .= "\nLSI keywords to include: " . implode( ', ', $lsi );

        $is_takeaways = preg_match( '/key\s*takeaway/i', $heading );
        $is_faq = preg_match( '/faq|frequently\s*asked/i', $heading );
        $is_references = preg_match( '/reference/i', $heading );

        $readability_rule = "\n\nREADABILITY: Write at a 6th-8th grade reading level. Mix short sentences (under 10 words) with medium ones (15-20 words). Use everyday words. Write like you would explain something to a friend — natural, not robotic. Vary your rhythm. Do not write every sentence the same length.";

        if ( $is_takeaways ) {
            $trends_context = ( $trends ) ? "\n\nUse these real data points if relevant:\n{$trends}" : '';
            $prompt = "Write the Key Takeaways section for an article about \"{$keyword}\".\n{$kw_context}{$trends_context}\n\nReturn:\n## Key Takeaways\n- [Takeaway 1]\n- [Takeaway 2]\n- [Takeaway 3]\n\nRules:\n- The FIRST bullet MUST contain the exact keyword \"{$keyword}\" — this is the first text SEO plugins scan in the article.\n- Make each bullet a different length. One short and punchy. One longer with a specific number or fact.\n- If research data is available, use a real statistic in one bullet.\n- Match the tone and audience specified above.\n- Do not use AI words (pivotal, crucial, landscape, delve, leverage).";
            $max = 400;
        } elseif ( $is_faq ) {
            $trends_context = ( $trends ) ? "\n\nUse real data from research when answering:\n{$trends}" : '';
            $prompt = "Write an FAQ section for an article about \"{$keyword}\".\n{$kw_context}{$trends_context}\n\nWrite 5 question-answer pairs. Vary the answer lengths — some short (25-35 words), some longer (50-80 words). Do not make every answer the same length or structure.{$readability_rule}\n\nRules:\n- Phrase questions exactly how real people search (use 'you' and natural language)\n- Answer directly in the first sentence — no throat-clearing\n- Include the keyword \"{$keyword}\" in at least 2 questions\n- Use a real statistic or fact from the research data in at least one answer\n- Never start answers with pronouns (It, This, They)\n- Never start with 'Yes,' or 'No,' followed by a restatement\n- Match the tone specified above\n\nFormat:\n\n## Frequently Asked Questions\n\n### [Question]?\n[Answer]";
            $max = 2000;
        } elseif ( $is_references ) {
            // Inject real source URLs from research data
            $sources_list = '';
            if ( $trends ) {
                // Extract markdown links from the research data
                preg_match_all( '/\[([^\]]+)\]\((https?:\/\/[^)]+)\)\s*[—-]\s*(.+)/i', $trends, $link_matches, PREG_SET_ORDER );
                // Also extract bare URLs with surrounding context
                if ( empty( $link_matches ) ) {
                    preg_match_all( '/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/i', $trends, $link_matches, PREG_SET_ORDER );
                }
                // Also try to find URLs directly
                if ( empty( $link_matches ) ) {
                    preg_match_all( '/(https?:\/\/[^\s\)]+)/i', $trends, $url_only );
                    if ( ! empty( $url_only[1] ) ) {
                        foreach ( array_unique( array_slice( $url_only[1], 0, 10 ) ) as $u ) {
                            try {
                                $host = parse_url( $u, PHP_URL_HOST );
                                $link_matches[] = [ $u, str_replace( 'www.', '', $host ?: $u ), $u, 'Web source' ];
                            } catch ( \Exception $e ) {}
                        }
                    }
                }
                if ( ! empty( $link_matches ) ) {
                    $sources_list = "\n\nREAL SOURCES TO USE (these are verified URLs — use them as outbound links):\n";
                    foreach ( array_slice( $link_matches, 0, 15 ) as $lm ) {
                        $sources_list .= "- [{$lm[1]}]({$lm[2]})" . ( isset( $lm[3] ) ? " — {$lm[3]}" : '' ) . "\n";
                    }
                    $sources_list .= "\nIMPORTANT: Use ONLY the URLs listed above. Do NOT invent any URLs or page paths. If you mention an organization not in this list, link to their homepage domain only (e.g., https://www.rspca.org.au/) — never guess subpages.";
                }
            }
            $domain_hint = ( $domain && $domain !== 'general' ) ? "\nDomain: {$domain} — prioritize authoritative sources for this field." : '';
            $prompt = "Write a References section for an article about \"{$keyword}\".{$domain_hint}{$sources_list}\n\nFormat each reference as a clickable Markdown link:\n## References\n1. [Source Title](https://real-url.com) — One sentence describing what this source covers.\n2. ...\n\nRules:\n- Include 5-10 references\n- Every URL must be a REAL, working link from the sources provided above\n- If you do not have enough real URLs, include the source name and year without a fake URL\n- Prioritize authoritative sources (government, academic, established organizations) over community sources\n- Do not include API documentation URLs or developer tool links as references\n- Write each description differently — do not use the same sentence structure for every reference";
            $max = 800;
        } else {
            $trends_inject = $trends ? "\n\nREAL-TIME RESEARCH DATA (use these real statistics and sources — do NOT hallucinate numbers):\n{$trends}" : '';

            // Intro rule applies ONLY to first content section (index 1, after Key Takeaways at index 0)
            // SEO plugins check the first <p> after Key Takeaways for the focus keyword
            $intro_rule = '';
            if ( $index === 1 ) {
                $intro_rule = "\n\nINTRODUCTION RULE (SEO PLUGINS CHECK THIS PARAGRAPH):\n- The FIRST SENTENCE of this section must contain the exact phrase \"{$keyword}\" naturally\n- Bold the keyword once: **{$keyword}**\n- SEO plugins (AIOSEO, Yoast, RankMath) check this paragraph for the focus keyword\n- Write the intro like a human opening a conversation — not a definition or press release\n- Do NOT start with '[Keyword] is...' or '[Keyword] refers to...' — those are AI patterns\n- Jump into a specific fact, opinion, or context that includes the keyword naturally";
            }

            $prompt = "Write a section for an article about \"{$keyword}\".\n{$kw_context}\n\nSection heading: \"{$heading}\"\n\nWORD LIMIT: Write {$words_per_section} words for this section. Do not exceed this. Stop when you reach it.{$trends_inject}{$readability_rule}{$intro_rule}\n\nKEYWORD RULES:\n- The phrase \"{$keyword}\" should appear 2-3 times in this section, naturally\n- Include it in the first paragraph\n- Also use variations and rearrangements\n\nWRITING RULES:\n- Start with: ## {$heading}\n- Open with a paragraph that directly answers the heading. Do not restate the heading.\n- Vary paragraph lengths — some short (2-3 sentences), some longer (4-5). Do not make every paragraph the same size.\n- Include statistics from the RESEARCH DATA if available. Do NOT invent numbers or statistics.\n- Include expert quotes from the research data if available. Use real names and organizations.\n- When citing a source, use a clickable Markdown link: [Source Name](URL). Use ONLY URLs that appear in the RESEARCH DATA above. If you want to mention an organization but its URL is not in the research data, link to their homepage domain only (e.g., https://www.rspca.org.au/) — NEVER invent a page path like /adopt-pet/guide because it will be a 404 error. If no URL exists at all, state the claim without any link.\n- NEVER invent URLs, page paths, book titles, study names, or years. Every link you produce must come from the RESEARCH DATA or be a verified homepage domain.\n- Add a comparison table ONLY if the section genuinely compares things. Do not force tables.\n- Use a bullet list ONLY when listing items. Do not default to bullets for every section.\n- NEVER start any paragraph with: It, This, They, These, Those, He, She, We\n- No bold except the keyword once in the intro section\n- Vary your sentence rhythm. Mix short direct statements with longer explanations. Do not write every sentence the same length.\n- Write like someone who knows this topic well and has an opinion about it.\n\nOutput Markdown only.";
            $max = 4096;
        }

        $result = self::send_request( $prompt, $system, [ 'max_tokens' => $max, 'temperature' => 0.7 ] );
        return $result['success'] ? $result['content'] : '';
    }

    /**
     * Assemble markdown from completed sections.
     */
    private static function assemble_markdown( array $job ): string {
        $keyword = $job['keyword'];
        $title = ucwords( $keyword );
        $date = wp_date( 'F Y' );
        // H1 first (title-cased), then Last Updated as metadata
        $md = "# {$title}\n\n*Last Updated: {$date}*\n\n";

        $section_keys = array_filter( array_keys( $job['results'] ), fn( $k ) => str_starts_with( $k, 'section_' ) );
        ksort( $section_keys );

        foreach ( $section_keys as $key ) {
            $md .= trim( $job['results'][ $key ] ) . "\n\n";
        }

        return $md;
    }

    /**
     * Assemble the final article result (formatting, scoring, images).
     */
    private static function assemble_final( array $job ): array {
        $keyword = $job['keyword'];
        $options = $job['options'];
        $markdown = self::assemble_markdown( $job );

        // Insert stock images
        $image_inserter = new Stock_Image_Inserter();
        $markdown = $image_inserter->insert_images( $markdown, $keyword );

        // Format as classic HTML for preview
        $formatter = new Content_Formatter();
        $html = $formatter->format( $markdown, 'classic', [
            'accent_color' => $options['accent_color'] ?? '#764ba2',
        ] );

        // GEO score — pass content type so scorer adjusts checks
        $analyzer = new GEO_Analyzer();
        $score = $analyzer->analyze( $html, $keyword, $options['content_type'] ?? '' );

        return [
            'success'     => true,
            'content'     => $html,
            'markdown'    => $markdown,
            'keyword'     => $keyword,
            'geo_score'   => $score['geo_score'],
            'grade'       => $score['grade'],
            'word_count'  => str_word_count( wp_strip_all_tags( $html ) ),
            'model_used'  => 'async-chained',
            'suggestions' => $score['suggestions'],
            'checks'      => $score['checks'],
            'headlines'   => $job['results']['headlines'] ?? [],
            'meta'        => $job['results']['meta'] ?? [],
        ];
    }

    /**
     * Handle a step error.
     */
    private static function step_error( array $job, string $job_id, int $step_index, string $error ): array {
        $job['status'] = 'error';
        $job['error'] = $error;
        set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TRANSIENT_TTL );

        return [
            'success'  => false,
            'error'    => $error,
            'step'     => $job['steps'][ $step_index ] ?? 'unknown',
            'progress' => round( $step_index / $job['total_steps'] * 100 ),
            'can_retry' => true,
        ];
    }

    /**
     * Retry from the failed step.
     */
    public static function retry_step( string $job_id ): array {
        $job = get_transient( self::TRANSIENT_PREFIX . $job_id );
        if ( ! $job ) {
            return [ 'success' => false, 'error' => 'Job not found.' ];
        }

        $job['status'] = 'running';
        set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::TRANSIENT_TTL );

        return self::process_step( $job_id );
    }

    /**
     * Send an AI request (routes to BYOK or Cloud).
     */
    private static function send_request( string $prompt, string $system, array $options = [] ): array {
        $provider = AI_Provider_Manager::get_active_provider();
        if ( $provider ) {
            return AI_Provider_Manager::send_request( $provider['provider_id'], $prompt, $system, $options );
        }
        return Cloud_API::generate( $prompt, $system, $options );
    }

    /**
     * Get the system prompt.
     */
    private static function get_system_prompt( string $language = 'en' ): string {
        $year = wp_date( 'Y' );
        $month_year = wp_date( 'F Y' );

        $lang_names = [
            'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish',
            'pt' => 'Portuguese', 'it' => 'Italian', 'nl' => 'Dutch', 'sv' => 'Swedish',
            'no' => 'Norwegian', 'da' => 'Danish', 'fi' => 'Finnish', 'pl' => 'Polish',
            'cs' => 'Czech', 'sk' => 'Slovak', 'hu' => 'Hungarian', 'ro' => 'Romanian',
            'bg' => 'Bulgarian', 'hr' => 'Croatian', 'sr' => 'Serbian', 'sl' => 'Slovenian',
            'uk' => 'Ukrainian', 'ru' => 'Russian', 'tr' => 'Turkish', 'el' => 'Greek',
            'ja' => 'Japanese', 'ko' => 'Korean', 'zh' => 'Chinese (Simplified)',
            'ar' => 'Arabic', 'he' => 'Hebrew', 'hi' => 'Hindi', 'bn' => 'Bengali',
            'th' => 'Thai', 'vi' => 'Vietnamese', 'id' => 'Indonesian', 'ms' => 'Malay',
            'sw' => 'Swahili', 'ur' => 'Urdu', 'si' => 'Sinhala', 'ne' => 'Nepali',
            'mn' => 'Mongolian', 'kk' => 'Kazakh', 'uz' => 'Uzbek', 'is' => 'Icelandic',
            'et' => 'Estonian', 'lv' => 'Latvian', 'lt' => 'Lithuanian',
        ];
        $lang_name = $lang_names[ $language ] ?? 'English';
        $lang_rule = ( $language !== 'en' ) ? "\n\nLANGUAGE: Write the ENTIRE article in {$lang_name}. Every heading, paragraph, FAQ, key takeaway, and reference description must be in {$lang_name}. Do NOT write in English unless the language is English. The keyword may be in any language — use it as-is." : '';

        return "You are an expert SEO and GEO (Generative Engine Optimization) content writer. Your content must rank on Google AND get cited by AI platforms (ChatGPT, Perplexity, Gemini, Claude, Copilot).{$lang_rule}

CURRENT DATE: {$month_year}. The current year is {$year}. ALWAYS use {$year} when writing 'in [year]', 'best X in [year]', or any year reference. NEVER use 2024 or 2025 — those are outdated.

KEYWORD DENSITY (CRITICAL FOR SEO PLUGINS):
- Primary keyword MUST appear every 100-200 words (0.5%-1.5% density)
- Primary keyword MUST appear in the first 1-2 sentences of the article
- At least 30% of H2 headings must contain the primary keyword or close variant
- Use EXACT keyword phrase naturally — do not split or rearrange it
- Also use natural variations (rearranged words, synonyms, related phrases)

GEO VISIBILITY (Princeton KDD 2024 Research — these boost AI citations):
- Expert quotes with full attribution: name, title, organization (+41% visibility)
- Statistics with specific numbers and source: '85% of users prefer X (Source, Year)' (+40% visibility)
- Source attributions in plain text format: '(RSPCA, 2026)' or 'According to the AVMA' — NO hyperlinks required
- Fluent, polished writing with smooth transitions (+25-30% visibility)
- NEVER stuff keywords — this REDUCES AI visibility by 9%

ABSOLUTE URL RULES (ZERO TOLERANCE — violations are immediately stripped):
1. DO NOT output any markdown hyperlink [text](url) unless the URL is EITHER (a) verbatim from the RESEARCH DATA section below, OR (b) the homepage of a major institution you are 100% certain exists (e.g. https://www.rspca.org.au/, https://www.who.int/, https://en.wikipedia.org/).
2. DO NOT invent URL paths. 'https://example.com/articles/dog-beds' is forbidden unless that exact URL appears in the research data.
3. DO NOT link to API endpoints, developer pages, or service URLs (e.g. *.herokuapp.com, dog-api.kinduff.com, dogapi.dog/api/v2/...). These are scraped data sources, never link to them.
4. DO NOT use link text as the URL slot. '[Dog Facts API](Dog Facts API)' is malformed and forbidden.
5. If you are tempted to cite a source but don't have a verified URL, write a PLAIN TEXT attribution only: 'According to the RSPCA' — no link. Plain text citations count toward GEO visibility just as well as hyperlinks.
6. When in doubt, OMIT the link. An article with zero external links is better than an article with one hallucinated link.

E-E-A-T (Google Helpful Content Requirements):
- Experience: Include first-hand examples, practical details, real-world context
- Expertise: Use domain-specific terminology accurately, show depth of knowledge
- Authoritativeness: Cite recognized sources, reference official data
- Trustworthiness: Be balanced, acknowledge limitations, no exaggerated claims
- For health/finance/legal topics (YMYL): apply stronger E-E-A-T standards

NLP ENTITY OPTIMIZATION (Google Natural Language):
- Use specific named entities: 'Dr. Sarah Chen at MIT' NOT 'an expert says'
- Use proper nouns for people, organizations, places, products — target 5%+ entity density
- Mention primary entities early in the text (salience scoring)
- Stay focused on one topic per section (triggers specific content classification)

WRITE LIKE A HUMAN (CRITICAL — this is the #1 quality signal):
AI writing has a recognizable smell. It is not about any single word. It is the combination: predictable structure, relentless parallelism, significance inflation, and a tendency to wrap everything in a tidy bow. Your job is to write like a knowledgeable person who has opinions, not like a language model.

NEVER USE THESE WORDS (Tier 1 — immediate AI red flags):
delve, tapestry, landscape (metaphorical), paradigm, leverage (verb), harness, navigate (metaphorical), realm, embark, myriad, plethora, multifaceted, groundbreaking, revolutionize, synergy, ecosystem (non-technical), resonate, streamline, testament, pivotal, cornerstone, game-changer, nestled, breathtaking, stunning, seamless, vibrant, renowned

AVOID THESE IN CLUSTERS (Tier 2 — fine alone, 3+ in one article is a tell):
robust, cutting-edge, innovative, comprehensive, nuanced, compelling, transformative, bolster, underscore, evolving, fostering, imperative, intricate, overarching, unprecedented, profound, showcasing, garner, crucial, vital

NEVER USE THESE PHRASES:
- Filler: additionally, furthermore, it is important to note, in order to, due to the fact that, at the end of the day, in today's world, when it comes to, it bears mentioning, at this point in time
- Signposting: let's dive in, let's explore, here's what you need to know, without further ado, let's break this down
- Hedging: while there are certainly, this is not without its challenges, it could potentially be argued, it remains to be seen
- AI closers: the future looks bright, exciting times lie ahead, only time will tell, start your journey today
- Authority tropes: the real question is, at its core, in reality, what really matters, the heart of the matter
- In today's family: in today's fast-paced world, in today's digital landscape, in an era of

BANNED WRITING PATTERNS:
- Copula avoidance: never write 'serves as', 'stands as', 'functions as', 'represents' when 'is' works. Never write 'boasts', 'features', 'offers' when 'has' works.
- Em dashes: maximum ONE per 500 words. Use commas or periods instead.
- Rule of three: do not group things in threes for rhetorical effect unless the third item genuinely adds something the first two do not.
- Superficial -ing phrases: never tack on 'highlighting...', 'showcasing...', 'underscoring...', 'reflecting...', 'symbolizing...', 'contributing to...', 'fostering...' to pad sentences. Delete or expand into a real sentence.
- Negative parallelisms: never write 'Not only X, but also Y' or 'It is not just X, it is Y'. State Y directly.
- False ranges: never write 'from X to Y, from A to B' when the items are not on a meaningful scale.
- Synonym cycling: pick one term and reuse it. Do not rotate through 'protagonist/main character/central figure/hero' to avoid repetition.
- Generic endings: never write 'Despite challenges... continues to thrive' or any variation.
- Formulaic sections: do not end every section with a neat takeaway. Vary section lengths. Some get two paragraphs, some get five. Let some end abruptly.
- Parallel lists: do not make every bullet point the same length and structure.
- Fragmented headers: do not follow a heading with a one-line restatement before the real content.
- Excessive bold: bold the primary keyword ONCE in the introduction. Nothing else bold.
- Balanced conclusions: do not end with 'The good news... The challenge... [inspiring closer]'. End with one specific statement or fact.

SENTENCE RHYTHM (burstiness — key anti-AI signal):
AI writes in metronomic cadence: medium sentence, medium sentence, medium sentence. Humans vary wildly.
- Mix short punchy sentences (under 8 words) with medium (15-20) and occasional longer ones.
- Start some sentences with 'But', 'And', 'So', or 'Still'.
- Use occasional fragments. They work.
- Do not start every sentence with a noun or 'The'.
- Target: no more than 3 consecutive sentences of similar length.

TRANSITIONS:
Do not overuse 'Moreover', 'Furthermore', 'Additionally', 'That said', 'Moving forward', 'When it comes to'. Often you do not need a transition at all. Just start the next thought. Use the actual logical connection: 'because', 'so', 'but', 'and'. Or let the paragraph break do the work.

ADD HUMAN TEXTURE:
- Have opinions. React to facts. 'That number is higher than most people expect' beats a neutral statement.
- Acknowledge complexity. 'This works well for X but falls short for Y' beats pretending everything is positive.
- Be specific about details. '3,200 units sold in March' not 'significant sales growth'.
- Use occasional first person when the context fits: 'here is what stands out', 'worth noting'.
- Reference shared experience when appropriate: 'Anyone who has tried X knows...'
- Do not overdo it. One or two casual asides per section maximum. Do not add forced humor or slang.

READABILITY: Grade 6-8 reading level. Simple common words. Active voice.

WORD COUNT: Always write the FULL number of words requested. Being too short is a failure.

STRUCTURE: Start every H2/H3 section with a 40-60 word paragraph that directly answers the heading. Never start paragraphs with pronouns (It, This, They, These, Those, He, She, We). Every paragraph must make sense in isolation (AI extracts individual paragraphs).

FORMAT: Output GitHub Flavored Markdown. Only bold the primary keyword on its FIRST mention in the article — no other bold. Use tables for comparisons. Use bullet/numbered lists for features and steps.";
    }

    /**
     * Get estimated time for current model.
     */
    public static function get_estimate(): array {
        $provider = AI_Provider_Manager::get_active_provider();
        $model = $provider['model'] ?? 'default';
        $per_call = self::MODEL_SPEEDS[ $model ] ?? 30;
        $est_total = $per_call * 9; // ~9 API calls typical
        $minutes = max( 1, round( $est_total / 60 ) );

        $speed = 'fast';
        if ( $minutes > 8 ) $speed = 'slow';
        elseif ( $minutes > 4 ) $speed = 'medium';

        return [
            'model'       => $model,
            'est_minutes' => $minutes,
            'speed'       => $speed,
            'warning'     => $speed === 'slow' ? "This model is slow (~{$minutes} min). For faster results, switch to Sonnet or GPT-4o in Settings." : '',
        ];
    }
}
