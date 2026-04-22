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

    /** @var array Multi-recipe storage for build_recipe() → generate() */
    private array $_multi_recipes = [];

    /**
     * v1.5.139 — Get author name, with SEOBetter settings override.
     * Priority: SEOBetter author_name setting > WP display_name > site name.
     * Google policy: author.name must be a real name, never an email address.
     */
    private function get_author_name( \WP_Post $post ): string {
        // v1.5.139 — Use SEOBetter author bio settings first
        $s = get_option( 'seobetter_settings', [] );
        if ( ! empty( $s['author_name'] ) ) {
            return $s['author_name'];
        }
        $author = get_userdata( $post->post_author );
        if ( $author && $author->display_name ) {
            $name = $author->display_name;
            if ( is_email( $name ) ) {
                return get_bloginfo( 'name' ) ?: 'Author';
            }
            return $name;
        }
        return get_bloginfo( 'name' ) ?: 'Author';
    }

    /**
     * v1.5.139 — Build full Person schema from SEOBetter author settings.
     * Includes sameAs, jobTitle, description, image, worksFor, knowsAbout
     * per Google's E-E-A-T guidelines and Schema.org/Person spec.
     */
    private function safe_build_author( \WP_Post $post ): array {
        try {
            return $this->build_author_schema( $post );
        } catch ( \Throwable $e ) {
            return [ '@type' => 'Person', 'name' => $this->get_author_name( $post ) ];
        }
    }

    private function build_author_schema( \WP_Post $post ): array {
        $s = get_option( 'seobetter_settings', [] );

        $person = [
            '@type' => 'Person',
            'name'  => $this->get_author_name( $post ),
            'url'   => get_author_posts_url( $post->post_author ),
        ];

        // From SEOBetter settings
        if ( ! empty( $s['author_title'] ) ) {
            $person['jobTitle'] = $s['author_title'];
        }
        if ( ! empty( $s['author_bio'] ) ) {
            $person['description'] = $s['author_bio'];
        }
        if ( ! empty( $s['author_image'] ) ) {
            $person['image'] = $s['author_image'];
        }
        if ( ! empty( $s['author_credentials'] ) ) {
            $person['knowsAbout'] = $s['author_credentials'];
        }

        // worksFor = the site
        $person['worksFor'] = [
            '@type' => 'Organization',
            'name'  => get_bloginfo( 'name' ),
            'url'   => home_url(),
        ];

        // sameAs — all configured social profiles
        $same_as = [];
        foreach ( [ 'author_linkedin', 'author_twitter', 'author_facebook', 'author_instagram', 'author_youtube', 'author_website' ] as $key ) {
            if ( ! empty( $s[ $key ] ) ) {
                $same_as[] = $s[ $key ];
            }
        }
        if ( ! empty( $same_as ) ) {
            $person['sameAs'] = $same_as;
        }

        return $person;
    }

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
    /**
     * v1.5.118 — Expanded schema generation with multi-schema stacking.
     * Each content type gets its primary schema + relevant secondary schemas.
     * All schemas output without individual @context (caller wraps in @graph).
     */
    // Content types that get Speakable (voice assistants)
    private const SPEAKABLE_TYPES = [ 'blog_post', 'news_article', 'opinion', 'pillar_guide' ];
    // Content types that get FAQPage secondary (when FAQ section detected)
    private const FAQ_TYPES = [
        'blog_post', 'how_to', 'listicle', 'review', 'comparison', 'buying_guide',
        'recipe', 'news_article', 'opinion', 'tech_article', 'white_paper',
        'scholarly_article', 'glossary_definition', 'case_study', 'interview', 'pillar_guide',
    ];
    // Content types that get ItemList
    private const ITEMLIST_TYPES = [ 'listicle', 'buying_guide', 'pillar_guide' ];

    public function generate( \WP_Post $post ): array {
        $schemas = [];

        $content_type = get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: 'blog_post';

        // Primary schema — varies by content type
        $primary = $this->generate_primary_schema( $post, $content_type );
        if ( $primary ) {
            $schemas[] = $primary;
        }

        // v1.5.121 — Add additional recipes (if build_recipe found multiple)
        if ( ! empty( $this->_multi_recipes ) && count( $this->_multi_recipes ) > 1 ) {
            foreach ( array_slice( $this->_multi_recipes, 1 ) as $extra_recipe ) {
                $schemas[] = $extra_recipe;
            }
        }

        // ---- Secondary schemas ----

        // FAQPage — added alongside primary when FAQ section detected
        if ( in_array( $content_type, self::FAQ_TYPES, true ) && $content_type !== 'faq_page' ) {
            $faq = $this->generate_faq_schema( $post );
            if ( $faq ) {
                $schemas[] = $faq;
            }
        }

        // ItemList — for listicles, buying guides, pillar guides
        if ( in_array( $content_type, self::ITEMLIST_TYPES, true ) ) {
            $itemlist = $this->generate_itemlist_schema( $post );
            if ( $itemlist ) {
                $schemas[] = $itemlist;
            }
        }

        // LocalBusiness — auto-detect from content with addresses
        $local = $this->generate_localbusiness_schemas( $post );
        foreach ( $local as $lb ) {
            $schemas[] = $lb;
        }

        // v1.5.119 — Content-detected schemas (triggered by what's IN the article)
        $content = $post->post_content;
        $content_type = get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: 'blog_post';
        $category = get_post_meta( $post->ID, '_seobetter_domain', true ) ?: '';

        // VideoObject — detect embedded YouTube/Vimeo/video tags
        $video = $this->detect_video_schema( $post, $content );
        if ( $video ) {
            $schemas[] = $video;
        }

        // SoftwareApplication — tech/business category + app/software mentions + rating
        $software = $this->detect_software_schema( $post, $content, $content_type, $category );
        if ( $software ) {
            $schemas[] = $software;
        }

        // Event — content has future date + location + event name
        $event = $this->detect_event_schema( $post, $content, $content_type );
        if ( $event ) {
            $schemas[] = $event;
        }

        // ImageObject — license metadata for article images
        $images = $this->detect_image_schemas( $post, $content );
        foreach ( $images as $img ) {
            $schemas[] = $img;
        }

        // ProfilePage — interview/personal_essay with person bio
        $profile = $this->detect_profile_schema( $post, $content, $content_type );
        if ( $profile ) {
            $schemas[] = $profile;
        }

        // Course — education/tech category + course/lesson/module structure
        $course = $this->detect_course_schema( $post, $content, $content_type, $category );
        if ( $course ) {
            $schemas[] = $course;
        }

        // Movie — entertainment category + movie titles
        $movie = $this->detect_movie_schema( $post, $content, $content_type, $category );
        if ( $movie ) {
            $schemas[] = $movie;
        }

        // Book — books category + book titles
        $book = $this->detect_book_schema( $post, $content, $content_type, $category );
        if ( $book ) {
            $schemas[] = $book;
        }

        // Dataset — content has data tables with numerical data
        $dataset = $this->detect_dataset_schema( $post, $content, $content_type );
        if ( $dataset ) {
            $schemas[] = $dataset;
        }

        // v1.5.135 — New content-detected schema types
        // Product — review/buying_guide/comparison with prices
        $product = $this->detect_product_schema( $post, $content, $content_type, $category );
        if ( $product ) {
            $schemas[] = $product;
        }

        // Organization — press_release/case_study/sponsored
        $org = $this->detect_organization_schema( $post, $content, $content_type );
        if ( $org ) {
            $schemas[] = $org;
        }

        // QAPage — interview/faq_page with Q&A pairs
        $qa = $this->detect_qa_schema( $post, $content, $content_type );
        if ( $qa ) {
            $schemas[] = $qa;
        }

        // ClaimReview / Fact Check — news/opinion with verification language
        $factcheck = $this->detect_factcheck_schema( $post, $content, $content_type );
        if ( $factcheck ) {
            $schemas[] = $factcheck;
        }

        // JobPosting — content with job listings
        $job = $this->detect_job_schema( $post, $content, $content_type );
        if ( $job ) {
            $schemas[] = $job;
        }

        // VacationRental / LodgingBusiness — travel content with accommodation
        $vacation = $this->detect_vacation_rental_schema( $post, $content, $content_type, $category );
        if ( $vacation ) {
            $schemas[] = $vacation;
        }

        // BreadcrumbList — always
        $schemas[] = $this->generate_breadcrumb_schema( $post );

        // Strip @context from individual schemas (caller wraps in single @graph)
        foreach ( $schemas as &$s ) {
            unset( $s['@context'] );
        }

        // v1.5.126 — Sanitize all string values in schema to prevent broken JSON.
        // WordPress wptexturize() converts escaped \" to unescaped smart quotes
        // when JSON-LD is stored inline in post_content. Replace literal double
        // quotes inside string values with single quotes to prevent this.
        $schemas = $this->sanitize_schema_strings( $schemas );

        return $schemas;
    }

    /**
     * Recursively replace double quotes with single quotes in all string values.
     * Prevents wptexturize from breaking inline JSON-LD.
     */
    private function sanitize_schema_strings( array $data ): array {
        foreach ( $data as $key => &$value ) {
            if ( is_string( $value ) ) {
                // Don't sanitize URLs or @type/@context values
                if ( $key !== '@type' && $key !== '@context' && $key !== '@id' && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
                    $value = str_replace( '"', "'", $value );
                }
            } elseif ( is_array( $value ) ) {
                $value = $this->sanitize_schema_strings( $value );
            }
        }
        return $data;
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
        $thumbnail = get_the_post_thumbnail_url( $post->ID, 'full' );

        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => $type,
            'headline'      => $post->post_title,
            'description'   => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ),
            'datePublished' => get_the_date( 'c', $post ),
            'dateModified'  => get_the_modified_date( 'c', $post ),
            'author'        => $this->safe_build_author( $post ),
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

        // v1.5.118 — Speakable for voice assistants (US English, news/blog content)
        if ( in_array( $type, [ 'BlogPosting', 'NewsArticle', 'OpinionNewsArticle', 'Article' ], true ) ) {
            $content_type_check = get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: 'blog_post';
            if ( in_array( $content_type_check, self::SPEAKABLE_TYPES, true ) ) {
                $schema['speakable'] = [
                    '@type'       => 'SpeakableSpecification',
                    'cssSelector' => [ 'h1', '.key-takeaways', 'h2 + p' ],
                ];
            }
        }

        // NewsArticle / OpinionNewsArticle need dateline
        if ( in_array( $type, [ 'NewsArticle', 'OpinionNewsArticle' ], true ) ) {
            $schema['articleSection'] = 'News';
        }

        // v1.5.192 — OpinionNewsArticle enrichment per GEO/AI-citation research:
        //   - `citation` = every outbound URL the article body cites
        //     (tells AI crawlers the piece is claim-backed, ~30-40% lift in
        //     generative-engine citation per Princeton GEO 2311.09735).
        //   - `backstory` = the content-type label ("Opinion")
        //     so AI models can disambiguate opinion from reporting.
        if ( $type === 'OpinionNewsArticle' ) {
            $urls = $this->extract_outbound_urls( $post->post_content );
            if ( ! empty( $urls ) ) {
                $schema['citation'] = array_map( function ( $u ) {
                    return [ '@type' => 'CreativeWork', 'url' => $u ];
                }, $urls );
            }
            $schema['backstory'] = 'Opinion piece — reflects the author\'s personal views, not an objective news report.';
        }

        return $schema;
    }

    /**
     * v1.5.192 — Extract outbound URLs from post content for schema `citation`.
     * Collects every external http(s) URL found in markdown links, HTML anchors,
     * or `<a href>` tags, deduplicates, filters to external (non-site) hosts.
     * Returns up to 20 URLs (schema size cap — more is diminishing returns).
     *
     * @return string[] List of unique external URLs.
     */
    private function extract_outbound_urls( string $content ): array {
        $urls = [];
        // Match <a href="URL"> and [text](URL)
        if ( preg_match_all( '/href=["\'](https?:\/\/[^"\'\s]+)["\']/i', $content, $m1 ) ) {
            $urls = array_merge( $urls, $m1[1] );
        }
        if ( preg_match_all( '/\[[^\]]+\]\((https?:\/\/[^)]+)\)/', $content, $m2 ) ) {
            $urls = array_merge( $urls, $m2[1] );
        }
        if ( empty( $urls ) ) return [];

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $seen = [];
        $out = [];
        foreach ( $urls as $u ) {
            $u = trim( $u, " \t\n\r\0\x0B\"'" );
            $host = wp_parse_url( $u, PHP_URL_HOST );
            if ( ! $host || $host === $site_host ) continue;
            $key = strtolower( rtrim( preg_replace( '/[?#].*$/', '', $u ), '/' ) );
            if ( isset( $seen[ $key ] ) ) continue;
            $seen[ $key ] = true;
            $out[] = $u;
            if ( count( $out ) >= 20 ) break;
        }
        return $out;
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
                        'name'     => wp_trim_words( $step_text, 10, '' ),
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
     * v1.5.121 — Full Google-compliant Recipe schema matching their exact example.
     * Supports MULTIPLE recipes per article (each gets its own Recipe schema).
     * Extracts all fields Google recommends: name, image, author, description,
     * recipeCuisine, prepTime, cookTime, totalTime, keywords, recipeYield,
     * recipeCategory, recipeIngredient, recipeInstructions (with name + text + url).
     * NEVER hardcodes values — only includes what's extractable from content.
     */
    private function build_recipe( \WP_Post $post ): array {
        $content   = $post->post_content;
        $text      = wp_strip_all_tags( $content );
        $thumbnail = get_the_post_thumbnail_url( $post->ID, 'full' );
        $permalink = get_permalink( $post->ID );
        $keyword   = get_post_meta( $post->ID, '_seobetter_focus_keyword', true ) ?: '';
        $country   = get_post_meta( $post->ID, '_seobetter_country', true ) ?: '';

        // v1.5.145 — Full Person schema with E-E-A-T fields for recipes too
        $author_data = $this->safe_build_author( $post );

        // Map country code to cuisine name
        $cuisine_map = [
            'AU' => 'Australian', 'US' => 'American', 'GB' => 'British', 'FR' => 'French',
            'IT' => 'Italian', 'JP' => 'Japanese', 'IN' => 'Indian', 'MX' => 'Mexican',
            'TH' => 'Thai', 'CN' => 'Chinese', 'KR' => 'Korean', 'ES' => 'Spanish',
            'DE' => 'German', 'BR' => 'Brazilian', 'GR' => 'Greek', 'TR' => 'Turkish',
            'VN' => 'Vietnamese', 'IE' => 'Irish', 'NZ' => 'New Zealand',
        ];
        $cuisine = $cuisine_map[ strtoupper( $country ) ] ?? '';

        // Try to detect MULTIPLE recipes by splitting on H2 headings
        // Each recipe section: H2 name → content with ingredients + instructions
        preg_match_all( '/<h2[^>]*>(.*?)<\/h2>(.*?)(?=<h2|$)/is', $content, $sections );

        $recipes = [];
        $recipe_num = 0;

        if ( ! empty( $sections[1] ) ) {
            for ( $s = 0; $s < count( $sections[1] ); $s++ ) {
                $heading = wp_strip_all_tags( $sections[1][ $s ] );
                $body = $sections[2][ $s ];
                $body_text = wp_strip_all_tags( $body );

                // v1.5.126 — Skip non-recipe sections. Expanded pattern to catch
                // intro/context H2s that aren't actual recipes.
                if ( preg_match( '/^(key\s*takeaway|why\s+(this|homemade|making|you)|how\s+to\s+store|quick\s*comparison|what\s*(ingredient|to\s*avoid)|pros\s*(and|&)|cons|faq|frequently|reference|safety|comparison\s*table|nutrition|benefit|matter|getting\s*started)/i', $heading ) ) {
                    continue;
                }

                // Must have BOTH an ingredients list (<ul>) AND an instructions list (<ol>)
                // to qualify as a recipe section. Just having one is likely a non-recipe section
                // with a bullet list (e.g. "Why Homemade Treats Matter" with benefit bullets).
                $has_ingredients = (bool) preg_match( '/<ul[^>]*>.*?<\/ul>/is', $body );
                $has_instructions = (bool) preg_match( '/<ol[^>]*>.*?<\/ol>/is', $body );
                if ( ! $has_ingredients || ! $has_instructions ) continue;

                $recipe_num++;
                $recipe = [
                    '@type'         => 'Recipe',
                    'name'          => $heading,
                    'description'   => wp_trim_words( $body_text, 25 ),
                    'datePublished' => get_the_date( 'c', $post ),
                    'author'        => $author_data,
                ];

                // v1.5.122 — Image array with multiple sizes (Google wants 1:1, 4:3, 16:9)
                // WordPress stores images in multiple sizes. Use the largest available
                // and include the full URL for all 3 ratio slots (Google accepts same URL).
                $recipe_img = '';
                if ( $recipe_num === 1 && $thumbnail ) {
                    $recipe_img = $thumbnail;
                } elseif ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $body, $img_match ) ) {
                    $recipe_img = $img_match[1];
                } elseif ( $thumbnail ) {
                    $recipe_img = $thumbnail;
                }
                if ( $recipe_img ) {
                    // Google recommends 3 images in different aspect ratios.
                    // If we only have one image, provide it 3 times — Google accepts this
                    // and will crop/resize as needed for different surfaces.
                    $recipe['image'] = [ $recipe_img, $recipe_img, $recipe_img ];
                }

                // Keywords from focus keyword
                if ( $keyword ) {
                    $recipe['keywords'] = $keyword;
                }

                // Cuisine from country
                if ( $cuisine ) {
                    $recipe['recipeCuisine'] = $cuisine;
                }

                // Extract ingredients from <ul> lists in this section
                $ingredients = [];
                preg_match_all( '/<ul[^>]*>(.*?)<\/ul>/is', $body, $ul_matches );
                foreach ( $ul_matches[1] as $ul ) {
                    preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $ul, $li_matches );
                    foreach ( $li_matches[1] as $li ) {
                        $item = trim( wp_strip_all_tags( $li ) );
                        if ( strlen( $item ) > 2 && strlen( $item ) < 200 ) {
                            // v1.5.145 — Skip nutritional data (macros, calories, minerals)
                            // Catches: "135 Calories (per serving)", "237mg Sodium", "3g Protein",
                            // "41g Carbs", "2mg Iron", "79mg Potassium", "Total Sugars" etc.
                            if ( preg_match( '/^\d+\s*(g|mg|mcg|kcal|cal|%)?\s*(fat|carbs?|protein|calories?|cal|kcal|sodium|fiber|fibre|sugar|cholesterol|saturated|iron|calcium|potassium|vitamin|total\s+fat|total\s+sugar|trans\s+fat|dietary\s+fiber)/i', $item ) ) continue;
                            if ( preg_match( '/\b(calories?|kcal)\s*(\(|per\s)/i', $item ) ) continue;
                            if ( preg_match( '/^(total\s+)?(fat|carbs?|protein|sodium|sugar|fiber)\b/i', $item ) ) continue;
                            // Skip "Nutrition Facts", "Per Serving", "Servings:" headers
                            if ( preg_match( '/^(nutrition|per\s+serving|serving\s+size|daily\s+value)/i', $item ) ) continue;
                            // Skip pure numbers or very short items like "N/A"
                            if ( preg_match( '/^[\d\s.,%]+$/', $item ) ) continue;
                            $ingredients[] = $item;
                        }
                    }
                }
                if ( ! empty( $ingredients ) ) {
                    $recipe['recipeIngredient'] = array_slice( $ingredients, 0, 30 );
                }

                // Extract instructions from <ol> lists — with name + text + url per step
                // v1.5.172 — Skip <ol> lists that are References/Sources (citations polluting steps)
                $instructions = [];
                preg_match_all( '/<ol[^>]*>(.*?)<\/ol>/is', $body, $ol_matches );
                $step_num = 0;
                foreach ( $ol_matches[1] as $ol ) {
                    // Skip reference/citation lists (contain URLs or "Source" text)
                    if ( preg_match( '/<a\s+[^>]*href=["\']https?:\/\//i', $ol ) && substr_count( $ol, '<li' ) >= 2 ) {
                        $link_count = preg_match_all( '/href=["\']https?:\/\//', $ol );
                        $li_count = preg_match_all( '/<li/', $ol );
                        // If most items have links, this is a References list, not instructions
                        if ( $link_count >= $li_count * 0.6 ) continue;
                    }
                    preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $ol, $li_matches );
                    foreach ( $li_matches[1] as $li ) {
                        $step_text = trim( wp_strip_all_tags( $li ) );
                        if ( strlen( $step_text ) < 10 ) continue;
                        // Skip ingredient-like items
                        if ( preg_match( '/^\d+\s*(cup|tbsp|tsp|gram|ml|oz|lb|kg)\b/i', $step_text ) ) continue;
                        // v1.5.172 — Skip source citations that leaked into instructions
                        // Catches: "1Healing Chicken Bone Broth - Source Name", "2www.allrecipes.com"
                        if ( preg_match( '/^\d*\s*(https?:\/\/|www\.)/i', $step_text ) ) continue;
                        if ( preg_match( '/^\d+[A-Z].*\s[-–—]\s/', $step_text ) && strlen( $step_text ) < 80 ) continue;
                        $step_num++;
                        $step = [
                            '@type' => 'HowToStep',
                            'name'  => wp_trim_words( $step_text, 10, '' ),
                            'text'  => $step_text,
                            'url'   => $permalink . '#step' . $recipe_num . '-' . $step_num,
                        ];
                        $instructions[] = $step;
                    }
                }
                if ( ! empty( $instructions ) ) {
                    $recipe['recipeInstructions'] = $instructions;
                }

                // Extract times from this section's text
                // v1.5.145 — Broadened time extraction patterns
                // Matches: "Prep Time: 10 minutes", "Prep: 10 min", "Prep 10 mins", "Prep time 10 minutes"
                if ( preg_match( '/prep(?:aration)?\s*(?:time)?[\s:]*(\d+)\s*(?:min|minute|mins)/i', $body_text, $prep ) ) {
                    $recipe['prepTime'] = 'PT' . $prep[1] . 'M';
                }
                if ( preg_match( '/cook(?:ing)?\s*(?:time)?[\s:]*(\d+)\s*(?:min|minute|mins)/i', $body_text, $cook ) ) {
                    $recipe['cookTime'] = 'PT' . $cook[1] . 'M';
                }
                // v1.5.172 — Also detect hours (e.g. "Cook on Low for 24-48 hours")
                if ( empty( $recipe['cookTime'] ) && preg_match( '/(?:cook|simmer|slow\s*cook|braise)\b.*?(\d+)[\s-]*(?:to\s*(\d+)\s*)?(?:hour|hr)s?/i', $body_text, $cook_hr ) ) {
                    $hours = $cook_hr[2] ?? $cook_hr[1]; // Use upper bound if range
                    $recipe['cookTime'] = 'PT' . $hours . 'H';
                }
                if ( preg_match( '/total\s*(?:time)?[\s:]*(\d+)\s*(?:min|minute|mins)/i', $body_text, $total ) ) {
                    $recipe['totalTime'] = 'PT' . $total[1] . 'M';
                }
                // Also try "X minutes" near "bake" or "oven" if no explicit times found
                if ( empty( $recipe['cookTime'] ) && preg_match( '/(?:bake|oven|roast)\b.*?(\d+)[\s-]*(?:to\s*\d+\s*)?(?:min|minute|mins)/i', $body_text, $bake ) ) {
                    $recipe['cookTime'] = 'PT' . $bake[1] . 'M';
                }
                if ( preg_match( '/(?:yield|serve|serving|makes|portion)[\s:]*(\d+\s*(?:serving|piece|treat|cookie|batch|portion|loaf|loaves|slice|roll)[s]?)/i', $body_text, $yield ) ) {
                    $recipe['recipeYield'] = $yield[1];
                }
                // Also try "serves X" or "makes X"
                if ( empty( $recipe['recipeYield'] ) && preg_match( '/(?:serves|makes|yields?)\s*[\s:]*(\d+)/i', $body_text, $yield2 ) ) {
                    $recipe['recipeYield'] = $yield2[1] . ' servings';
                }

                // v1.5.172 — Broadened category detection + broth/stock/stew
                if ( preg_match( '/\b(treat|snack|meal|drink|dessert|breakfast|dinner|lunch|side dish|appetizer|main course|biscuit|bread|cake|pastry|pie|soup|broth|stock|stew|salad|sauce|dip|smoothie|cocktail)\b/i', $body_text, $cat ) ) {
                    $recipe['recipeCategory'] = ucfirst( strtolower( $cat[1] ) );
                }
                // v1.5.172 — Fallback: try heading for category
                if ( empty( $recipe['recipeCategory'] ) && preg_match( '/\b(soup|broth|stock|stew|cake|bread|pie|salad|smoothie|cocktail|snack|treat|meal)\b/i', $heading, $hcat ) ) {
                    $recipe['recipeCategory'] = ucfirst( strtolower( $hcat[1] ) );
                }

                // v1.5.122 — Nutrition extraction (only if stated in content)
                if ( preg_match( '/(\d+)\s*(?:calories|cal|kcal)\b/i', $body_text, $cal ) ) {
                    $recipe['nutrition'] = [
                        '@type'    => 'NutritionInformation',
                        'calories' => $cal[1] . ' calories',
                    ];
                }

                $recipes[] = $recipe;
                if ( $recipe_num >= 5 ) break; // Max 5 recipes per article
            }
        }

        // Fallback: if no per-section recipes found, build single recipe from whole article
        if ( empty( $recipes ) ) {
            $recipe = [
                '@type'         => 'Recipe',
                'name'          => $post->post_title,
                'description'   => wp_trim_words( $text, 30 ),
                'datePublished' => get_the_date( 'c', $post ),
                'author'        => $author_data,
            ];
            if ( $thumbnail ) $recipe['image'] = [ $thumbnail, $thumbnail, $thumbnail ];
            if ( $keyword ) $recipe['keywords'] = $keyword;
            if ( $cuisine ) $recipe['recipeCuisine'] = $cuisine;

            // Extract from full content (existing logic)
            $ingredients = [];
            preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $content, $all_li );
            foreach ( $all_li[1] as $li ) {
                $item = trim( wp_strip_all_tags( $li ) );
                if ( preg_match( '/\b(cup|cups|tbsp|tsp|teaspoon|tablespoon|gram|grams|ml|oz|ounce|pound|lb|kg|piece|clove|pinch)\b/i', $item ) ) {
                    // v1.5.137 — Skip nutritional macros
                    if ( preg_match( '/^\d+[gm]?\s*(fat|carbs?|protein|calories|cal|kcal|sodium|fiber|fibre|sugar)\s*$/i', $item ) ) continue;
                    $ingredients[] = $item;
                }
            }
            if ( ! empty( $ingredients ) ) $recipe['recipeIngredient'] = array_slice( $ingredients, 0, 30 );

            if ( preg_match( '/prep(?:aration)?\s*(?:time)?[\s:]+(\d+)\s*(?:min|minute)/i', $text, $prep ) ) $recipe['prepTime'] = 'PT' . $prep[1] . 'M';
            if ( preg_match( '/cook(?:ing)?\s*(?:time)?[\s:]+(\d+)\s*(?:min|minute)/i', $text, $cook ) ) $recipe['cookTime'] = 'PT' . $cook[1] . 'M';

            $recipes[] = $recipe;
        }

        // Return first recipe as primary (generate() handles adding others)
        // Store all recipes for the generate() method to add to @graph
        $this->_multi_recipes = $recipes;
        return $recipes[0];
    }

    /**
     * v1.5.136 — Review schema: Google-exact format with smart itemReviewed detection.
     *
     * Detects WHAT is being reviewed from content + category and uses the correct
     * Schema.org @type: Product, SoftwareApplication, Restaurant, LocalBusiness,
     * Book, Movie, MobileApplication, VideoGame, etc.
     *
     * Rating only included if extractable from content (never hardcoded).
     * Includes publisher, author.url, image on itemReviewed.
     * Pros/Cons extracted as positiveNotes/negativeNotes.
     */
    private function build_review( \WP_Post $post ): array {
        $thumbnail = get_the_post_thumbnail_url( $post->ID, 'full' );
        $text      = wp_strip_all_tags( $post->post_content );
        $content   = $post->post_content;
        $category  = get_post_meta( $post->ID, '_seobetter_domain', true ) ?: '';
        $country   = get_post_meta( $post->ID, '_seobetter_country', true ) ?: '';

        // Item name = title with common review words stripped
        $item_name = trim( preg_replace( '/\b(review|in-depth|honest|full|best|top|guide|comparison|vs\.?|versus|roundup|our|the)\b/i', '', $post->post_title ) );
        $item_name = trim( preg_replace( '/\s+/', ' ', $item_name ) );
        $item_name = trim( preg_replace( '/\b(20\d{2}|in)\b\s*[:.\-]*\s*$/i', '', $item_name ) );
        if ( strlen( $item_name ) < 3 ) $item_name = $post->post_title;

        // ── Smart itemReviewed type detection ──
        // v1.5.136 — Check the TITLE/KEYWORD first for clear type signals.
        // Title is the strongest signal for what the user is actually reviewing.
        // Only fall to content body analysis when title has no clear signal.
        $reviewed_type = 'Product'; // Default
        $reviewed_extra = [];
        $title_lower = strtolower( $post->post_title );
        $keyword_lower = strtolower( get_post_meta( $post->ID, '_seobetter_focus_keyword', true ) ?: '' );
        $title_and_kw = $title_lower . ' ' . $keyword_lower;

        // Title-first detection: if the title/keyword has "book", "movie", "app", etc.
        // these are unambiguous signals that override content analysis
        $title_override = '';
        if ( preg_match( '/\b(book|novel|memoir|autobiography)\b/i', $title_and_kw ) ) $title_override = 'Book';
        elseif ( preg_match( '/\b(movie|film)\b/i', $title_and_kw ) ) $title_override = 'Movie';
        elseif ( preg_match( '/\b(app|software|saas)\b/i', $title_and_kw ) ) $title_override = 'Software';
        elseif ( preg_match( '/\b(game|gaming|ps5|xbox|nintendo)\b/i', $title_and_kw ) ) $title_override = 'VideoGame';
        elseif ( preg_match( '/\b(restaurant|cafe|diner|bistro)\b/i', $title_and_kw ) ) $title_override = 'Restaurant';
        elseif ( preg_match( '/\b(course|class|bootcamp)\b/i', $title_and_kw ) ) $title_override = 'Course';

        // Software / App detection — only if title signals it OR category is tech
        if ( $title_override === 'Software'
            || ( ! $title_override && ( in_array( $category, [ 'technology', 'games' ], true )
            || preg_match( '/\b(software|SaaS|plugin|extension|desktop app|web app|mobile app)\b/i', $text ) ) ) ) {
            if ( preg_match( '/\b(ios|android|iphone|ipad|mobile app|play store|app store)\b/i', $text ) ) {
                $reviewed_type = 'MobileApplication';
                if ( preg_match( '/\b(ios|iphone|ipad)\b/i', $text ) ) $reviewed_extra['operatingSystem'] = 'iOS';
                elseif ( preg_match( '/\bandroid\b/i', $text ) ) $reviewed_extra['operatingSystem'] = 'Android';
            } else {
                $reviewed_type = 'SoftwareApplication';
                if ( preg_match( '/\b(windows|mac|linux|chrome|web)\b/i', $text, $os ) ) {
                    $reviewed_extra['operatingSystem'] = ucfirst( $os[1] );
                }
            }
            // App category
            if ( preg_match( '/\b(productivity|finance|health|fitness|education|entertainment|social|photo|video|music|business|utility|game)\b/i', $text, $appcat ) ) {
                $reviewed_extra['applicationCategory'] = ucfirst( $appcat[1] ) . 'Application';
            }
        }
        // Restaurant / Food detection
        elseif ( $title_override === 'Restaurant'
            || ( ! $title_override && ( in_array( $category, [ 'food' ], true )
            || preg_match( '/\b(restaurant|cafe|diner|bistro|eatery|pizzeria|bakery|bar|pub|takeaway|food truck|cuisine|menu|chef)\b/i', $text ) ) ) ) {
            $reviewed_type = 'Restaurant';
            if ( preg_match( '/\b(italian|chinese|japanese|thai|indian|mexican|french|korean|vietnamese|american|australian|mediterranean|seafood|vegan|vegetarian)\b/i', $text, $cuisine ) ) {
                $reviewed_extra['servesCuisine'] = ucfirst( $cuisine[1] );
            }
        }
        // Movie / TV detection
        elseif ( $title_override === 'Movie'
            || ( ! $title_override && ( in_array( $category, [ 'entertainment' ], true )
            || preg_match( '/\b(movie|film|cinema|director|starring|cast|imdb|rotten tomatoes|box office|screenplay|oscar|academy award)\b/i', $text ) ) ) ) {
            $reviewed_type = 'Movie';
            if ( preg_match( '/\bdirector[:\s]+([A-Z][a-z]+ [A-Z][a-z]+)/i', $text, $dir ) ) {
                $reviewed_extra['director'] = [ '@type' => 'Person', 'name' => $dir[1] ];
            }
        }
        // Book detection
        elseif ( $title_override === 'Book'
            || ( ! $title_override && ( in_array( $category, [ 'books' ], true )
            || preg_match( '/\b(novel|memoir|autobiography|paperback|hardcover|ebook|kindle|isbn|book review|bestseller|chapter[s]?\s+\d+)\b/i', $text ) ) ) ) {
            $reviewed_type = 'Book';
            if ( preg_match( '/\bauthor[:\s]+([A-Z][a-z]+ [A-Z][a-z]+)/i', $text, $ba ) ) {
                $reviewed_extra['author'] = [ '@type' => 'Person', 'name' => $ba[1] ];
            }
        }
        // Video Game detection
        elseif ( $title_override === 'VideoGame'
            || ( ! $title_override && preg_match( '/\b(video game|gaming|playstation|xbox|nintendo|steam|pc game|console|fps|rpg|mmorpg|multiplayer|esports)\b/i', $text ) ) ) {
            $reviewed_type = 'VideoGame';
            if ( preg_match( '/\b(playstation|ps[45]|xbox|nintendo|pc|steam)\b/i', $text, $plat ) ) {
                $reviewed_extra['gamePlatform'] = $plat[1];
            }
        }
        // Local Business detection (has address)
        elseif ( preg_match( '/\d+\s+[A-Z][a-z]+\s+(St|Rd|Ave|Blvd|Dr|Street|Road|Avenue|Drive)\b/i', $text ) ) {
            $reviewed_type = 'LocalBusiness';
        }
        // Course / Education — requires education category OR specific course platform names
        // (NOT just "training" or "class" which appear in many non-education articles like dog breeding)
        elseif ( in_array( $category, [ 'education' ], true )
            || preg_match( '/\b(udemy|coursera|skillshare|masterclass|bootcamp|online course|certification program|learning path)\b/i', $text ) ) {
            $reviewed_type = 'Course';
            if ( preg_match( '/\b(?:by|from|offered by|provider)\s+([A-Z][a-zA-Z\s]{3,30})\b/', $text, $prov ) ) {
                $reviewed_extra['provider'] = [ '@type' => 'Organization', 'name' => trim( $prov[1] ) ];
            }
        }
        // Event — requires specific event words (NOT "show" which matches dog shows, TV shows, etc.)
        // Must also have a date pattern, not just a year in the title
        elseif ( preg_match( '/\b(conference|concert|festival|summit|expo|hackathon|meetup|symposium|trade show|convention)\b/i', $text )
            && preg_match( '/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2}/i', $text ) ) {
            $reviewed_type = 'Event';
        }

        // ── Build the itemReviewed object ──
        $item_reviewed = array_merge(
            [ '@type' => $reviewed_type, 'name' => $item_name ],
            $reviewed_extra
        );

        // Add image to itemReviewed
        if ( $thumbnail ) {
            $item_reviewed['image'] = $thumbnail;
        }

        // Add price for Product/Software if detected
        if ( in_array( $reviewed_type, [ 'Product', 'SoftwareApplication', 'MobileApplication' ], true ) ) {
            if ( preg_match( '/[\$\£\€\¥]\s*([\d,]+(?:\.\d{2})?)/i', $text, $price ) ) {
                $currency = 'USD';
                if ( preg_match( '/\£/', $text ) ) $currency = 'GBP';
                elseif ( preg_match( '/\€/', $text ) ) $currency = 'EUR';
                elseif ( preg_match( '/AUD/i', $text ) || $country === 'AU' ) $currency = 'AUD';
                elseif ( preg_match( '/CAD/i', $text ) || $country === 'CA' ) $currency = 'CAD';
                elseif ( preg_match( '/NZD/i', $text ) || $country === 'NZ' ) $currency = 'NZD';
                elseif ( preg_match( '/\¥|JPY/i', $text ) || $country === 'JP' ) $currency = 'JPY';

                $item_reviewed['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => str_replace( ',', '', $price[1] ),
                    'priceCurrency' => $currency,
                    'availability'  => 'https://schema.org/InStock',
                ];
            }
        }

        // Add priceRange for Restaurant/LocalBusiness
        if ( in_array( $reviewed_type, [ 'Restaurant', 'LocalBusiness' ], true ) ) {
            if ( preg_match( '/(\$\$?\$?\$?)/', $text, $pr ) ) {
                $item_reviewed['priceRange'] = $pr[1];
            }
        }

        // Add address for Restaurant/LocalBusiness if detected
        if ( in_array( $reviewed_type, [ 'Restaurant', 'LocalBusiness' ], true ) ) {
            if ( preg_match( '/(\d+\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?\s+(?:St|Rd|Ave|Blvd|Dr|Street|Road|Avenue|Drive|Place|Lane|Way|Circuit|Parade|Crescent))\b/i', $text, $addr ) ) {
                $address = [ '@type' => 'PostalAddress', 'streetAddress' => $addr[1] ];
                // Try to detect country from post meta
                $country_map = [
                    'AU' => 'AU', 'US' => 'US', 'GB' => 'GB', 'CA' => 'CA', 'NZ' => 'NZ',
                    'IE' => 'IE', 'DE' => 'DE', 'FR' => 'FR', 'JP' => 'JP', 'IN' => 'IN',
                ];
                if ( ! empty( $country ) && isset( $country_map[ $country ] ) ) {
                    $address['addressCountry'] = $country_map[ $country ];
                }
                $item_reviewed['address'] = $address;
            }
            // Phone
            if ( preg_match( '/(?:phone|tel|call)[:\s]+([+\d\s\-()]{8,20})/i', $text, $phone ) ) {
                $item_reviewed['telephone'] = trim( $phone[1] );
            }
        }

        // ── Build the Review schema ──
        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Review',
            'name'          => $post->post_title,
            'description'   => wp_trim_words( $text, 30 ),
            'datePublished' => get_the_date( 'c', $post ),
            'dateModified'  => get_the_modified_date( 'c', $post ),
            'author'        => $this->safe_build_author( $post ),
            'publisher'     => [
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
            ],
            'itemReviewed'  => $item_reviewed,
        ];

        if ( $thumbnail ) {
            $schema['image'] = [ $thumbnail, $thumbnail, $thumbnail ];
        }

        // ── Extract rating ONLY if present in content ──
        if ( preg_match( '/(?:rating|score|verdict|grade|we give (?:it )?|overall)\s*[:=]?\s*(\d+(?:\.\d+)?)\s*(?:\/|out of)\s*(\d+)/i', $text, $rating_match ) ) {
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

        // ── Pros/Cons as positiveNotes/negativeNotes ──
        // v1.5.136 — Multiple extraction strategies:
        // 1. Separate H3 Pros / H3 Cons headings
        // 2. Combined H2 "Pros and Cons" with styled boxes (Content_Formatter output)
        // 3. Green/red styled boxes detected by background color
        $pros = [];
        $cons = [];

        // Strategy 1: H3 "Pros" heading
        if ( preg_match( '/<h[2-4][^>]*>\s*Pros\s*<\/h[2-4]>(.*?)(?=<h[2-4]|$)/is', $content, $pros_match ) ) {
            preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $pros_match[1], $li );
            foreach ( $li[1] as $item ) {
                $t = trim( wp_strip_all_tags( $item ) );
                if ( strlen( $t ) > 3 ) $pros[] = $t;
            }
        }
        if ( preg_match( '/<h[2-4][^>]*>\s*Cons\s*<\/h[2-4]>(.*?)(?=<h[2-4]|$)/is', $content, $cons_match ) ) {
            preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $cons_match[1], $li );
            foreach ( $li[1] as $item ) {
                $t = trim( wp_strip_all_tags( $item ) );
                if ( strlen( $t ) > 3 ) $cons[] = $t;
            }
        }

        // Strategy 2: Styled boxes — green (#f0fdf4) for pros, red (#fef2f2) for cons
        if ( empty( $pros ) ) {
            if ( preg_match( '/<div[^>]*background\s*:\s*#f0fdf4[^>]*>(.*?)<\/div>/is', $content, $pm ) ) {
                preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $pm[1], $li );
                foreach ( $li[1] as $item ) {
                    $t = trim( wp_strip_all_tags( $item ) );
                    if ( strlen( $t ) > 3 ) $pros[] = $t;
                }
            }
        }
        if ( empty( $cons ) ) {
            if ( preg_match( '/<div[^>]*background\s*:\s*#fef2f2[^>]*>(.*?)<\/div>/is', $content, $cm ) ) {
                preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $cm[1], $li );
                foreach ( $li[1] as $item ) {
                    $t = trim( wp_strip_all_tags( $item ) );
                    if ( strlen( $t ) > 3 ) $cons[] = $t;
                }
            }
        }

        // Strategy 3: "Pros and Cons" H2 with all list items split by PROS/CONS labels
        if ( empty( $pros ) && empty( $cons ) ) {
            if ( preg_match( '/<h2[^>]*>[^<]*Pros\s*(?:and|&amp;|&)\s*Cons[^<]*<\/h2>(.*?)(?=<h2|$)/is', $content, $pc ) ) {
                $section = $pc[1];
                // Split at the CONS divider (red box or heading)
                $parts = preg_split( '/<div[^>]*#fef2f2|<[^>]*>\s*Cons\s*<\//is', $section, 2 );
                if ( count( $parts ) >= 2 ) {
                    preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $parts[0], $li );
                    foreach ( $li[1] as $item ) {
                        $t = trim( wp_strip_all_tags( $item ) );
                        if ( strlen( $t ) > 3 ) $pros[] = $t;
                    }
                    preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $parts[1], $li );
                    foreach ( $li[1] as $item ) {
                        $t = trim( wp_strip_all_tags( $item ) );
                        if ( strlen( $t ) > 3 ) $cons[] = $t;
                    }
                }
            }
        }
        if ( ! empty( $pros ) ) {
            $schema['positiveNotes'] = [
                '@type' => 'ItemList',
                'itemListElement' => array_map( function( $text, $i ) {
                    return [ '@type' => 'ListItem', 'position' => $i + 1, 'name' => $text ];
                }, array_slice( $pros, 0, 5 ), array_keys( array_slice( $pros, 0, 5 ) ) ),
            ];
        }
        if ( ! empty( $cons ) ) {
            $schema['negativeNotes'] = [
                '@type' => 'ItemList',
                'itemListElement' => array_map( function( $text, $i ) {
                    return [ '@type' => 'ListItem', 'position' => $i + 1, 'name' => $text ];
                }, array_slice( $cons, 0, 5 ), array_keys( array_slice( $cons, 0, 5 ) ) ),
            ];
        }

        return $schema;
    }

    /**
     * v1.5.118 — Generate LocalBusiness schemas from content with addresses.
     * Auto-detects businesses with street addresses in the article.
     */
    private function generate_localbusiness_schemas( \WP_Post $post ): array {
        $content = $post->post_content;
        $text = wp_strip_all_tags( $content );
        $schemas = [];

        // Look for address patterns: "123 Main St" or "42 Smith Road"
        // Match H2 heading followed by content containing an address
        preg_match_all( '/<h2[^>]*>(.*?)<\/h2>(.*?)(?=<h2|$)/is', $content, $sections );
        if ( empty( $sections[1] ) ) return [];

        for ( $i = 0; $i < count( $sections[1] ); $i++ ) {
            $heading = wp_strip_all_tags( $sections[1][ $i ] );
            $body = $sections[2][ $i ];
            $body_text = wp_strip_all_tags( $body );

            // Must contain a street address pattern
            if ( ! preg_match( '/\d+\s+[A-Z][a-z]+\s+(St|Rd|Ave|Blvd|Dr|Ln|Way|Hwy|Cres|Pde|Street|Road|Avenue|Drive|Place|Circuit)\b/i', $body_text ) ) {
                continue;
            }

            // Skip generic headings
            if ( preg_match( '/^(key takeaway|faq|frequently|reference|pros|cons|introduction|conclusion)/i', $heading ) ) {
                continue;
            }

            $business = [
                '@type'   => 'LocalBusiness',
                'name'    => $heading,
                'address' => [
                    '@type'          => 'PostalAddress',
                    'streetAddress'  => '', // Will be populated from content
                ],
            ];

            // Try to extract full address
            if ( preg_match( '/(\d+\s+[A-Za-z\s]+(?:St|Rd|Ave|Blvd|Dr|Ln|Way|Hwy|Cres|Pde|Street|Road|Avenue|Drive|Place|Circuit)[^,]*)/i', $body_text, $addr ) ) {
                $business['address']['streetAddress'] = trim( $addr[1] );
            }

            // Extract phone if present
            if ( preg_match( '/(?:\+?\d{1,3}[-.\s]?)?\(?\d{2,4}\)?[-.\s]?\d{3,4}[-.\s]?\d{3,4}/', $body_text, $phone ) ) {
                $business['telephone'] = trim( $phone[0] );
            }

            // Extract URL if present
            if ( preg_match( '/https?:\/\/[^\s<"]+/', $body, $url_match ) ) {
                $business['url'] = $url_match[0];
            }

            $schemas[] = $business;
            if ( count( $schemas ) >= 10 ) break; // Cap at 10 businesses
        }

        return $schemas;
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

            // v1.5.126 — Require a question mark to be a FAQ question.
            // Previously matched any heading starting with "What/How/Why" etc.,
            // which falsely detected content H2s like "Why Homemade Treats Matter"
            // and "What Ingredients to Avoid" as FAQ questions.
            $is_question = preg_match( '/\?\s*$/', $heading_text );
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
            if ( preg_match( '/^(introduction|conclusion|faq|frequently asked|summary|final thoughts|key takeaway|pros|cons|reference|quick comparison)/i', $name ) ) {
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

    // ================================================================
    // v1.5.119 — Content-detected schema builders
    // These scan the HTML content for patterns and generate schemas
    // automatically. Triggered by category + content type + content.
    // ================================================================

    /**
     * VideoObject — detect embedded YouTube/Vimeo/HTML5 video.
     */
    private function detect_video_schema( \WP_Post $post, string $content ): ?array {
        // YouTube embed
        if ( preg_match( '/(?:youtube\.com\/embed\/|youtu\.be\/|youtube\.com\/watch\?v=)([a-zA-Z0-9_-]{11})/', $content, $yt ) ) {
            $video_id = $yt[1];
            return [
                '@type'        => 'VideoObject',
                'name'         => $post->post_title,
                'description'  => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
                'thumbnailUrl' => "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg",
                'uploadDate'   => get_the_date( 'c', $post ),
                'embedUrl'     => "https://www.youtube.com/embed/{$video_id}",
                'contentUrl'   => "https://www.youtube.com/watch?v={$video_id}",
            ];
        }
        // Vimeo embed
        if ( preg_match( '/vimeo\.com\/(?:video\/)?(\d+)/', $content, $vm ) ) {
            return [
                '@type'        => 'VideoObject',
                'name'         => $post->post_title,
                'description'  => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
                'thumbnailUrl' => [],
                'uploadDate'   => get_the_date( 'c', $post ),
                'embedUrl'     => "https://player.vimeo.com/video/{$vm[1]}",
            ];
        }
        return null;
    }

    /**
     * SoftwareApplication — tech/business/ecommerce + app/software mentions.
     */
    private function detect_software_schema( \WP_Post $post, string $content, string $content_type, string $category ): ?array {
        $tech_cats = [ 'technology', 'business', 'ecommerce', 'blockchain', 'cryptocurrency' ];
        $review_types = [ 'review', 'comparison', 'buying_guide', 'tech_article', 'listicle' ];
        if ( ! in_array( $category, $tech_cats, true ) || ! in_array( $content_type, $review_types, true ) ) {
            return null;
        }
        $text = wp_strip_all_tags( $content );
        // Must mention software/app/platform/tool/SaaS
        if ( ! preg_match( '/\b(app|software|platform|tool|SaaS|application|plugin|extension)\b/i', $text ) ) {
            return null;
        }
        $schema = [
            '@type'               => 'SoftwareApplication',
            'name'                => $post->post_title,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem'     => 'Web',
            'offers'              => [
                '@type'         => 'Offer',
                'price'         => '0',
                'priceCurrency' => 'USD',
            ],
        ];
        // Extract rating if present
        if ( preg_match( '/(?:rating|score)[\s:]+(\d+(?:\.\d+)?)\s*(?:\/|out of)\s*(\d+)/i', $text, $r ) ) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $r[1],
                'bestRating'  => $r[2],
                'ratingCount' => '1',
            ];
        }
        return $schema;
    }

    /**
     * Event — content has future date + location + event-like heading.
     */
    private function detect_event_schema( \WP_Post $post, string $content, string $content_type ): ?array {
        $text = wp_strip_all_tags( $content );
        // Must have a date pattern AND a location pattern
        if ( ! preg_match( '/\b(20[2-3]\d[-\/]\d{1,2}[-\/]\d{1,2}|\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+20[2-3]\d)\b/i', $text, $date_match ) ) {
            return null;
        }
        if ( ! preg_match( '/\bat\s+([A-Z][a-zA-Z\s]+(?:Center|Centre|Arena|Stadium|Hall|Theater|Theatre|Convention|Hotel|Park|Venue))/i', $text, $loc_match ) ) {
            return null;
        }
        return [
            '@type'     => 'Event',
            'name'      => $post->post_title,
            'startDate' => $date_match[0],
            'location'  => [
                '@type' => 'Place',
                'name'  => trim( $loc_match[1] ),
            ],
            'description' => wp_trim_words( $text, 30 ),
            'url'         => get_permalink( $post->ID ),
        ];
    }

    /**
     * ImageObject — license metadata for article images.
     */
    private function detect_image_schemas( \WP_Post $post, string $content ): array {
        $schemas = [];
        preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>/i', $content, $imgs );
        if ( empty( $imgs[1] ) ) return [];

        $site_name = get_bloginfo( 'name' );
        foreach ( array_slice( $imgs[1], 0, 5 ) as $i => $src ) {
            $alt = $imgs[2][ $i ] ?? '';
            if ( strlen( $alt ) < 5 ) continue;
            $schemas[] = [
                '@type'            => 'ImageObject',
                'contentUrl'       => $src,
                'creditText'       => $site_name,
                'creator'          => [
                    '@type' => 'Organization',
                    'name'  => $site_name,
                ],
                'copyrightNotice'  => $site_name . ' ' . wp_date( 'Y' ),
            ];
        }
        return $schemas;
    }

    /**
     * ProfilePage — interview/personal_essay with person bio.
     */
    private function detect_profile_schema( \WP_Post $post, string $content, string $content_type ): ?array {
        if ( ! in_array( $content_type, [ 'interview', 'personal_essay' ], true ) ) {
            return null;
        }
        $text = wp_strip_all_tags( $content );
        // Look for a person name pattern (2-3 capitalized words)
        if ( ! preg_match( '/\b([A-Z][a-z]+ [A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b/', $text, $name ) ) {
            return null;
        }
        // Must have bio-like content nearby
        if ( ! preg_match( '/\b(CEO|founder|director|author|expert|professor|Dr\.|coach|manager|specialist|professional)\b/i', $text ) ) {
            return null;
        }
        return [
            '@type'      => 'ProfilePage',
            'dateCreated'  => get_the_date( 'c', $post ),
            'dateModified' => get_the_modified_date( 'c', $post ),
            'mainEntity' => [
                '@type'       => 'Person',
                'name'        => $name[1],
                'description' => wp_trim_words( $text, 20 ),
                'url'         => get_permalink( $post->ID ),
            ],
        ];
    }

    /**
     * Course — education/tech category + course/lesson/module structure.
     */
    private function detect_course_schema( \WP_Post $post, string $content, string $content_type, string $category ): ?array {
        if ( ! in_array( $category, [ 'education', 'technology' ], true ) ) {
            return null;
        }
        if ( ! in_array( $content_type, [ 'how_to', 'tech_article', 'listicle', 'pillar_guide' ], true ) ) {
            return null;
        }
        $text = wp_strip_all_tags( $content );
        if ( ! preg_match( '/\b(course|lesson|module|curriculum|certification|training program)\b/i', $text ) ) {
            return null;
        }
        return [
            '@type'       => 'Course',
            'name'        => $post->post_title,
            'description' => wp_trim_words( $text, 30 ),
            'provider'    => [
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
                'url'   => home_url(),
            ],
            'url'         => get_permalink( $post->ID ),
        ];
    }

    /**
     * Movie — entertainment category + movie titles.
     */
    private function detect_movie_schema( \WP_Post $post, string $content, string $content_type, string $category ): ?array {
        if ( $category !== 'entertainment' ) return null;
        if ( ! in_array( $content_type, [ 'review', 'comparison', 'listicle', 'blog_post' ], true ) ) {
            return null;
        }
        $text = wp_strip_all_tags( $content );
        // Look for movie-like mentions: quoted titles or "directed by"
        if ( ! preg_match( '/\b(directed by|starring|box office|screenplay|cinematography|film|movie)\b/i', $text ) ) {
            return null;
        }
        // Extract movie name from title
        $movie_name = preg_replace( '/\b(review|in-depth|comparison|best|top|guide|20\d{2})\b/i', '', $post->post_title );
        $movie_name = trim( preg_replace( '/\s+/', ' ', $movie_name ) );

        $schema = [
            '@type'       => 'Movie',
            'name'        => $movie_name ?: $post->post_title,
            'description' => wp_trim_words( $text, 30 ),
            'url'         => get_permalink( $post->ID ),
        ];
        // Extract director if mentioned
        if ( preg_match( '/directed by\s+([A-Z][a-z]+ [A-Z][a-z]+)/i', $text, $dir ) ) {
            $schema['director'] = [ '@type' => 'Person', 'name' => $dir[1] ];
        }
        // Extract rating if present
        if ( preg_match( '/(?:rating|score)[\s:]+(\d+(?:\.\d+)?)\s*(?:\/|out of)\s*(\d+)/i', $text, $r ) ) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $r[1],
                'bestRating'  => $r[2],
                'ratingCount' => '1',
            ];
        }
        return $schema;
    }

    /**
     * Book — books category + book titles.
     */
    private function detect_book_schema( \WP_Post $post, string $content, string $content_type, string $category ): ?array {
        if ( $category !== 'books' ) return null;
        if ( ! in_array( $content_type, [ 'review', 'listicle', 'blog_post', 'comparison' ], true ) ) {
            return null;
        }
        $text = wp_strip_all_tags( $content );
        if ( ! preg_match( '/\b(author|published|publisher|ISBN|paperback|hardcover|novel|chapter|pages)\b/i', $text ) ) {
            return null;
        }
        $book_name = preg_replace( '/\b(review|best|top|guide|20\d{2})\b/i', '', $post->post_title );
        $book_name = trim( preg_replace( '/\s+/', ' ', $book_name ) );

        $schema = [
            '@type' => 'Book',
            'name'  => $book_name ?: $post->post_title,
            'url'   => get_permalink( $post->ID ),
        ];
        if ( preg_match( '/\bby\s+([A-Z][a-z]+ [A-Z][a-z]+)/i', $text, $auth ) ) {
            $schema['author'] = [ '@type' => 'Person', 'name' => $auth[1] ];
        }
        return $schema;
    }

    /**
     * Dataset — content has data tables with numerical data.
     */
    private function detect_dataset_schema( \WP_Post $post, string $content, string $content_type ): ?array {
        if ( ! in_array( $content_type, [ 'white_paper', 'scholarly_article', 'tech_article', 'blog_post', 'news_article' ], true ) ) {
            return null;
        }
        // Must have a <table> with numerical data
        if ( ! preg_match( '/<table[^>]*>.*?<\/table>/is', $content ) ) {
            return null;
        }
        $text = wp_strip_all_tags( $content );
        // Must mention data/research/statistics/survey
        if ( ! preg_match( '/\b(dataset|data set|survey results|research data|statistical|findings|sample size)\b/i', $text ) ) {
            return null;
        }
        return [
            '@type'       => 'Dataset',
            'name'        => $post->post_title . ' - Data',
            'description' => wp_trim_words( $text, 30 ),
            'url'         => get_permalink( $post->ID ),
            'creator'     => [
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
                'url'   => home_url(),
            ],
            'datePublished' => get_the_date( 'c', $post ),
            'license'       => 'https://creativecommons.org/licenses/by/4.0/',
        ];
    }

    // ============================================================
    // v1.5.135 — New content-detected schema types
    // ============================================================

    /**
     * Product schema — detected in review, buying_guide, comparison, sponsored.
     * Only fires when content mentions specific product names with prices or ratings.
     */
    private function detect_product_schema( \WP_Post $post, string $content, string $content_type, string $category ): ?array {
        if ( ! in_array( $content_type, [ 'review', 'buying_guide', 'comparison', 'sponsored', 'listicle' ], true ) ) {
            return null;
        }
        $text = wp_strip_all_tags( $content );

        // Must mention price or cost patterns
        $has_price = preg_match( '/[\$\£\€\¥]\s*\d+[\d,\.]*|\d+[\d,\.]*\s*(USD|AUD|GBP|EUR|dollars|pounds)/i', $text, $price_match );
        if ( ! $has_price ) return null;

        // Extract product name from title (strip "review", "best", "top", "vs" etc.)
        $product_name = trim( preg_replace( '/\b(review|best|top|vs\.?|versus|guide|comparison|buying|in\s+20\d{2})\b/i', '', $post->post_title ) );
        $product_name = trim( preg_replace( '/\s+/', ' ', $product_name ) );
        if ( strlen( $product_name ) < 3 ) $product_name = $post->post_title;

        $schema = [
            '@type'       => 'Product',
            'name'        => $product_name,
            'description' => wp_trim_words( $text, 30 ),
            'url'         => get_permalink( $post->ID ),
        ];

        // Extract price
        if ( $has_price ) {
            $price_val = preg_replace( '/[^\d\.]/', '', $price_match[0] );
            $schema['offers'] = [
                '@type'         => 'Offer',
                'price'         => $price_val,
                'priceCurrency' => preg_match( '/\£|GBP/i', $price_match[0] ) ? 'GBP'
                    : ( preg_match( '/\€|EUR/i', $price_match[0] ) ? 'EUR'
                    : ( preg_match( '/AUD/i', $price_match[0] ) ? 'AUD' : 'USD' ) ),
                'availability'  => 'https://schema.org/InStock',
            ];
        }

        // Image
        $thumb = get_the_post_thumbnail_url( $post->ID, 'full' );
        if ( $thumb ) $schema['image'] = $thumb;

        return $schema;
    }

    /**
     * Organization schema — detected in press_release, case_study, sponsored.
     * Auto-adds publisher Organization for articles that mention companies.
     */
    private function detect_organization_schema( \WP_Post $post, string $content, string $content_type ): ?array {
        if ( ! in_array( $content_type, [ 'press_release', 'case_study', 'sponsored', 'interview' ], true ) ) {
            return null;
        }
        return [
            '@type' => 'Organization',
            'name'  => get_bloginfo( 'name' ),
            'url'   => home_url(),
            'logo'  => [
                '@type'  => 'ImageObject',
                'url'    => get_site_icon_url( 512 ) ?: '',
            ],
        ];
    }

    /**
     * QAPage schema — for interview and faq_page types.
     * Single best-answer format (different from FAQPage which has multiple Q&As).
     */
    private function detect_qa_schema( \WP_Post $post, string $content, string $content_type ): ?array {
        if ( ! in_array( $content_type, [ 'interview', 'faq_page' ], true ) ) {
            return null;
        }
        // Find the first Q&A pair (question heading + answer paragraph)
        if ( ! preg_match( '/<h[2-3][^>]*>(.*?\?)\s*<\/h[2-3]>\s*(?:<[^>]+>)*\s*<p[^>]*>(.*?)<\/p>/is', $content, $qa ) ) {
            return null;
        }
        $question = wp_strip_all_tags( $qa[1] );
        $answer = wp_strip_all_tags( $qa[2] );
        if ( strlen( $answer ) < 20 ) return null;

        return [
            '@type'      => 'QAPage',
            'mainEntity' => [
                '@type'          => 'Question',
                'name'           => $question,
                'text'           => $question,
                'answerCount'    => 1,
                'dateCreated'    => get_the_date( 'c', $post ),
                'acceptedAnswer' => [
                    '@type'       => 'Answer',
                    'text'        => $answer,
                    'dateCreated' => get_the_date( 'c', $post ),
                    'upvoteCount' => 0,
                    'author'      => [
                        '@type' => 'Person',
                        'name'  => $this->get_author_name( $post ),
                    ],
                ],
            ],
        ];
    }

    /**
     * ClaimReview / Fact Check schema — for news and opinion articles
     * that contain claim verification language.
     */
    private function detect_factcheck_schema( \WP_Post $post, string $content, string $content_type ): ?array {
        // v1.5.192 — Removed 'opinion' from ClaimReview eligibility.
        // Per Google's ClaimReview policy, ClaimReview is for fact-checking
        // someone else's claim, NOT for an author's own opinions. Emitting
        // ClaimReview on an op-ed risks a manual action.
        if ( ! in_array( $content_type, [ 'news_article', 'blog_post', 'scholarly_article' ], true ) ) {
            return null;
        }
        $text = wp_strip_all_tags( $content );

        // Must contain fact-check language
        if ( ! preg_match( '/\b(fact[- ]check|claim|verdict|rating|true|false|mostly true|mostly false|misleading|unproven)\b/i', $text ) ) {
            return null;
        }
        // Must have a claim + verdict pattern
        if ( ! preg_match( '/claim[:\s]+["\']?(.{20,150})["\']?/i', $text, $claim_match ) ) {
            return null;
        }

        // Try to extract verdict
        $verdict = 'Unrated';
        if ( preg_match( '/verdict[:\s]+(.{5,50})/i', $text, $v ) ) {
            $verdict = trim( $v[1] );
        } elseif ( preg_match( '/\b(true|false|mostly true|mostly false|misleading|partly true|unproven)\b/i', $text, $v ) ) {
            $verdict = ucfirst( $v[1] );
        }

        return [
            '@type'         => 'ClaimReview',
            'url'           => get_permalink( $post->ID ),
            'datePublished' => get_the_date( 'c', $post ),
            'author'        => [
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
                'url'   => home_url(),
            ],
            'claimReviewed' => trim( $claim_match[1] ),
            'reviewRating'  => [
                '@type'       => 'Rating',
                'ratingValue' => $verdict,
                'bestRating'  => 'True',
                'worstRating' => 'False',
                'alternateName' => $verdict,
            ],
        ];
    }

    /**
     * JobPosting schema — detected when content contains job/career listings.
     */
    private function detect_job_schema( \WP_Post $post, string $content, string $content_type ): ?array {
        $text = wp_strip_all_tags( $content );

        // Must have job-related patterns
        if ( ! preg_match( '/\b(job\s*posting|career|hiring|apply\s*now|salary|compensation|full[- ]time|part[- ]time|remote\s*position)\b/i', $text ) ) {
            return null;
        }
        // Must have a salary/pay mention
        if ( ! preg_match( '/[\$\£\€]\s*[\d,]+|salary|compensation|pay\s*range/i', $text ) ) {
            return null;
        }

        // Extract job title from first heading or title
        $job_title = $post->post_title;
        if ( preg_match( '/<h2[^>]*>(.*?(?:position|role|job|career|hiring).*?)<\/h2>/is', $content, $jt ) ) {
            $job_title = wp_strip_all_tags( $jt[1] );
        }

        $schema = [
            '@type'           => 'JobPosting',
            'title'           => $job_title,
            'description'     => wp_trim_words( $text, 50 ),
            'datePosted'      => get_the_date( 'c', $post ),
            'hiringOrganization' => [
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
                'url'   => home_url(),
            ],
        ];

        // Salary
        if ( preg_match( '/[\$\£\€]\s*([\d,]+)(?:\s*[-–]\s*[\$\£\€]?\s*([\d,]+))?/i', $text, $sal ) ) {
            $schema['baseSalary'] = [
                '@type'    => 'MonetaryAmount',
                'currency' => 'USD',
                'value'    => [
                    '@type'    => 'QuantitativeValue',
                    'minValue' => (int) str_replace( ',', '', $sal[1] ),
                    'maxValue' => ! empty( $sal[2] ) ? (int) str_replace( ',', '', $sal[2] ) : (int) str_replace( ',', '', $sal[1] ),
                    'unitText' => 'YEAR',
                ],
            ];
        }

        // Employment type
        if ( preg_match( '/\b(full[- ]time)\b/i', $text ) ) $schema['employmentType'] = 'FULL_TIME';
        elseif ( preg_match( '/\b(part[- ]time)\b/i', $text ) ) $schema['employmentType'] = 'PART_TIME';
        elseif ( preg_match( '/\b(contract)\b/i', $text ) ) $schema['employmentType'] = 'CONTRACTOR';

        return $schema;
    }

    /**
     * VacationRental schema — for travel/places content with accommodation.
     */
    private function detect_vacation_rental_schema( \WP_Post $post, string $content, string $content_type, string $category ): ?array {
        if ( ! in_array( $category, [ 'transportation', 'general' ], true ) && $content_type !== 'listicle' && $content_type !== 'review' ) {
            return null;
        }
        $text = wp_strip_all_tags( $content );

        // Must mention accommodation types
        if ( ! preg_match( '/\b(vacation\s*rental|airbnb|vrbo|holiday\s*home|beach\s*house|cabin|villa|cottage|chalet|lodge|holiday\s*let|serviced\s*apartment)\b/i', $text ) ) {
            return null;
        }

        // Must mention price/night or booking
        if ( ! preg_match( '/\b(per\s*night|\/night|book(?:ing)?|nightly\s*rate|from\s*[\$\£\€]\d+)\b/i', $text ) ) {
            return null;
        }

        // Extract property name from title
        $name = $post->post_title;

        $schema = [
            '@type'         => 'LodgingBusiness',
            'name'          => $name,
            'description'   => wp_trim_words( $text, 30 ),
            'url'           => get_permalink( $post->ID ),
        ];

        $thumb = get_the_post_thumbnail_url( $post->ID, 'full' );
        if ( $thumb ) $schema['image'] = $thumb;

        // Price range
        if ( preg_match( '/[\$\£\€]\s*(\d+)\s*(?:[-–]|to)\s*[\$\£\€]?\s*(\d+)/i', $text, $pr ) ) {
            $schema['priceRange'] = '$' . $pr[1] . ' - $' . $pr[2];
        }

        return $schema;
    }
}
