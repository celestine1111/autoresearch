<?php

namespace SEOBetter;

/**
 * Schema Markup Generator.
 *
 * Auto-generates JSON-LD structured data for posts:
 * - Article schema with author and dateModified
 * - FAQPage schema from H2/H3 headings
 * - HowTo schema for procedural content
 * - BreadcrumbList for navigation
 */
class Schema_Generator {

    /**
     * Generate all applicable schema for a post.
     *
     * @param \WP_Post $post The WordPress post.
     * @return array Combined schema array.
     */
    public function generate( \WP_Post $post ): array {
        $schemas = [];

        $schemas[] = $this->generate_article_schema( $post );

        $faq = $this->generate_faq_schema( $post );
        if ( $faq ) {
            $schemas[] = $faq;
        }

        $howto = $this->generate_howto_schema( $post );
        if ( $howto ) {
            $schemas[] = $howto;
        }

        $schemas[] = $this->generate_breadcrumb_schema( $post );

        return $schemas;
    }

    /**
     * Generate Article schema.
     */
    private function generate_article_schema( \WP_Post $post ): array {
        $author = get_userdata( $post->post_author );
        $thumbnail = get_the_post_thumbnail_url( $post->ID, 'full' );

        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => $post->post_title,
            'description'   => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ),
            'datePublished' => get_the_date( 'c', $post ),
            'dateModified'  => get_the_modified_date( 'c', $post ),
            'author'        => [
                '@type' => 'Person',
                'name'  => $author ? $author->display_name : 'Unknown',
            ],
            'publisher'     => [
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
                'url'   => home_url(),
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => get_permalink( $post->ID ),
            ],
        ];

        if ( $thumbnail ) {
            $schema['image'] = $thumbnail;
        }

        // Speakable markup — tells voice assistants which sections to read aloud
        $schema['speakable'] = [
            '@type'       => 'SpeakableSpecification',
            'cssSelector' => [ 'h1', 'h2 + p', '.key-takeaways', '.faq-answer' ],
        ];

        return $schema;
    }

    /**
     * Generate FAQPage schema from H2/H3 question-like headings.
     */
    private function generate_faq_schema( \WP_Post $post ): ?array {
        $content = $post->post_content;

        // Find headings that look like questions
        preg_match_all(
            '/<h[2-3][^>]*>(.*?)<\/h[2-3]>/is',
            $content,
            $heading_matches
        );

        if ( empty( $heading_matches[1] ) ) {
            return null;
        }

        $faq_items = [];
        $parts = preg_split( '/<h[2-3][^>]*>.*?<\/h[2-3]>/is', $content );

        foreach ( $heading_matches[1] as $i => $heading ) {
            $heading_text = wp_strip_all_tags( $heading );

            // Check if heading is a question or could be phrased as one
            $is_question = preg_match( '/\?$|^(what|how|why|when|where|who|which|can|does|is|are|should|do)\b/i', $heading_text );

            if ( ! $is_question ) {
                continue;
            }

            $answer_html = $parts[ $i + 1 ] ?? '';
            // Get first paragraph as the answer
            if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $answer_html, $para_match ) ) {
                $answer = wp_strip_all_tags( $para_match[1] );
            } else {
                $answer = wp_trim_words( wp_strip_all_tags( $answer_html ), 50 );
            }

            if ( strlen( $answer ) < 10 ) {
                continue;
            }

            $faq_items[] = [
                '@type'          => 'Question',
                'name'           => $heading_text,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $answer,
                ],
            ];
        }

        if ( empty( $faq_items ) ) {
            return null;
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $faq_items,
        ];
    }

    /**
     * Generate HowTo schema for procedural content with ordered lists.
     */
    private function generate_howto_schema( \WP_Post $post ): ?array {
        $content = $post->post_content;

        // Check if content has "how to" in the title
        if ( ! preg_match( '/how\s*to/i', $post->post_title ) ) {
            return null;
        }

        // Extract ordered list items as steps
        preg_match_all( '/<ol[^>]*>(.*?)<\/ol>/is', $content, $ol_matches );

        if ( empty( $ol_matches[1] ) ) {
            return null;
        }

        $steps = [];
        foreach ( $ol_matches[1] as $ol_content ) {
            preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $ol_content, $li_matches );
            foreach ( $li_matches[1] as $li ) {
                $step_text = wp_strip_all_tags( $li );
                if ( strlen( $step_text ) > 5 ) {
                    $steps[] = [
                        '@type' => 'HowToStep',
                        'text'  => $step_text,
                    ];
                }
            }
        }

        if ( count( $steps ) < 2 ) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => $post->post_title,
            'step'     => $steps,
        ];
    }

    /**
     * Generate BreadcrumbList schema.
     */
    private function generate_breadcrumb_schema( \WP_Post $post ): array {
        $items = [
            [
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => get_bloginfo( 'name' ),
                'item'     => home_url(),
            ],
        ];

        $categories = get_the_category( $post->ID );
        if ( ! empty( $categories ) ) {
            $cat = $categories[0];
            $items[] = [
                '@type'    => 'ListItem',
                'position' => 2,
                'name'     => $cat->name,
                'item'     => get_category_link( $cat->term_id ),
            ];
        }

        $items[] = [
            '@type'    => 'ListItem',
            'position' => count( $items ) + 1,
            'name'     => $post->post_title,
            'item'     => get_permalink( $post->ID ),
        ];

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}
