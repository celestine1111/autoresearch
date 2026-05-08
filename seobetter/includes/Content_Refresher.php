<?php

namespace SEOBetter;

/**
 * One-Click Content Refresher.
 *
 * Takes a stale post and regenerates specific sections with fresh
 * statistics, quotes, and citations while preserving the overall structure.
 *
 * Pro feature.
 */
class Content_Refresher {

    /**
     * Refresh a post with updated content.
     */
    public function refresh( int $post_id, array $options = [] ): array {
        if ( ! License_Manager::can_use( 'freshness_suggestions' ) ) {
            return [ 'success' => false, 'error' => 'Content refresh requires SEOBetter Pro.' ];
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return [ 'success' => false, 'error' => 'Post not found.' ];
        }

        $provider = AI_Provider_Manager::get_active_provider();
        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No AI provider configured.' ];
        }

        $keyword = get_post_meta( $post_id, '_seobetter_focus_keyword', true )
                ?: get_post_meta( $post_id, '_yoast_wpseo_focuskw', true )
                ?: get_post_meta( $post_id, 'rank_math_focus_keyword', true )
                ?: $post->post_title;

        // Analyze current content for specific issues
        $analyzer = new GEO_Analyzer();
        $current_score = $analyzer->analyze( $post->post_content, $post->post_title );

        // Convert HTML to readable text for the AI
        $content_text = wp_strip_all_tags( $post->post_content );
        // Preserve heading structure
        $md_content = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/is', '## $1', $post->post_content );
        $md_content = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/is', '### $1', $md_content );
        $md_content = wp_strip_all_tags( $md_content );

        $date = wp_date( 'F Y' );
        $word_count = str_word_count( $content_text );

        // Build targeted refresh instructions
        $issues = [];
        foreach ( $current_score['suggestions'] ?? [] as $s ) {
            $issues[] = '- ' . $s['message'];
        }
        $issue_text = ! empty( $issues ) ? implode( "\n", $issues ) : '- General refresh with updated statistics and citations';

        $prompt = "Refresh this article about \"{$keyword}\" for {$date}. The article is {$word_count} words and was last updated on " . get_the_modified_date( 'F j, Y', $post ) . ".

SPECIFIC ISSUES TO FIX:
{$issue_text}

REFRESH RULES:
- Update the \"Last Updated\" date to {$date}
- Replace outdated statistics with current ones (2024-2026 data)
- Add new expert quotes if missing (2+ required)
- Add inline citations in [Source, Year] format (5+ required)
- Ensure every H2/H3 section opens with a 40-60 word answer paragraph
- Add a comparison table if missing
- Never start paragraphs with pronouns (It, This, They)
- Keep the same structure and headings — only refresh the content within them
- Target reading level: grade 6-8
- The refreshed article must be AT LEAST as long as the original ({$word_count}+ words)
- Add FAQ section if missing (3-5 Q&A pairs)

ORIGINAL ARTICLE:

{$md_content}

Return the FULL refreshed article in GitHub Flavored Markdown.";

        $system = "You are an expert content refresher. Your job is to update stale content with fresh data, statistics, and citations while maintaining the original structure and topic. Write at grade 6-8 reading level. Every change should improve the article's chances of being cited by AI models.";

        $result = AI_Provider_Manager::send_request(
            $provider['provider_id'],
            $prompt,
            $system,
            [ 'max_tokens' => 8192, 'temperature' => 0.5 ]
        );

        if ( ! $result['success'] ) {
            return $result;
        }

        // Format as HTML
        $formatter = new Content_Formatter();
        $html = $formatter->format( $result['content'], 'gutenberg', [
            'accent_color'  => '#764ba2',
            // v1.5.216.62.114 — preserve content_type on refresh so per-type
            // design (faq_page accordion, etc.) re-applies on regenerate.
            'content_type'  => get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: 'blog_post',
        ] );

        // Score the refreshed content
        $new_score = $analyzer->analyze( $html, $post->post_title );

        return [
            'success'        => true,
            'content'        => $html,
            'markdown'       => $result['content'],
            'post_id'        => $post_id,
            'keyword'        => $keyword,
            'old_score'      => $current_score['geo_score'],
            'new_score'      => $new_score['geo_score'],
            'old_grade'      => $current_score['grade'],
            'new_grade'      => $new_score['grade'],
            'score_change'   => $new_score['geo_score'] - $current_score['geo_score'],
            'word_count'     => str_word_count( wp_strip_all_tags( $html ) ),
            'suggestions'    => $new_score['suggestions'],
        ];
    }

    /**
     * Apply a refresh — update the post content in WordPress.
     */
    public function apply_refresh( int $post_id, string $content ): bool {
        $result = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => $content,
        ] );

        return ! is_wp_error( $result );
    }
}
