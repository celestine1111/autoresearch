<?php

namespace SEOBetter;

/**
 * Schema Markup Generator.
 *
 * Auto-generates JSON-LD structured data for posts based on content type:
 * - Article / BlogPosting / NewsArticle / OpinionNewsArticle / ScholarlyArticle / TechArticle
 * - FAQPage (from Q&A headings)
 * - HowTo (for how-to articles with steps)
 * - Recipe (for recipe articles)
 * - Review (for review articles)
 * - Product (for buying guides)
 * - ItemList (for listicles)
 * - LiveBlogPosting (for live blogs)
 * - BreadcrumbList (always)
 */
class Schema_Generator {

    /**
     * Map SEOBetter content types to primary Schema.org @type values.
     */
    private const CONTENT_TYPE_MAP = [
        'blog_post'           => 'BlogPosting',
        'news_article'        => 'NewsArticle',
        'opinion'             => 'OpinionNewsArticle',
        // v1.5.116 — HowTo deprecated by Google (Sept 2023). Use Article instead.
        'how_to'              => 'Article',
        'listicle'            => 'Article',
        'review'              => 'Review',
        'comparison'          => 'Article',
        'buying_guide'        => 'Article',
        'pillar_guide'        => 'Article',
        'case_study'          => 'Article',
        'interview'           => 'Article',
        'faq_page'            => 'FAQPage',
        'recipe'              => 'Recipe',
        'tech_article'        => 'TechArticle',
        'white_paper'         => 'Article',
        'scholarly_article'   => 'ScholarlyArticle',
        'live_blog'           => 'LiveBlogPosting',
        'press_release'       => 'NewsArticle',
        'personal_essay'      => 'BlogPosting',
        'glossary_definition' => 'DefinedTerm',
        'sponsored'           => 'BlogPosting',
    ];

    /**
     * Generate all applicable schema for a post.
     *
     * @param \WP_Post $post The WordPress post.
     * @return array Combined schema array.
     */
    public function generate( \WP_Post $post ): array {
        $schemas = [];

        $content_type = get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: 'blog_post';

        // Primary schema — varies by content type
        $primary = $this->generate_primary_schema( $post, $content_type );
        if ( $primary ) {
            $schemas[] = $primary;
        }

        // Secondary schemas — added when relevant content is detected

        // FAQPage is often added alongside Article when a post has a FAQ section
        if ( $content_type !== 'faq_page' ) {
            $faq = $this->generate_faq_schema( $post );
            if ( $faq ) {
                $schemas[] = $faq;
            }
        }

        // v1.5.116 — HowTo schema DEPRECATED by Google (September 2023).
        // No longer generates any rich results. Removed entirely.
        // Keep how_to articles as Article @type with FAQPage secondary.

        // ItemList for listicles (adds rich-list support alongside the Article schema)
        if ( $content_type === 'listicle' ) {
            $itemlist = $this->generate_itemlist_schema( $post );
            if ( $itemlist ) {
                $schemas[] = $itemlist;
            }
        }

        $schemas[] = $this->generate_breadcrumb_schema( $post );

        return $schemas;
    }

    /**
     * Build the primary schema entity for a post based on its content type.
     */
    private function generate_primary_schema( \WP_Post $post, string $content_type ): ?array {
        $type = self::CONTENT_TYPE_MAP[ $content_type ] ?? 'Article';

        // Specialty types have their own builders
        switch ( $type ) {
            case 'HowTo':
                $howto = $this->build_howto( $post );
                return $howto ?: $this->build_article( $post, 'Article' );

            case 'Recipe':
                return $this->build_recipe( $post );

            case 'Review':
                return $this->build_review( $post );

            case 'FAQPage':
                $faq = $this->generate_faq_schema( $post );
                return $faq ?: $this->build_article( $post, 'Article' );

            case 'DefinedTerm':
                return $this->build_defined_term( $post );

            default:
                return $this->build_article( $post, $type );
        }
    }

