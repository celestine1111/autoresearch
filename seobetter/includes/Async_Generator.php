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
                    ? 'Researching real-time trends + building citation pool...'
                    : 'Researching recent trends + building citation pool...';

                $research = Trend_Researcher::research( $keyword, $options['domain'] ?? 'general', $options['country'] ?? '' );
                $job['results']['trends'] = $research['for_prompt'] ?? '';
                $job['results']['trend_source'] = $research['source'] ?? 'unknown';

                // v1.5.26 — stash the Places waterfall output so the post-generation
                // Places_Validator can use it as the closed-menu allow-list in
                // assemble_final(). Only populated for local-intent keywords; empty
                // array otherwise.
                $job['results']['places']              = $research['places'] ?? [];
                $job['results']['places_business_type'] = $research['places_business_type'] ?? '';
                $job['results']['places_location']     = $research['places_location'] ?? '';
                $job['results']['is_local_intent']     = ! empty( $research['is_local_intent'] );

                // v1.5.27 — STRUCTURAL pre-generation switch. When the keyword has local
                // intent but the Places waterfall returned <2 verified businesses, set
                // a flag that propagates through to generate_outline() and
                // generate_section() so they produce an informational article (no
                // business-name sections, no listicle numbering, disclaimer at end)
                // instead of asking the model to write a listicle that it will
                // inevitably hallucinate to fill.
                $places_count = (int) ( $research['places_count'] ?? 0 );
                $places_insufficient = ! empty( $research['is_local_intent'] ) && $places_count < 2;
                $job['options']['places_insufficient'] = $places_insufficient;
                $job['results']['places_insufficient'] = $places_insufficient;

                // v1.5.33 — when we have a non-empty pool but the number of verified
                // places is SMALLER than what a word-count-based outline would produce,
                // cap the number of business-name H2s to exactly places_count. This
                // stops the model from being asked to write a 6-item listicle when
                // only 2 real businesses exist — it will fill the gap with fakes.
                // The generate_outline() branch reads this cap and builds the outline
                // with exactly N business H2s + generic fill sections.
                if ( ! empty( $research['is_local_intent'] ) && $places_count >= 2 ) {
                    $job['options']['local_business_cap']  = $places_count;
                    $job['options']['local_business_mode'] = true;
                    // Thread the verified pool names to generate_outline() so
                    // it can substitute the "Business N" placeholders with the
                    // real names from the pool.
                    $job['options']['places_pool_for_outline'] = $research['places'] ?? [];
                }

                // Build the verified citation pool (real keyword-relevant URLs)
                // This drives both the AI prompt grounding and the post-save validator.
                $pool = Citation_Pool::build(
                    $keyword,
                    $options['country'] ?? '',
                    $options['domain'] ?? 'general'
                );
                $job['results']['citation_pool'] = $pool;
                $job['results']['citation_pool_prompt'] = Citation_Pool::format_for_prompt( $pool );

                // Detect search intent from keyword to adapt article structure
                $job['results']['intent'] = self::detect_intent( $keyword );

                // 5-Part Content Ranking Framework (§28) — record Phase 1-3
                // completion. Actual quality gate (Phase 5) runs at save time.
                $job['results']['framework'] = [
                    'phase_1_topic_selection' => [
                        'passed'        => count( $pool ) > 0 || ! empty( $research['sources'] ),
                        'sources_found' => count( $pool ),
                    ],
                    'phase_2_keyword_research' => [
                        'passed'        => str_word_count( $keyword ) >= 2 && str_word_count( $keyword ) <= 12,
                        'word_count'    => str_word_count( $keyword ),
                    ],
                    'phase_3_intent_grouping' => [
                        'passed' => true,
                        'intent' => $job['results']['intent'],
                    ],
                ];

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

                // Append the citation pool prompt to the trends context so every
                // section generation sees the AVAILABLE CITATIONS block. This
                // grounds the AI in a bounded pool of real URLs (Joshi 2025 RAG
                // approach) and is the primary defense against citation
                // hallucination — the validator's filter is just the safety net.
                $trends_context = ( $job['results']['trends'] ?? '' )
                    . ( $job['results']['citation_pool_prompt'] ?? '' );

                // v1.5.33 — pass the verified Places Pool so generate_section()
                // can match this heading against a pool entry and swap to the
                // strict "local business" prompt that forbids inventing facts.
                $section_places_pool = $job['results']['places'] ?? [];
                $section_places_location = $job['results']['places_location'] ?? '';

                $section_content = self::generate_section(
                    $keyword, $heading, $section_idx, $options,
                    $secondary, $lsi, $system,
                    $trends_context,
                    $job['results']['intent'] ?? 'informational',
                    $section_places_pool,
                    $section_places_location
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

        // v1.5.33 — Local Business Mode. When the Places waterfall returned
        // ≥2 verified businesses but fewer than a word-count-based outline
        // would demand (e.g. 2 real gelaterie but a 2000-word listicle would
        // produce 5 content sections), cap the listicle size to exactly the
        // verified count. The remaining word budget goes to generic fill
        // sections (What to Look For, Regional Tradition, How to Find Quality)
        // which the model can write without inventing anything about specific
        // businesses.
        $local_business_cap = (int) ( $options['local_business_cap'] ?? 0 );
        $local_business_mode = ! empty( $options['local_business_mode'] );

        if ( $local_business_mode && $local_business_cap > 0 && empty( $options['places_insufficient'] ) ) {
            $year = wp_date( 'Y' );
            $cap_text = (int) $local_business_cap;
            $prompt = "Create an article outline for: \"{$keyword}\"\n{$kw_context}\n\n"
                . "CRITICAL CONTEXT: Our verified-places database has found exactly {$cap_text} real businesses matching this keyword. You must write a listicle with EXACTLY {$cap_text} business-name H2s — not more, not fewer. Do NOT pad the listicle with invented businesses just because the user requested a longer article.\n\n"
                . "REQUIRED outline structure:\n"
                . "1. Key Takeaways\n"
                . "2-" . ( 1 + $cap_text ) . ". [{$cap_text} business-name H2s — our post-generation validator will match these against the verified pool. Use generic placeholder names like 'Business 1', 'Business 2' — the actual verified names will be injected into each section prompt.]\n"
                . ( 2 + $cap_text ) . ". What Makes [gelato / pizza / etc] in [location] Special (general educational section, no specific business names)\n"
                . ( 3 + $cap_text ) . ". What to Look For in a Quality [category] (general tips for travelers)\n"
                . ( 4 + $cap_text ) . ". Regional Context and Traditions (cultural background)\n"
                . ( 5 + $cap_text ) . ". FAQ\n"
                . ( 6 + $cap_text ) . ". References\n\n"
                . "CURRENT YEAR: {$year}.\nTarget audience: {$audience}\nTarget word count: {$total_words} words total.\n\n"
                . "RULES:\n"
                . "- Produce EXACTLY {$cap_text} business-name H2 headings (use 'Business 1', 'Business 2' etc as placeholders — the real names will replace these later)\n"
                . "- Then add the generic fill sections listed above so the article hits the target word count\n"
                . "- KEYWORD IN HEADINGS: At least 2 of the headings should contain the keyword or a close variant\n\n"
                . "Return ONLY the numbered list of H2 headings, one per line. No explanations.";
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
            // Replace the placeholder 'Business N' H2s with the actual verified
            // place names from the pool so each section generator knows exactly
            // which pool entry it's writing about.
            $pool_names = [];
            foreach ( ( $options['places_pool_for_outline'] ?? [] ) as $p ) {
                if ( is_array( $p ) && ! empty( $p['name'] ) ) $pool_names[] = $p['name'];
            }
            if ( ! empty( $pool_names ) ) {
                $pool_idx = 0;
                foreach ( $headings as $i => $h ) {
                    if ( preg_match( '/business\s*\d+/i', $h ) && isset( $pool_names[ $pool_idx ] ) ) {
                        $headings[ $i ] = $pool_names[ $pool_idx ];
                        $pool_idx++;
                    }
                }
            }
            return [ 'success' => true, 'headings' => $headings ];
        }

        // v1.5.27 — structural override when Places waterfall returned <2 verified
        // businesses for a local-intent keyword. Force an informational article
        // structure instead of a listicle so the model is never even asked to
        // produce business-name-shaped sections.
        if ( ! empty( $options['places_insufficient'] ) ) {
            $prompt = "Create an INFORMATIONAL ARTICLE outline for: \"{$keyword}\"\n{$kw_context}\n\n"
                . "CRITICAL CONTEXT: This keyword asks about local businesses in a small city, but our verified-places database has no real businesses for this location. Therefore this article must be written as a GENERAL INFORMATIONAL GUIDE, not a listicle. Do NOT produce section headings that name specific businesses, restaurants, shops, hotels, cafés, or establishments.\n\n"
                . "FORBIDDEN heading patterns:\n"
                . "- \"1. [Business Name]\", \"#1: [Name]\", \"Top Pick: [Name]\"\n"
                . "- Any proper noun that looks like a business name (e.g. \"Gelateria X\", \"Trattoria Y\", \"Hotel Z\")\n"
                . "- \"Best [type] in [city]\" as an H2 (fine as H1 title, not as section)\n\n"
                . "REQUIRED heading patterns (use these as templates, adapt to the keyword):\n"
                . "- Key Takeaways\n"
                . "- What to Look for in [type of business/experience]\n"
                . "- History and Cultural Context of [topic] in [region]\n"
                . "- Regional Variations and Traditions\n"
                . "- How to Find Quality [type] When Traveling in [region]\n"
                . "- Questions to Ask Before Visiting\n"
                . "- FAQ\n"
                . "- References\n\n"
                . "CURRENT YEAR: {$year}.\nTarget audience: {$audience}\nTarget word count: {$total_words} words\nKEYWORD IN HEADINGS: At least {$min_kw_headings} headings should contain the keyword or a close variant (natural phrasing, not stuffing).\n\n"
                . "Return ONLY the numbered list of H2 headings, one per line. No explanations.";
        } else {
            $prompt = "Create an article outline for: \"{$keyword}\"\n{$kw_context}\n\n{$intent_guidance}\n{$tone_guidance}\n\nCONTENT TYPE: {$content_type}\nREQUIRED SECTIONS: {$prose['sections']}\nGUIDANCE: {$prose['guidance']}\n\nCURRENT YEAR: {$year}. If any heading references a year, use {$year}.\nTarget audience: {$audience}\nDomain: " . ( $options['domain'] ?? 'general' ) . "\n\nRequirements:\n- Follow the REQUIRED SECTIONS structure above — use those as your H2 headings\n- Adapt the section names to fit the specific keyword naturally\n- KEYWORD IN HEADINGS: At least {$min_kw_headings} of the H2 headings MUST contain the exact phrase \"{$keyword}\" or a very close variant. SEO plugins check this — headings without the keyword get flagged.\n- Make headings sound natural, not like SEO templates\n- Target word count: {$total_words} words\n\nReturn ONLY the numbered list of H2 headings, one per line. No explanations.";
        }

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
    private static function generate_section( string $keyword, string $heading, int $index, array $options, array $secondary, array $lsi, string $system, string $trends, string $intent = 'informational', array $places_pool = [], string $places_location = '' ): string {
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

        // v1.5.27 — structural anti-hallucination rule injected into every
        // non-takeaways/non-faq/non-references section when the Places waterfall
        // returned <2 verified businesses for a local-intent keyword. This is
        // a HARD rule — the section MUST NOT name specific businesses. Paired
        // with the informational outline from generate_outline() and the
        // Places_Validator empty-pool trap as the Layer 3 backstop.
        if ( ! empty( $options['places_insufficient'] ) ) {
            $kw_context .= "\n\n*** PLACES INSUFFICIENT — HARD RULE ***\n"
                . "Our verified-places database has ZERO verified businesses for this location. This section MUST NOT name any specific business, restaurant, shop, hotel, café, gelateria, trattoria, osteria, pizzeria, bar, bakery, or establishment. Writing 'Gelateria X is famous for...' or 'At Hotel Y you can...' is FORBIDDEN even if it sounds plausible.\n"
                . "Instead: write about the topic in general terms — history, traditions, what to look for, regional variations, cultural context, practical tips for travelers. If the reader wants specific business recommendations, the article's disclaimer paragraph tells them to check TripAdvisor, Google Maps, OpenStreetMap, or Yelp directly.\n"
                . "If you feel tempted to name a business, replace it with a generic noun like 'a traditional gelateria', 'a family-run trattoria', 'a local osteria'. Never invent a business name.";
        }

        $is_takeaways = preg_match( '/key\s*takeaway/i', $heading );
        $is_faq = preg_match( '/faq|frequently\s*asked/i', $heading );
        $is_references = preg_match( '/reference/i', $heading );

        // v1.5.33 — check if this section's heading matches a verified place
        // in the Places Pool. If yes, we're writing about a specific real
        // business and MUST NOT invent any facts beyond what the pool contains.
        $matched_place = null;
        if ( ! empty( $places_pool ) && ! $is_takeaways && ! $is_faq && ! $is_references ) {
            // Strip listicle numbering from the heading before matching
            $candidate = preg_replace( '/^(?:#?\d+[\.\):—\-]|no\.\s*\d+\s*[—\-])\s*/i', '', $heading );
            $matched_place = Places_Validator::pool_lookup( $candidate, $places_pool );
        }

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
            // The References section is now built programmatically by the plugin
            // at save time from the verified citation pool — the AI no longer
            // generates it. Return an empty string so the section slot is skipped.
            // See seo-guidelines/external-links-policy.md, Layer 3b.
            return '';
        } elseif ( $matched_place !== null ) {
            // v1.5.33 — STRICT LOCAL BUSINESS SECTION. This H2 matches a
            // verified place in the Places Pool. Use ONLY the verified data
            // (name, address, website, phone, rating) — forbid inventing any
            // other facts about the business (hours, prices, menu, history,
            // reviews, chef names, signature dishes, etc). Pad the word count
            // with GENERAL context (regional gelato culture, what to look for
            // in good gelato, how tourists can enjoy it) instead of fabricated
            // business specifics.
            $place_name    = $matched_place['name'] ?? '';
            $place_address = $matched_place['address'] ?? '';
            $place_website = $matched_place['website'] ?? '';
            $place_phone   = $matched_place['phone'] ?? '';
            $place_rating  = isset( $matched_place['rating'] ) ? number_format( (float) $matched_place['rating'], 1 ) : '';
            $place_source  = $matched_place['source'] ?? '';
            $place_type    = $matched_place['type'] ?? '';

            $verified_block = "VERIFIED POOL ENTRY FOR THIS SECTION (use ONLY this data — invent NOTHING):\n";
            $verified_block .= "- Name: {$place_name}\n";
            if ( $place_address ) $verified_block .= "- Address: {$place_address}\n";
            if ( $place_website )  $verified_block .= "- Website: {$place_website}\n";
            if ( $place_phone )    $verified_block .= "- Phone: {$place_phone}\n";
            if ( $place_rating )   $verified_block .= "- Rating: {$place_rating}/5\n";
            if ( $place_type )     $verified_block .= "- Type: {$place_type}\n";
            if ( $place_source )   $verified_block .= "- Verified via: {$place_source}\n";

            $location_phrase = $places_location ? " in {$places_location}" : '';

            $prompt = "Write a short H2 section about the verified local business \"{$place_name}\"{$location_phrase}.\n\n"
                . "{$verified_block}\n"
                . "*** CRITICAL ANTI-HALLUCINATION RULES ***\n\n"
                . "1. State the business name, its address (if provided), and cite the verified source. That is the ONLY specific-business information you may include.\n\n"
                . "2. DO NOT invent or describe any of the following — NONE of these facts are in the verified pool and you do NOT know them:\n"
                . "   - Opening hours, days of the week, closing times, seasonal closures\n"
                . "   - Menu items, flavors, prices, specialty dishes, signature products\n"
                . "   - The owner's name, founder's name, chef's name, staff names\n"
                . "   - History, founding year, family background, generational ownership\n"
                . "   - Interior design, decor, atmosphere, seating capacity\n"
                . "   - Customer reviews, quotes from customers, what people say\n"
                . "   - Awards, accolades, rankings, recognitions (unless explicitly in the pool)\n"
                . "   - Ingredients, recipes, preparation methods, techniques used\n"
                . "   - Distance from landmarks, walking directions, parking availability\n\n"
                . "3. If you feel tempted to write any of the above, STOP and replace it with general content about the CATEGORY or REGION instead. For example, instead of 'Gelateria X is famous for pistachio made with Sicilian nuts', write 'Traditional Tuscan gelaterias often showcase seasonal flavors from local ingredients — look for shops that list their ingredient sources'.\n\n"
                . "4. Fill the {$words_per_section}-word budget with GENERAL educational content about the category: what to look for in good {$prose['guidance']}, how the regional tradition works, what tourists should know when visiting the region, general signs of quality, how to pick a good establishment. The verified business serves as one example in this broader context, NOT the subject of fabricated specifics.\n\n"
                . "5. If you cannot reach {$words_per_section} words without inventing facts, stop earlier. Short real content beats long fabricated content. A 150-word real section is fine.\n\n"
                . "WRITING STRUCTURE:\n"
                . "- Start with: ## {$heading}\n"
                . "- First paragraph (2-3 sentences): name the business, state its address, mention its verified rating or category. That's it — no inventions.\n"
                . "- Second paragraph onwards: general context about the category/region. Educate the reader about the CATEGORY, not this specific business.\n"
                . "- Close with a practical tip for how a tourist would approach this kind of business in this kind of town.\n\n"
                . "OTHER RULES:\n"
                . "- Do NOT use bullet lists for fabricated flavor lists / menu items / hours.\n"
                . "- Do NOT invent tables with fake opening hours.\n"
                . "- Do NOT cite a source inline unless the source URL appears in the RESEARCH DATA below.\n"
                . "- Keyword \"{$keyword}\" should appear naturally 1-2 times.\n"
                . "- Never start a paragraph with: It, This, They, These.\n\n"
                . ( $trends ? "REFERENCE RESEARCH DATA (for general context only — do NOT use to invent business specifics):\n{$trends}\n\n" : '' )
                . "Output Markdown only.";
            $max = 2500;
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
            // v1.5.14 — thread content_type for HowTo step-box detection
            'content_type' => $options['content_type'] ?? '',
        ] );

        // v1.5.26 — Layer 3 structural anti-hallucination guarantee for local-intent
        // listicles. Walks every H2/H3 section, extracts the business-name candidate,
        // checks it against the verified Places Pool from fetchPlacesWaterfall(), and
        // deletes any section whose business name is not in the pool. Mirrors the
        // 4-pass defensive pattern used by validate_outbound_links() for URLs.
        //
        // Skipped for non-local articles (empty pool). For local articles where more
        // than 50% of sections get stripped, sets force_informational=true and the
        // caller could re-run generation — for now we keep the cleaned (partially-
        // gutted) article but surface a loud warning in the result panel so the user
        // knows to regenerate manually with a broader keyword.
        $places_pool = $job['results']['places'] ?? [];
        $is_local_intent = ! empty( $job['results']['is_local_intent'] );
        $places_warnings = [];
        $places_force_informational = false;
        // v1.5.27 — also run the validator when pool is empty but the keyword
        // has local intent, so the backstop can strip any hallucinated business
        // sections that slipped through the pre-generation prompt override.
        if ( ! empty( $places_pool ) || $is_local_intent ) {
            $pv_result = Places_Validator::validate(
                $html,
                $places_pool,
                $job['results']['places_business_type'] ?? '',
                $is_local_intent
            );
            if ( ! $pv_result['force_informational'] ) {
                $html = $pv_result['html'];
            }
            $places_warnings = $pv_result['warnings'];
            $places_force_informational = $pv_result['force_informational'];
        }

        // v1.5.29 — Places_Link_Injector runs AFTER Places_Validator so we
        // only decorate sections that survived the validator's strip. For each
        // kept H2 that matches a pool entry, injects a meta line with address,
        // Google Maps link, website, phone, rating. Zero cost, zero risk of
        // hallucination (all data comes from the verified pool). Skipped
        // silently when pool is empty.
        if ( ! empty( $places_pool ) ) {
            $html = Places_Link_Injector::inject( $html, $places_pool );
        }

        // GEO score — pass content type so scorer adjusts checks
        $analyzer = new GEO_Analyzer();
        $score = $analyzer->analyze( $html, $keyword, $options['content_type'] ?? '' );

        // 5-Part Framework (§28) Phase 5 — Quality Gate on the assembled article
        $framework = new Content_Ranking_Framework();
        $quality_gate = $framework->quality_gate( $html, $keyword, $options['content_type'] ?? '' );

        // v1.5.26 — merge any Places_Validator warnings into the score suggestions
        // so the Analyze & Improve panel surfaces hallucination strips as high-
        // priority items the user can act on (the sentinel also still fires in
        // GEO_Analyzer::check_local_places_grounding for scoring purposes).
        $suggestions = $score['suggestions'] ?? [];
        foreach ( $places_warnings as $pw ) {
            array_unshift( $suggestions, [
                'type'     => 'places_validator',
                'priority' => $places_force_informational ? 'critical' : 'high',
                'message'  => $pw,
            ] );
        }

        // v1.5.27 — if the pre-generation switch fired (places_insufficient), add
        // a dedicated high-priority suggestion explaining to the user WHY they got
        // a general informational article instead of the listicle they asked for,
        // and how to enable real listicles by configuring a free API key.
        if ( ! empty( $job['results']['places_insufficient'] ) ) {
            $loc = $job['results']['places_location'] ?? 'this location';
            array_unshift( $suggestions, [
                'type'     => 'places_insufficient',
                'priority' => 'high',
                'message'  => sprintf(
                    '⚠️ No verified businesses were found in %s — your article was written as a general informational guide instead of a listicle to prevent hallucinated business names. To enable real listicles for small cities worldwide, configure a free Foursquare API key (2 min signup at developer.foursquare.com) in Settings → Integrations. OpenStreetMap (the free default) has thin coverage for small towns.',
                    $loc
                ),
            ] );
        }

        return [
            'success'       => true,
            'content'       => $html,
            'markdown'      => $markdown,
            'keyword'       => $keyword,
            'geo_score'     => $score['geo_score'],
            'grade'         => $score['grade'],
            'word_count'    => str_word_count( wp_strip_all_tags( $html ) ),
            'model_used'    => 'async-chained',
            'suggestions'   => $suggestions,
            'checks'        => $score['checks'],
            'headlines'     => $job['results']['headlines'] ?? [],
            'meta'          => $job['results']['meta'] ?? [],
            // v1.5.26 — surface Places_Validator outcome so the UI can show a
            // red banner when an article was gutted by the validator.
            // v1.5.27 — also surface places_insufficient + is_local_intent so
            // the UI can show an orange "written as informational instead of
            // listicle" banner with a link to Settings → Integrations.
            'places_validator' => [
                'pool_size'             => count( $places_pool ),
                'warnings'              => $places_warnings,
                'force_informational'   => $places_force_informational,
                'is_local_intent'       => $is_local_intent,
                'places_insufficient'   => ! empty( $job['results']['places_insufficient'] ),
                'places_location'       => $job['results']['places_location'] ?? '',
                'places_business_type'  => $job['results']['places_business_type'] ?? '',
            ],
            // Thread the citation pool through to the save path so
            // validate_outbound_links() can use it as the primary allow-list
            // and build_references_section() can auto-generate References.
            'citation_pool' => $job['results']['citation_pool'] ?? [],
            // 5-Part Framework phase tracking (§28)
            'framework'     => array_merge(
                $job['results']['framework'] ?? [],
                [
                    'phase_4_writing' => [
                        'passed'   => true,
                        'pipeline' => 'Async_Generator',
                    ],
                    'phase_5_quality_gate' => $quality_gate,
                ]
            ),
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

KEYWORD PLACEMENT (CRITICAL FOR SEO PLUGINS):
- The primary keyword should appear naturally every 100-200 words of body text
- Primary keyword must appear in the first 1-2 sentences of the article
- At least one third of H2 headings should contain the primary keyword or a close variant
- Use the exact keyword phrase naturally — do not split or rearrange it
- Also use natural variations (rearranged words, synonyms, related phrases)
- IMPORTANT: Do NOT copy any density instruction numbers or ratios into the article body itself. The above is guidance for HOW YOU WRITE, not content to include in the text.

GEO VISIBILITY (Princeton KDD 2024 Research — these boost AI citations):
- Expert quotes with full attribution: name, title, organization
- Statistics with specific numbers and a source citation: 'eighty-five percent of users prefer X (Source, Year)' — when you include a real statistic from the research data, write the number normally
- Source attributions in plain text format: '(RSPCA, 2026)' or 'According to the AVMA' — NO hyperlinks required
- Fluent, polished writing with smooth transitions
- NEVER stuff keywords — doing so reduces AI visibility
- IMPORTANT: Do NOT output any bracketed boost percentage numbers in the article body. Those are internal notes for you about why each guidance exists. NEVER write phrases like \"5% entity density\", \"0.5% density\", or any literal percentage-as-filler in the article body — those are instructions to you, not content for readers.

CITATION RULES (closed-menu grounding — the plugin injects an AVAILABLE CITATIONS list below):

The plugin pre-fetches real keyword-relevant article URLs before you write and injects them below as AVAILABLE CITATIONS. Those URLs are the ONLY URLs you may output as hyperlinks. Think of it as a closed menu — if a URL isn't on the menu, you can't order it.

1. Use ONLY URLs from the AVAILABLE CITATIONS list. Copy them character-for-character. Any URL you output that is not in the list is automatically stripped.
2. Match the citation to the claim. Pick the pool URL whose title is closest to the specific point you're making. Don't cite a dog-beds article for a claim about dog food.
3. Use each pool URL at most once. Spread citations across sections — don't pile them all in one.
4. Plain-text attributions are fully encouraged and count equally for GEO visibility. If no pool URL supports a claim, write 'According to a 2026 RSPCA report' or 'Research from the AVMA found that...' — no link needed.
5. DO NOT invent URL paths, modify pool URLs, or output URLs that aren't in the pool. Don't guess. Don't approximate.
6. DO NOT use API/dataset/tool names as anchor text. '[Dog Facts API](...)', '[Pexels API](...)', '[Reddit API](...)' are stripped unconditionally.
7. DO NOT use link text as the URL slot. '[Dog Facts API](Dog Facts API)' is malformed markdown.
8. DO NOT output a '## References', '## Sources', '## Bibliography', '## Further Reading', or '## Citations' section. The plugin builds References programmatically from the pool URLs you actually cited in the body. Don't pre-empt it.
9. If the AVAILABLE CITATIONS list is empty (obscure topic, no sources found), use plain-text attributions only and output ZERO hyperlinks.
10. TARGET: an article using 3-6 pool citations (matched to their claims) plus plenty of plain-text attributions is a PASS. An article with 5 hyperlinks to homepages, APIs, or URLs not in the pool is a FAIL.

PLACES RULES (closed-menu grounding for local businesses — CRITICAL, added v1.5.23):

When the research data below includes a \"REAL LOCAL PLACES\" section, those are the ONLY businesses, restaurants, shops, hotels, cafés, or establishments you may name in the article. The plugin fetched them from OpenStreetMap (Nominatim + Overpass) because the AI cannot be trusted to know real local business names. Think of it as a closed menu — if a business isn't on the menu, you can't serve it.

1. Use ONLY the exact business names from the REAL LOCAL PLACES list. Copy them character-for-character including any accents, apostrophes, or non-English characters.
2. Attach the real address from the list — do NOT invent street names or building numbers.
3. If a place has a website URL, link to it. If it only has an OpenStreetMap URL, link to that.
4. Use each place at most once.
5. DO NOT invent shop names, restaurant names, or business names under ANY circumstances. No \"Trattoria Bella Vista\" unless it's in the list. No \"Gelato di Piazza\" unless it's in the list. This is the most common hallucination failure and the v1.5.23 release exists specifically to prevent it.
6. If the REAL LOCAL PLACES section contains a LOCAL-INTENT WARNING (research returned zero verified businesses), DO NOT write a listicle of businesses. Write a general informational article about the topic instead. Never make up businesses to fill a listicle. Add a disclaimer paragraph at the end: \"Note: This article doesn't name specific local businesses because verified open-map data wasn't available for this location. We recommend checking Google Maps or OpenStreetMap directly for current listings.\"
7. TARGET: a listicle naming 5-10 real places from the list with real addresses and working URLs is a PASS. A listicle with plausible-sounding but invented business names is a CRITICAL FAIL — the article will be blocked by the GEO_Analyzer local-places sentinel check and the user will be asked to regenerate.

E-E-A-T (Google Helpful Content Requirements):
- Experience: Include first-hand examples, practical details, real-world context
- Expertise: Use domain-specific terminology accurately, show depth of knowledge
- Authoritativeness: Cite recognized sources, reference official data
- Trustworthiness: Be balanced, acknowledge limitations, no exaggerated claims
- For health/finance/legal topics (YMYL): apply stronger E-E-A-T standards

NLP ENTITY OPTIMIZATION (Google Natural Language):
- Use specific named entities: 'Dr. Sarah Chen at MIT' NOT 'an expert says'
- Use lots of proper nouns for people, organizations, places, products — aim for high entity saturation throughout
- Mention primary entities early in the text (salience scoring)
- Stay focused on one topic per section (triggers specific content classification)
- IMPORTANT: the above are instructions for YOUR writing style, not content for readers. Do NOT write the phrase \"entity density\" or similar technical SEO jargon in the article body.

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

RICH FORMATTING (use these patterns naturally — the plugin auto-styles them into colored boxes in the published article):
- For actionable advice, start the paragraph with 'Tip:' (max 2 per article)
- For important context the reader should not miss, start with 'Note:' (max 2 per article)
- For safety or risk warnings, start with 'Warning:' (only when truly relevant — never force one)
- For one interesting fact per article, start a paragraph with 'Did you know?' followed by the fact (max 1 per article)
- When you introduce a technical term for the first time, format the definition on its own paragraph as: **Term**: explanation here. (1-2 per article)
- For ONE key insight per major section, write the whole sentence as a single bold line on its own paragraph: **This is the most important point in this section.** (1 per H2 section, max 2 per article)
- For expert quotes, write on their own line as: \"Quote text here\" — Dr. Name, Title, Organization
- Use H2 headings 'Key Takeaways', 'Pros and Cons', 'What You'll Need', or 'Key Insights' verbatim where they fit naturally — these auto-style the following list into colored boxes
- Statistics with numbers ('78% of dogs prefer X', '3 out of 5 owners report...') are auto pulled-out into stat callouts — write them naturally inside paragraphs
- For any claim or quote sourced from a social media post (Reddit, Hacker News, Bluesky, Mastodon, DEV.to, Lemmy), DO NOT weave it into a regular paragraph. Instead format it as a markdown blockquote with a platform marker on the first line:
  > [bluesky @alice.bsky.social] The TypeScript error rate is down 40 percent this year.
  > https://bsky.app/profile/alice.bsky.social/post/xyz
  The plugin will render this as a dedicated review-before-publish card so the user can verify or delete it. Social media content can be unreliable or AI-generated, so it MUST be visually separated from your vetted prose.
- Valid platform markers: bluesky, mastodon, reddit, hn (or 'hacker news'), dev.to, lemmy. Always include the @handle or username. Always include the post URL on its own second blockquote line when you have one.
- These rich formatting hints REPLACE the BANNED WRITING PATTERNS rule against excessive bold ONLY for the specific cases above (definitions, key insights). Everywhere else, no bold.

FORMAT: Output GitHub Flavored Markdown. Use tables for comparisons. Use bullet/numbered lists for features and steps.";
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
