<?php

namespace SEOBetter;

/**
 * Content Freshness Manager.
 *
 * Tracks content age and flags stale pages for refresh.
 * Based on research: content freshness is a critical tiebreaker for AI citations.
 * Strategy: 1 updated post per 5 new posts. Only update content 1+ year old.
 */
class Content_Freshness_Manager {

    private const STALE_DAYS     = 365; // Flag content older than 1 year
    private const WARNING_DAYS   = 180; // Warn for content older than 6 months
    private const REFRESH_RATIO  = 5;   // 1 refresh per 5 new posts

    /**
     * Get all content with freshness status.
     */
    public function get_freshness_report(): array {
        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'modified',
            'order'          => 'ASC',
        ] );

        $stale = [];
        $warning = [];
        $fresh = [];
        $now = time();

        foreach ( $posts as $post ) {
            $modified = strtotime( $post->post_modified );
            $days_since = round( ( $now - $modified ) / DAY_IN_SECONDS );

            $item = [
                'id'            => $post->ID,
                'title'         => $post->post_title,
                'url'           => get_permalink( $post->ID ),
                'last_modified' => $post->post_modified,
                'days_since'    => $days_since,
                'word_count'    => str_word_count( wp_strip_all_tags( $post->post_content ) ),
                'has_freshness_signal' => $this->has_freshness_signal( $post->post_content ),
            ];

            if ( $days_since >= self::STALE_DAYS ) {
                $item['status'] = 'stale';
                $stale[] = $item;
            } elseif ( $days_since >= self::WARNING_DAYS ) {
                $item['status'] = 'warning';
                $warning[] = $item;
            } else {
                $item['status'] = 'fresh';
                $fresh[] = $item;
            }
        }

        return [
            'stale'           => $stale,
            'warning'         => $warning,
            'fresh'           => $fresh,
            'stale_count'     => count( $stale ),
            'warning_count'   => count( $warning ),
            'fresh_count'     => count( $fresh ),
            'total'           => count( $posts ),
            'refresh_needed'  => count( $stale ),
            'next_refresh_candidates' => array_slice( $stale, 0, 5 ),
        ];
    }

    /**
     * Get refresh suggestions for a specific post.
     */
    public function get_refresh_suggestions( \WP_Post $post ): array {
        $suggestions = [];
        $content = $post->post_content;
        $text = wp_strip_all_tags( $content );

        // Check for outdated year references
        $current_year = (int) date( 'Y' );
        preg_match_all( '/\b(20[12]\d)\b/', $text, $year_matches );
        $old_years = array_filter( $year_matches[1], fn( $y ) => (int) $y < $current_year - 1 );
        if ( ! empty( $old_years ) ) {
            $suggestions[] = [
                'type'     => 'dates',
                'priority' => 'high',
                'message'  => 'Update outdated year references: ' . implode( ', ', array_unique( $old_years ) ),
            ];
        }

        // Check for freshness signal
        if ( ! $this->has_freshness_signal( $content ) ) {
            $suggestions[] = [
                'type'     => 'freshness',
                'priority' => 'high',
                'message'  => 'Add "Last Updated: ' . wp_date( 'F Y' ) . '" at the top. Freshness is a critical tiebreaker for AI citations.',
            ];
        }

        // Check word count vs benchmarks
        $word_count = str_word_count( $text );
        if ( $word_count < 1500 ) {
            $suggestions[] = [
                'type'     => 'length',
                'priority' => 'medium',
                'message'  => "Content is {$word_count} words. Consider expanding to 1,500-2,500 words to match current competitor benchmarks.",
            ];
        }

        // Check for statistics freshness
        preg_match_all( '/\(.*?(20[12]\d).*?\)/', $text, $stat_years );
        $old_stats = array_filter( $stat_years[1] ?? [], fn( $y ) => (int) $y < $current_year - 2 );
        if ( ! empty( $old_stats ) ) {
            $suggestions[] = [
                'type'     => 'statistics',
                'priority' => 'high',
                'message'  => 'Update statistics with recent data. Found references from: ' . implode( ', ', array_unique( $old_stats ) ),
            ];
        }

        // Check for broken/outdated external links
        preg_match_all( '/<a[^>]+href=["\']https?:\/\/[^"\']+["\']/i', $content, $link_matches );
        if ( count( $link_matches[0] ) > 0 ) {
            $suggestions[] = [
                'type'     => 'links',
                'priority' => 'medium',
                'message'  => 'Verify all ' . count( $link_matches[0] ) . ' external links still work. Broken links hurt authority signals.',
            ];
        }

        // Suggest adding new GEO elements
        preg_match_all( '/"[^"]{20,}"/', $text, $quotes );
        if ( count( $quotes[0] ) < 2 ) {
            $suggestions[] = [
                'type'     => 'geo',
                'priority' => 'medium',
                'message'  => 'Add fresh expert quotes from current sources. Quotation Addition boosts GEO visibility by 41%.',
            ];
        }

        // Suggest title update
        $suggestions[] = [
            'type'     => 'title',
            'priority' => 'low',
            'message'  => 'Consider adding "Updated" or current year to the title for higher CTR from SERPs.',
        ];

        return $suggestions;
    }

    /**
     * Check if content has a freshness signal.
     *
     * v1.5.216.56 — multilingual. Was English-only; falsely flagged every
     * non-English post as missing a Last Updated line even when the article
     * had a localized one (e.g. Japanese `最終更新日`, Korean `최종 수정일`,
     * Arabic `آخر تحديث`). Now matches the English regex OR any of the 30+
     * localized labels from Localized_Strings::get_translations()['last_updated'].
     */
    private function has_freshness_signal( string $content ): bool {
        // English markers — keep all five so "updated on" and "published on"
        // still trigger even in non-English articles that mix English chrome.
        if ( preg_match( '/last\s*updated|date\s*modified|updated\s*on|reviewed\s*on|published\s*on/iu', $content ) ) {
            return true;
        }
        // Localized labels from Localized_Strings — case-insensitive,
        // Unicode-aware. Cached per-request.
        static $localized_pattern = null;
        if ( $localized_pattern === null ) {
            $strings = Localized_Strings::get_translations_for( 'last_updated' );
            $parts = [];
            foreach ( $strings as $lang => $label ) {
                if ( $lang === 'en' || $label === '' ) continue;
                $parts[] = preg_quote( $label, '/' );
            }
            $localized_pattern = $parts ? '/' . implode( '|', $parts ) . '/iu' : '';
        }
        if ( $localized_pattern !== '' && preg_match( $localized_pattern, $content ) ) {
            return true;
        }
        return false;
    }

    // ── v1.5.216.23 — Phase 1 item 4: sortable inventory + priority scoring ──

    /**
     * Build the sortable inventory of all published posts with refresh-priority
     * composite score. Replaces the 3-section (stale/warning/fresh) report
     * from `get_freshness_report()` with a single rich table that the new
     * Freshness admin page renders.
     *
     * Pro+ tier: priority weighted by GSC click decay + position drift.
     * Free tier: priority weighted by age + outdated-year flags + missing
     * "Last Updated" signal. License gate is `gsc_freshness_driver`.
     *
     * @param int $limit  Max posts to return (default 200; UI can paginate later).
     * @return array of post-rows ready for the freshness.php table.
     */
    public function get_inventory( int $limit = 200 ): array {
        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'modified',
            'order'          => 'ASC',
        ] );

        $now              = time();
        $current_year     = (int) date( 'Y' );
        $can_use_gsc      = License_Manager::can_use( 'gsc_freshness_driver' );
        $gsc_connected    = GSC_Manager::is_connected();
        $gsc_active       = $can_use_gsc && $gsc_connected;
        $rows             = [];

        foreach ( $posts as $post ) {
            $modified_ts   = strtotime( $post->post_modified );
            $age_days      = max( 0, (int) round( ( $now - $modified_ts ) / DAY_IN_SECONDS ) );
            $text          = wp_strip_all_tags( $post->post_content );
            // v1.5.216.57 — language-aware word count (was str_word_count, which
            // returns ~0 for Japanese / Chinese / Korean / Thai because they
            // have no inter-word spaces).
            $post_lang     = (string) get_post_meta( $post->ID, '_seobetter_language', true ) ?: 'en';
            $word_count    = GEO_Analyzer::count_words_lang( $text, $post_lang );
            $has_signal    = $this->has_freshness_signal( $post->post_content );

            // Outdated year mentions in the body — flag any year < (current - 1)
            preg_match_all( '/\b(20[12]\d)\b/', $text, $year_matches );
            $outdated_years = 0;
            foreach ( ( $year_matches[1] ?? [] ) as $y ) {
                if ( (int) $y < $current_year - 1 ) $outdated_years++;
            }

            // GSC stats (Pro+ only — Free sees lock badge in UI, no data leak)
            $gsc_stats = [];
            if ( $gsc_active ) {
                $gsc_stats = GSC_Manager::get_post_stats( $post->ID );
            }

            // Composite priority 0-100. With GSC: 50% age/flags + 50% GSC decay
            // signals. Without GSC: 100% age/flags. Per pro-features-ideas.md
            // Phase 1 item 4 strategic deep-dive scoring formula.
            $base_priority = self::compute_base_priority( $age_days, $outdated_years, $has_signal );
            if ( $gsc_active && ! empty( $gsc_stats ) ) {
                $gsc_priority = self::compute_gsc_priority( $gsc_stats );
                $priority     = (int) round( ( $base_priority * 0.5 ) + ( $gsc_priority * 0.5 ) );
            } else {
                $priority = $base_priority;
            }
            $priority = max( 0, min( 100, $priority ) );

            $rows[] = [
                'id'             => $post->ID,
                'title'          => $post->post_title,
                'url'            => get_permalink( $post->ID ),
                'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
                'modified'       => $post->post_modified,
                'age_days'       => $age_days,
                'word_count'     => $word_count,
                'outdated_years' => $outdated_years,
                'has_signal'     => $has_signal,
                'gsc'            => $gsc_stats,
                'priority'       => $priority,
            ];
        }

        // Sort by priority DESC by default; UI JS can re-sort by any column
        usort( $rows, fn( $a, $b ) => $b['priority'] - $a['priority'] );

        return [
            'rows'           => $rows,
            'total'          => count( $rows ),
            'gsc_connected'  => $gsc_connected,
            'gsc_active'     => $gsc_active,           // can_use AND connected
            'can_use_gsc'    => $can_use_gsc,          // tier check (Pro+)
            'oauth_configured' => GSC_Manager::is_oauth_configured(),
        ];
    }

    /**
     * Base priority (no GSC) — age + flags + missing signal.
     *
     * Formula per the strategic deep-dive in pro-features-ideas.md:
     *   priority = age_days/3
     *            + outdated_year_count * 15
     *            + (missing_freshness_signal ? 10 : 0)
     *            + (age_days > 365 ? 20 : 0)
     */
    private static function compute_base_priority( int $age_days, int $outdated_years, bool $has_signal ): int {
        $score  = (int) round( $age_days / 3 );
        $score += $outdated_years * 15;
        if ( ! $has_signal )       $score += 10;
        if ( $age_days > 365 )      $score += 20;
        return max( 0, min( 100, $score ) );
    }

    /**
     * GSC-driven priority component (Pro+ only). Higher score = MORE in need
     * of refresh based on traffic-decay signals.
     *
     * Heuristic for the v1 MVP (28-day snapshot only — no historical delta yet):
     *   - Position 11-30 (striking distance, just off page 1) → high refresh
     *     priority (a small content lift might push to top 10)
     *   - Position 1-10 (already ranking) → low priority (don't break what works)
     *   - Position 30+ (deep) → moderate priority (refresh probably won't fix this alone)
     *   - Low impressions / 0 clicks → high priority (re-target the topic)
     *
     * v2 will compare 28d vs prior-28d snapshots once we have historical data;
     * for now we only have the latest snapshot.
     */
    private static function compute_gsc_priority( array $stats ): int {
        $position    = (float) ( $stats['position_28d'] ?? 0 );
        $clicks      = (int) ( $stats['clicks_28d'] ?? 0 );
        $impressions = (int) ( $stats['impressions_28d'] ?? 0 );

        $score = 0;

        // Striking distance — biggest opportunity bucket
        if ( $position >= 11 && $position <= 30 ) {
            $score += 50;
        } elseif ( $position > 30 ) {
            $score += 30;
        } elseif ( $position >= 1 && $position <= 10 ) {
            $score += 5; // ranking already; light refresh suggestion only
        }

        // Impressions but no clicks → snippet/title might be the issue
        if ( $impressions >= 100 && $clicks === 0 ) {
            $score += 25;
        }

        // No GSC presence at all → either too new or invisible
        if ( $impressions < 10 ) {
            $score += 15;
        }

        return max( 0, min( 100, $score ) );
    }

    // ── v1.5.216.54 — Freshness Diagnostic (Why? drawer + editor panel) ──

    /**
     * Per-post diagnostic that explains the priority score signal-by-signal.
     * Powers the "Why?" drawer on the Freshness page and the Freshness tab in
     * the post-edit metabox + Gutenberg sidebar mirror.
     *
     * Tier behaviour:
     *   - Free: returns ['locked' => true, 'tier_required' => 'pro'] — UI shows
     *     a single upsell card.
     *   - Pro: age + outdated_years + missing_signal sections.
     *   - Pro+: same + GSC click decay, position drift, top queries (28d),
     *     striking-distance flag.
     *
     * Non-destructive — every action this returns is informational or
     * clipboard-style. Mutating actions are explicitly out of scope per
     * pro-features-ideas.md §477 "Don't Build" line 489.
     *
     * @return array shape:
     *   [
     *     'locked'         => bool,             // true → show upsell only
     *     'tier_required'  => 'pro'|'pro_plus', // when locked
     *     'post_id'        => int,
     *     'priority'       => int 0-100,
     *     'has_gsc'        => bool,
     *     'signals'        => [ {...}, ... ],   // ordered most→least urgent
     *     'top_queries'    => [ {...}, ... ],   // Pro+ only, may be empty
     *     'last_updated_string' => 'May 2026',  // pre-formatted clipboard payload
     *   ]
     */
    public function diagnostic_for_post( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return [ 'locked' => false, 'error' => 'post_not_found_or_unpublished', 'post_id' => $post_id ];
        }

        // Base tier gate — Pro at minimum
        if ( ! License_Manager::can_use( 'freshness_diagnostic' ) ) {
            return [
                'locked'        => true,
                'tier_required' => 'pro',
                'post_id'       => $post_id,
            ];
        }

        $now           = time();
        $current_year  = (int) date( 'Y' );
        $modified_ts   = strtotime( $post->post_modified );
        $age_days      = max( 0, (int) round( ( $now - $modified_ts ) / DAY_IN_SECONDS ) );
        $text          = wp_strip_all_tags( $post->post_content );
        // v1.5.216.57 — language-aware word count for the diagnostic header.
        $post_lang     = (string) get_post_meta( $post_id, '_seobetter_language', true ) ?: 'en';
        $word_count    = GEO_Analyzer::count_words_lang( $text, $post_lang );
        $has_signal    = $this->has_freshness_signal( $post->post_content );

        // Year mention scan + per-occurrence snippets (~80 chars context window)
        preg_match_all( '/(.{0,40})\b(20[12]\d)\b(.{0,40})/u', $text, $year_ctx, PREG_SET_ORDER );
        $year_counts   = [];
        $year_snippets = [];
        foreach ( $year_ctx as $m ) {
            $y = $m[2];
            if ( (int) $y < $current_year - 1 ) {
                $year_counts[ $y ] = ( $year_counts[ $y ] ?? 0 ) + 1;
                if ( count( $year_snippets ) < 3 ) {
                    $before = trim( preg_replace( '/\s+/', ' ', $m[1] ) );
                    $after  = trim( preg_replace( '/\s+/', ' ', $m[3] ) );
                    $year_snippets[] = ( $before !== '' ? '…' . $before . ' ' : '' ) . $y . ( $after !== '' ? ' ' . $after . '…' : '' );
                }
            }
        }
        $outdated_years_total = array_sum( $year_counts );

        // GSC signals — Pro+ only, requires connection + active sync
        $can_use_gsc = License_Manager::can_use( 'gsc_freshness_driver' );
        $gsc_active  = $can_use_gsc && GSC_Manager::is_connected();
        $gsc_stats   = $gsc_active ? GSC_Manager::get_post_stats( $post_id ) : [];

        $base_priority = self::compute_base_priority( $age_days, $outdated_years_total, $has_signal );
        if ( $gsc_active && ! empty( $gsc_stats ) ) {
            $gsc_priority = self::compute_gsc_priority( $gsc_stats );
            $priority     = (int) round( ( $base_priority * 0.5 ) + ( $gsc_priority * 0.5 ) );
        } else {
            $priority = $base_priority;
        }
        $priority = max( 0, min( 100, $priority ) );

        $signals = [];

        // ── Signal 1: age ──
        if ( $age_days >= 365 ) {
            $signals[] = [
                'id'           => 'age',
                'severity'     => 'critical',
                'label'        => sprintf( '%dy old (last modified %s)', max( 1, (int) round( $age_days / 365 ) ), wp_date( 'M j, Y', $modified_ts ) ),
                'detail'       => __( 'Posts older than 1 year drift in rankings as the topic evolves and competitors publish fresher takes.', 'seobetter' ),
                'contributes'  => 20 + (int) round( $age_days / 3 ),
                'checklist'    => [
                    __( 'Add the current year to the post title', 'seobetter' ),
                    __( 'Replace any statistics older than 12 months with 2025-2026 data', 'seobetter' ),
                    __( 'Verify any companies / products / regulations mentioned still exist or have been rebranded', 'seobetter' ),
                    __( 'Add a new "Recent updates" or "What changed in 2026" section near the top', 'seobetter' ),
                ],
                'action'       => null,
            ];
        } elseif ( $age_days >= 180 ) {
            $signals[] = [
                'id'           => 'age',
                'severity'     => 'warning',
                'label'        => sprintf( '%dmo old (last modified %s)', (int) round( $age_days / 30 ), wp_date( 'M j, Y', $modified_ts ) ),
                'detail'       => __( 'Aging content — review whether facts, stats, or recommendations are still current.', 'seobetter' ),
                'contributes'  => (int) round( $age_days / 3 ),
                'checklist'    => [
                    __( 'Skim the post and flag anything that says "this year" or names a recent event — verify it still makes sense', 'seobetter' ),
                    __( 'Update the "Last Updated" line if you make changes', 'seobetter' ),
                ],
                'action'       => null,
            ];
        }

        // ── Signal 2: outdated year mentions ──
        if ( $outdated_years_total > 0 ) {
            $year_summary = [];
            foreach ( $year_counts as $y => $count ) {
                $year_summary[] = sprintf( '%s (%d×)', $y, $count );
            }
            $signals[] = [
                'id'           => 'outdated_years',
                'severity'     => $outdated_years_total >= 4 ? 'critical' : 'warning',
                'label'        => sprintf(
                    /* translators: 1: comma list of "YYYY (N×)" */
                    __( 'Outdated year mentions: %s', 'seobetter' ),
                    implode( ', ', $year_summary )
                ),
                'detail'       => __( 'Old year references signal stale content to readers and search engines. The exact instances are listed below.', 'seobetter' ),
                'contributes'  => $outdated_years_total * 15,
                // Inline context snippets — user can SEE where these appear in the post
                // without needing to do a copy/paste/find dance themselves.
                'snippets'     => $year_snippets,
                'checklist'    => [
                    __( 'Click "Edit this post" above and use Ctrl+F (Cmd+F on Mac) to find each year shown below', 'seobetter' ),
                    __( 'For each old year: replace with the current year ONLY if the underlying claim still holds. If a 2020 study is cited, find a more recent one or keep + add "(originally published 2020, still relevant)"', 'seobetter' ),
                    __( 'Update the "Last Updated" line at the top of the post to today\'s date', 'seobetter' ),
                ],
                'action'       => null,
            ];
        }

        // ── Signal 3: missing "Last Updated:" signal ──
        if ( ! $has_signal ) {
            // v1.5.216.56 — localize the copy payload to the post's language so
            // pasting "最終更新日: …" into a Japanese post doesn't look like a
            // bilingual artifact.
            $post_lang  = strtolower( (string) get_post_meta( $post_id, '_seobetter_language', true ) ?: 'en' );
            $label      = Localized_Strings::get( 'last_updated', $post_lang );
            $copy_payload = sprintf( '%s: %s', $label, wp_date( 'F j, Y' ) );
            $signals[] = [
                'id'           => 'no_freshness_signal',
                'severity'     => 'warning',
                'label'        => __( 'No "Last Updated:" line in the post', 'seobetter' ),
                'detail'       => __( "Add a freshness signal at the top of the post body — AI engines and readers use it as a tiebreaker. We'll generate the line for you; copy and paste it into your post.", 'seobetter' ),
                'contributes'  => 10,
                // Show the actual line as a code-style preview the user can read,
                // plus a Copy button so they can paste it into the post.
                'preview_line' => $copy_payload,
                'action'       => [
                    'type'    => 'copy',
                    'payload' => $copy_payload,
                    'label'   => __( 'Copy this line', 'seobetter' ),
                ],
            ];
        }

        // ── Signal 4-6: GSC-driven (Pro+ only) ──
        $top_queries = [];
        if ( $gsc_active && ! empty( $gsc_stats ) ) {
            $position    = (float) ( $gsc_stats['position_28d'] ?? 0 );
            $clicks      = (int) ( $gsc_stats['clicks_28d'] ?? 0 );
            $impressions = (int) ( $gsc_stats['impressions_28d'] ?? 0 );

            // Striking distance — biggest signal
            if ( $position >= 11 && $position <= 30 ) {
                $signals[] = [
                    'id'           => 'striking_distance',
                    'severity'     => 'critical',
                    'label'        => sprintf( __( 'Striking distance: position %.1f (just off page 1)', 'seobetter' ), $position ),
                    'detail'       => __( 'You rank just off page 1 — a modest content lift can push you to top 10.', 'seobetter' ),
                    'contributes'  => 50,
                    'checklist'    => [
                        __( 'Find your thinnest H2 section (under 100 words) and expand it to 200-300 words with concrete examples', 'seobetter' ),
                        __( 'Add 1-2 fresh statistics from a 2025-2026 source — include the source URL inline', 'seobetter' ),
                        __( 'Add an FAQ section: scroll down to the SEOBetter metabox → Schema Blocks tab → enable FAQ Page block', 'seobetter' ),
                        __( 'Open the SERP for your keyword in another tab — what content type / sections do the page-1 results have that you don\'t?', 'seobetter' ),
                        __( 'Add a comparison table if there are multiple options to compare', 'seobetter' ),
                    ],
                    'action'       => null,
                ];
            } elseif ( $position > 30 ) {
                $signals[] = [
                    'id'           => 'deep_ranking',
                    'severity'     => 'warning',
                    'label'        => sprintf( __( 'Ranking deep: average position %.1f', 'seobetter' ), $position ),
                    'detail'       => __( 'Position >30 usually means a content / intent gap, not just freshness. Refreshing alone may not move the needle.', 'seobetter' ),
                    'contributes'  => 30,
                    'checklist'    => [
                        __( 'Open the SERP for your target keyword in another tab — note what content TYPES rank (listicle? how-to? product page?)', 'seobetter' ),
                        __( 'Compare your post\'s structure against the top 3 results — what major sections are you missing?', 'seobetter' ),
                        __( 'Verify search intent: is this informational, commercial, or transactional? Does your post match?', 'seobetter' ),
                        __( 'Consider a full outline rebuild rather than a refresh — this might need new H2s, not new sentences', 'seobetter' ),
                    ],
                    'action'       => null,
                ];
            }

            // Impressions but no clicks = title/snippet problem, not content
            if ( $impressions >= 100 && $clicks === 0 ) {
                $signals[] = [
                    'id'           => 'snippet_problem',
                    'severity'     => 'warning',
                    'label'        => sprintf( __( '%s impressions, 0 clicks (28d)', 'seobetter' ), number_format_i18n( $impressions ) ),
                    'detail'       => __( "Google is showing your post but nobody clicks — that's a title or meta-description problem, not a content problem.", 'seobetter' ),
                    'contributes'  => 25,
                    'checklist'    => [
                        __( 'Scroll to the SEOBetter metabox → General tab → review the SERP Preview', 'seobetter' ),
                        __( 'Rewrite the SEO Title to lead with the user\'s search intent (e.g. "Best X for Y in 2026")', 'seobetter' ),
                        __( 'Tighten the meta description to 150 chars — lead with the direct answer, end with a benefit', 'seobetter' ),
                        __( 'Run the Page Analysis tab — confirm "Focus keyword in title" is green', 'seobetter' ),
                        __( 'Add power words (Ultimate / Complete / Free / Updated 2026) if appropriate to your brand', 'seobetter' ),
                    ],
                    'action'       => null,
                ];
            }

            // No GSC visibility at all
            if ( $impressions < 10 ) {
                $signals[] = [
                    'id'           => 'no_visibility',
                    'severity'     => 'warning',
                    'label'        => __( 'Effectively invisible in search (under 10 impressions / 28d)', 'seobetter' ),
                    'detail'       => __( 'Either too new to rank, or the keyword targeting needs a rethink. A refresh alone won\'t fix this.', 'seobetter' ),
                    'contributes'  => 15,
                    'checklist'    => [
                        __( 'Verify the focus keyword has actual search volume (use Google Trends or a keyword tool)', 'seobetter' ),
                        __( 'If the post is less than 8 weeks old, give it more time before refreshing', 'seobetter' ),
                        __( 'Add internal links from your higher-traffic posts to this one', 'seobetter' ),
                        __( 'Re-run keyword research — pick a longer-tail variant with less competition', 'seobetter' ),
                        __( 'Submit the URL to GSC URL Inspection → Request Indexing', 'seobetter' ),
                    ],
                    'action'       => null,
                ];
            }

            // Pull top queries (cached real-time call)
            $top_queries = GSC_Manager::get_post_top_queries( $post_id );
        }

        // Sort signals by contribution descending
        usort( $signals, fn( $a, $b ) => ( $b['contributes'] ?? 0 ) - ( $a['contributes'] ?? 0 ) );

        return [
            'locked'              => false,
            'post_id'             => $post_id,
            'post_title'          => $post->post_title,
            'edit_url'            => get_edit_post_link( $post_id, 'raw' ),
            'priority'            => $priority,
            'has_gsc'             => $gsc_active,
            'gsc_connected'       => GSC_Manager::is_connected(),
            'can_use_gsc'         => $can_use_gsc,
            'word_count'          => $word_count,
            'age_days'            => $age_days,
            'modified'            => $post->post_modified,
            'signals'             => $signals,
            'top_queries'         => $top_queries,
            'last_updated_string' => sprintf( 'Last Updated: %s', wp_date( 'F j, Y' ) ),
        ];
    }
}
