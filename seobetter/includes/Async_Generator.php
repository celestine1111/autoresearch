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
                // v1.5.137 — Strip Crossref/academic junk from research context BEFORE AI sees it.
                // Prevents AI from writing about "cited 0 times" or academic paper titles.
                $trends_raw = $research['for_prompt'] ?? '';
                $trends_raw = preg_replace( '/[^\n]*(?:cited \d+ times|Crossref,?\s*\d{4}|doi\.org\/|Government Gazette|Annual Report \d{4})[^\n]*\n?/i', '', $trends_raw );
                $job['results']['trends'] = $trends_raw;
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
                // v1.5.47 — threshold lowered from < 2 to < 1. A single verified
                // place is still better than an informational article with the
                // real business buried in body text and no meta line. With 1
                // pool entry, Local Business Mode below produces 1 business H2 +
                // generic fills, and Places_Link_Injector attaches the address /
                // Google Maps / website meta line below that H2.
                $places_insufficient = ! empty( $research['is_local_intent'] ) && $places_count < 1;
                $job['options']['places_insufficient'] = $places_insufficient;
                $job['results']['places_insufficient'] = $places_insufficient;

                // v1.5.33 — when we have a non-empty pool but the number of verified
                // places is SMALLER than what a word-count-based outline would produce,
                // cap the number of business-name H2s to exactly places_count. This
                // stops the model from being asked to write a 6-item listicle when
                // only 2 real businesses exist — it will fill the gap with fakes.
                // The generate_outline() branch reads this cap and builds the outline
                // with exactly N business H2s + generic fill sections.
                if ( ! empty( $research['is_local_intent'] ) && $places_count >= 1 ) {
                    $job['options']['local_business_cap']  = $places_count;
                    $job['options']['local_business_mode'] = true;
                    // Thread the verified pool names to generate_outline() so
                    // it can substitute the "Business N" placeholders with the
                    // real names from the pool.
                    $job['options']['places_pool_for_outline'] = $research['places'] ?? [];
                }

                // v1.5.81 — stash Sonar research data from the Vercel backend.
                // This data is server-side (Ben's key) so it's available for
                // ALL users regardless of their AI provider. Used by Citation
                // Pool (URLs), inject-fix buttons (quotes, stats, table), and
                // threaded to the frontend for the Optimize All button.
                $job['results']['sonar_data'] = [
                    'citations'  => $research['sonar_citations'] ?? [],
                    'quotes'     => $research['sonar_quotes'] ?? [],
                    'statistics' => $research['sonar_statistics'] ?? [],
                    'table_data' => $research['sonar_table_data'] ?? null,
                    'available'  => ! empty( $research['sonar_available'] ),
                ];

                // v1.5.124 — Recipe sourcing from DEDICATED recipe domains via Tavily.
                // Uses get_recipe_domains() — a SEPARATE list from get_authority_domains().
                // These are recipe-specific sites (cookpad, allrecipes, bbcgoodfood)
                // NOT the general authority list (which has news/health/gov sites).
                // Only fires when content_type === 'recipe'. Never affects other types.
                if ( ( $options['content_type'] ?? '' ) === 'recipe' ) {
                    $recipe_domains = self::get_recipe_domains( $options['country'] ?? '' );
                    $settings = get_option( 'seobetter_settings', [] );
                    $tavily_key = $settings['tavily_api_key'] ?? '';
                    $tavily_recipes = [ 'results' => [] ];

                    if ( ! empty( $tavily_key ) ) {
                        $tavily_body = [
                            'api_key'             => $tavily_key,
                            'query'               => $keyword . ' recipe ingredients instructions',
                            'include_raw_content'  => true,
                            'max_results'          => 5,
                            'search_depth'         => 'basic',
                        ];
                        if ( ! empty( $recipe_domains ) ) {
                            $tavily_body['include_domains'] = $recipe_domains;
                        }
                        $resp = wp_remote_post( 'https://api.tavily.com/search', [
                            'timeout' => 20,
                            'headers' => [ 'Content-Type' => 'application/json' ],
                            'body'    => wp_json_encode( $tavily_body ),
                        ] );
                        if ( ! is_wp_error( $resp ) ) {
                            $body = json_decode( wp_remote_retrieve_body( $resp ), true );
                            if ( ! empty( $body['results'] ) ) {
                                $tavily_recipes['results'] = $body['results'];
                            }
                            // Fallback: if recipe domains found < 2, retry without domain filter
                            if ( count( $tavily_recipes['results'] ) < 2 && ! empty( $recipe_domains ) ) {
                                unset( $tavily_body['include_domains'] );
                                $resp2 = wp_remote_post( 'https://api.tavily.com/search', [
                                    'timeout' => 20,
                                    'headers' => [ 'Content-Type' => 'application/json' ],
                                    'body'    => wp_json_encode( $tavily_body ),
                                ] );
                                if ( ! is_wp_error( $resp2 ) ) {
                                    $body2 = json_decode( wp_remote_retrieve_body( $resp2 ), true );
                                    if ( ! empty( $body2['results'] ) ) {
                                        $tavily_recipes['results'] = $body2['results'];
                                    }
                                }
                            }
                        }
                    }

                    // v1.5.131 — Recipe sourcing from Tavily.
                    // Count ALL results with 200+ chars as valid sources (not gated on extraction).
                    // Try to extract structured data as a bonus — if extraction succeeds,
                    // give AI the parsed ingredients to copy. If not, give raw text.
                    $recipe_data_block = '';
                    $usable_recipe_count = 0;
                    $recipe_sources = [];
                    $extracted_recipes = [];

                    if ( ! empty( $tavily_recipes['results'] ) ) {
                        // v1.5.133 — Get the Vercel cloud URL for Firecrawl scraping
                        $cloud_url = Cloud_API::get_cloud_url();

                        foreach ( array_slice( $tavily_recipes['results'], 0, 5 ) as $recipe_result ) {
                            $page_url = $recipe_result['url'] ?? '';
                            $raw = $recipe_result['raw_content'] ?? '';

                            // v1.5.133 — Try Firecrawl scrape for clean markdown (much better than Tavily raw HTML)
                            $firecrawl_md = null;
                            if ( ! empty( $page_url ) && ! empty( $cloud_url ) ) {
                                $scrape_resp = wp_remote_post( $cloud_url . '/api/scrape', [
                                    'timeout' => 15,
                                    'headers' => [ 'Content-Type' => 'application/json' ],
                                    'body'    => wp_json_encode( [ 'url' => $page_url ] ),
                                ] );
                                if ( ! is_wp_error( $scrape_resp ) ) {
                                    $scrape_body = json_decode( wp_remote_retrieve_body( $scrape_resp ), true );
                                    if ( ! empty( $scrape_body['success'] ) && ! empty( $scrape_body['markdown'] ) ) {
                                        $firecrawl_md = $scrape_body['markdown'];
                                    }
                                }
                            }

                            // Use Firecrawl markdown if available, fall back to Tavily raw_content
                            $content_to_parse = $firecrawl_md ?? $raw;
                            if ( strlen( $content_to_parse ) < 200 ) continue;

                            $clean = $firecrawl_md
                                ? mb_substr( preg_replace( '/\s+/', ' ', $firecrawl_md ), 0, 1200 )
                                : mb_substr( preg_replace( '/\s+/', ' ', strip_tags( $raw ) ), 0, 800 );

                            $source = [
                                'title' => $recipe_result['title'] ?? 'Unknown',
                                'url'   => $page_url,
                                'text'  => $clean,
                            ];

                            // Extract structured recipe data — works much better on Firecrawl markdown
                            $parsed = self::extract_recipe_from_raw( $content_to_parse );
                            if ( ! empty( $parsed['ingredients'] ) ) {
                                $source['parsed'] = $parsed;
                                $parsed['source_title'] = $source['title'];
                                $parsed['source_url']   = $source['url'];
                                $extracted_recipes[] = $parsed;
                            }

                            $recipe_sources[] = $source;
                        }
                        $usable_recipe_count = count( $recipe_sources );

                        if ( $usable_recipe_count > 0 ) {
                            $recipe_data_block .= "\n\n=== REAL RECIPE DATA (from verified sources — use these as your base) ===\n";
                            $recipe_data_block .= "You have {$usable_recipe_count} real recipe source(s) below. Write EXACTLY {$usable_recipe_count} recipe(s) — one per source.\n";
                            $recipe_data_block .= "ABSOLUTE RULE: Do NOT invent any recipe. Use ONLY ingredients and steps from these sources. Do NOT add, remove, substitute, or change any ingredient or measurement.\n";
                            $recipe_data_block .= "What you CAN change: (1) Give each recipe a creative unique NAME. (2) Rewrite the intro in your own words. (3) Rephrase instruction wording (same steps, different phrasing). (4) Keep temperatures and times exactly as stated.\n";
                            $recipe_data_block .= "At the end of EACH recipe write: \"Inspired by [Source Name](source_url)\"\n\n";

                            foreach ( $recipe_sources as $ri => $src ) {
                                $n = $ri + 1;
                                $recipe_data_block .= "--- Source {$n}: {$src['title']} ---\n";
                                $recipe_data_block .= "URL: {$src['url']}\n";

                                // If we parsed structured data, show it clearly
                                if ( ! empty( $src['parsed']['ingredients'] ) ) {
                                    $recipe_data_block .= "INGREDIENTS (copy these EXACTLY):\n";
                                    foreach ( $src['parsed']['ingredients'] as $ing ) {
                                        $recipe_data_block .= "  - {$ing}\n";
                                    }
                                    if ( ! empty( $src['parsed']['instructions'] ) ) {
                                        $recipe_data_block .= "INSTRUCTIONS (rephrase wording but keep same steps):\n";
                                        foreach ( array_slice( $src['parsed']['instructions'], 0, 10 ) as $si => $step ) {
                                            $recipe_data_block .= "  " . ( $si + 1 ) . ". {$step}\n";
                                        }
                                    }
                                } else {
                                    // Fall back to raw text excerpt
                                    $recipe_data_block .= "Content excerpt (find and copy the exact ingredients and steps from this):\n";
                                    $recipe_data_block .= $src['text'] . "\n";
                                }

                                $recipe_data_block .= "\n";
                            }
                        }
                    }

                    // Store extracted data for assemble_final() injection
                    $job['recipe_source_count']   = $usable_recipe_count;
                    $job['extracted_recipes']      = $extracted_recipes;

                    if ( $recipe_data_block ) {
                        $job['results']['trends'] = ( $job['results']['trends'] ?? '' ) . $recipe_data_block;
                    }
                }

                // Build the verified citation pool (real keyword-relevant URLs)
                // v1.5.81 — pass Sonar citations as additional pool candidates
                $pool = Citation_Pool::build(
                    $keyword,
                    $options['country'] ?? '',
                    $options['domain'] ?? 'general',
                    $research['sonar_citations'] ?? []
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
                // v1.5.127 — Pass recipe source count to outline so template matches sources
                if ( isset( $job['recipe_source_count'] ) ) {
                    $options['recipe_source_count'] = $job['recipe_source_count'];
                }
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
            default => "SEARCH INTENT: Informational (user wants to learn/understand).\nSTRUCTURE: Comprehensive guide with definitions, step-by-step explanations, real statistics from research data, and FAQ. Be thorough and educational.",
        };
    }

    /**
     * Get tone-specific writing guidance so the AI actually changes voice.
     */
    /**
     * v1.5.124 — RECIPE-ONLY domain list. Completely separate from get_authority_domains().
     * Only used when content_type === 'recipe' during Tavily recipe search.
     * Never affects other article types. Contains recipe-specific sites per country
     * in local languages.
     */
    private static function get_recipe_domains( string $country = '' ): array {
        // Global recipe sites (always included)
        $global = [
            'allrecipes.com', 'foodnetwork.com', 'bbcgoodfood.com',
            'epicurious.com', 'seriouseats.com', 'food52.com',
            // Pet recipe authorities (global)
            'petmd.com', 'akc.org', 'thesprucepets.com', 'rover.com',
            'mindiampets.com.au',
        ];

        // Country-specific recipe sites in local languages
        $by_country = [
            'AU' => [ 'taste.com.au', 'sbs.com.au', 'delicious.com.au', 'recipetineats.com' ],
            'US' => [ 'bonappetit.com', 'kingarthurbaking.com', 'simplyrecipes.com' ],
            'GB' => [ 'bbc.co.uk', 'jamieoliver.com', 'greatbritishchefs.com', 'olivemagazine.com' ],
            'CA' => [ 'canadianliving.com', 'ricardocuisine.com', 'chatelaine.com' ],
            'NZ' => [ 'foodhub.co.nz', 'cuisine.co.nz', 'edmondscooking.co.nz' ],
            'IE' => [ 'rte.ie', 'irishexaminer.com' ],
            'JP' => [ 'cookpad.com', 'kurashiru.com', 'delishkitchen.tv', 'orangepage.net' ],
            'KR' => [ '10000recipe.com', 'haemukja.com', 'wtable.co.kr' ],
            'CN' => [ 'xiachufang.com', 'meishichina.com', 'douguo.com' ],
            'TW' => [ 'icook.tw', 'cookpad.com' ],
            'FR' => [ 'marmiton.org', 'cuisineaz.com', '750g.com' ],
            'DE' => [ 'chefkoch.de', 'lecker.de', 'essen-und-trinken.de', 'kochbar.de' ],
            'AT' => [ 'chefkoch.de', 'ichkoche.at' ],
            'CH' => [ 'chefkoch.de', 'swissmilk.ch' ],
            'IT' => [ 'giallozafferano.it', 'cucchiaio.it', 'cookist.it' ],
            'ES' => [ 'recetasgratis.net', 'directoalpaladar.com' ],
            'PT' => [ 'saboresajinomoto.com.br', 'teleculinaria.pt' ],
            'BR' => [ 'tudogostoso.com.br', 'panelinha.com.br', 'cybercook.com.br' ],
            'MX' => [ 'kiwilimon.com', 'cocinadelirante.com', 'recetasgratis.net' ],
            'AR' => [ 'recetasgratis.com.ar', 'paulinacocina.com.ar' ],
            'CL' => [ 'recetasgratis.net', 'gourmet.cl' ],
            'CO' => [ 'recetasgratis.net', 'mycolombianrecipes.com' ],
            'IN' => [ 'vegrecipesofindia.com', 'hebbars kitchen.com', 'tarladalal.com', 'sanjeevkapoor.com' ],
            'TH' => [ 'wongnai.com', 'cookpad.com' ],
            'VN' => [ 'cooky.vn', 'monngonmoingay.com' ],
            'ID' => [ 'cookpad.com', 'resepkoki.id', 'masakapahariini.com' ],
            'MY' => [ 'rasa.my', 'cookpad.com' ],
            'PH' => [ 'panlasangpinoy.com', 'cookpad.com' ],
            'SG' => [ 'noobcook.com', 'cookpad.com' ],
            'SE' => [ 'koket.se', 'ica.se', 'arla.se' ],
            'NO' => [ 'matprat.no', 'godt.no', 'tine.no' ],
            'DK' => [ 'valdemarsro.dk', 'arla.dk' ],
            'FI' => [ 'valio.fi', 'kotikokki.net' ],
            'NL' => [ 'ah.nl', 'leukerecepten.nl', 'smulweb.nl' ],
            'BE' => [ 'dagelijksekost.een.be', 'leukerecepten.nl' ],
            'PL' => [ 'kwestiasmaku.com', 'przepisy.pl', 'mojegotowanie.pl' ],
            'CZ' => [ 'recepty.cz', 'toprecepty.cz' ],
            'HU' => [ 'nosalty.hu', 'mindmegette.hu' ],
            'RO' => [ 'reteteculinare.ro', 'bucataras.ro' ],
            'TR' => [ 'nefisyemektarifleri.com', 'yemek.com', 'lezzet.com.tr' ],
            'RU' => [ 'povarenok.ru', 'russianfood.com', 'gotovim-doma.ru' ],
            'UA' => [ 'smachno.ua', 'povarenok.ru' ],
            'GR' => [ 'sintagespareas.gr', 'akispetretzikis.com' ],
            'IL' => [ 'foodish.co.il', 'mako.co.il' ],
            'AE' => [ 'shahiya.com', 'fatafeat.com' ],
            'SA' => [ 'shahiya.com', 'atyabtabkha.com' ],
            'EG' => [ 'shahiya.com', 'fatafeat.com' ],
            'ZA' => [ 'food24.com', 'eatingout.co.za' ],
            'NG' => [ 'allnigerianrecipes.com', 'sisiyeloo.com' ],
            'KE' => [ 'kaluhiskitchen.com' ],
        ];

        $country_upper = strtoupper( $country );
        $result = $global;
        if ( isset( $by_country[ $country_upper ] ) ) {
            $result = array_merge( $by_country[ $country_upper ], $result );
        }

        return array_unique( $result );
    }

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
    /**
     * v1.5.127 — Build recipe template dynamically based on available sources.
     * The number of recipes matches the number of real sources from Tavily.
     * If 0 sources found, the article becomes informational (no recipe cards).
     */
    private static function build_recipe_template( int $source_count ): array {
        // Clamp to 1-5 recipes
        $count = max( 0, min( 5, $source_count ) );

        if ( $count === 0 ) {
            // No recipe sources found — write informational article instead
            return [
                'sections' => 'Key Takeaways, Why This Matters (stats and context), What to Look For in Good Recipes, Where to Find Trusted Recipes Online, What Ingredients to Avoid (Safety), Pros and Cons, FAQ, References',
                'guidance' => 'IMPORTANT: No verified recipe data was found from trusted sources. Do NOT invent any recipes. Instead, write an informational guide about this topic: what to look for in good recipes, safety tips, where to find trusted recipes online (link to well-known recipe sites), and general guidance. Focus on education and safety rather than providing specific recipes without verified sources.',
                'schema'   => 'Article',
            ];
        }

        // Build section list dynamically
        $recipe_sections = [];
        for ( $i = 1; $i <= $count; $i++ ) {
            $recipe_sections[] = "Recipe {$i}: [Creative Name] (with Ingredients + Instructions + Storage subsections)";
        }
        $sections = 'Key Takeaways, Why This Matters (stats and context - NOT inside recipes), Quick Comparison Table, '
            . implode( ', ', $recipe_sections )
            . ', What Ingredients to Avoid (Safety), Pros and Cons, FAQ, References';

        $guidance = "RECIPE CARD FORMAT. Write EXACTLY {$count} recipe(s) — one per real source provided in the research data above. "
            . "ABSOLUTE RULE: Every recipe MUST come from the REAL RECIPE DATA sources. Do NOT invent any recipe. "
            . "Copy the EXACT ingredients and quantities listed under each source's INGREDIENTS section. Do NOT change, add, or remove any ingredient. "
            . "Each recipe MUST have: a creative unique name as the H2, then subsections: Ingredients (as a bullet list under ### Ingredients), Instructions (as a numbered list under ### Instructions), and Storage Notes. "
            . "Put ALL statistics, expert quotes, and context in the intro section BEFORE the recipes - NEVER inside a recipe card. "
            . 'At the end of EACH recipe write: "Inspired by [Source Name](source_url)" citing the original source. Every recipe MUST have this attribution line.';

        return [
            'sections' => $sections,
            'guidance' => $guidance,
            'schema'   => 'Recipe',
        ];
    }

    private static function get_prose_template( string $content_type, int $recipe_source_count = 3 ): array {
        $templates = [
            'blog_post' => ['sections' => 'Key Takeaways, 3-5 topic sections with H2 headings, Pros and Cons, FAQ, References', 'guidance' => 'Conversational blog entry. Grab attention with an opening hook. Personal voice allowed. Include a Pros and Cons section. End with a call to action.', 'schema' => 'BlogPosting'],
            'news_article' => ['sections' => 'Key Takeaways, Lede (who/what/when/where/why), Supporting Details, Background Context, What Happens Next, FAQ, References', 'guidance' => 'Inverted pyramid: most important facts first. Neutral third person. Attribute every claim. Short paragraphs.', 'schema' => 'NewsArticle'],
            'opinion' => ['sections' => 'Key Takeaways, Thesis Statement, Supporting Arguments (3 points with evidence), Counterargument, Call to Action, FAQ, References', 'guidance' => 'Argumentative piece with clear stance. First person allowed. Confident tone. Address the strongest counterargument.', 'schema' => 'OpinionNewsArticle'],
            'how_to' => ['sections' => 'Key Takeaways, Why This Matters, What You Will Need, Numbered Steps (each step: action verb + result), Common Problems, Conclusion, FAQ, References', 'guidance' => 'Step-by-step tutorial. Imperative voice (do this, then do that). Clear prerequisites. Each step should be independently actionable.', 'schema' => 'HowTo'],
            'listicle' => ['sections' => 'Key Takeaways, Introduction (selection criteria and why this list), 10 Numbered Items (EACH item gets its own H2 heading numbered 1-10 like "1. Product Name" with 100-200 words per item), Conclusion (overall recommendation), FAQ, References', 'guidance' => 'TOP 10 LIST FORMAT: You MUST create exactly 10 numbered items. Each item gets its OWN H2 heading formatted as "1. Item Name", "2. Item Name" etc. Write 100-200 words per item. Make it scannable — readers skip to items they care about. Include one specific detail or stat per item.', 'schema' => 'Article'],
            'review' => ['sections' => 'Key Takeaways, What It Is and Who It Is For, Key Specs and Features, Hands-on Experience, Pros and Cons, Verdict and Rating, FAQ, References', 'guidance' => 'Honest product evaluation. Evidence-based claims. Include specific measurements and comparisons. Declare a clear verdict.', 'schema' => 'Review'],
            'comparison' => ['sections' => 'Key Takeaways, Quick Overview Table, One H2 Per Comparison Criterion (declare winner per criterion), Overall Verdict, Who Should Pick Which, FAQ, References', 'guidance' => 'Head-to-head comparison. Must include at least one comparison table. Declare a winner per criterion and overall. Be specific with numbers.', 'schema' => 'Article'],
            'buying_guide' => ['sections' => 'Key Takeaways, Quick Picks Table, Individual Product Mini-Reviews (each with H2), What to Look For When Buying, FAQ, References', 'guidance' => 'Best X for Y roundup. Start with a quick picks summary table. Each product gets pros/cons/verdict. End with buying criteria.', 'schema' => 'Article'],
            'pillar_guide' => ['sections' => 'Key Takeaways, Table of Contents, 5-10 Chapter Sections (each a standalone mini-guide with H2), Summary and Next Steps, Further Reading, FAQ, References', 'guidance' => 'Comprehensive evergreen guide. Each chapter should work as a standalone article. Include internal links between chapters. This is the definitive resource on the topic.', 'schema' => 'Article'],
            'case_study' => ['sections' => 'Key Takeaways, Executive Summary, The Challenge, The Solution, Results and Metrics, Client Quote, FAQ, References', 'guidance' => 'Customer success story. Lead with the metric. Problem-solution-result structure. Include specific numbers. End with a direct quote from the client.', 'schema' => 'Article'],
            'interview' => ['sections' => 'Key Takeaways, Introduction (why this person), Short Bio, Q&A Pairs (5-10 questions), Closing Thoughts, FAQ, References', 'guidance' => 'Q&A format. Questions should be bold H3 headings. Answers should feel natural and conversational. Include follow-up questions.', 'schema' => 'Article'],
            'faq_page' => ['sections' => 'Topic Introduction, 10-15 Question and Answer Pairs, References', 'guidance' => 'Collection of Q&A pairs. Questions phrased exactly as users search. Direct answers in the first sentence. Vary answer lengths.', 'schema' => 'FAQPage'],
            'recipe' => self::build_recipe_template( $recipe_source_count ),
            'tech_article' => ['sections' => 'Key Takeaways, What You Will Build, Prerequisites, Setup, Code Walkthrough (with code blocks), Testing and Verification, Recap and Further Reading, FAQ, References', 'guidance' => 'Developer tutorial. Include code blocks. Explain each step. List prerequisites clearly. Test everything you recommend.', 'schema' => 'TechArticle'],
            'white_paper' => ['sections' => 'Executive Summary, Introduction and Problem Statement, Methodology, Findings, Analysis, Recommendations, Conclusion, FAQ, References', 'guidance' => 'Authoritative research report. Data-driven. Formal tone. Every claim backed by evidence. Include charts/tables for data visualization.', 'schema' => 'Report'],
            'scholarly_article' => ['sections' => 'Abstract, Introduction, Literature Review, Methods, Results, Discussion, Conclusion, FAQ, References', 'guidance' => 'Academic paper format. Formal academic tone. Cite all claims. Include methodology details. Discuss limitations.', 'schema' => 'ScholarlyArticle'],
            'live_blog' => ['sections' => 'What We Are Covering, Timestamped Updates (latest first), FAQ, References', 'guidance' => 'Real-time event coverage. Each update gets a timestamp. Latest first. Short punchy updates. Include quotes from participants.', 'schema' => 'LiveBlogPosting'],
            'press_release' => ['sections' => 'Headline, Subheadline, Dateline and Lede Paragraph, Body with Quotes, About Company, Media Contact, FAQ, References', 'guidance' => 'Corporate announcement. Formal third person. Include at least 2 executive quotes. End with company boilerplate. Keep under 800 words.', 'schema' => 'NewsArticle'],
            'personal_essay' => ['sections' => 'Opening Scene, Tension or Conflict, Reflection, Resolution or Lesson, FAQ, References', 'guidance' => 'First-person narrative. Show don\'t tell. Vivid sensory details. Honest personal voice. Build to a realization or lesson learned.', 'schema' => 'BlogPosting'],
            'glossary_definition' => ['sections' => 'One-Sentence Definition, Expanded Explanation, Examples, Related Terms, FAQ, References', 'guidance' => 'What is X? explainer. Lead with the definition. Expand with context. Include practical examples. Link to related terms.', 'schema' => 'Article'],
            'sponsored' => ['sections' => 'Sponsorship Disclosure, Introduction, Body, Sponsor Call to Action, FAQ, References', 'guidance' => 'Paid content. MUST include clear disclosure at the top. Balance informational value with sponsor messaging. Be transparent.', 'schema' => 'AdvertiserContentArticle'],
        ];

        // v1.5.154 — Shared SEO + humanizer + readability + structure rules.
        // These produce articles that score 80+ on first generation without
        // needing a second optimization pass.
        $shared = ' CRITICAL RULES FOR ALL TYPES:'
            // Readability (grade 6-8 target)
            . ' READABILITY: Write at grade 6-8 reading level. Use short sentences (15-20 words max). Use simple words — "use" not "utilize", "help" not "facilitate", "show" not "demonstrate". Break long paragraphs into 2-3 sentences. No academic jargon unless the content type requires it.'
            // Section structure
            . ' SECTION OPENINGS: Start EVERY H2/H3 section with a 40-60 word paragraph that directly answers the heading question. This is the optimal length for AI extraction. Do NOT start sections with background context — answer first, explain second.'
            // Keyword density
            . ' KEYWORD DENSITY: Use the focus keyword naturally 1-2 times per section. Do NOT repeat it in every sentence — that is keyword stuffing which REDUCES visibility by 9%. Use synonyms and variations instead (e.g. for "dog food" use "pet nutrition", "canine diet", "kibble").'
            // Citations
            . ' CITATIONS: Include inline citations as clickable Markdown links using ONLY URLs from the AVAILABLE CITATIONS list. Aim for 1 citation per 200 words. Link every source mention.'
            // Statistics
            . ' STATISTICS: Include 3+ real statistics per 1000 words with source attribution (+40% AI visibility).'
            // Expert quotes
            . ' QUOTES: Include 2+ expert quotes with full name and organization (+41% visibility).'
            // Comparison table
            . ' TABLE: Include at least one comparison or data table. LLMs are 30-40% more likely to cite content with tables.'
            // FAQ section
            . ' FAQ: Include a "## Frequently Asked Questions" section with 3-5 Q&A pairs. Questions should end with ? and be phrased as users search.'
            // Pros and Cons
            . ' PROS/CONS: Include a "## Pros and Cons" section with bullet lists (auto-styles into colored boxes).'
            // Humanizer
            . ' HUMANIZER: No AI words (delve, leverage, pivotal, tapestry, landscape, multifaceted, comprehensive, utilizing, aforementioned). Vary sentence rhythm. Write like a knowledgeable human with opinions, not a textbook.'
            // E-E-A-T
            . ' E-E-A-T: Show experience (first-hand testing), expertise (specific knowledge), authority (cite real sources), trustworthiness (honest pros/cons, no fake claims).'
            // URLs
            . ' NEVER invent URLs. Only use URLs from the AVAILABLE CITATIONS list. If mentioning an organization without a URL, do NOT link it.';

        $template = $templates[ $content_type ] ?? $templates['blog_post'];
        $template['guidance'] .= $shared;
        return $template;
    }

    /**
     * Generate the article outline (one API call).
     */
    private static function generate_outline( string $keyword, array $options, array $secondary, array $lsi ): array {
        $total_words = $options['word_count'] ?? 2000;
        // v1.5.46 — scale content sections to the actual word target more
        // tightly. Previously: max(3, min(8, round(total/400))) which produced
        // 3 sections for any target <=1200 words and overshot by 25% on short
        // articles. New scale matches the per-section formula in
        // generate_section() so outline length and section word budgets are
        // consistent end-to-end.
        if ( $total_words <= 600 ) {
            $content_sections = 2;
        } elseif ( $total_words <= 1000 ) {
            $content_sections = 3;
        } elseif ( $total_words <= 1500 ) {
            $content_sections = 4;
        } elseif ( $total_words <= 2200 ) {
            $content_sections = 5;
        } elseif ( $total_words <= 2800 ) {
            $content_sections = 6;
        } else {
            $content_sections = min( 8, round( $total_words / 400 ) );
        }
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

        // v1.5.115 — Country context for AI prompts. Without this, the AI
        // defaults to US-centric content even when Australia is selected.
        $country_code = strtoupper( $options['country'] ?? '' );
        $country_names = [
            'AU' => 'Australia', 'US' => 'United States', 'GB' => 'United Kingdom',
            'CA' => 'Canada', 'NZ' => 'New Zealand', 'DE' => 'Germany', 'FR' => 'France',
            'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands', 'SE' => 'Sweden',
            'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland', 'JP' => 'Japan',
            'KR' => 'South Korea', 'CN' => 'China', 'IN' => 'India', 'SG' => 'Singapore',
            'MY' => 'Malaysia', 'ID' => 'Indonesia', 'TH' => 'Thailand', 'PH' => 'Philippines',
            'BR' => 'Brazil', 'MX' => 'Mexico', 'AR' => 'Argentina', 'CL' => 'Chile',
            'CO' => 'Colombia', 'ZA' => 'South Africa', 'NG' => 'Nigeria', 'KE' => 'Kenya',
            'EG' => 'Egypt', 'IL' => 'Israel', 'AE' => 'UAE', 'SA' => 'Saudi Arabia',
            'TR' => 'Turkey', 'PL' => 'Poland', 'CZ' => 'Czech Republic', 'RO' => 'Romania',
            'IE' => 'Ireland', 'PT' => 'Portugal', 'AT' => 'Austria', 'CH' => 'Switzerland',
            'BE' => 'Belgium', 'GR' => 'Greece',
        ];
        $country_name = $country_names[ $country_code ] ?? '';
        $country_context = '';
        if ( $country_name ) {
            $country_context = "\nTARGET COUNTRY: {$country_name}. Write for a {$country_name} audience. Use local brands, regulations, pricing (local currency), terminology, and cultural references specific to {$country_name}. Do NOT default to US examples, US brands, US regulations, or US pricing unless the keyword specifically mentions the US.";
        }
        $recipe_count = $options['recipe_source_count'] ?? 3;
        $prose = self::get_prose_template( $content_type, $recipe_count );

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
            // v1.5.60 — content types where a comparison table boosts GEO cite
            // rate 30-40% AND fires CORE-EEAT O2 + Tables score in GEO_Analyzer.
            // For these types, force an explicit "Quick Comparison Table" H2 in
            // the outline so generate_section() can emit a real markdown table.
            $table_content_types = [ 'listicle', 'how_to', 'buying_guide', 'comparison', 'review', 'ultimate_guide' ];
            $needs_table = in_array( $content_type, $table_content_types, true );
            $table_instruction = $needs_table
                ? "- INCLUDE ONE H2 titled exactly \"Quick Comparison Table\" (or \"At a Glance\" for comparison articles). This section will contain a real markdown comparison table — it is REQUIRED for this content type to meet GEO scoring.\n"
                : '';

            $prompt = "Create an article outline for: \"{$keyword}\"\n{$kw_context}\n\n{$intent_guidance}\n{$tone_guidance}\n\nCONTENT TYPE: {$content_type}\nREQUIRED SECTIONS: {$prose['sections']}\nGUIDANCE: {$prose['guidance']}\n\nCURRENT YEAR: {$year}. If any heading references a year, use {$year}.\nTarget audience: {$audience}\nDomain: " . ( $options['domain'] ?? 'general' ) . "{$country_context}\n\nRequirements:\n"
                . "- Follow the REQUIRED SECTIONS structure above — use those as your H2 headings\n"
                . "- Adapt the section names to fit the specific keyword naturally\n"
                . "- KEYWORD IN HEADINGS: At least {$min_kw_headings} of the H2 headings MUST contain the exact phrase \"{$keyword}\" or a very close variant. SEO plugins check this — headings without the keyword get flagged.\n"
                . $table_instruction
                . "- Make headings sound natural, not like SEO templates\n"
                . "- Target word count: {$total_words} words\n\n"
                . "Return ONLY the numbered list of H2 headings, one per line. No explanations.";
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
        // v1.5.46 — stricter word budget formula. Previously: 0.85 × total /
        // num_sections, which ignored the fixed cost of Key Takeaways + FAQ
        // and produced 25%+ overshoot on short articles (user selected 2000,
        // got 2480). New formula reserves a fixed 350-word budget for the
        // structural sections (takeaways ~100, FAQ ~250) and distributes the
        // remaining budget across content sections. Also trimmed num_sections
        // scaling for better fit on 500/1000/1500 targets.
        $structural_budget = 350; // Key Takeaways + FAQ + References combined
        $content_budget = max( 150, $total_words - $structural_budget );
        // Scale number of content sections to match total target more tightly
        if ( $total_words <= 600 ) {
            $num_sections = 2;
        } elseif ( $total_words <= 1000 ) {
            $num_sections = 3;
        } elseif ( $total_words <= 1500 ) {
            $num_sections = 4;
        } elseif ( $total_words <= 2200 ) {
            $num_sections = 5;
        } elseif ( $total_words <= 2800 ) {
            $num_sections = 6;
        } else {
            $num_sections = min( 8, round( $total_words / 400 ) );
        }
        // Reduce per-section budget by 15% to compensate for average AI overshoot
        $words_per_section = max( 60, round( ( $content_budget / $num_sections ) * 0.85 ) );
        $tone = $options['tone'] ?? 'authoritative';
        $audience = $options['audience'] ?? '';
        $domain = $options['domain'] ?? 'general';
        $intent_guidance = self::get_intent_guidance( $intent );
        $tone_guidance = self::get_tone_guidance( $tone );
        $content_type = $options['content_type'] ?? 'blog_post';
        $recipe_count = $options['recipe_source_count'] ?? 3;
        $prose = self::get_prose_template( $content_type, $recipe_count );
        $kw_context = "\nCONTENT TYPE: {$content_type}. {$prose['guidance']}";
        $kw_context .= "\n{$intent_guidance}";
        $kw_context .= "\n{$tone_guidance}";
        if ( $audience ) $kw_context .= "\nTarget audience: {$audience} — write for this specific reader, use their language and concerns";
        if ( $domain && $domain !== 'general' ) $kw_context .= "\nContent domain: {$domain}";
        // v1.5.115 — Country context in every section prompt
        $country_code_sec = strtoupper( $options['country'] ?? '' );
        $country_names_sec = [
            'AU' => 'Australia', 'US' => 'United States', 'GB' => 'United Kingdom',
            'CA' => 'Canada', 'NZ' => 'New Zealand', 'DE' => 'Germany', 'FR' => 'France',
            'IT' => 'Italy', 'ES' => 'Spain', 'JP' => 'Japan', 'KR' => 'South Korea',
            'IN' => 'India', 'BR' => 'Brazil', 'MX' => 'Mexico', 'ZA' => 'South Africa',
            'IE' => 'Ireland', 'NL' => 'Netherlands', 'SE' => 'Sweden', 'NO' => 'Norway',
            'SG' => 'Singapore', 'AE' => 'UAE', 'SA' => 'Saudi Arabia', 'TR' => 'Turkey',
        ];
        $country_name_sec = $country_names_sec[ $country_code_sec ] ?? '';
        if ( $country_name_sec ) {
            $kw_context .= "\nTARGET COUNTRY: {$country_name_sec}. Use {$country_name_sec} brands, regulations, pricing (local currency), terminology. Do NOT default to US examples unless the keyword mentions US.";
        }
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
        // v1.5.60 — detect comparison table sections injected by the outline
        // for Listicle / How-To / Buying Guide / Comparison / Review content
        // types. When this heading fires, the section generator produces a
        // real markdown table (not a prose section) so the GEO_Analyzer
        // Tables check scores 100 and CORE-EEAT O2 fires.
        $is_table = preg_match( '/(quick\s*comparison|at\s*a\s*glance|comparison\s*table|at-a-glance|side.by.side)/i', $heading );

        // v1.5.33 — check if this section's heading matches a verified place
        // in the Places Pool. If yes, we're writing about a specific real
        // business and MUST NOT invent any facts beyond what the pool contains.
        $matched_place = null;
        if ( ! empty( $places_pool ) && ! $is_takeaways && ! $is_faq && ! $is_references ) {
            // Strip listicle numbering from the heading before matching
            $candidate = preg_replace( '/^(?:#?\d+[\.\):—\-]|no\.\s*\d+\s*[—\-])\s*/i', '', $heading );
            $matched_place = Places_Validator::pool_lookup( $candidate, $places_pool );
        }

        // v1.5.60 — readability + density rules rewritten based on live Mindiam
        // Pets article audit. v1.5.48's rule produced grade 11-13 (target 6-8)
        // because the model treated the abstract rules as hints. New rule gives
        // EXPLICIT sentence-pair examples (DO vs DON'T) that the model imitates.
        // Also: v1.5.48's "AT MOST 2 per section" keyword cap over-corrected —
        // live articles landed at 0.33% density (target 0.5-1.5%, AIOSEO requires
        // >0.5%). New formula scales with section length: 2-4 mentions per 500
        // words. Plus hard requirements for first-hand language ("we tested",
        // "in our experience") to fire CORE-EEAT E1, and 3+ named entities per
        // section to fire CORE-EEAT A1 and Entity Density.
        // v1.5.61 — tightened from v1.5.60's max(2, /250) min / max(3, /150)
        // max which produced 2.31% density on live Mudgee test (target 0.5-1.5%).
        // New formula: target 1 mention per 300-400 words, hard-capped at 2 per
        // section regardless of length. For a 400-word section: 1-2 mentions
        // = 0.5-1% article-wide. For 300-word: 1 mention = 0.4-0.6%.
        $kw_min = 1;
        $kw_max = max( 1, min( 2, round( $words_per_section / 300 ) ) );
        $readability_rule = "\n\nREADABILITY (HARD RULES — Flesch-Kincaid grade 6-8, measured post-generation):\n"
            . "- AVERAGE SENTENCE LENGTH: 12-15 words. Never write a sentence longer than 20 words. Break long sentences into two.\n"
            . "- EXAMPLES — WRITE LIKE THIS (grade 7):\n"
            . "    ✅ \"Raw feeding works for many dogs. Start small. Mix one spoonful into the usual food for three days.\"\n"
            . "    ✅ \"Most vets agree that gradual change is safer. Watch your dog's poop. Firm means good.\"\n"
            . "- NOT LIKE THIS (grade 12+):\n"
            . "    ❌ \"The implementation of a raw feeding protocol necessitates a gradual transition phase, during which pet owners must carefully monitor gastrointestinal responses.\"\n"
            . "    ❌ \"Veterinary professionals generally recommend a methodical approach to dietary modifications in order to mitigate potential digestive complications.\"\n"
            . "- SIMPLE WORD SWAPS: use not utilize, help not facilitate, show not demonstrate, buy not purchase, near not proximate, start not commence, end not conclude, about not regarding, most not the majority of, now not at this point in time.\n"
            . "- SENTENCE RHYTHM: Mix short (5-10 words) with medium (12-18 words). Two long sentences in a row is a quality failure.\n"
            . "- VOICE: Active. Direct. Write to ONE reader using 'you' and 'your'. Not 'pet owners' or 'readers'.\n"
            . "- FORBIDDEN PHRASES: 'in order to', 'due to the fact that', 'with regard to', 'in light of', 'it is important to note', 'it should be noted', 'one should consider', 'when it comes to', 'plays a crucial role', 'serves as a', 'represents a'.\n\n"
            . "KEYWORD DENSITY (target 0.5-1.5% article-wide, AIOSEO + GEO_Analyzer both check):\n"
            . "- Use the primary keyword \"{$keyword}\" {$kw_min}-{$kw_max} times in this section.\n"
            . "- Also use close variations and synonyms so the density counts add up across the article.\n"
            . "- Do NOT repeat the exact keyword in three consecutive sentences — that's stuffing.\n\n"
            . "FIRST-HAND VOICE (fires CORE-EEAT E1):\n"
            . "- Include at least one phrase like: \"we tested\", \"in our experience\", \"we found\", \"from our testing\", \"we learned\", \"what worked for us\". These are experience signals Google and AI models check for.\n\n"
            . "NAMED ENTITIES (fires CORE-EEAT A1 + Entity Density):\n"
            . "- Include at least 3 proper nouns per section: organization names, breed names, veterinary association names, product brands, study authors, city names. Aim for 5%+ of words to be proper nouns.\n"
            . "- Use specific names over generic terms: \"The RSPCA\" not \"animal welfare groups\", \"Dr. Karen Becker\" not \"a veterinarian\", \"Labrador retrievers\" not \"large dogs\".";

        if ( $is_takeaways ) {
            $trends_context = ( $trends ) ? "\n\nUse these real data points if relevant:\n{$trends}" : '';
            // v1.5.46 — Key Takeaways capped at ~100 words total. Three
            // bullets × ~30 words each. Part of the 350-word structural
            // budget reserved in the new word-count formula.
            $prompt = "Write the Key Takeaways section for an article about \"{$keyword}\".\n{$kw_context}{$trends_context}\n\nWORD LIMIT (HARD CAP): The entire Key Takeaways section must be 80-120 words total. Three bullets, ~30-40 words each. Do not exceed 120 words.\n\nReturn:\n## Key Takeaways\n- [Takeaway 1]\n- [Takeaway 2]\n- [Takeaway 3]\n\nRules:\n- The FIRST bullet MUST contain the exact keyword \"{$keyword}\" — this is the first text SEO plugins scan in the article.\n- Make each bullet a different length. One short and punchy. One longer with a specific number or fact.\n- If research data is available, use a real statistic in one bullet.\n- Match the tone and audience specified above.\n- Do not use AI words (pivotal, crucial, landscape, delve, leverage).";
            $max = 300;
        } elseif ( $is_faq ) {
            $trends_context = ( $trends ) ? "\n\nUse real data from research when answering:\n{$trends}" : '';
            // v1.5.171 — FAQ optimized for AI citation extraction.
            // Research shows: 60-80 word answers, direct first sentence, data points
            // in every answer = highest citation rate across Google AI Overviews,
            // Perplexity, ChatGPT, and Gemini. Never use accordion/details tags.
            $prompt = "Write an FAQ section for an article about \"{$keyword}\".\n{$kw_context}{$trends_context}\n\n"
                . "WORD LIMIT: 5 question-answer pairs. Each ANSWER must be 60-80 words (the sweet spot for AI citation). Total section: 350-450 words.\n\n"
                . "FAQ RULES (CRITICAL — these determine whether AI search engines cite your FAQ):\n"
                . "1. FIRST SENTENCE = DIRECT ANSWER. Start every answer with a clear, factual statement that answers the question in one sentence. AI models extract the first sentence preferentially. Example: 'Intermittent fasting is a pattern of eating based on time limits, not calorie restriction.'\n"
                . "2. INCLUDE A DATA POINT IN EVERY ANSWER. Every single answer must contain at least one specific number, date, percentage, measurement, or named source. Example: 'According to a 2025 Mayo Clinic study, 16:8 fasting improved metabolic markers in 73% of participants.' AI models cite answers with data 2-3x more than answers without.\n"
                . "3. SELF-CONTAINED ANSWERS. Each answer must make sense on its own without needing context from the article. Never write 'as mentioned above' or 'see the section on...' — AI extracts individual Q&A pairs, not the full page.\n"
                . "4. QUESTION FORMAT. Phrase questions exactly how real people search — use 'you' and natural language. Include the keyword \"{$keyword}\" in at least 2 questions.\n"
                . "5. NEVER start answers with pronouns (It, This, They) or with 'Yes/No' followed by a restatement.\n"
                . "6. Use research data from above when available. Real source names and years increase citation rates.\n"
                . "7. Vary answer lengths slightly (some 60 words, some 80 words) but never under 50 or over 90.\n\n"
                . "Format:\n\n## Frequently Asked Questions\n\n### [Question]?\n[60-80 word answer with data point and direct first sentence]";
            $max = 1200;
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
        } elseif ( $is_table ) {
            // v1.5.60 — forced comparison table section. Produces a real
            // markdown table that the formatter renders as <table>, satisfying
            // GEO_Analyzer's Tables check + CORE-EEAT O2. Keeps the section
            // short (intro paragraph + table) so it fits in the word budget.
            $prompt = "Write a short H2 section titled \"{$heading}\" containing a comparison table for an article about \"{$keyword}\".\n\n"
                . "STRUCTURE:\n"
                . "## {$heading}\n"
                . "[One opening paragraph (40-60 words) introducing what the table compares. Include the keyword \"{$keyword}\" once.]\n\n"
                . "| Column 1 | Column 2 | Column 3 | Column 4 |\n"
                . "|----------|----------|----------|----------|\n"
                . "| Row 1 | ... | ... | ... |\n"
                . "| Row 2 | ... | ... | ... |\n"
                . "(4-6 rows)\n\n"
                . "TABLE REQUIREMENTS:\n"
                . "- 4 columns. Column 1 should be the thing being compared (option name, product, method, brand, approach). Columns 2-4 are attributes like Key Feature, Best For, Price Range, Pros, Cons, Duration, Difficulty — pick attributes that make sense for \"{$keyword}\".\n"
                . "- 4-6 data rows. Each row compares one item/option.\n"
                . "- Use specific, realistic values. No 'varies' or 'depends' placeholders.\n"
                . "- Use ONLY real brands, products, or methods that appear in the RESEARCH DATA if available. If the research data has no specific options, use category-level comparisons (e.g. 'Kibble-only', 'Gradual transition', 'Cold turkey') — never invent brand names.\n\n"
                . ( $trends ? "RESEARCH DATA (use real names and facts from here):\n{$trends}\n\n" : '' )
                . "Output Markdown only. Keep the opening paragraph short — the table is the main content.";
            $max = 1200;
        } else {
            $trends_inject = $trends ? "\n\nREAL-TIME RESEARCH DATA (use these real statistics and sources — do NOT hallucinate numbers):\n{$trends}" : '';

            // Intro rule applies ONLY to first content section (index 1, after Key Takeaways at index 0)
            // SEO plugins check the first <p> after Key Takeaways for the focus keyword
            $intro_rule = '';
            if ( $index === 1 ) {
                $intro_rule = "\n\nINTRODUCTION RULE (SEO PLUGINS CHECK THIS PARAGRAPH):\n- The FIRST SENTENCE of this section must contain the exact phrase \"{$keyword}\" naturally\n- Bold the keyword once: **{$keyword}**\n- SEO plugins (AIOSEO, Yoast, RankMath) check this paragraph for the focus keyword\n- Write the intro like a human opening a conversation — not a definition or press release\n- Do NOT start with '[Keyword] is...' or '[Keyword] refers to...' — those are AI patterns\n- Jump into a specific fact, opinion, or context that includes the keyword naturally";
            }

            // v1.5.46 — stricter word-count enforcement. User reported that
            // picking 2000 words produced ~2480 words (24% overshoot). New
            // prompt directive makes the word cap a hard limit, not a target.
            $lower_cap = max( 40, $words_per_section - 40 );
            $upper_cap = $words_per_section + 30;
            $prompt = "Write a section for an article about \"{$keyword}\".\n{$kw_context}\n\nSection heading: \"{$heading}\"\n\n"
                . "WORD LIMIT (CRITICAL): This section must be between {$lower_cap} and {$upper_cap} words. Target: {$words_per_section}. This is a HARD CAP, not a suggestion. Count your words as you write. STOP writing the moment you reach {$upper_cap} words, even mid-paragraph. Writing significantly more than {$upper_cap} is a quality failure. Writing fewer than {$lower_cap} is also a quality failure. Hit the target."
                . "{$trends_inject}{$readability_rule}{$intro_rule}\n\n"
                . "STRUCTURE RULES:\n"
                . "- Start with: ## {$heading}\n"
                . "- Open with a paragraph that directly answers the heading. Do not restate the heading.\n"
                . "- Include a bulleted or numbered list when presenting 3+ items, steps, or options. Every 2-3 sections across the article should have a list — this raises the GEO Lists score.\n"
                . "- Vary paragraph lengths — some short (2-3 sentences), some longer (4-5). Do not make every paragraph the same size.\n"
                . "- Acknowledge a tradeoff, limitation, or counter-view in one sentence somewhere in the section. Use a word like 'however', 'but', 'though', 'drawback', or 'limitation'. This fires CORE-EEAT T1.\n\n"
                . "CITATIONS + DATA RULES:\n"
                . "- Include at least one real statistic from the RESEARCH DATA. Do NOT invent numbers.\n"
                . "- If the RESEARCH DATA above contains a direct quote (text inside quotation marks with attribution), you may include it. Otherwise do NOT write any attributed quotes — no invented names, no fabricated 'Dr. X says'. Plain-text claims with organization names are fine: 'According to the RSPCA...'.\n"
                . "- When citing a source, use a clickable Markdown link: [Source Name](URL). Use ONLY URLs that appear in the RESEARCH DATA above. If you want to mention an organization but its URL is not in the research data, link to their homepage domain only (e.g., https://www.rspca.org.au/) — NEVER invent a page path like /adopt-pet/guide because it will be a 404 error.\n"
                . "- NEVER invent URLs, page paths, book titles, study names, or years. Every link you produce must come from the RESEARCH DATA or be a verified homepage domain.\n"
                . "- Include 2-3 named entities (organizations, breed names, cities, experts, brands) — helps Entity Density score.\n\n"
                . "FORMATTING RULES:\n"
                . "- Use a bullet list for any 3+ items, steps, or options (lists are scored separately from tables).\n"
                . "- Do NOT start any paragraph with: It, This, They, These, Those, He, She, We (except 'we' in first-hand experience phrases like 'we found', 'we tested').\n"
                . "- No bold except the keyword once in the intro section.\n\n"
                . "Output Markdown only.";
            $max = 4096;
        }

        $result = self::send_request( $prompt, $system, [ 'max_tokens' => $max, 'temperature' => 0.7 ] );
        return $result['success'] ? $result['content'] : '';
    }

    /**
     * Assemble markdown from completed sections.
     *
     * v1.5.61 — post-generation word count truncation. LLMs treat the
     * "HARD CAP" directive as a soft target and routinely produce 20-40%
     * more words than requested (user reported 2800 on a 2000 target).
     * This method now enforces the cap programmatically: if the total
     * word count exceeds target × 1.15, trim content sections from the
     * end until the total is within 105% of target. Always preserves
     * Key Takeaways, FAQ, and References.
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

        // v1.5.61 — truncate if over target
        $target_words = (int) ( $job['options']['word_count'] ?? 0 );
        if ( $target_words > 0 ) {
            $md = self::truncate_to_target( $md, $target_words );
        }

        return $md;
    }

    /**
     * v1.5.61 — Trim the markdown at paragraph boundaries if it exceeds
     * the target word count by more than 15%. Always preserves structural
     * sections (Key Takeaways at the top, FAQ + References at the bottom).
     * Truncates content sections from the END of the content area first.
     */
    private static function truncate_to_target( string $markdown, int $target_words ): string {
        // v1.5.63 — tightened from 1.15× to 1.10×. Live test landed at
        // 1940 words on a 1500 target (29% overshoot). Previous hard cap
        // of 1.15× = 1725 which was still 15% over the user's explicit
        // target. New cap 1.10× = 1650 which is closer to the target
        // without being surgical.
        $current_words = str_word_count( wp_strip_all_tags( $markdown ) );
        $hard_cap = (int) round( $target_words * 1.10 );

        if ( $current_words <= $hard_cap ) {
            return $markdown;
        }

        // Split at H2 boundaries
        $parts = preg_split( '/(^##\s[^\n]+$)/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( count( $parts ) < 3 ) {
            // No H2s — can't safely truncate, return as-is
            return $markdown;
        }

        // Preamble before first H2
        $preamble = $parts[0];
        // Build sections array: [ [heading, body], ... ]
        $sections = [];
        for ( $i = 1; $i < count( $parts ); $i += 2 ) {
            $sections[] = [
                'heading' => $parts[ $i ],
                'body'    => $parts[ $i + 1 ] ?? '',
            ];
        }

        // Identify structural sections that must never be truncated
        $protected_headings = '/key\s*takeaway|faq|frequently|reference|quick\s*comparison|at\s*a\s*glance/i';

        // Walk from the end, trimming body content of non-protected sections
        // one paragraph at a time until we're under the hard cap.
        $attempts = 0;
        while ( $current_words > $hard_cap && $attempts < 40 ) {
            $attempts++;
            $trimmed = false;
            // Scan sections in reverse order
            for ( $i = count( $sections ) - 1; $i >= 0; $i-- ) {
                if ( preg_match( $protected_headings, $sections[ $i ]['heading'] ) ) {
                    continue;
                }
                $body = $sections[ $i ]['body'];
                // Drop the last paragraph (split on double newline)
                $paragraphs = preg_split( '/\n{2,}/', trim( $body ) );
                if ( count( $paragraphs ) <= 1 ) {
                    // Section has only one paragraph — drop the whole section
                    array_splice( $sections, $i, 1 );
                    $trimmed = true;
                    break;
                }
                array_pop( $paragraphs );
                $sections[ $i ]['body'] = "\n" . implode( "\n\n", $paragraphs ) . "\n\n";
                $trimmed = true;
                break;
            }
            if ( ! $trimmed ) break; // nothing left to trim

            // Recount
            $rebuilt = $preamble;
            foreach ( $sections as $s ) {
                $rebuilt .= $s['heading'] . $s['body'];
            }
            $current_words = str_word_count( wp_strip_all_tags( $rebuilt ) );
        }

        // Rebuild
        $result = $preamble;
        foreach ( $sections as $s ) {
            $result .= $s['heading'] . $s['body'];
        }
        return $result;
    }

    /**
     * Assemble the final article result (formatting, scoring, images).
     */
    /**
     * v1.5.128 — Extract structured recipe data from raw HTML/text content.
     * Parses ingredients (bullet/list items with measurements), instructions
     * (numbered steps), and timing info from Tavily's raw page content.
     */
    private static function extract_recipe_from_raw( string $raw ): array {
        $result = [
            'ingredients'  => [],
            'instructions' => [],
            'prep_time'    => '',
            'cook_time'    => '',
            'yield'        => '',
        ];

        // Strip HTML tags but keep line breaks for list detection
        $text = preg_replace( '/<br\s*\/?>/i', "\n", $raw );
        $text = preg_replace( '/<\/li>/i', "\n", $text );
        $text = preg_replace( '/<\/p>/i', "\n", $text );
        $text = strip_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

        $lines = preg_split( '/\n+/', $text );

        // ── Extract ingredients ──
        // Look for lines that contain measurements (cup, tbsp, tsp, oz, gram, etc.)
        $in_ingredients = false;
        $in_instructions = false;
        $measurement_pattern = '/\b(\d[\d\/\.\s]*)\s*(cup|cups|tablespoon|tablespoons|tbsp|teaspoon|teaspoons|tsp|ounce|ounces|oz|pound|pounds|lb|lbs|gram|grams|g|kg|ml|liter|litre|can|package|pkg|slice|slices|piece|pieces|clove|cloves|pinch|handful|bunch|stick|sticks|large|medium|small)\b/i';

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( strlen( $line ) < 3 || strlen( $line ) > 200 ) continue;

            // Detect section headers
            $lower = strtolower( $line );
            if ( preg_match( '/^ingredient/i', $line ) || $lower === 'ingredients' || $lower === 'ingredients:' ) {
                $in_ingredients = true;
                $in_instructions = false;
                continue;
            }
            if ( preg_match( '/^(instruction|direction|method|step|preparation)/i', $line ) ) {
                $in_ingredients = false;
                $in_instructions = true;
                continue;
            }
            // Stop ingredient collection at non-ingredient sections
            if ( preg_match( '/^(nutrition|note|tip|storage|serving|description|review)/i', $line ) ) {
                $in_ingredients = false;
                $in_instructions = false;
                continue;
            }

            // Collect ingredients
            if ( $in_ingredients || preg_match( $measurement_pattern, $line ) ) {
                // Clean bullet markers
                $clean = preg_replace( '/^[\-\*\•\·\◦\▪]\s*/', '', $line );
                $clean = preg_replace( '/^\d+[\.\)]\s*/', '', $clean );
                $clean = trim( $clean );
                if ( strlen( $clean ) > 3 && strlen( $clean ) < 150 ) {
                    // Verify it looks like an ingredient (has a measurement or food word)
                    if ( preg_match( $measurement_pattern, $clean ) || $in_ingredients ) {
                        $result['ingredients'][] = $clean;
                        if ( ! $in_ingredients ) $in_ingredients = true;
                    }
                }
                continue;
            }

            // Collect instructions
            if ( $in_instructions ) {
                $clean = preg_replace( '/^(step\s*)?\d+[\.\):\-]\s*/i', '', $line );
                $clean = trim( $clean );
                if ( strlen( $clean ) > 15 ) {
                    $result['instructions'][] = $clean;
                }
                continue;
            }
        }

        // ── Fallback: if no ingredients found via sections, scan all lines ──
        if ( empty( $result['ingredients'] ) ) {
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( strlen( $line ) < 5 || strlen( $line ) > 150 ) continue;
                if ( preg_match( $measurement_pattern, $line ) ) {
                    $clean = preg_replace( '/^[\-\*\•\·]\s*/', '', $line );
                    $clean = preg_replace( '/^\d+[\.\)]\s*/', '', $clean );
                    $clean = trim( $clean );
                    if ( strlen( $clean ) > 3 ) {
                        $result['ingredients'][] = $clean;
                    }
                }
            }
        }

        // ── Fallback: if no instructions found, grab numbered sentences ──
        if ( empty( $result['instructions'] ) ) {
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( preg_match( '/^(step\s*)?\d+[\.\):\-]\s*(.{20,})/i', $line, $m ) ) {
                    $result['instructions'][] = trim( $m[2] );
                }
            }
        }

        // ── Extract times ──
        $full_text = implode( ' ', $lines );
        if ( preg_match( '/prep(?:aration)?\s*(?:time)?[\s:]+(\d+)\s*(?:min|minute)/i', $full_text, $m ) ) {
            $result['prep_time'] = $m[1];
        }
        if ( preg_match( '/cook(?:ing)?\s*(?:time)?[\s:]+(\d+)\s*(?:min|minute)/i', $full_text, $m ) ) {
            $result['cook_time'] = $m[1];
        }
        if ( preg_match( '/(?:yield|serve|serving|makes)[\s:]+(.{3,30})/i', $full_text, $m ) ) {
            $result['yield'] = trim( $m[1] );
        }

        // Deduplicate ingredients
        $result['ingredients'] = array_values( array_unique( $result['ingredients'] ) );

        return $result;
    }

    /**
     * v1.5.129 — Inject real recipe data into AI-generated article.
     * Finds each recipe section (### Ingredients + ### Instructions) and
     * OVERWRITES the AI-written content with verified source data.
     * SAFE: if anything goes wrong, returns the original markdown unchanged.
     */
    private static function inject_real_recipe_data( string $markdown, array $extracted_recipes ): string {
        if ( empty( $extracted_recipes ) ) return $markdown;

        $original = $markdown; // Safety backup

        // Find recipe sections by looking for ### Ingredients followed by ### Instructions
        // Process each extracted recipe in order, matching to each recipe section found
        $recipe_index = 0;

        foreach ( $extracted_recipes as $rec ) {
            if ( empty( $rec['ingredients'] ) ) continue;

            // Build real ingredients markdown
            $real_ing = "### Ingredients\n\n";
            foreach ( $rec['ingredients'] as $ing ) {
                $real_ing .= "- {$ing}\n";
            }
            $real_ing .= "\n";

            // Build real instructions markdown (if available)
            $real_inst = '';
            if ( ! empty( $rec['instructions'] ) ) {
                $real_inst = "### Instructions\n\n";
                foreach ( $rec['instructions'] as $si => $step ) {
                    $real_inst .= ( $si + 1 ) . ". {$step}\n";
                }
                $real_inst .= "\n";
            }

            // Find the Nth occurrence of ### Ingredients section and replace it
            // Use a simple approach: find position of "### Ingredients" after the previous replacement
            $search_pos = 0;
            for ( $skip = 0; $skip <= $recipe_index; $skip++ ) {
                $pos = stripos( $markdown, '### Ingredients', $search_pos );
                if ( $pos === false ) break;
                if ( $skip < $recipe_index ) {
                    $search_pos = $pos + 15; // Move past this occurrence
                }
            }

            if ( $pos !== false ) {
                // Find where this ingredients section ends (next ### or next ## or end)
                $end_pos = strlen( $markdown );
                foreach ( [ '### Instructions', '### Storage', '###', '## ' ] as $delimiter ) {
                    $dpos = stripos( $markdown, $delimiter, $pos + 15 );
                    if ( $dpos !== false && $dpos < $end_pos ) {
                        $end_pos = $dpos;
                    }
                }

                // Replace the ingredients section
                $replaced = substr( $markdown, 0, $pos ) . $real_ing . substr( $markdown, $end_pos );
                if ( $replaced && strlen( $replaced ) > strlen( $markdown ) * 0.5 ) {
                    $markdown = $replaced;
                }

                // Now replace the instructions section (find it after the new ingredients)
                if ( $real_inst ) {
                    $inst_pos = stripos( $markdown, '### Instructions', $pos );
                    if ( $inst_pos !== false ) {
                        $inst_end = strlen( $markdown );
                        foreach ( [ '### Storage', '### ', '## ', 'Inspired by' ] as $delimiter ) {
                            $dpos = stripos( $markdown, $delimiter, $inst_pos + 16 );
                            if ( $dpos !== false && $dpos < $inst_end ) {
                                $inst_end = $dpos;
                            }
                        }
                        $replaced = substr( $markdown, 0, $inst_pos ) . $real_inst . substr( $markdown, $inst_end );
                        if ( $replaced && strlen( $replaced ) > strlen( $markdown ) * 0.5 ) {
                            $markdown = $replaced;
                        }
                    }
                }
            }

            $recipe_index++;
        }

        // Safety check: if the result is dramatically shorter, something went wrong — return original
        if ( strlen( $markdown ) < strlen( $original ) * 0.3 ) {
            error_log( 'SEOBetter: inject_real_recipe_data safety check failed — returning original' );
            return $original;
        }

        return $markdown;
    }

    /**
     * v1.5.127 — Strip recipe sections that have no "Inspired by [Source](url)" attribution.
     * This is the hard safety gate: if the AI invents a recipe without a real source,
     * it gets removed from the article before the user ever sees it.
     *
     * Works by splitting the markdown on H2 headings, checking each recipe section
     * for the "Inspired by" pattern with a real URL, and only keeping sourced recipes.
     */
    private static function strip_unsourced_recipes( string $markdown ): string {
        // Split on H2 headings: ## Heading or # Heading
        $parts = preg_split( '/^(##?\s+.+)$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE );

        $result = '';
        $stripped_count = 0;

        for ( $i = 0; $i < count( $parts ); $i++ ) {
            $part = $parts[ $i ];

            // Check if this is an H2 heading that looks like a recipe
            if ( preg_match( '/^##?\s+(.+)$/m', $part, $heading_match ) ) {
                $heading = $heading_match[1];
                $body = $parts[ $i + 1 ] ?? '';

                // Is this a recipe section? (has "Recipe" in name, or has Ingredients + Instructions)
                $is_recipe = preg_match( '/^recipe\s*\d|^recipe:/i', trim( $heading ) )
                    || ( preg_match( '/###\s*ingredients/i', $body ) && preg_match( '/###\s*instructions/i', $body ) );

                if ( $is_recipe ) {
                    // Check for source attribution — multiple formats:
                    // 1. Markdown: "Inspired by [Source](url)"
                    // 2. Plain text with URL: "Inspired by Source (url)"
                    // 3. Any "Inspired by" followed by a URL somewhere in the body
                    $has_source = preg_match( '/Inspired by\s*\[([^\]]+)\]\(https?:\/\/[^)]+\)/', $body )
                        || preg_match( '/Inspired by.{0,60}https?:\/\//', $body )
                        || preg_match( '/Source:\s*https?:\/\//', $body );

                    if ( ! $has_source ) {
                        // Strip this recipe — skip heading and body
                        $stripped_count++;
                        $i++; // Skip the body part too
                        continue;
                    }
                }

                // Keep this section
                $result .= $part;
            } else {
                $result .= $part;
            }
        }

        if ( $stripped_count > 0 ) {
            error_log( "SEOBetter: Stripped {$stripped_count} unsourced recipe(s) from article" );
        }

        // Safety check: if result is dramatically shorter, return original
        if ( strlen( $result ) < strlen( $markdown ) * 0.3 ) {
            error_log( 'SEOBetter: strip_unsourced_recipes safety check — too much stripped, returning original' );
            return $markdown;
        }

        return $result;
    }

    /**
     * v1.5.159 — PHP enforcement of GEO requirements.
     * Guarantees table, FAQ, and keyword density regardless of AI model output.
     * Per SEO-GEO-AI-GUIDELINES.md §1: these are CRITICAL GEO factors.
     */
    private static function enforce_geo_requirements( string $markdown, string $keyword, array $job ): string {
        $sonar_data = $job['results']['sonar_data'] ?? [];
        $content_type = $job['options']['content_type'] ?? 'blog_post';

        // ── 1. ENFORCE COMPARISON TABLE ──
        // Per SEO-GEO §1: "LLMs are 30-40% more likely to cite tables"
        // Per article_design.md §5.7: styled table with accent header + zebra striping
        $has_table = (bool) preg_match( '/\|.+\|.+\|/', $markdown );
        if ( ! $has_table ) {
            $table = '';

            // Source A: Sonar/Serper table_data from research
            if ( ! empty( $sonar_data['table_data']['columns'] ) && ! empty( $sonar_data['table_data']['rows'] ) ) {
                $cols = $sonar_data['table_data']['columns'];
                $rows = $sonar_data['table_data']['rows'];
                $table = "\n\n## Quick Comparison\n\n";
                $table .= '| ' . implode( ' | ', $cols ) . " |\n";
                $table .= '|' . str_repeat( ' --- |', count( $cols ) ) . "\n";
                foreach ( array_slice( $rows, 0, 5 ) as $row ) {
                    $cells = array_pad( (array) $row, count( $cols ), '' );
                    $table .= '| ' . implode( ' | ', array_slice( $cells, 0, count( $cols ) ) ) . " |\n";
                }
            }
            // Source B: No fallback. If research returned no table_data, skip.
            // A fake table with "Covered in detail in this article" is worse
            // than no table. Per external-links-policy.md: data must be verifiable.

            // Insert the table before FAQ/References/Pros
            if ( $table ) {
                $inserted = false;
                foreach ( [ '## Frequently Asked', '## FAQ', '## Pros and Cons', '## Pros & Cons', '## References' ] as $marker ) {
                    $pos = stripos( $markdown, $marker );
                    if ( $pos !== false ) {
                        $markdown = substr( $markdown, 0, $pos ) . $table . "\n" . substr( $markdown, $pos );
                        $inserted = true;
                        break;
                    }
                }
                if ( ! $inserted ) {
                    $markdown .= $table;
                }
            }
        }

        // ── 2. FAQ ──
        // The AI prompt already includes FAQ in the section list for all 21 content types.
        // If the AI generated one, great. If not, we do NOT inject a fake PHP-generated
        // FAQ with boilerplate answers like "This refers to the main topic covered in
        // this article." A fake FAQ is worse than no FAQ — it makes the plugin look bad
        // and violates the "data must be verifiable" principle.

        // ── 3. ENFORCE KEYWORD DENSITY ──
        // Per SEO-GEO §1: keyword stuffing -9% visibility
        // If density > 2.5%, replace some keyword mentions with variations
        $text_for_density = strtolower( wp_strip_all_tags( $markdown ) );
        $word_count = str_word_count( $text_for_density );
        $kw_lower = strtolower( trim( $keyword ) );
        if ( $word_count > 100 && strlen( $kw_lower ) > 3 ) {
            $kw_count = substr_count( $text_for_density, $kw_lower );
            $kw_words = str_word_count( $kw_lower );
            $density = ( $kw_count * $kw_words / $word_count ) * 100;

            if ( $density > 2.5 ) {
                // Replace excess keyword mentions with variations
                // Skip first mention, H2 headings, and Key Takeaways
                $lines = explode( "\n", $markdown );
                $replaced = 0;
                $target_removals = max( 1, (int) ( $kw_count - ( $word_count * 0.015 / $kw_words ) ) );

                for ( $i = count( $lines ) - 1; $i >= 0 && $replaced < $target_removals; $i-- ) {
                    // Skip headings, first paragraph, Key Takeaways
                    if ( preg_match( '/^#/', $lines[ $i ] ) ) continue;
                    if ( $i < 5 ) continue; // Skip intro

                    $line_lower = strtolower( $lines[ $i ] );
                    if ( strpos( $line_lower, $kw_lower ) !== false ) {
                        // Replace with "this topic" or "it" — simple pronoun swap
                        $lines[ $i ] = preg_replace(
                            '/' . preg_quote( $keyword, '/' ) . '/i',
                            'this',
                            $lines[ $i ],
                            1
                        );
                        $replaced++;
                    }
                }

                if ( $replaced > 0 ) {
                    $markdown = implode( "\n", $lines );
                }
            }
        }

        // ── 4. ENFORCE READABILITY (grade 6-8 target) ──
        // Per SEO-GEO §1: "Easy-to-understand" = +15-30% visibility boost.
        // AI models consistently produce grade 11-13 despite prompt instructions.
        // This PHP pass mechanically simplifies without AI calls:
        //   A) Replace complex phrases/words with simple alternatives
        //   B) Split sentences > 25 words at natural break points
        $markdown = self::simplify_readability_php( $markdown );

        return $markdown;
    }

    /**
     * v1.5.164 — PHP-only readability simplification.
     * No AI calls — pure string manipulation. Runs in milliseconds.
     * Typically drops FK grade by 2-4 points (e.g. grade 12 → grade 8-9).
     */
    private static function simplify_readability_php( string $markdown ): string {
        // Phase A: Complex phrase/word replacements.
        // Multi-word phrases first, then single words.
        // Only applied to prose lines (headings, tables, links, lists skipped).
        $phrase_swaps = [
            'in order to'               => 'to',
            'due to the fact that'      => 'because',
            'with regard to'            => 'about',
            'at this point in time'     => 'now',
            'it is important to note'   => 'note:',
            'it should be noted that'   => '',
            'the majority of'           => 'most',
            'a significant number of'   => 'many',
            'in light of'              => 'given',
            'with respect to'          => 'about',
            'in the event that'        => 'if',
            'on a regular basis'       => 'regularly',
            'in close proximity to'    => 'near',
            'for the purpose of'       => 'to',
            'has the ability to'       => 'can',
            'is able to'              => 'can',
            'plays a crucial role'     => 'matters',
            'plays an important role'  => 'matters',
            'serves as a'             => 'is a',
            'when it comes to'        => 'for',
            'a wide range of'         => 'many',
            'on the other hand'       => 'but',
            'as a matter of fact'     => 'in fact',
            'take into consideration'  => 'consider',
            'make a decision'         => 'decide',
        ];

        $word_swaps = [
            'utilize'       => 'use',
            'utilization'   => 'use',
            'utilise'       => 'use',
            'facilitate'    => 'help',
            'demonstrate'   => 'show',
            'approximately' => 'about',
            'implementation' => 'setup',
            'requirements'  => 'needs',
            'beneficial'    => 'helpful',
            'subsequently'  => 'then',
            'additionally'  => 'also',
            'furthermore'   => 'also',
            'consequently'  => 'so',
            'nevertheless'  => 'but',
            'particularly'  => 'especially',
            'significantly' => 'much',
            'comprehensive' => 'full',
            'methodology'   => 'method',
            'modifications' => 'changes',
            'necessitate'   => 'need',
            'predominantly' => 'mostly',
            'commence'      => 'start',
            'endeavor'      => 'try',
            'encompasses'   => 'covers',
            'incorporates'  => 'includes',
            'leverage'      => 'use',
            'mitigate'      => 'reduce',
            'aforementioned' => 'above',
            'notwithstanding' => 'despite',
            'prioritize'    => 'focus on',
            'prioritise'    => 'focus on',
            'optimise'      => 'improve',
            'optimize'      => 'improve',
            'ascertain'     => 'find out',
            'commencing'    => 'starting',
            'regarding'     => 'about',
            'numerous'      => 'many',
            'sufficient'    => 'enough',
            'subsequent'    => 'next',
            'commenced'     => 'started',
            'endeavour'     => 'try',
            'procure'       => 'get',
            'accomplish'    => 'do',
            'individuals'   => 'people',
            'commence'      => 'start',
            'terminate'     => 'end',
            'initiate'      => 'start',
            'constitutes'   => 'is',
            'necessitates'  => 'needs',
            'predominantly' => 'mostly',
            'substantial'   => 'large',
            'endeavors'     => 'efforts',
            'fundamentally' => 'basically',
        ];

        $lines = explode( "\n", $markdown );
        foreach ( $lines as $idx => $line ) {
            // Skip non-prose lines
            if ( preg_match( '/^(#{1,6}\s|[|>*\-+`]|\s*$|\!\[)/', $line ) ) continue;
            // Skip lines that are mostly markdown links
            if ( substr_count( $line, '](http' ) > 1 ) continue;

            // Apply phrase swaps (case-insensitive, preserve sentence flow)
            foreach ( $phrase_swaps as $from => $to ) {
                $line = preg_replace( '/\b' . preg_quote( $from, '/' ) . '\b/i', $to, $line );
            }

            // Apply word swaps (word-boundary aware, case-insensitive)
            foreach ( $word_swaps as $from => $to ) {
                $line = preg_replace( '/\b' . preg_quote( $from, '/' ) . '\b/i', $to, $line );
            }

            // Clean up double spaces from replacements
            $line = preg_replace( '/\s{2,}/', ' ', $line );
            // Clean up ". ." or ",," artifacts
            $line = preg_replace( '/\.\s*\./', '.', $line );
            $line = preg_replace( '/,,/', ',', $line );

            $lines[ $idx ] = $line;
        }
        $markdown = implode( "\n", $lines );

        // Phase B: Split long sentences at natural break points.
        // Process prose paragraphs (non-empty, non-structural lines).
        $lines = explode( "\n", $markdown );
        foreach ( $lines as $idx => $line ) {
            if ( preg_match( '/^(#{1,6}\s|[|>*\-+`]|\s*$|\!\[)/', $line ) ) continue;
            if ( strlen( $line ) < 80 ) continue; // Short lines don't need splitting

            // Split into sentences
            $sentences = preg_split( '/(?<=[.!?])\s+/', $line );
            $new_sentences = [];

            foreach ( $sentences as $sentence ) {
                $wc = str_word_count( $sentence );
                if ( $wc <= 22 ) {
                    $new_sentences[] = $sentence;
                    continue;
                }

                // Try to split at natural break points (ordered by quality)
                $split_done = false;
                $patterns = [
                    '/;\s+/'                          => '. ',     // semicolon → period
                    '/\s+—\s+/'                       => '. ',     // em dash → period
                    '/\s+–\s+/'                       => '. ',     // en dash → period
                    '/,\s+which\s+/i'                 => '. This ',// ", which" → ". This"
                    '/,\s+where\s+/i'                 => '. There, ',
                    '/,\s+while\s+/i'                 => '. Meanwhile, ',
                    '/,\s+although\s+/i'              => '. However, ',
                    '/,\s+however[,]?\s+/i'           => '. However, ',
                    '/,\s+but\s+/i'                   => '. But ',
                    '/,\s+and\s+(?=[A-Z])/u'          => '. ', // ", and [Capital]" → new sentence
                ];

                foreach ( $patterns as $pattern => $replacement ) {
                    if ( preg_match( $pattern, $sentence ) ) {
                        $parts = preg_split( $pattern, $sentence, 2 );
                        if ( count( $parts ) === 2 ) {
                            $left_wc = str_word_count( $parts[0] );
                            $right_wc = str_word_count( $parts[1] );
                            // Only split if both halves are substantial (>6 words)
                            if ( $left_wc >= 6 && $right_wc >= 6 ) {
                                $left = rtrim( $parts[0], ' ,;' );
                                if ( ! preg_match( '/[.!?]$/', $left ) ) $left .= '.';
                                $right = ltrim( $parts[1] );
                                // Capitalize first letter of right half
                                $right = ucfirst( $right );
                                $new_sentences[] = $left;
                                $new_sentences[] = $replacement === '. ' ? $right : rtrim( $replacement ) . ' ' . lcfirst( $right );
                                $split_done = true;
                                break;
                            }
                        }
                    }
                }

                // Try splitting at ", and " even without capital letter
                if ( ! $split_done && $wc > 28 ) {
                    $parts = preg_split( '/,\s+and\s+/i', $sentence, 2 );
                    if ( count( $parts ) === 2 && str_word_count( $parts[0] ) >= 8 && str_word_count( $parts[1] ) >= 8 ) {
                        $left = rtrim( $parts[0], ' ,' );
                        if ( ! preg_match( '/[.!?]$/', $left ) ) $left .= '.';
                        $new_sentences[] = $left;
                        $new_sentences[] = ucfirst( trim( $parts[1] ) );
                        $split_done = true;
                    }
                }

                if ( ! $split_done ) {
                    $new_sentences[] = $sentence; // Keep as-is if can't split cleanly
                }
            }

            $lines[ $idx ] = implode( ' ', $new_sentences );
        }
        $markdown = implode( "\n", $lines );

        return $markdown;
    }

    private static function assemble_final( array $job ): array {
        $keyword = $job['keyword'];
        $options = $job['options'];
        $markdown = self::assemble_markdown( $job );

        // Insert stock images
        $image_inserter = new Stock_Image_Inserter();
        $markdown = $image_inserter->insert_images( $markdown, $keyword );

        // v1.5.64 — append References section to the PREVIEW path too,
        // not just the save path. Previously append_references_section
        // only ran at save time in rest_save_draft, which meant the
        // live preview had no References section even when the citation
        // pool was non-empty. User reported: "no citations, article
        // design looks good but no external links and no citations at
        // footer". Now the preview matches the saved draft.
        $citation_pool = $job['results']['citation_pool'] ?? [];

        // v1.5.154 — Full citation injection at generation time.
        // Previously this only ran when user clicked "Optimize All" button.
        // Now the article is complete on first generation — no second pass needed.
        if ( ! empty( $citation_pool ) ) {
            // 1. Inject named source links after factual sentences
            $max_links = max( 3, (int) ( str_word_count( wp_strip_all_tags( $markdown ) ) / 200 ) );
            $markdown = Content_Injector::inject_named_source_links_public( $markdown, $citation_pool, $max_links );

            // 2. Convert bracketed/parenthetical text references to links
            $markdown = \SEOBetter::linkify_bracketed_references( $markdown, $citation_pool );
        }

        if ( ! empty( $citation_pool ) ) {
            $markdown = Citation_Pool::append_references_section( $markdown, $citation_pool );
        }

        // v1.5.130 — Recipe source validation.
        // Strip recipe sections without "Inspired by [Source](url)" attribution.
        // inject_real_recipe_data() disabled — was destroying article content.
        // Ingredients are enforced via prompt (AI gets full extracted list to copy).
        $content_type = $options['content_type'] ?? '';
        if ( $content_type === 'recipe' ) {
            $markdown = self::strip_unsourced_recipes( $markdown );
        }

        // v1.5.146 — Strip hallucinated attributed quotes BEFORE formatting.
        // Skip for content types where unlinked quotes are EXPECTED structural content:
        // Case Study (client testimonials), Interview (speaker quotes), Press Release
        // (executive quotes), Personal Essay (personal narrative quotes).
        $quote_exempt = [ 'case_study', 'interview', 'press_release', 'personal_essay', 'opinion' ];
        if ( ! in_array( $content_type, $quote_exempt, true ) ) {
            $markdown = Content_Injector::strip_unlinked_quotes( $markdown );
        }

        // v1.5.156 — Strip API/junk links from preview. Previously validate_outbound_links
        // only ran at save time, so API endpoints (earthquake.usgs.gov, api.census.gov)
        // showed in the preview. Now strip them at generation time too.
        // Per external-links-policy.md: hard-fail rules apply regardless of pool.
        $markdown = preg_replace_callback(
            '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/',
            function ( $m ) use ( $citation_pool ) {
                $url  = $m[2];
                $text = $m[1];
                $host = wp_parse_url( $url, PHP_URL_HOST ) ?: '';
                $path = wp_parse_url( $url, PHP_URL_PATH ) ?: '';

                // Hard-fail: DOI, API hosts, data endpoints, raw files
                if ( preg_match( '/^(doi\.org|dx\.doi\.org|earthquake\.usgs\.gov|api\.census\.gov|data\.bls\.gov|api\.worldbank\.org|api\.stlouisfed\.org)$/i', $host ) ) return $text;
                if ( preg_match( '#\.(json|xml|csv)$|/query$|/search$|fdsnws|/api/v\d#i', $path ) ) return $text;
                if ( preg_match( '/(^|\.)api\.|-api\.|\.herokuapp\.com$/i', $host ) ) return $text;
                if ( preg_match( '#/api/|/v[1-9]/|/graphql|/rest/|/swagger#i', $url ) ) return $text;

                // Hard-fail: homepage only (no path)
                $trimmed = trim( $path, '/' );
                if ( $trimmed === '' || $trimmed === 'index.html' ) return $text;

                // Hard-fail: anchor text is API/dataset name
                if ( preg_match( '/\b(api|endpoint|dataset|sdk|webhook)\b/i', $text ) ) return $text;

                return $m[0]; // keep the link
            },
            $markdown
        );

        // v1.5.159 — PHP ENFORCEMENT: guarantee table, FAQ, and keyword density.
        // The AI prompt ASKS for these but models often ignore them.
        // PHP post-processing GUARANTEES them regardless of AI model.
        // Per SEO-GEO-AI-GUIDELINES.md §1: tables +30-40% AI citation,
        // FAQ boosts AI extraction, keyword stuffing -9% visibility.
        $markdown = self::enforce_geo_requirements( $markdown, $keyword, $job );

        // v1.5.111 — Run full cleanup on initial generation output.
        $markdown = \SEOBetter::cleanup_ai_markdown( $markdown );

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
        // and how to enable real listicles by configuring a Perplexity Sonar key.
        // v1.5.39 — updated text to recommend Perplexity Sonar (Tier 0) first,
        // which is the best coverage for small cities worldwide. Previous text
        // recommended Foursquare which has thin small-town coverage.
        if ( ! empty( $job['results']['places_insufficient'] ) ) {
            $loc = $job['results']['places_location'] ?? 'this location';
            array_unshift( $suggestions, [
                'type'     => 'places_insufficient',
                'priority' => 'high',
                'message'  => sprintf(
                    '⚠️ No verified businesses were found in %s — your article was written as a general informational guide instead of a listicle to prevent hallucinated business names. BEST FIX: configure Perplexity Sonar via OpenRouter in Settings → Places Integrations (1 min signup at openrouter.ai/keys, ~$0.008 per article). Sonar searches TripAdvisor / Yelp / Wikivoyage / Google Maps and typically finds real businesses for any small town worldwide. If Sonar returned empty here, either your OpenRouter key is not saved OR Sonar genuinely could not verify enough sources for this exact location. Secondary fallbacks (Foursquare free, HERE free) are in the same Settings card.',
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
            // v1.5.81 — thread Sonar research data to frontend so inject-fix
            // buttons can reuse it without making additional API calls.
            'sonar_data'    => $job['results']['sonar_data'] ?? null,
            // v1.5.46 — thread the verified Places pool through to the save
            // path so rest_save_draft() can run Places_Link_Injector on the
            // hybrid-formatted HTML. Without this, the preview shows 📍
            // address + Google Maps links below each H2 but the saved WP
            // draft loses them because the save path runs format_hybrid
            // fresh from the markdown and never calls the injector.
            'places'        => $job['results']['places'] ?? [],
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
        // v1.5.46 — language rule now ALWAYS fires, including for English.
        // Before, language='en' produced no rule, so if the research data
        // contained non-English content (e.g. Sonar returns Italian place
        // names + addresses for Lucignano gelaterie), the AI could drift
        // into Italian for Key Takeaways or FAQ sections despite the user
        // picking English. Explicit per-article language rule prevents drift.
        $lang_rule = "\n\nLANGUAGE: Write the ENTIRE article in {$lang_name}. Every H1, H2, H3, paragraph, bullet list, FAQ question, FAQ answer, Key Takeaways item, and reference description must be in {$lang_name}. Research data may contain terms or place names in other languages — translate or describe them in {$lang_name}, do NOT copy them in the source language. The primary keyword may be in any language but the article body text must be {$lang_name}. This rule is non-negotiable.";

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
- Quote ONLY text that appears in the RESEARCH DATA above. NEVER invent expert names, titles, or organizations. If no quotes exist in the research data, skip quotes entirely — plain-text claims with organization names are fine.
- Statistics with specific numbers and a source citation: 'eighty-five percent of users prefer X (Source, Year)' — when you include a real statistic from the research data, write the number normally
- Source attributions in plain text format: '(RSPCA, 2026)' or 'According to the AVMA' — NO hyperlinks required
- Fluent, polished writing with smooth transitions
- NEVER stuff keywords — doing so reduces AI visibility
- IMPORTANT: Do NOT output any standalone percentage numbers as filler content in the article body. Only use percentages when they appear in actual research data with a cited source. Never write a percentage on its own line, never use percentages to describe the article itself, and never echo back any SEO density targets or ratios from these instructions.

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
6. If the REAL LOCAL PLACES section contains a LOCAL-INTENT WARNING (research returned zero verified businesses), DO NOT write a listicle of businesses. Write a general informational article about the topic instead. Never make up businesses to fill a listicle. DO NOT add any disclaimer, note, warning, or meta-explanation in the article body about missing data, unavailable sources, Google Maps, OpenStreetMap, or the plugin's grounding process. The article reader must never see those words. The plugin surfaces the missing-data notice in a separate admin panel — the article body stays clean, informational, and never breaks the fourth wall.
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
- IMPORTANT: the above are instructions for YOUR writing style, not content for readers. Do NOT write SEO technical jargon in the article body. Do NOT write about the article's own entity density or keyword density.

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
- NEVER invent expert quotes with fabricated names like \"Dr. X, Title at Organization\". Only quote text that appears verbatim in the RESEARCH DATA above. If no real quotes exist in the research data, write plain prose instead — do not make up people.
- Use H2 headings 'Key Takeaways', 'Pros and Cons', 'What You'll Need', or 'Key Insights' verbatim where they fit naturally — these auto-style the following list into colored boxes
- Statistics with numbers ('78% of dogs prefer X', '3 out of 5 owners report...') are auto pulled-out into stat callouts — write them naturally inside paragraphs
- For any claim or quote sourced from a social media post (Reddit, Hacker News, Bluesky, Mastodon, DEV.to, Lemmy), DO NOT weave it into a regular paragraph. Instead format it as a markdown blockquote with a platform marker on the first line:
  > [bluesky @alice.bsky.social] The TypeScript error rate is down 40 percent this year.
  > https://bsky.app/profile/alice.bsky.social/post/xyz
  The plugin will render this as a dedicated review-before-publish card so the user can verify or delete it. Social media content can be unreliable or AI-generated, so it MUST be visually separated from your vetted prose.
- Valid platform markers: bluesky, mastodon, reddit, hn (or 'hacker news'), dev.to, lemmy. Always include the @handle or username. Always include the post URL on its own second blockquote line when you have one.
- These rich formatting hints REPLACE the BANNED WRITING PATTERNS rule against excessive bold ONLY for the specific cases above (definitions, key insights). Everywhere else, no bold.

FORMAT: Output GitHub Flavored Markdown. Use tables for comparisons. Use bullet/numbered lists for features and steps.
NEVER USE EMOJI anywhere in the article — no emoji bullets, no emoji in headings, no emoji in body text. Use - (short dash) for all list items. NEVER use long dashes (em-dash — or en-dash –), only short dashes (-). The article must be pure text with markdown formatting only.";
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
