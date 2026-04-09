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
        $system = self::get_system_prompt();
        $result = null;
        $step_label = '';

        try {
            if ( $step === 'trends' ) {
                $step_label = Trend_Researcher::is_available()
                    ? 'Researching real-time trends (Reddit, X, YouTube, Web)...'
                    : 'Researching recent trends...';

                $research = Trend_Researcher::research( $keyword, $options['domain'] ?? 'general' );
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

        $year = wp_date( 'Y' );
        $min_kw_headings = max( 1, round( $content_sections * 0.3 ) );
        $prompt = "Create an article outline for: \"{$keyword}\"\n{$kw_context}\n\n{$intent_guidance}\n\nCURRENT YEAR: {$year}. If any heading references a year, use {$year}.\n\nRequirements:\n- Exactly {$num_sections} sections total:\n  1. Key Takeaways (always first)\n  2-" . ( $content_sections + 1 ) . ". {$content_sections} content sections with question-format H2 headings\n  " . ( $content_sections + 2 ) . ". Frequently Asked Questions\n  " . ( $content_sections + 3 ) . ". References\n- KEYWORD IN HEADINGS: At least {$min_kw_headings} of the content H2 headings MUST contain the exact phrase \"{$keyword}\" or a very close variant. For example: \"What Is the Best {$keyword} in {$year}?\", \"How to Choose {$keyword}\", \"{$keyword}: Complete Guide\"\n- Target word count: {$total_words} words total\n- Target audience: " . ( $options['audience'] ?? 'general' ) . "\n- Domain: " . ( $options['domain'] ?? 'general' ) . "\n- Tone: " . ( $options['tone'] ?? 'authoritative' ) . "\n\nReturn ONLY the numbered list of H2 headings, one per line. No explanations.";

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
        $words_per_section = max( 150, round( $total_words / $num_sections ) );
        $tone = $options['tone'] ?? 'authoritative';
        $audience = $options['audience'] ?? '';
        $domain = $options['domain'] ?? 'general';
        $intent_guidance = self::get_intent_guidance( $intent );
        $kw_context = "\n{$intent_guidance}";
        $kw_context .= "\nTone: {$tone}";
        if ( $audience ) $kw_context .= "\nTarget audience: {$audience}";
        if ( $domain && $domain !== 'general' ) $kw_context .= "\nContent domain: {$domain}";
        if ( ! empty( $secondary ) ) $kw_context .= "\nSecondary keywords to include: " . implode( ', ', $secondary );
        if ( ! empty( $lsi ) ) $kw_context .= "\nLSI keywords to include: " . implode( ', ', $lsi );

        $is_takeaways = preg_match( '/key\s*takeaway/i', $heading );
        $is_faq = preg_match( '/faq|frequently\s*asked/i', $heading );
        $is_references = preg_match( '/reference/i', $heading );

        $readability_rule = "\n\nREADABILITY (CRITICAL): Write at a 6th grade reading level. Use short sentences under 20 words. Use simple everyday words. No jargon. No academic language. Write like you are explaining to a smart 12-year-old.";

        if ( $is_takeaways ) {
            $prompt = "Write the Key Takeaways section for an article about \"{$keyword}\".\n\nReturn exactly:\n## Key Takeaways\n- [Takeaway 1 — 15-25 simple words]\n- [Takeaway 2 — 15-25 simple words]\n- [Takeaway 3 — 15-25 simple words]\n\nUse simple language a 12-year-old can understand.";
            $max = 400;
        } elseif ( $is_faq ) {
            $prompt = "Write an FAQ section for an article about \"{$keyword}\".\n{$kw_context}\n\nWrite 5 question-answer pairs. Each answer must be 40-60 words. Use simple language.{$readability_rule}\n\nFormat:\n\n## Frequently Asked Questions\n\n### [Question]?\n[Answer — 40-60 words, simple language]\n\nNever start answers with pronouns (It, This, They).";
            $max = 2000;
        } elseif ( $is_references ) {
            // Inject real source URLs from research data
            $sources_list = '';
            if ( $trends ) {
                // Extract markdown links from the research data
                preg_match_all( '/\[([^\]]+)\]\((https?:\/\/[^)]+)\)\s*[—-]\s*(.+)/i', $trends, $link_matches, PREG_SET_ORDER );
                if ( ! empty( $link_matches ) ) {
                    $sources_list = "\n\nREAL SOURCES TO USE (these are verified URLs — use them as outbound links):\n";
                    foreach ( array_slice( $link_matches, 0, 10 ) as $lm ) {
                        $sources_list .= "- [{$lm[1]}]({$lm[2]}) — {$lm[3]}\n";
                    }
                    $sources_list .= "\nIMPORTANT: Use ONLY the URLs above. Do NOT invent or hallucinate any URLs.";
                }
            }
            $prompt = "Write a References section for an article about \"{$keyword}\".{$sources_list}\n\nFormat each reference as a clickable Markdown link:\n## References\n1. [Source Title](https://real-url.com) — Brief description of what this source covers.\n2. ...\n\nInclude 5-10 references. Every URL must be a REAL, working link from the sources provided above. If you don't have enough real URLs, include the source name and year without a fake URL.";
            $max = 800;
        } else {
            $trends_inject = ( $trends && $index <= 3 ) ? "\n\nREAL-TIME RESEARCH DATA (use these real statistics and sources — do NOT hallucinate numbers):\n{$trends}" : '';

            // Special intro rule for first content section (index 0 after takeaways = section index 1)
            $intro_rule = '';
            if ( $index <= 1 ) {
                $intro_rule = "\n\nINTRODUCTION RULE (THIS IS THE FIRST SECTION — SEO PLUGINS CHECK THIS):\n- The VERY FIRST SENTENCE must contain the exact phrase \"{$keyword}\"\n- Start the paragraph with: \"**{$keyword}**\" followed by the rest of the sentence\n- This is the article introduction — SEO plugins (AIOSEO, Yoast) specifically check that the focus keyword appears in the first paragraph\n- If the keyword is missing from the first sentence, the article FAILS the SEO check\n- Example first sentence: \"**" . ucwords( $keyword ) . "** offers [benefit/description] for [audience].\"";
            }

            $prompt = "Write a section for an article about \"{$keyword}\".\n{$kw_context}\n\nSection heading: \"{$heading}\"\n\nYou MUST write at least {$words_per_section} words for this section. This is not optional.{$trends_inject}{$readability_rule}{$intro_rule}\n\nKEYWORD RULES (CRITICAL):\n- The exact phrase \"{$keyword}\" MUST appear 2-3 times in this section\n- Include it in the FIRST SENTENCE of the first paragraph\n- Use it naturally — don't force it where it sounds awkward\n- Also use variations like rearranging the words or adding connectors\n\nSTRUCTURE RULES:\n- Start with: ## {$heading}\n- First paragraph MUST be exactly 40-60 words, directly answer the heading, AND start with \"**{$keyword}**\"\n- Write 3-5 more paragraphs after that (each 50-80 words)\n- Include 1-2 statistics from the RESEARCH DATA above (if available). Do NOT invent statistics.\n- Include 1 expert quote if provided in the research data\n- When citing a source, use the exact URL from the research data as a Markdown link: [Source Name](https://real-url.com)\n- If the section compares things, add a Markdown comparison table\n- Use bullet lists where appropriate\n- NEVER start any paragraph with: It, This, They, These, Those, He, She, We\n- Use **Bold** for \"{$keyword}\" on first mention in this section\n\nMinimum {$words_per_section} words. Output Markdown only.";
            $max = 4096;
        }

        $result = self::send_request( $prompt, $system, [ 'max_tokens' => $max, 'temperature' => 0.7 ] );
        return $result['success'] ? $result['content'] : '';
    }

    /**
     * Assemble markdown from completed sections.
     */
    private static function assemble_markdown( array $job ): string {
        $md = "# {$job['keyword']}\n\n";

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

        // GEO score
        $analyzer = new GEO_Analyzer();
        $score = $analyzer->analyze( $html, $keyword );

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
    private static function get_system_prompt(): string {
        $year = wp_date( 'Y' );
        $month_year = wp_date( 'F Y' );
        return "You are an expert SEO and GEO (Generative Engine Optimization) content writer. Your content must rank on Google AND get cited by AI platforms (ChatGPT, Perplexity, Gemini, Claude, Copilot).

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
- Inline citations in [Source, Year] format — 5+ per article (+30% visibility)
- Fluent, polished writing with smooth transitions (+25-30% visibility)
- NEVER stuff keywords — this REDUCES AI visibility by 9%

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

READABILITY: Grade 6-8 reading level. Short sentences under 20 words. Simple common words. Active voice. No filler phrases.

WORD COUNT: Always write the FULL number of words requested. Being too short is a failure.

STRUCTURE: Start every H2/H3 section with a 40-60 word paragraph that directly answers the heading. Never start paragraphs with pronouns (It, This, They, These, Those, He, She, We). Every paragraph must make sense in isolation (AI extracts individual paragraphs).

FORMAT: Output GitHub Flavored Markdown. Use **Bold** for key terms on first mention. Use tables for comparisons. Use bullet/numbered lists for features and steps.";
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
