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
     * v1.5.206a — Schema.org @types that accept `inLanguage`.
     *
     * Per Schema.org, `inLanguage` is defined on CreativeWork, Event, LinkRole,
     * PronounceableText, and WriteAction. Any @type that extends those inherits
     * it. Types extending Intangible (BreadcrumbList, ItemList, DefinedTerm,
     * JobPosting), Organization (LocalBusiness, Restaurant), Place, or Product
     * do NOT accept it — adding inLanguage there triggers validator warnings.
     *
     * Whitelist all @types emitted by Schema_Generator that DO accept inLanguage.
     */
    private const INLANGUAGE_ACCEPTED_TYPES = [
        // Article family (CreativeWork subclasses)
        'Article', 'BlogPosting', 'NewsArticle', 'OpinionNewsArticle',
        'ScholarlyArticle', 'TechArticle', 'Report', 'ReportageNewsArticle',
        'LiveBlogPosting', 'AnalysisNewsArticle', 'BackgroundNewsArticle',
        'OpinionNewsArticle', 'ReviewNewsArticle', 'AskPublicNewsArticle',
        'AdvertiserContentArticle', 'SatiricalArticle',
        // HowTo family
        'HowTo',
        // Food
        'Recipe',
        // Reviews (CreativeWork subclasses)
        'Review', 'ClaimReview', 'CriticReview', 'EmployerReview', 'MediaReview',
        'UserReview',
        // WebPage family
        'WebPage', 'FAQPage', 'QAPage', 'ProfilePage', 'CollectionPage',
        'ItemPage', 'AboutPage', 'ContactPage', 'SearchResultsPage',
        'MedicalWebPage', 'RealEstateListing',
        // Media (MediaObject → CreativeWork)
        'ImageObject', 'VideoObject', 'AudioObject', 'MediaObject', 'Photograph',
        '3DModel', 'MusicVideoObject',
        // Software / apps (CreativeWork subclasses)
        'SoftwareApplication', 'WebApplication', 'MobileApplication',
        'VideoGame', 'GameServer',
        // Data
        'Dataset', 'DataFeed', 'DataDownload',
        // Courses / learning
        'Course', 'LearningResource', 'EducationalOccupationalCredential',
        // Documents
        'DigitalDocument', 'TextDigitalDocument', 'NoteDigitalDocument',
        'PresentationDigitalDocument', 'SpreadsheetDigitalDocument',
        // Books / screen
        'Book', 'Movie', 'TVEpisode', 'TVSeries', 'PodcastEpisode',
        'MusicRecording', 'MusicAlbum',
        // Events (has own inLanguage)
        'Event', 'BusinessEvent', 'ChildrensEvent', 'ComedyEvent',
        'CourseInstance', 'DanceEvent', 'DeliveryEvent', 'EducationEvent',
        'ExhibitionEvent', 'Festival', 'FoodEvent', 'LiteraryEvent',
        'MusicEvent', 'PublicationEvent', 'SaleEvent', 'ScreeningEvent',
        'SocialEvent', 'SportsEvent', 'TheaterEvent', 'VisualArtsEvent',
        // Other CreativeWork descendants
        'Guide', 'Question', 'Quotation', 'Comment',
    ];

    /**
     * v1.5.206a — Resolve article language as BCP-47 code for schema inLanguage.
     *
     * Priority: user-selected `_seobetter_language` post meta > WordPress locale > 'en'.
     * Converts WP locale ('en_US') to BCP-47 ('en-US') by replacing '_' with '-'.
     * Additive: injected into every top-level schema via generate() post-processor.
     */
    private function get_in_language( \WP_Post $post ): string {
        $lang = get_post_meta( $post->ID, '_seobetter_language', true );
        if ( $lang && is_string( $lang ) ) {
            return str_replace( '_', '-', sanitize_text_field( $lang ) );
        }
        $locale = get_locale();
        if ( $locale ) {
            return str_replace( '_', '-', $locale );
        }
        return 'en';
    }

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

    /**
     * v1.5.212 — Top-level Organization entity for the site publisher.
     * Always emitted (unless a content-type-specific Organization already exists
     * in the @graph, e.g. for press_release/case_study which emit a richer
     * enriched Organization via detect_organization_schema()).
     *
     * Differs from nested `publisher` field in Article schemas: this top-level
     * node gives the site a first-class Knowledge Graph entity AI engines can
     * reference, and makes the AI Overview readiness check pass.
     */
    private function build_site_organization_schema(): array {
        $org = [
            '@type' => 'Organization',
            '@id'   => trailingslashit( home_url() ) . '#organization',
            'name'  => get_bloginfo( 'name' ),
            'url'   => home_url(),
        ];

        $logo_url = get_site_icon_url( 512 );
        if ( $logo_url ) {
            $org['logo'] = [
                '@type' => 'ImageObject',
                'url'   => $logo_url,
            ];
        }

        $tagline = get_bloginfo( 'description' );
        if ( ! empty( $tagline ) ) {
            $org['description'] = $tagline;
        }

        // sameAs from SEOBetter settings (author social profiles double as org links
        // for solo-publisher sites — the common WP case)
        $s = get_option( 'seobetter_settings', [] );
        $same_as = [];
        foreach ( [ 'author_linkedin', 'author_twitter', 'author_facebook', 'author_instagram', 'author_youtube', 'author_website' ] as $key ) {
            if ( ! empty( $s[ $key ] ) ) {
                $same_as[] = $s[ $key ];
            }
        }
        if ( ! empty( $same_as ) ) {
            $org['sameAs'] = $same_as;
        }

        return $org;
    }

    /**
     * v1.5.212 — Top-level Person entity for the article author.
     * Always emitted when the article has a Person author (vs Organization).
     * Duplicates the full author info into a standalone top-level @graph node
     * for AI-engine entity grounding + Knowledge Graph discovery.
     *
     * Shares schema fields with build_author_schema() (nested author field in
     * Article schema) but emitted standalone with @id anchor.
     */
    /**
     * v1.5.213 — Build minimal {@type, @id, name} reference to the top-level
     * Person/Organization nodes that build_site_*_schema emits. Replaces inline
     * author/publisher duplication: pre-fix every Recipe in a multi-recipe
     * article carried a 13-field Person object (~500 bytes) → 4 recipes × 4
     * fields-deep = 2KB+ of duplicated identity data per article. With @id refs
     * the per-Recipe author shrinks to ~80 bytes and consumers join to the
     * single canonical Person at the @graph root.
     *
     * Google + Schema.org explicitly support @id resolution within a @graph;
     * the minimal ref includes `name` as a fallback for non-graph-aware older
     * consumers (legacy crawlers, third-party SEO scrapers).
     */
    private function author_id_ref( \WP_Post $post ): array {
        $author_slug = '';
        $author = get_userdata( $post->post_author );
        if ( $author && $author->user_login ) {
            $author_slug = $author->user_login;
        }
        return [
            '@type' => 'Person',
            '@id'   => trailingslashit( home_url() ) . '#author-' . ( $author_slug ?: $post->post_author ),
            'name'  => $this->get_author_name( $post ),
        ];
    }

    /**
     * v1.5.213 — see author_id_ref(). Mirrors build_site_organization_schema's
     * @id pattern (home_url + #organization).
     */
    private function publisher_id_ref(): array {
        return [
            '@type' => 'Organization',
            '@id'   => trailingslashit( home_url() ) . '#organization',
            'name'  => get_bloginfo( 'name' ),
        ];
    }

    private function build_site_author_person_schema( \WP_Post $post ): array {
        // Reuse the full author schema builder (v1.5.139 — has sameAs, jobTitle,
        // knowsAbout, worksFor, image, description) and add an @id anchor so
        // Article.author nested fields can reference via @id instead of duplicating.
        $person = $this->safe_build_author( $post );

        // Anchor: site URL + author slug or user ID
        $author_slug = '';
        $author = get_userdata( $post->post_author );
        if ( $author && $author->user_login ) {
            $author_slug = $author->user_login;
        }
        $person['@id'] = trailingslashit( home_url() ) . '#author-' . ( $author_slug ?: $post->post_author );

        return $person;
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
    // Content types that get Speakable (voice assistants).
    // v1.5.210 — added how_to, faq_page, interview per §10.3 enrichment rollout.
    //   - how_to: voice assistants can read step-by-step sections (mobile + Google Assistant still supports HowTo voice even though the desktop rich result is deprecated)
    //   - faq_page: Q&A format is voice-native — the highest-value voice-read type
    //   - interview: Q&A transcript lends itself to audio consumption
    // Sponsored deliberately excluded (Google policy — paid content shouldn't be voice-read without audible disclosure).
    // v1.5.213 — Expanded from 7 to 10 types. Recipe + personal_essay +
    // press_release benefit from voice-assistant readout: Recipe via Key
    // Takeaways block (introduces the dish), personal_essay via the lede
    // paragraph (first-person hook), press_release via the dateline + first
    // graf (the news lede). All three articles routinely show up in voice
    // search results for their respective intents (recipe queries, opinion
    // pieces, brand news), so emitting Speakable widens the surface where
    // Google Assistant / Alexa can read them aloud.
    // v1.5.216.62.71 — `listicle` added. Voice assistants reading "Top 10 X"
    // out loud is a frequent voice-search use case (Google Assistant
    // / Siri / Alexa "Hey Google, what are the best dog beds in
    // Australia"), so emitting Speakable on listicle widens the surface
    // where the article can be read aloud. cssSelector chain in
    // build_article() already targets h1 + .key-takeaways + first
    // paragraph after each h2, which works naturally for a numbered
    // listicle. User-reported on T3 #5 Listicle: Speakable was missing
    // entirely from the JSON-LD blob.
    private const SPEAKABLE_TYPES = [ 'blog_post', 'news_article', 'opinion', 'pillar_guide', 'how_to', 'faq_page', 'interview', 'recipe', 'personal_essay', 'press_release', 'listicle' ];

    // Content types that get universal `citation[]` injection (v1.5.210).
    // Implements the "biggest LLM-citation lever" rollout flagged in v1.5.209
    // parked gaps. Pattern established in v1.5.192 (Opinion) + v1.5.195 (PR) +
    // v1.5.201 (Personal Essay) + v1.5.209 (Sponsored) — every article type
    // that references external sources benefits from a declared citation
    // graph because hybrid BM25+vector retrievers (Perplexity / ChatGPT-with-
    // search / Gemini / Claude) weight pages with citation[] higher.
    // Excluded: recipe (recipe card format has its own source attribution via
    // "Inspired by [Source]" suffix), glossary (single-term definition),
    // live_blog (timestamped updates — citations inline per update),
    // faq_page (FAQPage schema doesn't support citation at the @type level),
    // news_article base (only press_release/opinion NewsArticle subtypes get citation[]).
    // Exact 10 types from the v1.5.209 parked-gaps list. Blog_post + listicle
    // intentionally NOT added — not in Ben's sign-off scope for this release,
    // can be added in a follow-up if desired (they'd be straightforward since
    // both go through build_article and would pick up the same logic).
    private const CITATION_TYPES = [
        'how_to', 'review', 'comparison', 'buying_guide',
        'tech_article', 'white_paper', 'scholarly_article',
        'case_study', 'interview', 'pillar_guide',
    ];

    // Content types that get FAQPage secondary (when FAQ section detected)
    private const FAQ_TYPES = [
        'blog_post', 'how_to', 'listicle', 'review', 'comparison', 'buying_guide',
        'recipe', 'news_article', 'opinion', 'tech_article', 'white_paper',
        'scholarly_article', 'glossary_definition', 'case_study', 'interview', 'pillar_guide',
        // v1.5.216.62.19 — added per schema-audit Bug 3. Press release prose template
        // (v1.5.195) explicitly tells the AI to write "FAQ (2-3 Q&A)"; without
        // press_release in FAQ_TYPES, those Q&A pairs were never wrapped in FAQPage
        // schema, losing the secondary rich-result lane.
        'press_release',
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

        // v1.5.213 — Recipe articles co-emit an Article wrapper alongside the
        // Recipe[] schemas. Pre-fix: a Recipe-content-type article emitted ONLY
        // Recipe[] in the @graph, which gave it ONE rich-result eligibility lane
        // (Recipe card). With the Article wrapper, the page is also eligible for
        // Article snippet + Speakable voice readout — two extra surfaces. Per
        // Google's @graph spec, multiple top-level @types are explicitly
        // supported and Google picks the most-specific @type per surface.
        if ( $content_type === 'recipe' ) {
            $schemas[] = $this->build_recipe_article_wrapper( $post );
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

        // v1.5.216.29 — Phase 1 item 10: User-edited Schema Blocks override
        // auto-detection. Read manually-curated blocks first; track which
        // @types the user has explicitly defined so we skip auto-detect for
        // those types (avoiding duplicate / conflicting nodes in @graph).
        $manual_blocks    = Schema_Blocks_Manager::build_all_jsonld( $post->ID );
        $manual_types_set = [];
        foreach ( $manual_blocks as $node ) {
            $t = $node['@type'] ?? '';
            if ( is_string( $t ) ) $manual_types_set[ $t ] = true;
            elseif ( is_array( $t ) ) {
                foreach ( $t as $sub ) if ( is_string( $sub ) ) $manual_types_set[ $sub ] = true;
            }
            $schemas[] = $node;
        }
        $has_manual = function ( array $check_types ) use ( $manual_types_set ): bool {
            foreach ( $check_types as $t ) {
                if ( ! empty( $manual_types_set[ $t ] ) ) return true;
            }
            return false;
        };

        // LocalBusiness — auto-detect from content with addresses (skip if
        // manual block already supplied LocalBusiness/Restaurant/Store/etc.)
        if ( ! $has_manual( [ 'LocalBusiness', 'Restaurant', 'Store', 'FoodEstablishment', 'LodgingBusiness' ] ) ) {
            $local = $this->generate_localbusiness_schemas( $post );
            foreach ( $local as $lb ) {
                $schemas[] = $lb;
            }
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

        // Event — content has future date + location + event name (skip if manual)
        if ( ! $has_manual( [ 'Event' ] ) ) {
            $event = $this->detect_event_schema( $post, $content, $content_type );
            if ( $event ) {
                $schemas[] = $event;
            }
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
        // Product — review/buying_guide/comparison with prices (skip if manual)
        if ( ! $has_manual( [ 'Product' ] ) ) {
            $product = $this->detect_product_schema( $post, $content, $content_type, $category );
            if ( $product ) {
                $schemas[] = $product;
            }
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

        // JobPosting — content with job listings (skip if manual)
        if ( ! $has_manual( [ 'JobPosting' ] ) ) {
            $job = $this->detect_job_schema( $post, $content, $content_type );
            if ( $job ) {
                $schemas[] = $job;
            }
        }

        // VacationRental / LodgingBusiness — travel content with accommodation
        // (skip if manual VacationRental/LodgingBusiness block supplied)
        if ( ! $has_manual( [ 'VacationRental', 'LodgingBusiness' ] ) ) {
            $vacation = $this->detect_vacation_rental_schema( $post, $content, $content_type, $category );
            if ( $vacation ) {
                $schemas[] = $vacation;
            }
        }

        // v1.5.212 — Top-level E-E-A-T Organization + Person entities on every article.
        // Pre-v1.5.212: Organization emitted only for press_release/case_study/sponsored/interview.
        // Person only nested inside author/publisher fields — never a top-level @graph node.
        // Result: AI Overview readiness check (Rich Results tab v1.5.207) failed for 17 of 21
        // content types because $has_type(['Organization','Person']) looks at top-level @types.
        //
        // Fix: always emit top-level Organization (site publisher) + Person (article author)
        // with @id anchors. Matches industry standard (Yoast / RankMath / AIOSEO all do this)
        // and strengthens E-E-A-T signal to Google + AI engines.
        //
        // Duplicate guard: if generate_primary_schema already emitted Organization (for
        // press_release/case_study/sponsored/interview content types), we skip the universal
        // one to avoid two Organization nodes in the @graph. Same pattern for Person when the
        // author is represented as an Organization (site-name fallback case).
        $has_organization = false;
        $has_person = false;
        foreach ( $schemas as $s ) {
            $t = $s['@type'] ?? '';
            if ( $t === 'Organization' ) $has_organization = true;
            if ( $t === 'Person' ) $has_person = true;
        }

        if ( ! $has_organization ) {
            $schemas[] = $this->build_site_organization_schema();
        }
        if ( ! $has_person ) {
            $schemas[] = $this->build_site_author_person_schema( $post );
        }

        // BreadcrumbList — always
        $schemas[] = $this->generate_breadcrumb_schema( $post );

        // Strip @context from individual schemas (caller wraps in single @graph)
        // v1.5.206a — Inject inLanguage (BCP-47) into top-level schemas whose
        // @type accepts it per Schema.org (CreativeWork + Event descendants).
        // Additive: only sets inLanguage when the builder hasn't already set one.
        // Never overwrites existing values, never removes other fields.
        // Skipped for BreadcrumbList/ItemList/LocalBusiness/Product/DefinedTerm/
        // Organization/JobPosting etc. — those don't accept inLanguage and
        // injecting it triggers schema.org validator warnings.
        $in_language = $this->get_in_language( $post );
        foreach ( $schemas as &$s ) {
            unset( $s['@context'] );
            if ( is_array( $s ) && ! isset( $s['inLanguage'] ) ) {
                $type = $s['@type'] ?? '';
                if ( is_string( $type ) && in_array( $type, self::INLANGUAGE_ACCEPTED_TYPES, true ) ) {
                    $s['inLanguage'] = $in_language;
                }
            }
        }
        unset( $s );

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

        // Specialty types have their own builders.
        // v1.5.213 — `case 'HowTo'` removed. CONTENT_TYPE_MAP['how_to'] has been
        // 'Article' since v1.5.116 (Google deprecated HowTo rich result Sept 2023),
        // so this branch was unreachable. Kept the build_howto() method itself for
        // potential Bing/Yandex use, but the default path through build_article()
        // now handles how_to content type cleanly. Speakable on how_to articles
        // gives them voice-readout coverage which compensates for the lost rich
        // result on Google.
        switch ( $type ) {
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
            // v1.5.197 — clean the description: strip wp:html blocks
            // (type badge, Opinion disclosure bar, callouts, author bio,
            // tables) and all heading text before summarising. Previously
            // wp_strip_all_tags kept the visible text of every structural
            // element, producing descriptions like "💬 Opinion — this piece
            // reflects the author's views... Should University Be Free In
            // Australia Last Updated: April 2026 Key Takeaways ...". The
            // helper returns a clean 30-word summary from the actual article
            // prose only.
            'description'   => $this->build_clean_description( $post->post_content ),
            'datePublished' => get_the_date( 'c', $post ),
            'dateModified'  => get_the_modified_date( 'c', $post ),
            // v1.5.213 — @id refs to the top-level Person + Organization nodes
            // (see build_site_author_person_schema / build_site_organization_schema).
            // Keeps the @graph DRY — one canonical Person + Organization, with
            // every Article/Recipe/Review pointing at them by @id rather than
            // repeating the 13-field Person object on each.
            'author'        => $this->author_id_ref( $post ),
            'publisher'     => $this->publisher_id_ref(),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => get_permalink( $post->ID ),
            ],
        ];

        if ( $thumbnail ) {
            // v1.5.216.62.51 — emit a single image URL (string) instead of
            // a 3-element array of the same URL repeated. The v62.19
            // 3-dupe pattern was added for "richer carousel / Discover
            // surfaces" but 3 IDENTICAL URLs provides zero semantic value
            // over a single URL — Google's docs explicitly state the
            // multi-image array is for 3 DISTINCT aspect ratios (1:1,
            // 4:3, 16:9). Without real cropping at upload time we can't
            // produce 3 distinct ratios, so the cleaner single-URL form
            // wins. Recipe types continue to emit a 3-element array
            // because Google's Recipe rich result actively uses it.
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

        // v1.5.195 — Press Release specific overrides on NewsArticle.
        // When this NewsArticle is a press release (vs. regular news reporting),
        // swap articleSection to "Press Release" so AI models + Google News can
        // disambiguate corporate announcements from editorial reporting. Also
        // add `citation` (outbound URLs) so AI engines treat the release as
        // claim-backed (Princeton GEO arXiv 2311.09735: +30-40% citation rate
        // on content with linked sources), and ensure `speakable` targets the
        // lede + boilerplate (voice-assistant reads the news + the About block).
        if ( $type === 'NewsArticle' ) {
            $content_type_check = get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: '';
            if ( $content_type_check === 'press_release' ) {
                $schema['articleSection'] = 'Press Release';
                $urls = $this->extract_outbound_urls( $post->post_content );
                if ( ! empty( $urls ) ) {
                    $schema['citation'] = array_map( function ( $u ) {
                        return [ '@type' => 'CreativeWork', 'url' => $u ];
                    }, $urls );
                }
                $schema['speakable'] = [
                    '@type'       => 'SpeakableSpecification',
                    'cssSelector' => [ 'h1', 'h2 + p', '.seobetter-author-bio' ],
                ];
            }
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

        // v1.5.201 — Personal Essay enrichment on BlogPosting. Schema.org /
        // Google / schemavalidator all recommend BlogPosting (not generic
        // Article) for first-person narrative — BlogPosting is already the
        // primary @type for personal_essay, this block just adds the extra
        // signals AI engines + voice assistants use:
        //   - `articleSection: "Personal Essay"` — disambiguates literary
        //     narrative from generic blog posts (matches the Opinion /
        //     Press Release pattern established in v1.5.192 / v1.5.195).
        //   - `citation` — any outbound source the essay references
        //     (books, news events, songs) so E-E-A-T Experience signals
        //     line up with actual linked evidence.
        //   - `backstory` — explicit "Personal essay…" label for AI
        //     disambiguation, matching OpinionNewsArticle treatment.
        //   - `speakable` targets `h1, h2 + p, .seobetter-author-bio` —
        //     voice assistants read the opening line of each section
        //     plus the bio. Google RR Test now matches .seobetter-author-bio
        //     after v1.5.200.
        if ( $type === 'BlogPosting' ) {
            $content_type_check = get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: '';
            if ( $content_type_check === 'personal_essay' ) {
                $schema['articleSection'] = 'Personal Essay';
                $urls = $this->extract_outbound_urls( $post->post_content );
                if ( ! empty( $urls ) ) {
                    $schema['citation'] = array_map( function ( $u ) {
                        return [ '@type' => 'CreativeWork', 'url' => $u ];
                    }, $urls );
                }
                $schema['backstory'] = 'Personal essay — first-person literary narrative based on the author\'s lived experience.';
                $schema['speakable'] = [
                    '@type'       => 'SpeakableSpecification',
                    'cssSelector' => [ 'h1', 'h2 + p', '.seobetter-author-bio' ],
                ];
            }

            // v1.5.209 — Sponsored content compliance + disclosure enrichment.
            // Matches the Opinion / Press Release / Personal Essay enrichment
            // pattern established in v1.5.192-201. Addresses FTC / ACCC
            // misleading-conduct risk + Google's Sponsored content policy +
            // AI-engine disambiguation of paid placements from editorial.
            //
            // Previously: sponsored fell through to base BlogPosting schema
            // with no disclosure signal, making AI engines (and Google Search)
            // unable to distinguish sponsored articles from organic editorial.
            // Both §10.1 of SEO-GEO-AI-GUIDELINES.md and structured-data.md §5
            // documented sponsored as `AdvertiserContentArticle` but that
            // @type is not recognized by Google — CONTENT_TYPE_MAP correctly
            // maps to BlogPosting; this block adds the missing disclosure.
            //
            // Note on Speakable: deliberately NOT added for sponsored. Per
            // Google policy, voice assistants should not read paid placements
            // aloud without audio disclosure, which WordPress cannot guarantee.
            if ( $content_type_check === 'sponsored' ) {
                $schema['articleSection'] = 'Sponsored';
                $urls = $this->extract_outbound_urls( $post->post_content );
                if ( ! empty( $urls ) ) {
                    $schema['citation'] = array_map( function ( $u ) {
                        return [ '@type' => 'CreativeWork', 'url' => $u ];
                    }, $urls );
                }
                $schema['backstory'] = 'Sponsored content — this article is a paid placement clearly disclosed to readers. Views and claims reflect the sponsoring organisation\'s position, not an objective editorial assessment.';
                // v1.5.209 — Optional sponsor Organization if configured.
                // Stored in _seobetter_sponsor_name post_meta; when absent the
                // field is omitted rather than faked. Ships without a new UI
                // field for now — can be populated via metabox or block editor
                // custom field in a follow-up.
                $sponsor_name = (string) get_post_meta( $post->ID, '_seobetter_sponsor_name', true );
                if ( $sponsor_name !== '' ) {
                    $sponsor = [
                        '@type' => 'Organization',
                        'name'  => $sponsor_name,
                    ];
                    $sponsor_url = (string) get_post_meta( $post->ID, '_seobetter_sponsor_url', true );
                    if ( $sponsor_url !== '' ) {
                        $sponsor['url'] = esc_url_raw( $sponsor_url );
                    }
                    $schema['sponsor'] = $sponsor;
                }
            }
        }

        // v1.5.210 — Universal citation[] rollout for 10 content types.
        // Fires AFTER all type-specific override branches above so existing
        // Opinion / Press Release / Personal Essay / Sponsored citation[]
        // injection still wins. Only adds citation[] when:
        //   (a) content_type is in CITATION_TYPES (how_to / review / comparison /
        //       buying_guide / tech_article / white_paper / scholarly_article /
        //       case_study / interview / pillar_guide),
        //   (b) $schema['citation'] hasn't already been set by an override,
        //   (c) extract_outbound_urls() returns at least one URL.
        // Implements the "biggest LLM-citation lever still unused" parked gap
        // logged in v1.5.209 BUILD_LOG. Pattern matches v1.5.192 Opinion:
        //   [ { "@type": "CreativeWork", "url": "https://..." }, ... ]
        // Fresh get_post_meta() call (not reusing $content_type_check from
        // earlier branches) because this block runs for all @types including
        // TechArticle / ScholarlyArticle / LiveBlogPosting where the earlier
        // speakable branch didn't fire.
        if ( ! isset( $schema['citation'] ) ) {
            $ct_for_citation = (string) ( get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: '' );
            if ( in_array( $ct_for_citation, self::CITATION_TYPES, true ) ) {
                $urls = $this->extract_outbound_urls( $post->post_content );
                if ( ! empty( $urls ) ) {
                    $schema['citation'] = array_map( function ( $u ) {
                        return [ '@type' => 'CreativeWork', 'url' => $u ];
                    }, $urls );
                }
            }
        }

        return $schema;
    }

    /**
     * v1.5.192 — Extract outbound URLs from post content for schema `citation`.
     * Collects every external http(s) URL found in markdown links, HTML anchors,
     * or `<a href>` tags, deduplicates, filters to external (non-site) hosts.
     * Returns up to 20 URLs (schema size cap — more is diminishing returns).
     *
     * v1.5.197 — Additionally excludes URLs that are configured as the
     * author's `sameAs` social profiles in SEOBetter settings. These URLs
     * are rendered in the author-bio block at the end of every article by
     * Content_Formatter::build_author_bio(), but they are NOT citations
     * for the article's claims — they belong in the Person schema's
     * sameAs array, not the article's citation[] array.
     *
     * @return string[] List of unique external URLs, author social profiles removed.
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

        // v1.5.197 — Build exclusion set of the author's configured social
        // profiles. Normalised the same way as candidate URLs so trailing
        // slashes / query strings don't defeat the match.
        $normalize = function ( string $u ): string {
            return strtolower( rtrim( preg_replace( '/[?#].*$/', '', trim( $u ) ), '/' ) );
        };
        $exclude = [];
        $s = get_option( 'seobetter_settings', [] );
        foreach ( [ 'author_linkedin', 'author_twitter', 'author_facebook', 'author_instagram', 'author_youtube', 'author_website' ] as $key ) {
            if ( ! empty( $s[ $key ] ) ) {
                $exclude[ $normalize( $s[ $key ] ) ] = true;
            }
        }

        $seen = [];
        $out = [];
        foreach ( $urls as $u ) {
            $u = trim( $u, " \t\n\r\0\x0B\"'" );
            // v1.5.216.62.30 — strip tracking parameters before the URL
            // ends up in citation[] schema. Pre-fix, citations could land
            // in @graph as e.g. https://primalpetfoods.com/...?srsltid=AfmB
            // OorjzuW... — that srsltid token is a Google Shopping click-
            // attribution param, not part of the canonical source URL.
            // Citation graphs that LLMs (Perplexity / ChatGPT / Claude)
            // parse should point at canonical sources, not Google-tracked
            // variants — otherwise the same source dedupes poorly across
            // articles and weakens the citation signal.
            $u = self::strip_tracking_params( $u );
            $host = wp_parse_url( $u, PHP_URL_HOST );
            if ( ! $host || $host === $site_host ) continue;
            $key = $normalize( $u );
            if ( isset( $exclude[ $key ] ) ) continue; // skip author bio social links
            if ( isset( $seen[ $key ] ) ) continue;
            $seen[ $key ] = true;
            $out[] = $u;
            if ( count( $out ) >= 20 ) break;
        }
        return $out;
    }

    /**
     * v1.5.216.62.30 — Strip ad/analytics tracking parameters from a URL
     * so citation[] in @graph points at canonical sources.
     *
     * Removes the standard list of tracking query parameters used by ad
     * platforms, analytics tools, and email marketing services. Preserves
     * everything else in the query string (including legitimate product
     * IDs, page numbers, search params).
     *
     * Sources for the parameter list:
     *   - Google: srsltid, gclid, gclsrc, gbraid, wbraid, dclid, _ga
     *   - Meta:   fbclid, igshid
     *   - Microsoft: msclkid
     *   - Yandex: yclid
     *   - Mailchimp: mc_cid, mc_eid
     *   - HubSpot: _hsenc, _hsmi
     *   - Vero: vero_id, vero_conv
     *   - Universal: utm_source, utm_medium, utm_campaign, utm_term, utm_content
     *
     * If stripping leaves an empty query string, the `?` is removed too —
     * the URL ends up clean (`https://example.com/page` not `…/page?`).
     *
     * @param string $url Input URL (may or may not have tracking params).
     * @return string URL with tracking params removed.
     */
    public static function strip_tracking_params( string $url ): string {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['query'] ) ) return $url;

        parse_str( $parts['query'], $params );
        if ( empty( $params ) || ! is_array( $params ) ) return $url;

        $strip_keys = [
            // Google
            'srsltid', 'gclid', 'gclsrc', 'gbraid', 'wbraid', 'dclid',
            // Meta
            'fbclid', 'igshid',
            // Microsoft Ads
            'msclkid',
            // Yandex
            'yclid',
            // Mailchimp
            'mc_cid', 'mc_eid',
            // HubSpot
            '_hsenc', '_hsmi', '__hssc', '__hstc', '__hsfp',
            // Vero
            'vero_id', 'vero_conv',
            // Drip
            'drip_uid',
            // Klaviyo
            '_kx',
            // Pardot
            'pk_campaign', 'pk_kwd', 'pk_source', 'pk_medium',
            // Google Analytics cross-domain
            '_ga', '_gid',
        ];
        // NOTE: 'ref' and 'source' are deliberately NOT in the strip list —
        // many sites use them as legitimate query params (e.g. ?ref=footer
        // for internal click attribution that isn't ad-tracking, or ?source=
        // for content sourcing). The cost of false-positive stripping
        // (corrupted destination URL) outweighs the benefit. We can revisit
        // if a specific false-positive case comes up.

        // Conservative — drop only the well-known tracking keys, preserve everything else.
        // Also drop any key starting with 'utm_' (utm_source / utm_medium / utm_campaign / utm_term / utm_content / etc.).
        foreach ( array_keys( $params ) as $key ) {
            $lc = strtolower( (string) $key );
            if ( in_array( $lc, $strip_keys, true ) ) {
                unset( $params[ $key ] );
                continue;
            }
            if ( strpos( $lc, 'utm_' ) === 0 ) {
                unset( $params[ $key ] );
            }
        }

        // Rebuild URL with cleaned query string (or no query string at all if empty)
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        $port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
        $user   = $parts['user'] ?? '';
        $pass   = isset( $parts['pass'] ) ? ':' . $parts['pass'] : '';
        $auth   = $user || $pass ? $user . $pass . '@' : '';
        $path   = $parts['path'] ?? '';
        $query  = ! empty( $params ) ? '?' . http_build_query( $params ) : '';
        $frag   = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

        if ( ! $host ) return $url; // malformed — return as-is rather than corrupt
        return $scheme . '://' . $auth . $host . $port . $path . $query . $frag;
    }

    /**
     * v1.5.197 — Build a clean 30-word description from post content.
     *
     * Removes structural chrome that bloats the raw-text scrape (opinion
     * disclosure bar, type badges, Key Takeaways box, tables, author bio,
     * pull-quotes, callouts — anything inside a `<!-- wp:html -->` block),
     * all H1-H6 headings, "Last Updated: …" lines, and Table-of-Contents
     * patterns before summarising. Applies to every schema type emitted
     * by build_article(), so this single fix cleans descriptions across
     * all 21 content types.
     */
    private function build_clean_description( string $content ): string {
        // 1. Strip every wp:html block (where badges, callouts, tables,
        //    author bio, Opinion disclosure bar live). Greedy across newlines.
        $clean = preg_replace( '/<!-- wp:html -->.*?<!-- \/wp:html -->/s', '', (string) $content );
        // 2. Strip all heading elements (H1 often duplicates the title; H2
        //    headings are structural, not article prose).
        $clean = preg_replace( '/<h[1-6][^>]*>.*?<\/h[1-6]>/is', '', (string) $clean );
        // 3. Strip "Last Updated: Month YYYY" style stamps.
        $clean = preg_replace( '/Last Updated:?\s*[A-Za-z]+\s*\d{4}/i', '', (string) $clean );
        // 4. Strip remaining tags and collapse whitespace.
        $clean = wp_strip_all_tags( (string) $clean );
        $clean = preg_replace( '/\s+/', ' ', trim( (string) $clean ) );
        return wp_trim_words( $clean, 30 );
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
            // v1.5.216.62.51 — single-URL image (see BlogPosting/NewsArticle
            // builder above for full reasoning).
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
        $language  = get_post_meta( $post->ID, '_seobetter_language', true ) ?: 'en';

        // v1.5.213 — @id refs to top-level Person/Organization. Pre-fix every
        // Recipe in a multi-recipe article inlined the full 13-field Person
        // (~500 bytes × 4 recipes = 2KB of duplicated identity per article).
        // With @id refs, per-Recipe author is ~80 bytes and the canonical
        // Person lives once in the @graph root.
        $author_ref = $this->author_id_ref( $post );

        // v1.5.213 — Translate the focus keyword for the Recipe `keywords`
        // field when the article language is non-English. Pre-fix: a Japanese
        // recipe shipped `keywords: "Best Slow Cooker Recipes for Winter 2026"`
        // — schema field language mismatched the article body. Now passes
        // through the same translate-headings batch helper used elsewhere.
        // Fail-open: translation errors fall back to the original keyword.
        $translated_keyword = $keyword;
        if ( $keyword && $language && substr( $language, 0, 2 ) !== 'en' ) {
            $batch = \SEOBetter\Cloud_API::translate_strings_batch( [ $keyword ], $language );
            if ( is_array( $batch ) && ! empty( $batch[0] ) && $batch[0] !== $keyword ) {
                $translated_keyword = $batch[0];
            }
        }

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
                    'author'        => $author_ref,
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
                // v1.5.213 — uses translated form when article language is non-English.
                if ( $translated_keyword ) {
                    $recipe['keywords'] = $translated_keyword;
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
                // v1.5.213 — @id ref to canonical Person node.
                'author'        => $author_ref,
            ];
            if ( $thumbnail ) $recipe['image'] = [ $thumbnail, $thumbnail, $thumbnail ];
            // v1.5.213 — uses translated form for non-English articles.
            if ( $translated_keyword ) $recipe['keywords'] = $translated_keyword;
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
     * v1.5.213 — Article wrapper schema co-emitted alongside Recipe[] for
     * recipe content type. Gives the page an Article snippet + Speakable
     * voice-readout eligibility lane in addition to the Recipe rich-result
     * lane. Lightweight: just the article-level fields (no recipe ingredient/
     * instruction repetition — those stay on the Recipe[] nodes).
     *
     * @id pattern matches the Recipe nodes' implicit page identity, so
     * downstream consumers can join the Article wrapper to the Recipe[] via
     * mainEntityOfPage.
     */
    private function build_recipe_article_wrapper( \WP_Post $post ): array {
        $thumbnail = get_the_post_thumbnail_url( $post->ID, 'full' );
        $permalink = get_permalink( $post->ID );

        $schema = [
            '@type'         => 'Article',
            '@id'           => $permalink . '#article',
            'headline'      => $post->post_title,
            'description'   => $this->build_clean_description( $post->post_content ),
            'datePublished' => get_the_date( 'c', $post ),
            'dateModified'  => get_the_modified_date( 'c', $post ),
            'author'        => $this->author_id_ref( $post ),
            'publisher'     => $this->publisher_id_ref(),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => $permalink,
            ],
            'articleSection' => 'Recipe',
            // Speakable on the wrapper — voice assistants read the H1 + Key
            // Takeaways block (the dish-level intro) before the per-recipe
            // ingredient/instruction details. SPEAKABLE_TYPES expansion v1.5.213.
            'speakable' => [
                '@type'       => 'SpeakableSpecification',
                'cssSelector' => [ 'h1', '.key-takeaways', 'h2 + p' ],
            ],
        ];
        if ( $thumbnail ) {
            $schema['image'] = $thumbnail;
        }
        return $schema;
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
            // v1.5.213 — @id refs to canonical Person + Organization at @graph root.
            'author'        => $this->author_id_ref( $post ),
            'publisher'     => $this->publisher_id_ref(),
            'itemReviewed'  => $item_reviewed,
        ];

        if ( $thumbnail ) {
            // v1.5.216.62.51 — single-URL image for Review (was 3-dupe array).
            $schema['image'] = $thumbnail;
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

        // v1.5.210 — citation[] for review. Review goes through its own
        // builder (build_review), not build_article, so the universal
        // CITATION_TYPES rollout block at the end of build_article doesn't
        // cover it. Mirror the same logic here. Review's content_type IS
        // in CITATION_TYPES so the check is just "do we have outbound URLs".
        if ( ! isset( $schema['citation'] ) ) {
            $urls = $this->extract_outbound_urls( $post->post_content );
            if ( ! empty( $urls ) ) {
                $schema['citation'] = array_map( function ( $u ) {
                    return [ '@type' => 'CreativeWork', 'url' => $u ];
                }, $urls );
            }
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

        $faq_schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $faq_items,
        ];

        // v1.5.210 — Speakable for faq_page primary (voice-native format).
        // FAQPage doesn't flow through build_article, so the generic
        // SPEAKABLE_TYPES check in build_article can't reach it. Inject the
        // speakable cssSelector directly here. Targets H3 + answer paragraph
        // (typical FAQ format uses H3 for questions) plus H2 + paragraph
        // (fallback for H2-based FAQ format). Voice assistants read each
        // question followed by its direct answer — the highest-value
        // voice-read type.
        // Only inject when faq_page is the PRIMARY content type. When FAQPage
        // is secondary (FAQ section inside a blog post / how-to / etc.), the
        // primary schema handles speakable per its own type's selector.
        $content_type_check = (string) ( get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: '' );
        if ( $content_type_check === 'faq_page' ) {
            $faq_schema['speakable'] = [
                '@type'       => 'SpeakableSpecification',
                'cssSelector' => [ 'h1', 'h2 + p', 'h3 + p' ],
            ];
        }

        return $faq_schema;
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
     * VideoObject — detect embedded video from 21 platforms (global + regional).
     *
     * v1.5.216.62.24 — extended from YouTube + Vimeo to 21 platforms including
     * regional players that dominate non-Western markets:
     *   Global: YouTube, Vimeo, Rumble, TikTok, Twitch, Facebook Watch, Instagram Reels
     *   China:  Bilibili, Youku, iQiyi
     *   Japan:  Niconico
     *   Korea:  Naver TV, Kakao TV
     *   France: Dailymotion
     *   Indonesia: Vidio
     *   Iran:   Aparat
     *   Russia: RuTube, VK Video, Coub
     *   Corp:   Wistia, Mux, Brightcove
     *
     * Per-platform configs live in get_video_platform_configs() so the list is
     * easy to extend. First match wins (single VideoObject per article).
     *
     * Returns a fully-formed VideoObject node with embedUrl + thumbnailUrl
     * (where extractable) + name + description + uploadDate.
     */
    private function detect_video_schema( \WP_Post $post, string $content ): ?array {
        // v1.5.216.62.64 — require actual embed-element presence.
        // Pre-fix the per-platform regex matched ANY YouTube URL including
        // plain text links like `[Watch a video](https://www.youtube.com/watch?v=egyNJ7xPyoQ)`,
        // producing fake VideoObject schema for articles that didn't
        // actually embed a video. User-reported on T3 #4 How-To: schema
        // declared embedUrl/contentUrl/thumbnailUrl for a YouTube video
        // that wasn't visible on the page (zero `<iframe>` rendered).
        // Schema.org policy: structured data must match visible content.
        // Google flags this as cloaking.
        //
        // Fix: extract all <iframe>, <embed>, <video> tags from the
        // content and run the per-platform pattern only against the
        // concatenated tag text. Text-link references to videos no
        // longer trigger VideoObject. Real player embeds still match
        // because their src= URL is inside the iframe tag string.
        if ( ! preg_match_all( '/<(?:iframe|embed|video)\b[^>]+>/i', $content, $tag_matches ) ) {
            return null;
        }
        $embed_blob = implode( ' ', $tag_matches[0] );

        $configs = self::get_video_platform_configs();
        foreach ( $configs as $platform ) {
            if ( preg_match( $platform['pattern'], $embed_blob, $m ) ) {
                $node = [
                    '@type'       => 'VideoObject',
                    'name'        => $post->post_title,
                    'description' => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
                    'uploadDate'  => get_the_date( 'c', $post ),
                ];
                // Some platforms expose a deterministic embedUrl + thumbnailUrl
                // pattern from the captured ID; others (Vimeo, TikTok, FB) need
                // an oEmbed call we don't make here, so we set what we know.
                if ( isset( $platform['embed_template'] ) && isset( $m[1] ) ) {
                    $node['embedUrl'] = sprintf( $platform['embed_template'], $m[1] );
                }
                if ( isset( $platform['content_template'] ) && isset( $m[1] ) ) {
                    $node['contentUrl'] = sprintf( $platform['content_template'], $m[1] );
                }
                if ( isset( $platform['thumbnail_template'] ) && isset( $m[1] ) ) {
                    $node['thumbnailUrl'] = sprintf( $platform['thumbnail_template'], $m[1] );
                }
                $node['publisher'] = $platform['publisher_name'];
                return $node;
            }
        }
        return null;
    }

    /**
     * v1.5.216.62.24 — Per-platform regex + URL templates for video detection.
     *
     * Order matters: more specific patterns first so we don't accidentally match
     * a Bilibili URL with the Vimeo regex (Vimeo's `vimeo.com/123456` is broad).
     * YouTube + Vimeo first to preserve pre-v62.24 behavior for the 99% case.
     *
     * Each entry:
     *   pattern            - PCRE matching the embed/share URL, capture 1 = ID
     *   embed_template     - sprintf format for player URL (optional)
     *   content_template   - sprintf format for canonical watch URL (optional)
     *   thumbnail_template - sprintf format for thumbnail URL (optional, preferred)
     *   publisher_name     - human-readable platform name
     */
    private static function get_video_platform_configs(): array {
        return [
            // ---- Global ----
            [
                'pattern'            => '/(?:youtube\.com\/embed\/|youtu\.be\/|youtube\.com\/watch\?v=|youtube-nocookie\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
                'embed_template'     => 'https://www.youtube.com/embed/%s',
                'content_template'   => 'https://www.youtube.com/watch?v=%s',
                'thumbnail_template' => 'https://img.youtube.com/vi/%s/maxresdefault.jpg',
                'publisher_name'     => 'YouTube',
            ],
            [
                'pattern'          => '/vimeo\.com\/(?:video\/)?(\d{6,})/',
                'embed_template'   => 'https://player.vimeo.com/video/%s',
                'content_template' => 'https://vimeo.com/%s',
                'publisher_name'   => 'Vimeo',
            ],
            [
                'pattern'          => '/rumble\.com\/(?:embed\/)?(v[a-z0-9]+)/i',
                'embed_template'   => 'https://rumble.com/embed/%s/',
                'publisher_name'   => 'Rumble',
            ],
            [
                'pattern'          => '/tiktok\.com\/(?:@[\w.]+\/video\/|embed\/v\d+\/)(\d+)/',
                'embed_template'   => 'https://www.tiktok.com/embed/v2/%s',
                'publisher_name'   => 'TikTok',
            ],
            [
                'pattern'          => '/(?:clips\.twitch\.tv\/|twitch\.tv\/videos\/)([a-zA-Z0-9_-]+)/',
                'publisher_name'   => 'Twitch',
            ],
            [
                'pattern'          => '/(?:facebook\.com\/watch\/?\?v=|fb\.watch\/)([a-zA-Z0-9_-]+)/',
                'publisher_name'   => 'Facebook Watch',
            ],
            [
                'pattern'          => '/instagram\.com\/reel\/([a-zA-Z0-9_-]+)/',
                'publisher_name'   => 'Instagram Reels',
            ],
            // ---- China ----
            [
                'pattern'          => '/(?:bilibili\.com\/video\/|player\.bilibili\.com\/player\.html\?bvid=)(BV[a-zA-Z0-9]+)/',
                'embed_template'   => 'https://player.bilibili.com/player.html?bvid=%s',
                'content_template' => 'https://www.bilibili.com/video/%s',
                'publisher_name'   => 'Bilibili',
            ],
            [
                'pattern'          => '/(?:v\.youku\.com\/v_show\/id_|player\.youku\.com\/embed\/)([a-zA-Z0-9=]+)/',
                'embed_template'   => 'https://player.youku.com/embed/%s',
                'publisher_name'   => 'Youku',
            ],
            [
                'pattern'          => '/iqiyi\.com\/v_([a-zA-Z0-9]+)\.html/',
                'publisher_name'   => 'iQiyi',
            ],
            // ---- Japan ----
            [
                'pattern'          => '/(?:nicovideo\.jp\/watch\/|embed\.nicovideo\.jp\/watch\/)(sm\d+|nm\d+|so\d+)/',
                'embed_template'   => 'https://embed.nicovideo.jp/watch/%s',
                'content_template' => 'https://www.nicovideo.jp/watch/%s',
                'publisher_name'   => 'Niconico',
            ],
            // ---- Korea ----
            [
                'pattern'          => '/tv\.naver\.com\/v\/(\d+)/',
                'content_template' => 'https://tv.naver.com/v/%s',
                'publisher_name'   => 'Naver TV',
            ],
            [
                'pattern'          => '/tv\.kakao\.com\/(?:channel\/\d+\/cliplink\/|v\/)(\d+)/',
                'content_template' => 'https://tv.kakao.com/v/%s',
                'publisher_name'   => 'Kakao TV',
            ],
            // ---- France ----
            [
                'pattern'          => '/dailymotion\.com\/(?:video\/|embed\/video\/)([a-zA-Z0-9]+)/',
                'embed_template'   => 'https://www.dailymotion.com/embed/video/%s',
                'content_template' => 'https://www.dailymotion.com/video/%s',
                'thumbnail_template' => 'https://www.dailymotion.com/thumbnail/video/%s',
                'publisher_name'   => 'Dailymotion',
            ],
            // ---- Indonesia ----
            [
                'pattern'          => '/vidio\.com\/(?:watch\/|embed\/)(\d+)/',
                'embed_template'   => 'https://www.vidio.com/embed/%s',
                'publisher_name'   => 'Vidio',
            ],
            // ---- Iran ----
            [
                'pattern'          => '/aparat\.com\/v\/([a-zA-Z0-9]+)/',
                'content_template' => 'https://www.aparat.com/v/%s',
                'publisher_name'   => 'Aparat',
            ],
            // ---- Russia ----
            [
                'pattern'          => '/(?:rutube\.ru\/play\/embed\/|rutube\.ru\/video\/)([a-z0-9]+)/i',
                'embed_template'   => 'https://rutube.ru/play/embed/%s',
                'content_template' => 'https://rutube.ru/video/%s',
                'publisher_name'   => 'RuTube',
            ],
            [
                'pattern'          => '/(?:vk\.com\/video_ext\.php\?oid=([\d-]+)&id=\d+|vkvideo\.ru\/video([\d_-]+))/',
                'publisher_name'   => 'VK Video',
            ],
            [
                'pattern'          => '/coub\.com\/(?:view|embed)\/([a-zA-Z0-9]+)/',
                'embed_template'   => 'https://coub.com/embed/%s',
                'content_template' => 'https://coub.com/view/%s',
                'publisher_name'   => 'Coub',
            ],
            // ---- Corporate / OTT ----
            [
                'pattern'          => '/wistia\.com\/medias\/([a-zA-Z0-9]+)/',
                'embed_template'   => 'https://fast.wistia.com/embed/medias/%s',
                'publisher_name'   => 'Wistia',
            ],
            [
                'pattern'          => '/stream\.mux\.com\/([a-zA-Z0-9]+)/',
                'publisher_name'   => 'Mux',
            ],
            [
                'pattern'          => '/players\.brightcove\.net\/(\d+)\/.*?videoId=([a-zA-Z0-9]+)/',
                'publisher_name'   => 'Brightcove',
            ],
        ];
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
        // v1.5.213.2 — capture the FULL <img> tag so we can inspect class /
        // parent context and skip author bio + featured-image duplicates.
        preg_match_all( '/<img[^>]+>/i', $content, $img_tags );
        if ( empty( $img_tags[0] ) ) return [];

        $site_name = get_bloginfo( 'name' );
        $featured_url = (string) get_the_post_thumbnail_url( $post->ID, 'full' );
        // Author profile photo URL from Settings (used for skip-list matching).
        $settings = get_option( 'seobetter_settings', [] );
        $author_image_url = isset( $settings['author_image'] ) ? (string) $settings['author_image'] : '';

        $count = 0;
        foreach ( $img_tags[0] as $tag ) {
            if ( $count >= 5 ) break;
            // Extract src + alt from the tag.
            if ( ! preg_match( '/src=["\']([^"\']+)["\']/i', $tag, $src_m ) ) continue;
            $src = $src_m[1];
            $alt = '';
            if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $tag, $alt_m ) ) {
                $alt = $alt_m[1];
            }
            if ( strlen( $alt ) < 5 ) continue;

            // v1.5.213.2 — Skip non-content images:
            //   1. Author bio photo (matched by URL or author-bio container class)
            //   2. Featured image (already represented by the Article/Recipe schema's `image` field)
            //   3. Tiny/icon images (avatars, logos, sprite icons) by class hint
            if ( $author_image_url && strpos( $src, $author_image_url ) === 0 ) continue;
            if ( $featured_url && $src === $featured_url ) continue;
            if ( preg_match( '/class=["\'][^"\']*(?:author-bio|seobetter-author|avatar|wp-post-image|gravatar|icon|emoji|logo)[^"\']*["\']/i', $tag ) ) continue;

            // v1.5.213 — Populate `name` + `description` + `caption` from alt text.
            $schemas[] = [
                '@type'            => 'ImageObject',
                'contentUrl'       => $src,
                'name'             => $alt,
                'description'      => $alt,
                'caption'          => $alt,
                'creditText'       => $site_name,
                'creator'          => [
                    '@type' => 'Organization',
                    'name'  => $site_name,
                ],
                'copyrightNotice'  => $site_name . ' ' . wp_date( 'Y' ),
            ];
            $count++;
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
        // v1.5.216.62.71 — REMOVED 'listicle' from this trigger list.
        //
        // Pre-fix detect_product_schema() emitted a single top-level Product
        // node for listicles, treating the entire "Top 10 X" article as one
        // product. The Product node would have:
        //   - name: article title minus "best/top/in-2026/etc" (e.g.
        //     "washable dog beds australia for 2026: picks revealed" — not
        //     a real product name, just a slug-derived blob)
        //   - description: first 30 words of body (Key Takeaways prose, not
        //     a product description)
        //   - offers.price: first price match in body ($110 from one bed
        //     of 10) misrepresented as "the" price
        //   - offers.priceCurrency: defaulted to USD even when article was
        //     entirely in AUD
        // Google Rich Results Validator flags this as "missing required
        // fields" (no review, no aggregateRating, no real brand) AND it
        // misrepresents structured data (treating an article as a product
        // is a Schema.org policy violation per §2 — content type must
        // match marked-up @type).
        //
        // Listicles are correctly represented by the ItemList wrapper
        // (already emitted by build_aioseo_schema) — each numbered item
        // becomes a ListItem. A future enhancement will enrich each
        // ListItem to be a full Product node with name/image/offer/
        // aggregateRating per item, but a single bogus top-level Product
        // is strictly worse than no Product at all. User-reported on T3
        // #5 Listicle retest with Schema.org Validator: the Product node
        // failed validation while the ItemList passed.
        if ( ! in_array( $content_type, [ 'review', 'buying_guide', 'comparison', 'sponsored' ], true ) ) {
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
        $org = [
            '@type' => 'Organization',
            'name'  => get_bloginfo( 'name' ),
            'url'   => home_url(),
            'logo'  => [
                '@type' => 'ImageObject',
                'url'   => get_site_icon_url( 512 ) ?: '',
            ],
        ];

        // v1.5.195 — Enrich with description + sameAs so AI engines can
        // disambiguate the organization (same entity-grounding rationale as
        // the Person schema's sameAs). sameAs is pulled from the social
        // profiles configured in SEOBetter settings — they serve double duty
        // as the author's AND the organization's canonical links.
        $tagline = get_bloginfo( 'description' );
        if ( ! empty( $tagline ) ) {
            $org['description'] = $tagline;
        }
        $s = get_option( 'seobetter_settings', [] );
        $same_as = [];
        foreach ( [ 'author_linkedin', 'author_twitter', 'author_facebook', 'author_instagram', 'author_youtube', 'author_website' ] as $key ) {
            if ( ! empty( $s[ $key ] ) ) {
                $same_as[] = $s[ $key ];
            }
        }
        if ( ! empty( $same_as ) ) {
            $org['sameAs'] = $same_as;
        }

        return $org;
    }

    /**
     * QAPage schema — for interview and faq_page types.
     * Single best-answer format (different from FAQPage which has multiple Q&As).
     */
    private function detect_qa_schema( \WP_Post $post, string $content, string $content_type ): ?array {
        // v1.5.216.62.19 — schema-audit Bug 4: removed 'faq_page' from this list.
        // faq_page's primary @type is already FAQPage; emitting QAPage as a secondary
        // node creates duplicate Q&A signal that confuses Google Rich Results.
        // structured-data.md §5 line 380 + SEO-GEO-AI-GUIDELINES.md §10.3 line 781
        // both list FAQPage's secondaries as BreadcrumbList + Speakable only.
        if ( $content_type !== 'interview' ) {
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
