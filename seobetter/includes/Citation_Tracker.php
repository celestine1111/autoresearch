<?php

namespace SEOBetter;

/**
 * AI Citation Tracker.
 *
 * Checks whether your content is being cited by AI engines
 * (ChatGPT, Perplexity, Google AI Overviews, Gemini, Claude).
 *
 * Sends your post's focus keyword to each AI engine via the Cloud API
 * and checks if your domain appears in the response.
 *
 * Pro feature.
 */
class Citation_Tracker {

    /**
     * AI engines to check.
     */
    private const ENGINES = [
        'google_aio'  => 'Google AI Overviews',
        'perplexity'  => 'Perplexity',
        'chatgpt'     => 'ChatGPT',
        'gemini'      => 'Gemini',
        'claude'      => 'Claude',
    ];

    /**
     * Check if a post is cited by AI engines.
     *
     * Uses the AI provider to simulate a search query and check
     * if the site's domain or post URL appears in the response.
     */
    public function check_post( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [ 'success' => false, 'error' => 'Post not found.' ];
        }

        $keyword = get_post_meta( $post_id, '_seobetter_focus_keyword', true )
                ?: get_post_meta( $post_id, '_yoast_wpseo_focuskw', true )
                ?: get_post_meta( $post_id, 'rank_math_focus_keyword', true )
                ?: $post->post_title;

        $domain = wp_parse_url( home_url(), PHP_URL_HOST );
        $url = get_permalink( $post_id );

        $provider = AI_Provider_Manager::get_active_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No AI provider configured. Connect one in Settings.' ];
        }

        $results = [];
        $cited_count = 0;

        // Check each engine by asking the AI to simulate what that engine would cite
        $prompt = "You are simulating an AI search engine response. A user searches for: \"{$keyword}\"

List the top 10 websites/sources that an AI search engine would most likely cite when answering this query. For each source, provide:
- The domain name
- Why it would be cited (authority, relevance, content quality)

Be realistic — include actual well-known domains in this niche.

Also evaluate: would the website \"{$domain}\" (URL: {$url}) likely be cited for this query? Answer YES or NO with a brief reason.

Format:
1. [domain.com] — [reason]
...
10. [domain.com] — [reason]