    /**
     * Build a standard Article-family schema (Article, BlogPosting, NewsArticle, etc.).
     */
    private function build_article( \WP_Post $post, string $type ): array {
        $author    = get_userdata( $post->post_author );
        $thumbnail = get_the_post_thumbnail_url( $post->ID, 'full' );

        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => $type,
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

        // NewsArticle / OpinionNewsArticle need dateline
        if ( in_array( $type, [ 'NewsArticle', 'OpinionNewsArticle' ], true ) ) {
            $schema['articleSection'] = 'News';
        }

        return $schema;
    }

    /**
     * Build full HowTo schema with steps extracted from ordered lists.
     */
    private function build_howto( \WP_Post $post ): ?array {
        $content = $post->post_content;

        preg_match_all( '/<ol[^>]*>(.*?)<\/ol>/is', $content, $ol_matches );
        if ( empty( $ol_matches[1] ) ) {
            return null;
        }

        $steps = [];
        $position = 1;
        foreach ( $ol_matches[1] as $ol_content ) {
            preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $ol_content, $li_matches );
            foreach ( $li_matches[1] as $li ) {
                $step_text = wp_strip_all_tags( $li );
                if ( strlen( $step_text ) > 5 ) {
                    $steps[] = [
                        '@type'    => 'HowToStep',
                        'position' => $position++,
                        'name'     => wp_trim_words( $step_text, 8, '' ),
                        'text'     => $step_text,
                    ];
                }
            }
        }

        if ( count( $steps ) < 2 ) {
            return null;
        }