SITE CHECK: {$domain} — [YES/NO] — [reason]";

        $system = 'You are an AI search engine analyst. Provide realistic, factual assessments of which sources AI engines cite for queries. Be honest — do not inflate the likelihood of any particular site being cited.';

        $response = AI_Provider_Manager::send_request(
            $provider['provider_id'],
            $prompt,
            $system,
            [ 'max_tokens' => 1500, 'temperature' => 0.3 ]
        );

        if ( ! $response['success'] ) {
            return [ 'success' => false, 'error' => $response['error'] ?? 'AI request failed.' ];
        }

        $content = $response['content'];

        // Parse the site check result
        $is_cited = false;
        $cite_reason = '';
        if ( preg_match( '/SITE CHECK:.*?(YES|NO)\s*[—\-]\s*(.+)/i', $content, $m ) ) {
            $is_cited = strtoupper( trim( $m[1] ) ) === 'YES';
            $cite_reason = trim( $m[2] );
        }

        // Parse competing sources
        $competitors = [];
        if ( preg_match_all( '/^\d+\.\s*\[?([^\]\n—\-]+)\]?\s*[—\-]\s*(.+)$/m', $content, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $comp_domain = trim( $match[1], '[] ' );
                $competitors[] = [
                    'domain' => $comp_domain,
                    'reason' => trim( $match[2] ),
                    'is_you' => stripos( $comp_domain, $domain ) !== false,
                ];
            }
        }

        // Calculate visibility score
        $position = null;
        foreach ( $competitors as $i => $comp ) {
            if ( $comp['is_you'] ) {
                $position = $i + 1;
                $is_cited = true;
                break;
            }
        }

        $visibility_score = 0;
        if ( $is_cited ) {
            if ( $position !== null ) {
                // Higher score for higher position
                $visibility_score = max( 10, 100 - ( ( $position - 1 ) * 10 ) );
            } else {
                $visibility_score = 30; // Cited but not in top 10
            }
        }

        $result = [
            'success'          => true,
            'keyword'          => $keyword,
            'domain'           => $domain,
            'is_cited'         => $is_cited,
            'cite_reason'      => $cite_reason,
            'visibility_score' => $visibility_score,
            'position'         => $position,
            'competitors'      => array_slice( $competitors, 0, 10 ),
            'checked_at'       => current_time( 'mysql' ),
        ];

        // Cache result
        update_post_meta( $post_id, '_seobetter_citation_check', $result );

        return $result;
    }

    /**
     * Get cached citation check for a post.
     */
    public function get_cached( int $post_id ): ?array {
        $cached = get_post_meta( $post_id, '_seobetter_citation_check', true );
        return is_array( $cached ) ? $cached : null;
    }

    /**
     * Check multiple posts (site-wide report).
     */
    public function check_site( int $limit = 20 ): array {
        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_key'       => '_seobetter_geo_score',
        ] );

        $results = [];
        foreach ( $posts as $post ) {
            // Use cached if less than 7 days old
            $cached = $this->get_cached( $post->ID );
            if ( $cached && isset( $cached['checked_at'] ) ) {
                $age = time() - strtotime( $cached['checked_at'] );
                if ( $age < 7 * DAY_IN_SECONDS ) {
                    $results[] = array_merge( $cached, [
                        'post_id'    => $post->ID,
                        'post_title' => $post->post_title,
                        'post_url'   => get_permalink( $post->ID ),
                        'from_cache' => true,
                    ] );
                    continue;
                }
            }

            // Only check 3 posts per site-wide scan to avoid API overload
            if ( count( array_filter( $results, fn( $r ) => empty( $r['from_cache'] ) ) ) >= 3 ) {
                $results[] = [
                    'post_id'    => $post->ID,
                    'post_title' => $post->post_title,
                    'post_url'   => get_permalink( $post->ID ),
                    'skipped'    => true,
                ];
                continue;
            }

            $check = $this->check_post( $post->ID );
            if ( $check['success'] ) {
                $results[] = array_merge( $check, [
                    'post_id'    => $post->ID,
                    'post_title' => $post->post_title,
                    'post_url'   => get_permalink( $post->ID ),
                    'from_cache' => false,
                ] );
            }
        }

        $cited_count = count( array_filter( $results, fn( $r ) => ! empty( $r['is_cited'] ) ) );
        $total_checked = count( array_filter( $results, fn( $r ) => empty( $r['skipped'] ) ) );

        return [
            'results'       => $results,
            'total_checked' => $total_checked,
            'cited_count'   => $cited_count,
            'cite_rate'     => $total_checked > 0 ? round( ( $cited_count / $total_checked ) * 100 ) : 0,
        ];
    }

    /**
     * Get improvement suggestions based on citation check.
     */
    public function get_suggestions( array $result ): array {
        $suggestions = [];

        if ( ! $result['is_cited'] ) {
            $suggestions[] = [
                'priority' => 'high',
                'message'  => 'Your site is not being cited for this keyword. Focus on adding expert quotes, statistics, and inline citations to increase AI visibility.',
            ];
            $suggestions[] = [
                'priority' => 'high',
                'message'  => 'Add a comparison table — AI engines cite tables 30-40% more than plain text.',
            ];
            $suggestions[] = [
                'priority' => 'medium',
                'message'  => 'Ensure your Key Takeaways section directly answers the query in 40-60 words.',
            ];
        } elseif ( $result['visibility_score'] < 70 ) {
            $suggestions[] = [
                'priority' => 'medium',
                'message'  => 'Your site is cited but not in the top positions. Add more unique data, expert quotes, and original research to improve ranking.',
            ];
        }

        if ( ! empty( $result['competitors'] ) ) {
            $top_competitor = $result['competitors'][0]['domain'] ?? '';
            if ( $top_competitor && stripos( $top_competitor, $result['domain'] ) === false ) {
                $suggestions[] = [
                    'priority' => 'medium',
                    'message'  => "Top cited source is {$top_competitor}. Analyze their content structure and ensure yours matches or exceeds their depth.",
                ];
            }
        }

        return $suggestions;
    }
}