        $thumbnail = get_the_post_thumbnail_url( $post->ID, 'full' );

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'HowTo',
            'name'        => $post->post_title,
            'description' => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
            'step'        => $steps,
            'totalTime'   => 'PT' . max( 5, count( $steps ) * 2 ) . 'M',
        ];

        if ( $thumbnail ) {
            $schema['image'] = $thumbnail;
        }

        return $schema;
    }

    /**
     * Build Recipe schema — uses ordered-list instructions and any ingredient-like lists.
     */
    /**
     * v1.5.116 — Recipe schema rewritten for Google compliance.
     * - REMOVED hardcoded prepTime/cookTime/totalTime/recipeYield (policy violation)
     * - REMOVED hardcoded recipeCategory/recipeCuisine
     * - Ingredients: now extracts ALL list items under an "Ingredients" heading,
     *   not just items matching measurement unit regex (missed pet food ingredients)
     * - Times: only included if extractable from content text
     * - Required by Google: name + image only. Everything else is recommended.
     */
    private function build_recipe( \WP_Post $post ): array {
        $content   = $post->post_content;
        $text      = wp_strip_all_tags( $content );
        $thumbnail = get_the_post_thumbnail_url( $post->ID, 'full' );
        $author    = get_userdata( $post->post_author );

        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Recipe',
            'name'          => $post->post_title,
            'description'   => wp_trim_words( $text, 30 ),
            'datePublished' => get_the_date( 'c', $post ),
            'author'        => [
                '@type' => 'Person',
                'name'  => $author ? $author->display_name : get_bloginfo( 'name' ),
            ],
        ];

        if ( $thumbnail ) {
            $schema['image'] = $thumbnail;
        }

        // Extract ingredients: find list items under any heading containing "ingredient"
        // Also accept any <ul> list that follows an H2/H3 with "ingredient" in it
        $ingredients = [];
        // Method 1: look for list items after an "Ingredients" heading
        if ( preg_match( '/<h[2-4][^>]*>[^<]*ingredient[^<]*<\/h[2-4]>\s*(?:<[^>]*>)*\s*<ul[^>]*>(.*?)<\/ul>/is', $content, $ing_match ) ) {
            preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $ing_match[1], $li_matches );
            foreach ( $li_matches[1] as $li ) {
                $item = trim( wp_strip_all_tags( $li ) );
                if ( strlen( $item ) > 2 ) {
                    $ingredients[] = $item;
                }
            }
        }
        // Method 2 fallback: any list item with measurement units
        if ( empty( $ingredients ) ) {
            preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $content, $all_li );
            foreach ( $all_li[1] as $li ) {
                $item = trim( wp_strip_all_tags( $li ) );
                if ( preg_match( '/\b(cup|cups|tbsp|tsp|teaspoon|tablespoon|gram|grams|ml|oz|ounce|pound|lb|kg|piece|clove|pinch)\b/i', $item ) ) {
                    $ingredients[] = $item;
                }
            }
        }
        if ( ! empty( $ingredients ) ) {
            $schema['recipeIngredient'] = array_slice( $ingredients, 0, 30 );
        }

        // Extract instructions from ordered lists
        $instructions = [];
        // Method 1: look for ordered list after "Instructions" or "Directions" heading
        if ( preg_match( '/<h[2-4][^>]*>[^<]*(?:instruction|direction|step|method)[^<]*<\/h[2-4]>\s*(?:<[^>]*>)*\s*<ol[^>]*>(.*?)<\/ol>/is', $content, $ins_match ) ) {
            preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $ins_match[1], $li_matches );
            foreach ( $li_matches[1] as $li ) {
                $step_text = trim( wp_strip_all_tags( $li ) );
                if ( strlen( $step_text ) > 5 ) {
                    $instructions[] = [
                        '@type' => 'HowToStep',
                        'text'  => $step_text,
                    ];
                }
            }
        }
        // Method 2 fallback: any ordered list
        if ( empty( $instructions ) ) {
            preg_match_all( '/<ol[^>]*>(.*?)<\/ol>/is', $content, $ol_matches );
            foreach ( $ol_matches[1] as $ol_content ) {
                preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $ol_content, $li_matches );
                foreach ( $li_matches[1] as $li ) {
                    $step_text = trim( wp_strip_all_tags( $li ) );
                    if ( strlen( $step_text ) > 5 ) {
                        $instructions[] = [
                            '@type' => 'HowToStep',
                            'text'  => $step_text,
                        ];
                    }
                }
            }
        }
        if ( ! empty( $instructions ) ) {
            $schema['recipeInstructions'] = $instructions;
        }

        // Extract times ONLY if present in content (never hardcode)
        if ( preg_match( '/prep(?:aration)?\s*(?:time)?[\s:]+(\d+)\s*(?:min|minute)/i', $text, $prep ) ) {
            $schema['prepTime'] = 'PT' . $prep[1] . 'M';
        }
        if ( preg_match( '/cook(?:ing)?\s*(?:time)?[\s:]+(\d+)\s*(?:min|minute)/i', $text, $cook ) ) {
            $schema['cookTime'] = 'PT' . $cook[1] . 'M';
        }
        if ( preg_match( '/total\s*(?:time)?[\s:]+(\d+)\s*(?:min|minute)/i', $text, $total ) ) {
            $schema['totalTime'] = 'PT' . $total[1] . 'M';
        }
        // Extract yield ONLY if present
        if ( preg_match( '/(?:yield|serve|serving|makes)[\s:]+(\d+\s*(?:serving|piece|treat|cookie|batch|portion)[s]?)/i', $text, $yield ) ) {
            $schema['recipeYield'] = $yield[1];
        }

        return $schema;
    }

    /**
     * Build Review schema. Item reviewed is extracted from the post title.
     */
    /**
     * v1.5.116 — Review schema: removed hardcoded 4.5 rating (Google policy violation).
     * Rating is only included if extractable from content (e.g., "Rating: 4/5" or
     * "We give it 8 out of 10"). Otherwise uses Article schema type instead of Review
     * to avoid the required reviewRating field.
     */
    private function build_review( \WP_Post $post ): array {
        $author    = get_userdata( $post->post_author );
        $thumbnail = get_the_post_thumbnail_url( $post->ID, 'full' );
        $text      = wp_strip_all_tags( $post->post_content );

        // Item name = title with "review" stripped
        $item_name = trim( preg_replace( '/\b(review|in-depth|honest|full|best|top|guide)\b/i', '', $post->post_title ) );
        $item_name = trim( preg_replace( '/\s+/', ' ', $item_name ) );
        // Clean trailing year and punctuation
        $item_name = trim( preg_replace( '/\b(20\d{2}|in)\b\s*[:.\-]*\s*$/i', '', $item_name ) );

        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Review',
            'name'          => $post->post_title,
            'description'   => wp_trim_words( $text, 30 ),
            'datePublished' => get_the_date( 'c', $post ),
            'author'        => [
                '@type' => 'Person',
                'name'  => $author ? $author->display_name : get_bloginfo( 'name' ),
            ],
            'itemReviewed'  => [
                '@type' => 'Product',
                'name'  => $item_name ?: $post->post_title,
            ],
        ];

        if ( $thumbnail ) {
            $schema['image'] = $thumbnail;
        }

        // Extract rating ONLY if present in content — never hardcode
        // Patterns: "4.5/5", "Rating: 8/10", "Score: 4 out of 5", "we give it 9/10"
        if ( preg_match( '/(?:rating|score|verdict|grade|we give (?:it )?)\s*[:=]?\s*(\d+(?:\.\d+)?)\s*(?:\/|out of)\s*(\d+)/i', $text, $rating_match ) ) {
            $value = floatval( $rating_match[1] );
            $best = intval( $rating_match[2] );
            if ( $value > 0 && $best > 0 && $value <= $best ) {
                $schema['reviewRating'] = [
                    '@type'       => 'Rating',
                    'ratingValue' => (string) $value,
                    'bestRating'  => (string) $best,
                    'worstRating' => '1',
                ];
            }
        }

        // If no rating found, still valid as Review without reviewRating
        // (Google won't show star snippets but won't flag it as violation)

        return $schema;
    }

    /**
     * Build DefinedTerm schema for glossary definitions.
     */
    private function build_defined_term( \WP_Post $post ): array {
        return [
            '@context'    => 'https://schema.org',
            '@type'       => 'DefinedTerm',
            'name'        => $post->post_title,
            'description' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 ),
            'inDefinedTermSet' => [
                '@type' => 'DefinedTermSet',
                'name'  => get_bloginfo( 'name' ) . ' Glossary',
                'url'   => home_url(),
            ],
            'url' => get_permalink( $post->ID ),
        ];
    }

    /**
     * Generate FAQPage schema from H2/H3 question-like headings.
     */
    private function generate_faq_schema( \WP_Post $post ): ?array {
        $content = $post->post_content;

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

            $is_question = preg_match( '/\?$|^(what|how|why|when|where|who|which|can|does|is|are|should|do)\b/i', $heading_text );
            if ( ! $is_question ) {
                continue;
            }

            $answer_html = $parts[ $i + 1 ] ?? '';
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
     * Used as a *secondary* schema when the post title suggests how-to but
     * the content type isn't explicitly how_to.
     */
    private function generate_howto_schema( \WP_Post $post ): ?array {
        if ( ! preg_match( '/how\s*to/i', $post->post_title ) ) {
            return null;
        }
        return $this->build_howto( $post );
    }

    /**
     * Generate ItemList schema for listicles.
     * Extracts H2 subheadings as list items.
     */
    private function generate_itemlist_schema( \WP_Post $post ): ?array {
        $content = $post->post_content;

        preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $content, $h2_matches );
        if ( empty( $h2_matches[1] ) ) {
            return null;
        }

        $items = [];
        $position = 1;
        foreach ( $h2_matches[1] as $heading ) {
            $name = wp_strip_all_tags( $heading );
            // Skip generic non-list headings
            if ( preg_match( '/^(introduction|conclusion|faq|frequently asked|summary|final thoughts)/i', $name ) ) {
                continue;
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $name,
            ];
            if ( $position > 30 ) {
                break;
            }
        }

        if ( count( $items ) < 3 ) {
            return null;
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'itemListElement' => $items,
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
