<?php

namespace SEOBetter;

/**
 * v1.5.216.29 — User-editable Schema Blocks (Phase 1 item 10).
 *
 * Schema_Generator already auto-detects Product / Event / LocalBusiness /
 * VacationRental / JobPosting from article content via heuristic regex. That
 * works well enough for casual content but is unreliable for high-stakes
 * pages: an actual product listing needs an exact SKU, currency, and
 * availability state — not a guess from "$129" found in prose. A real job
 * posting needs employmentType + baseSalary structure that Google REJECTS
 * if mistyped.
 *
 * Schema Blocks let Pro+ users manually fill in authoritative values per
 * block type. Each saved block:
 *   - Overrides auto-detected schema for the same @type when present
 *   - Validates required Google Rich Results fields per `structured-data.md` §4
 *   - Persists to `_seobetter_schema_blocks` post meta (single array)
 *
 * Tier: Pro+ ($69/mo) and Agency ($179/mo) — `schema_blocks_5` feature in
 * License_Manager::PROPLUS_FEATURES.
 *
 * Storage shape (post meta `_seobetter_schema_blocks`):
 *   [
 *     'product'    => [ 'enabled' => true, 'name' => '…', 'price' => '129.00', … ],
 *     'event'      => [ 'enabled' => false, … ],
 *     'localbusiness' => [ 'enabled' => true, … ],
 *     'vacationrental' => [ 'enabled' => false, … ],
 *     'jobposting' => [ 'enabled' => false, … ],
 *   ]
 *
 * Each block uses `enabled` flag rather than presence-as-enabled because
 * the user may have started filling in a block then disabled it; we want
 * to preserve their inputs without emitting the schema.
 */
class Schema_Blocks_Manager {

    private const META_KEY = '_seobetter_schema_blocks';

    /**
     * The 5 supported block types — keep in sync with structured-data.md §4
     * and Schema_Generator's auto-detect counterparts.
     */
    public const BLOCK_TYPES = [ 'product', 'event', 'localbusiness', 'vacationrental', 'jobposting' ];

    /**
     * Get all schema blocks for a post. Returns array keyed by block type;
     * missing blocks return as empty arrays (not undefined).
     */
    public static function get_all( int $post_id ): array {
        $stored = get_post_meta( $post_id, self::META_KEY, true );
        $stored = is_array( $stored ) ? $stored : [];
        $out = [];
        foreach ( self::BLOCK_TYPES as $type ) {
            $out[ $type ] = $stored[ $type ] ?? [];
        }
        return $out;
    }

    /**
     * Get a single block by type. Returns null when not set.
     */
    public static function get( int $post_id, string $block_type ): ?array {
        $all = self::get_all( $post_id );
        $block = $all[ $block_type ] ?? [];
        return empty( $block ) ? null : $block;
    }

    /**
     * Save (replace) the full blocks array for a post.
     *
     * @param int   $post_id
     * @param array $blocks  Map of block_type => block_data
     * @return array{success:bool,error?:string,blocks?:array}
     */
    public static function save_all( int $post_id, array $blocks ): array {
        if ( ! License_Manager::can_use( 'schema_blocks_5' ) ) {
            return [ 'success' => false, 'error' => __( 'Schema Blocks require SEOBetter Pro+ ($69/mo).', 'seobetter' ) ];
        }

        $sanitized = [];
        foreach ( self::BLOCK_TYPES as $type ) {
            $raw = is_array( $blocks[ $type ] ?? null ) ? $blocks[ $type ] : [];
            $sanitized[ $type ] = self::sanitize_block( $type, $raw );
        }

        update_post_meta( $post_id, self::META_KEY, $sanitized );

        return [ 'success' => true, 'blocks' => $sanitized ];
    }

    /**
     * Delete all schema blocks for a post.
     */
    public static function delete_all( int $post_id ): bool {
        return delete_post_meta( $post_id, self::META_KEY );
    }

    /**
     * Per-type sanitization. Each block has its own field schema. Anything
     * not in the schema is dropped. `enabled` always preserved.
     */
    private static function sanitize_block( string $type, array $data ): array {
        $enabled = ! empty( $data['enabled'] );
        $clean = [ 'enabled' => $enabled ];

        $field_map = [
            'product' => [
                'name'         => 'text',
                'description'  => 'textarea',
                'brand'        => 'text',
                'image_url'    => 'url',
                'sku'          => 'text',
                'mpn'          => 'text',
                'gtin'         => 'text',
                'price'        => 'text',         // string preserves trailing zeros
                'currency'     => 'text',
                'availability' => 'select',       // InStock / OutOfStock / PreOrder
                'condition'    => 'select',       // NewCondition / UsedCondition / RefurbishedCondition
                'rating_value' => 'text',
                'rating_count' => 'int',
            ],
            'event' => [
                'name'             => 'text',
                'description'      => 'textarea',
                'start_date'       => 'datetime',
                'end_date'         => 'datetime',
                'event_status'     => 'select',   // EventScheduled / EventPostponed / EventCancelled / EventMovedOnline
                'attendance_mode'  => 'select',   // OfflineEventAttendanceMode / OnlineEventAttendanceMode / MixedEventAttendanceMode
                'location_name'    => 'text',
                'location_address' => 'textarea',
                'organizer_name'   => 'text',
                'organizer_url'    => 'url',
                'offers_url'       => 'url',
                'offers_price'     => 'text',
                'offers_currency'  => 'text',
            ],
            'localbusiness' => [
                'business_type'    => 'select',   // LocalBusiness / Restaurant / Store / FoodEstablishment / etc.
                'name'             => 'text',
                'description'      => 'textarea',
                'street_address'   => 'text',
                'locality'         => 'text',
                'region'           => 'text',
                'postal_code'      => 'text',
                'country'          => 'text',
                'telephone'        => 'text',
                'image_url'        => 'url',
                'opening_hours'    => 'textarea', // free-text per OSM convention "Mo-Fr 09:00-17:00"
                'price_range'      => 'text',     // "$$" / "$$$"
                'latitude'         => 'text',
                'longitude'        => 'text',
            ],
            'vacationrental' => [
                'name'             => 'text',
                'description'      => 'textarea',
                'street_address'   => 'text',
                'locality'         => 'text',
                'region'           => 'text',
                'postal_code'      => 'text',
                'country'          => 'text',
                'image_url'        => 'url',
                'number_of_rooms'  => 'int',
                'occupancy_max'    => 'int',
                'amenities'        => 'textarea', // newline-separated list
                'price_range'      => 'text',
                'pet_friendly'     => 'bool',
            ],
            'jobposting' => [
                'title'                   => 'text',
                'description'             => 'textarea',
                'date_posted'             => 'date',
                'valid_through'           => 'date',
                'employment_type'         => 'select', // FULL_TIME / PART_TIME / CONTRACTOR / TEMPORARY / INTERN / VOLUNTEER / PER_DIEM / OTHER
                'hiring_organization'     => 'text',
                'hiring_organization_url' => 'url',
                'job_location_address'    => 'text',
                'job_location_locality'   => 'text',
                'job_location_region'     => 'text',
                'job_location_country'    => 'text',
                'remote_ok'               => 'bool',
                'salary_min'              => 'text',
                'salary_max'              => 'text',
                'salary_currency'         => 'text',
                'salary_unit'             => 'select',  // HOUR / DAY / WEEK / MONTH / YEAR
            ],
        ];

        $schema = $field_map[ $type ] ?? [];
        foreach ( $schema as $field => $field_type ) {
            $val = $data[ $field ] ?? '';
            switch ( $field_type ) {
                case 'text':
                case 'select':
                    $clean[ $field ] = sanitize_text_field( (string) $val );
                    break;
                case 'textarea':
                    $clean[ $field ] = sanitize_textarea_field( (string) $val );
                    break;
                case 'url':
                    $clean[ $field ] = esc_url_raw( (string) $val );
                    break;
                case 'int':
                    $clean[ $field ] = $val === '' ? '' : absint( $val );
                    break;
                case 'bool':
                    $clean[ $field ] = ! empty( $val );
                    break;
                case 'date':
                case 'datetime':
                    // Accept any date-parseable string; output ISO 8601 for JSON-LD
                    $clean[ $field ] = (string) $val !== '' && strtotime( (string) $val ) !== false
                        ? sanitize_text_field( (string) $val )
                        : '';
                    break;
                default:
                    $clean[ $field ] = sanitize_text_field( (string) $val );
            }
        }
        return $clean;
    }

    // ====================================================================
    // JSON-LD assembly — converts saved block data into Schema.org JSON-LD
    // suitable for inclusion in the @graph. Called by Schema_Generator.
    //
    // Each builder returns null when:
    //   - The block isn't enabled, OR
    //   - Required fields per `structured-data.md` §4 are missing (we don't
    //     emit invalid schema; better to show nothing than a Rich Results
    //     Test failure)
    // ====================================================================

    /**
     * Build JSON-LD for all schema blocks on a post.
     *
     * v1.5.216.62.28 — dual-source: walks Gutenberg blocks in
     * post_content (the new v62.28 native blocks) AND legacy post meta
     * (the pre-v62.28 metabox-panel storage). When a single post has
     * BOTH a Gutenberg block AND a legacy meta entry of the same type,
     * the Gutenberg block wins (single source of truth, single @type
     * node in @graph — Google flags duplicates).
     *
     * Posts created post-v62.28 use Gutenberg blocks exclusively. Legacy
     * post meta path is read-only — kept so customers who saved schema
     * via the metabox panel before v62.28 don't lose their schema after
     * the panel is retired. Customers can migrate by re-saving each
     * legacy entry as a Gutenberg block (or via WP-CLI in a future
     * release).
     *
     * Returns array of nodes ready to merge into @graph. Disabled /
     * invalid blocks skipped silently — same fail-closed behavior as
     * the original implementation.
     *
     * @param int $post_id WordPress post ID.
     * @return array<int, array> JSON-LD nodes.
     */
    public static function build_all_jsonld( int $post_id ): array {
        $nodes = [];
        $seen_block_types = [];

        // === New path: Gutenberg blocks in post_content ===
        // Multiple blocks of the same type can co-exist (e.g. 5 Product
        // cards in one buying guide); we emit a JSON-LD node per block.
        if ( class_exists( Schema_Blocks_Registry::class ) ) {
            $found = Schema_Blocks_Registry::collect_blocks_from_post( $post_id );
            foreach ( $found as $entry ) {
                $type  = $entry['type'];
                $attrs = $entry['attrs'];
                $node  = self::build_jsonld( $type, $attrs );
                if ( $node ) {
                    $nodes[] = $node;
                    $seen_block_types[ $type ] = true;
                }
            }
        }

        // === Legacy path: post meta (pre-v62.28 metabox panel data) ===
        // Skipped per-type when a Gutenberg block of the same type
        // already emitted — avoids duplicate @type nodes in @graph.
        $all = self::get_all( $post_id );
        foreach ( self::BLOCK_TYPES as $type ) {
            if ( isset( $seen_block_types[ $type ] ) ) continue;
            $block = $all[ $type ] ?? [];
            if ( empty( $block['enabled'] ) ) continue;
            $node = self::build_jsonld( $type, $block );
            if ( $node ) $nodes[] = $node;
        }
        return $nodes;
    }

    /**
     * Per-type JSON-LD builder. Maps to Schema.org with required fields
     * checked first.
     */
    public static function build_jsonld( string $type, array $b ): ?array {
        switch ( $type ) {
            case 'product':         return self::build_product_jsonld( $b );
            case 'event':           return self::build_event_jsonld( $b );
            case 'localbusiness':   return self::build_localbusiness_jsonld( $b );
            case 'vacationrental':  return self::build_vacationrental_jsonld( $b );
            case 'jobposting':      return self::build_jobposting_jsonld( $b );
        }
        return null;
    }

    /** Product — Required: name, image, offers.price, offers.priceCurrency, offers.availability */
    private static function build_product_jsonld( array $b ): ?array {
        if ( empty( $b['name'] ) || empty( $b['price'] ) || empty( $b['currency'] ) ) {
            return null;
        }
        $node = [
            '@type' => 'Product',
            'name'  => $b['name'],
        ];
        if ( ! empty( $b['description'] ) ) $node['description'] = $b['description'];
        if ( ! empty( $b['brand'] ) )       $node['brand'] = [ '@type' => 'Brand', 'name' => $b['brand'] ];
        if ( ! empty( $b['image_url'] ) )   $node['image'] = $b['image_url'];
        if ( ! empty( $b['sku'] ) )         $node['sku'] = $b['sku'];
        if ( ! empty( $b['mpn'] ) )         $node['mpn'] = $b['mpn'];
        if ( ! empty( $b['gtin'] ) )        $node['gtin'] = $b['gtin'];

        $availability = $b['availability'] ?: 'InStock';
        $offers = [
            '@type'         => 'Offer',
            'price'         => $b['price'],
            'priceCurrency' => $b['currency'],
            'availability'  => 'https://schema.org/' . $availability,
        ];
        if ( ! empty( $b['condition'] ) ) {
            $offers['itemCondition'] = 'https://schema.org/' . $b['condition'];
        }
        $node['offers'] = $offers;

        if ( ! empty( $b['rating_value'] ) && ! empty( $b['rating_count'] ) ) {
            $node['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $b['rating_value'],
                'reviewCount' => (int) $b['rating_count'],
            ];
        }
        return $node;
    }

    /** Event — Required: name, startDate, location.name, location.address */
    private static function build_event_jsonld( array $b ): ?array {
        if ( empty( $b['name'] ) || empty( $b['start_date'] ) ) return null;
        if ( empty( $b['location_name'] ) && empty( $b['location_address'] ) ) return null;

        $node = [
            '@type'     => 'Event',
            'name'      => $b['name'],
            'startDate' => self::iso_datetime( $b['start_date'] ),
        ];
        if ( ! empty( $b['description'] ) )  $node['description'] = $b['description'];
        if ( ! empty( $b['end_date'] ) )     $node['endDate'] = self::iso_datetime( $b['end_date'] );
        if ( ! empty( $b['event_status'] ) ) $node['eventStatus'] = 'https://schema.org/' . $b['event_status'];
        if ( ! empty( $b['attendance_mode'] ) ) $node['eventAttendanceMode'] = 'https://schema.org/' . $b['attendance_mode'];

        $location = [];
        $location['@type'] = ( ( $b['attendance_mode'] ?? '' ) === 'OnlineEventAttendanceMode' ) ? 'VirtualLocation' : 'Place';
        if ( ! empty( $b['location_name'] ) )    $location['name'] = $b['location_name'];
        if ( ! empty( $b['location_address'] ) ) $location['address'] = $b['location_address'];
        $node['location'] = $location;

        if ( ! empty( $b['organizer_name'] ) ) {
            $organizer = [ '@type' => 'Organization', 'name' => $b['organizer_name'] ];
            if ( ! empty( $b['organizer_url'] ) ) $organizer['url'] = self::normalize_url( $b['organizer_url'] );
            $node['organizer'] = $organizer;
        }
        if ( ! empty( $b['offers_url'] ) ) {
            $offers = [ '@type' => 'Offer', 'url' => self::normalize_url( $b['offers_url'] ) ];
            if ( ! empty( $b['offers_price'] ) )    $offers['price'] = $b['offers_price'];
            if ( ! empty( $b['offers_currency'] ) ) $offers['priceCurrency'] = $b['offers_currency'];
            $node['offers'] = $offers;
        }
        return $node;
    }

    /** LocalBusiness — Required: name, address. Telephone + openingHours strongly recommended */
    private static function build_localbusiness_jsonld( array $b ): ?array {
        if ( empty( $b['name'] ) ) return null;
        if ( empty( $b['street_address'] ) && empty( $b['locality'] ) ) return null;

        $type = $b['business_type'] ?: 'LocalBusiness';
        $node = [
            '@type' => $type,
            'name'  => $b['name'],
        ];
        if ( ! empty( $b['description'] ) ) $node['description'] = $b['description'];

        $address = [ '@type' => 'PostalAddress' ];
        if ( ! empty( $b['street_address'] ) ) $address['streetAddress'] = $b['street_address'];
        if ( ! empty( $b['locality'] ) )       $address['addressLocality'] = $b['locality'];
        if ( ! empty( $b['region'] ) )         $address['addressRegion'] = $b['region'];
        if ( ! empty( $b['postal_code'] ) )    $address['postalCode'] = $b['postal_code'];
        if ( ! empty( $b['country'] ) )        $address['addressCountry'] = $b['country'];
        $node['address'] = $address;

        if ( ! empty( $b['telephone'] ) )     $node['telephone'] = $b['telephone'];
        if ( ! empty( $b['image_url'] ) )     $node['image'] = $b['image_url'];
        if ( ! empty( $b['opening_hours'] ) ) {
            $node['openingHours'] = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $b['opening_hours'] ) ?: [] ) ) );
        }
        if ( ! empty( $b['price_range'] ) )   $node['priceRange'] = $b['price_range'];

        if ( ! empty( $b['latitude'] ) && ! empty( $b['longitude'] ) ) {
            $node['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => $b['latitude'],
                'longitude' => $b['longitude'],
            ];
        }
        return $node;
    }

    /**
     * VacationRental — Schema.org subclass of Accommodation.
     *
     * v1.5.216.62.40 — @type changed from `["LodgingBusiness", "VacationRental"]`
     * to just `"VacationRental"`. The two parent classes were semantically
     * incompatible: VacationRental inherits from Accommodation (Place →
     * Accommodation), whereas LodgingBusiness inherits from
     * LocalBusiness (Place → Organization). Properties like `occupancy`,
     * `numberOfRooms`, and `amenityFeature` are only defined on
     * Accommodation — Schema.org Validator warned "occupancy is not
     * recognised for an object of type LodgingBusiness". Dropping
     * LodgingBusiness keeps every existing field valid and matches
     * Google's documented VacationRental rich-result spec.
     */
    private static function build_vacationrental_jsonld( array $b ): ?array {
        if ( empty( $b['name'] ) ) return null;
        if ( empty( $b['street_address'] ) && empty( $b['locality'] ) ) return null;

        $node = [
            '@type' => 'VacationRental',
            'name'  => $b['name'],
        ];
        if ( ! empty( $b['description'] ) ) $node['description'] = $b['description'];

        $address = [ '@type' => 'PostalAddress' ];
        if ( ! empty( $b['street_address'] ) ) $address['streetAddress'] = $b['street_address'];
        if ( ! empty( $b['locality'] ) )       $address['addressLocality'] = $b['locality'];
        if ( ! empty( $b['region'] ) )         $address['addressRegion'] = $b['region'];
        if ( ! empty( $b['postal_code'] ) )    $address['postalCode'] = $b['postal_code'];
        if ( ! empty( $b['country'] ) )        $address['addressCountry'] = $b['country'];
        $node['address'] = $address;

        if ( ! empty( $b['image_url'] ) )       $node['image'] = $b['image_url'];
        if ( ! empty( $b['number_of_rooms'] ) ) $node['numberOfRooms'] = (int) $b['number_of_rooms'];
        if ( ! empty( $b['occupancy_max'] ) ) {
            $node['occupancy'] = [
                '@type' => 'QuantitativeValue',
                'maxValue' => (int) $b['occupancy_max'],
            ];
        }
        if ( ! empty( $b['amenities'] ) ) {
            $items = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $b['amenities'] ) ?: [] ) ) );
            $node['amenityFeature'] = array_map( fn( $a ) => [ '@type' => 'LocationFeatureSpecification', 'name' => $a, 'value' => true ], $items );
        }
        if ( ! empty( $b['price_range'] ) ) $node['priceRange'] = $b['price_range'];
        if ( ! empty( $b['pet_friendly'] ) ) {
            $node['petsAllowed'] = true;
        }
        return $node;
    }

    /** JobPosting — Required: title, description, datePosted, hiringOrganization.name, jobLocation.address, employmentType */
    private static function build_jobposting_jsonld( array $b ): ?array {
        if ( empty( $b['title'] ) || empty( $b['description'] ) ) return null;
        if ( empty( $b['date_posted'] ) || empty( $b['hiring_organization'] ) ) return null;

        $node = [
            '@type'              => 'JobPosting',
            'title'              => $b['title'],
            'description'        => $b['description'],
            'datePosted'         => self::iso_date( $b['date_posted'] ),
            'hiringOrganization' => [
                '@type' => 'Organization',
                'name'  => $b['hiring_organization'],
            ],
        ];
        if ( ! empty( $b['hiring_organization_url'] ) ) {
            $node['hiringOrganization']['sameAs'] = self::normalize_url( $b['hiring_organization_url'] );
        }
        if ( ! empty( $b['valid_through'] ) )   $node['validThrough'] = self::iso_datetime( $b['valid_through'] );
        if ( ! empty( $b['employment_type'] ) ) $node['employmentType'] = $b['employment_type'];

        if ( ! empty( $b['job_location_address'] ) || ! empty( $b['job_location_locality'] ) ) {
            $job_address = [ '@type' => 'PostalAddress' ];
            if ( ! empty( $b['job_location_address'] ) )  $job_address['streetAddress'] = $b['job_location_address'];
            if ( ! empty( $b['job_location_locality'] ) ) $job_address['addressLocality'] = $b['job_location_locality'];
            if ( ! empty( $b['job_location_region'] ) )   $job_address['addressRegion'] = $b['job_location_region'];
            if ( ! empty( $b['job_location_country'] ) )  $job_address['addressCountry'] = $b['job_location_country'];
            $node['jobLocation'] = [ '@type' => 'Place', 'address' => $job_address ];
        }
        if ( ! empty( $b['remote_ok'] ) ) {
            $node['jobLocationType'] = 'TELECOMMUTE';
        }

        if ( ! empty( $b['salary_min'] ) || ! empty( $b['salary_max'] ) ) {
            $node['baseSalary'] = [
                '@type'    => 'MonetaryAmount',
                'currency' => $b['salary_currency'] ?: 'USD',
                'value'    => [
                    '@type'    => 'QuantitativeValue',
                    'unitText' => $b['salary_unit'] ?: 'YEAR',
                ],
            ];
            if ( ! empty( $b['salary_min'] ) ) $node['baseSalary']['value']['minValue'] = (float) $b['salary_min'];
            if ( ! empty( $b['salary_max'] ) ) $node['baseSalary']['value']['maxValue'] = (float) $b['salary_max'];
        }
        return $node;
    }

    /**
     * Coerce a date input into ISO 8601 date (YYYY-MM-DD). Returns
     * empty string if the input doesn't parse.
     */
    private static function iso_date( string $input ): string {
        $ts = strtotime( $input );
        return $ts ? gmdate( 'Y-m-d', $ts ) : '';
    }

    /**
     * Coerce a datetime input into ISO 8601 datetime. Returns empty
     * string if the input doesn't parse.
     */
    private static function iso_datetime( string $input ): string {
        $ts = strtotime( $input );
        return $ts ? gmdate( 'c', $ts ) : '';
    }

    /**
     * Normalize a user-typed URL.
     *
     * v1.5.216.62.41 — users typing brand names like "seobetter.com" or
     * "www.example.com" produced JSON-LD where a URL field was a bare
     * domain. Schema.org Validator and downstream consumers expect a
     * fully-qualified URL with protocol; missing https:// is the most
     * common data-entry error in URL fields. Two-step normalization:
     *
     *   1. Trim whitespace.
     *   2. If the value doesn't already start with http:// or https://,
     *      prepend "https://" so the wire format is always valid.
     *
     * Empty input returns an empty string (callers gate on truthy values
     * before merging the URL into the JSON-LD node, so the empty-string
     * sentinel preserves existing behavior). Doesn't reject obviously
     * invalid URLs — `notarealurl` becomes `https://notarealurl`, which
     * is the user's problem; this helper only fixes the missing-protocol
     * gotcha.
     */
    private static function normalize_url( string $url ): string {
        $url = trim( $url );
        if ( $url === '' ) return '';
        if ( ! preg_match( '#^https?://#i', $url ) ) {
            $url = 'https://' . ltrim( $url, '/' );
        }
        return $url;
    }

    // ================================================================
    // FRONT-END RENDERING (v1.5.216.62.24)
    //
    // Pre-v62.24, Schema Blocks emitted JSON-LD ONLY — no visible card on the
    // post body. v62.24 adds styled cards so a Pro+ user filling in e.g. a
    // LocalBusiness block sees both the schema (machine-readable, for Google +
    // LLMs) and a human-readable card (typography matched to article_design
    // §11). Hooked from seobetter.php into the `the_content` filter so the
    // cards prepend the post body when rendered on the front end.
    //
    // All cards use inline styles to be theme-proof (the plugin's standard
    // approach — see article_design.md §11). Mobile-first single-column layout,
    // accent colors mirror the article's primary color where available.
    // ================================================================

    /**
     * Build the concatenated HTML for every enabled block on a post.
     * Returns an empty string when no enabled+valid block is found.
     *
     * @param int $post_id WordPress post ID.
     * @return string Concatenated HTML for all enabled blocks.
     */
    public static function render_all_html( int $post_id ): string {
        $blocks = self::get_all( $post_id );
        if ( empty( $blocks ) ) return '';
        $out = '';
        foreach ( self::BLOCK_TYPES as $type ) {
            $b = $blocks[ $type ] ?? null;
            if ( ! $b || empty( $b['enabled'] ) ) continue;
            $html = self::render_html( $type, $b );
            if ( $html !== '' ) $out .= $html;
        }
        return $out;
    }

    /**
     * Dispatch to the per-type render method. Returns empty string if the
     * block lacks the minimum fields needed to render a meaningful card
     * (mirrors the build_*_jsonld() validation — never render a half-empty
     * card the same way we never emit invalid schema).
     */
    public static function render_html( string $type, array $b ): string {
        switch ( $type ) {
            case 'product':         return self::render_product_card( $b );
            case 'event':           return self::render_event_card( $b );
            case 'localbusiness':   return self::render_localbusiness_card( $b );
            case 'vacationrental':  return self::render_vacationrental_card( $b );
            case 'jobposting':      return self::render_jobposting_card( $b );
        }
        return '';
    }

    /**
     * LocalBusiness card. Required: name + (street_address OR locality).
     * Renders a header with business-type icon, formatted PostalAddress,
     * click-to-call phone, opening-hours table, price-range badge, and
     * a Google Maps directions link.
     */
    private static function render_localbusiness_card( array $b ): string {
        if ( empty( $b['name'] ) ) return '';
        if ( empty( $b['street_address'] ) && empty( $b['locality'] ) ) return '';

        $name        = esc_html( $b['name'] );
        $type        = $b['business_type'] ?? 'LocalBusiness';
        $type_label  = self::humanize_business_type( $type );
        $description = ! empty( $b['description'] ) ? esc_html( $b['description'] ) : '';
        $telephone   = ! empty( $b['telephone'] ) ? $b['telephone'] : '';
        $price_range = ! empty( $b['price_range'] ) ? esc_html( $b['price_range'] ) : '';
        $image_url   = ! empty( $b['image_url'] ) ? esc_url( $b['image_url'] ) : '';
        $address_lines = self::format_address_lines( $b );
        $address_html  = implode( '<br>', array_map( 'esc_html', $address_lines ) );
        $maps_url      = self::build_maps_directions_url( $b );
        $hours_lines   = ! empty( $b['opening_hours'] )
            ? array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $b['opening_hours'] ) ?: [] ) ) )
            : [];

        $tel_safe = esc_attr( preg_replace( '/[^\d+]/', '', $telephone ) );

        $html  = '<div class="sb-localbusiness-card" style="border:1px solid #e5e7eb;border-radius:12px;padding:1.25em 1.5em;margin:1.5em 0;background:#ffffff !important;color:#1e293b !important;line-height:1.65;box-shadow:0 1px 3px rgba(0,0,0,0.04)">';
        // Header
        $html .= '<div style="display:flex;align-items:center;gap:0.75em;margin-bottom:0.75em;flex-wrap:wrap">';
        $html .= '<span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;background:#f1f5f9;color:#0f172a !important;font-size:1.1em;flex-shrink:0">📍</span>';
        $html .= '<div style="flex:1;min-width:0">';
        $html .= '<div style="font-size:1.15em;font-weight:700;color:#0f172a !important;line-height:1.3">' . $name . '</div>';
        $html .= '<div style="font-size:0.8em;color:#64748b !important;text-transform:uppercase;letter-spacing:0.04em;font-weight:600;margin-top:2px">' . esc_html( $type_label ) . '</div>';
        $html .= '</div>';
        if ( $price_range !== '' ) {
            $html .= '<span style="font-size:0.85em;font-weight:700;color:#166534 !important;background:#dcfce7;padding:3px 10px;border-radius:999px">' . $price_range . '</span>';
        }
        $html .= '</div>';

        // Optional image strip
        if ( $image_url !== '' ) {
            $html .= '<img src="' . $image_url . '" alt="' . $name . '" style="width:100%;max-height:220px;object-fit:cover;border-radius:8px;margin-bottom:0.75em" />';
        }

        // Description
        if ( $description !== '' ) {
            $html .= '<p style="margin:0 0 0.75em;color:#334155 !important;font-size:0.95em">' . $description . '</p>';
        }

        // Address + phone block
        $html .= '<div style="display:grid;grid-template-columns:auto 1fr;gap:0.5em 0.75em;margin:0.75em 0;font-size:0.95em">';
        $html .= '<span style="color:#64748b !important">Address</span>';
        $html .= '<span style="color:#0f172a !important">' . $address_html . '</span>';
        if ( $telephone !== '' ) {
            $html .= '<span style="color:#64748b !important">Phone</span>';
            $html .= '<span style="color:#0f172a !important"><a href="tel:' . $tel_safe . '" style="color:#0369a1 !important;text-decoration:none;font-weight:600">' . esc_html( $telephone ) . '</a></span>';
        }
        $html .= '</div>';

        // Opening hours
        if ( ! empty( $hours_lines ) ) {
            $html .= '<div style="margin-top:0.75em;padding-top:0.75em;border-top:1px solid #f1f5f9">';
            $html .= '<div style="font-size:0.8em;font-weight:700;color:#64748b !important;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.4em">Hours</div>';
            $html .= '<ul style="list-style:none;padding:0;margin:0;font-size:0.9em">';
            foreach ( $hours_lines as $line ) {
                $html .= '<li style="color:#334155 !important;padding:2px 0">' . esc_html( $line ) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        // CTA — Google Maps
        if ( $maps_url !== '' ) {
            $html .= '<div style="margin-top:1em;display:flex;gap:0.5em;flex-wrap:wrap">';
            $html .= '<a href="' . esc_url( $maps_url ) . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;background:#0f172a !important;color:#ffffff !important;text-decoration:none;padding:8px 16px;border-radius:8px;font-size:0.9em;font-weight:600">→ Directions</a>';
            if ( $telephone !== '' ) {
                $html .= '<a href="tel:' . $tel_safe . '" style="display:inline-flex;align-items:center;gap:6px;border:1px solid #e5e7eb;color:#0f172a !important;text-decoration:none;padding:8px 16px;border-radius:8px;font-size:0.9em;font-weight:600">📞 Call</a>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Product card. Required: name, price, currency.
     */
    private static function render_product_card( array $b ): string {
        if ( empty( $b['name'] ) || empty( $b['price'] ) || empty( $b['currency'] ) ) return '';
        $name        = esc_html( $b['name'] );
        $brand       = ! empty( $b['brand'] ) ? esc_html( $b['brand'] ) : '';
        $description = ! empty( $b['description'] ) ? esc_html( $b['description'] ) : '';
        $image_url   = ! empty( $b['image_url'] ) ? esc_url( $b['image_url'] ) : '';
        $price       = number_format_i18n( (float) $b['price'], 2 );
        $currency    = esc_html( $b['currency'] );
        $availability = ! empty( $b['availability'] ) ? esc_html( str_replace( 'https://schema.org/', '', $b['availability'] ) ) : '';
        $sku         = ! empty( $b['sku'] ) ? esc_html( $b['sku'] ) : '';

        $html  = '<div class="sb-product-card" style="border:1px solid #e5e7eb;border-radius:12px;padding:1.25em 1.5em;margin:1.5em 0;background:#ffffff !important;color:#1e293b !important;line-height:1.65;box-shadow:0 1px 3px rgba(0,0,0,0.04);display:flex;gap:1.25em;flex-wrap:wrap;align-items:flex-start">';
        if ( $image_url !== '' ) {
            $html .= '<img src="' . $image_url . '" alt="' . $name . '" style="width:140px;height:140px;object-fit:cover;border-radius:8px;flex-shrink:0" />';
        }
        $html .= '<div style="flex:1;min-width:200px">';
        if ( $brand !== '' ) {
            $html .= '<div style="font-size:0.8em;color:#64748b !important;text-transform:uppercase;letter-spacing:0.06em;font-weight:600;margin-bottom:0.25em">' . $brand . '</div>';
        }
        $html .= '<div style="font-size:1.15em;font-weight:700;color:#0f172a !important;margin-bottom:0.5em">' . $name . '</div>';
        if ( $description !== '' ) {
            $html .= '<p style="margin:0 0 0.75em;color:#334155 !important;font-size:0.95em">' . $description . '</p>';
        }
        $html .= '<div style="display:flex;gap:0.75em;align-items:center;flex-wrap:wrap">';
        $html .= '<span style="font-size:1.4em;font-weight:800;color:#0f172a !important">' . $currency . ' ' . $price . '</span>';
        if ( $availability !== '' ) {
            $html .= '<span style="font-size:0.85em;font-weight:600;color:#166534 !important;background:#dcfce7;padding:3px 10px;border-radius:999px">' . $availability . '</span>';
        }
        $html .= '</div>';
        if ( $sku !== '' ) {
            $html .= '<div style="font-size:0.8em;color:#94a3b8 !important;margin-top:0.5em">SKU: ' . $sku . '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Event card. Required: name, startDate, location.
     */
    private static function render_event_card( array $b ): string {
        if ( empty( $b['name'] ) || empty( $b['start_date'] ) ) return '';
        $name        = esc_html( $b['name'] );
        $description = ! empty( $b['description'] ) ? esc_html( $b['description'] ) : '';
        $start_iso   = self::iso_datetime( $b['start_date'] );
        $start_human = $start_iso ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $start_iso ) ) ) : esc_html( $b['start_date'] );
        $location    = ! empty( $b['location_name'] ) ? esc_html( $b['location_name'] ) : ( ! empty( $b['location_address'] ) ? esc_html( $b['location_address'] ) : '' );
        $image_url   = ! empty( $b['image_url'] ) ? esc_url( $b['image_url'] ) : '';
        $url         = ! empty( $b['url'] ) ? esc_url( $b['url'] ) : '';

        $html  = '<div class="sb-event-card" style="border:1px solid #e5e7eb;border-radius:12px;padding:1.25em 1.5em;margin:1.5em 0;background:#ffffff !important;color:#1e293b !important;line-height:1.65;box-shadow:0 1px 3px rgba(0,0,0,0.04)">';
        $html .= '<div style="display:flex;align-items:center;gap:0.75em;margin-bottom:0.75em">';
        $html .= '<span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;background:#fef3c7;color:#78350f !important;font-size:1.1em">📅</span>';
        $html .= '<div style="flex:1"><div style="font-size:1.15em;font-weight:700;color:#0f172a !important">' . $name . '</div></div>';
        $html .= '</div>';
        if ( $image_url !== '' ) {
            $html .= '<img src="' . $image_url . '" alt="' . $name . '" style="width:100%;max-height:220px;object-fit:cover;border-radius:8px;margin-bottom:0.75em" />';
        }
        if ( $description !== '' ) {
            $html .= '<p style="margin:0 0 0.75em;color:#334155 !important;font-size:0.95em">' . $description . '</p>';
        }
        $html .= '<div style="display:grid;grid-template-columns:auto 1fr;gap:0.5em 0.75em;font-size:0.95em">';
        $html .= '<span style="color:#64748b !important">When</span><span style="color:#0f172a !important">' . $start_human . '</span>';
        if ( $location !== '' ) {
            $html .= '<span style="color:#64748b !important">Where</span><span style="color:#0f172a !important">' . $location . '</span>';
        }
        $html .= '</div>';
        if ( $url !== '' ) {
            $html .= '<div style="margin-top:1em"><a href="' . $url . '" target="_blank" rel="noopener" style="display:inline-block;background:#78350f !important;color:#fff !important;text-decoration:none;padding:8px 16px;border-radius:8px;font-size:0.9em;font-weight:600">Get tickets →</a></div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * VacationRental card. Required: name + (street_address OR locality).
     */
    private static function render_vacationrental_card( array $b ): string {
        if ( empty( $b['name'] ) ) return '';
        if ( empty( $b['street_address'] ) && empty( $b['locality'] ) ) return '';
        $name        = esc_html( $b['name'] );
        $description = ! empty( $b['description'] ) ? esc_html( $b['description'] ) : '';
        $rooms       = ! empty( $b['number_of_rooms'] ) ? (int) $b['number_of_rooms'] : 0;
        $occupancy   = ! empty( $b['occupancy_max'] ) ? (int) $b['occupancy_max'] : 0;
        $price_range = ! empty( $b['price_range'] ) ? esc_html( $b['price_range'] ) : '';
        $image_url   = ! empty( $b['image_url'] ) ? esc_url( $b['image_url'] ) : '';
        $address_html = implode( ', ', array_map( 'esc_html', self::format_address_lines( $b ) ) );

        $html  = '<div class="sb-vacationrental-card" style="border:1px solid #e5e7eb;border-radius:12px;padding:1.25em 1.5em;margin:1.5em 0;background:#ffffff !important;color:#1e293b !important;line-height:1.65;box-shadow:0 1px 3px rgba(0,0,0,0.04)">';
        if ( $image_url !== '' ) {
            $html .= '<img src="' . $image_url . '" alt="' . $name . '" style="width:100%;max-height:280px;object-fit:cover;border-radius:8px;margin-bottom:1em" />';
        }
        $html .= '<div style="display:flex;justify-content:space-between;gap:0.75em;flex-wrap:wrap;margin-bottom:0.5em">';
        $html .= '<div style="font-size:1.15em;font-weight:700;color:#0f172a !important;flex:1">' . $name . '</div>';
        if ( $price_range !== '' ) $html .= '<span style="font-size:0.9em;font-weight:700;color:#0369a1 !important">' . $price_range . '</span>';
        $html .= '</div>';
        $html .= '<div style="font-size:0.9em;color:#64748b !important;margin-bottom:0.75em">' . $address_html . '</div>';
        if ( $description !== '' ) {
            $html .= '<p style="margin:0 0 0.75em;color:#334155 !important;font-size:0.95em">' . $description . '</p>';
        }
        $facts = [];
        if ( $rooms > 0 ) $facts[] = sprintf( '%d room%s', $rooms, $rooms === 1 ? '' : 's' );
        if ( $occupancy > 0 ) $facts[] = sprintf( 'Sleeps %d', $occupancy );
        if ( ! empty( $b['pet_friendly'] ) ) $facts[] = '🐾 Pet-friendly';
        if ( ! empty( $facts ) ) {
            $html .= '<div style="display:flex;gap:0.75em;flex-wrap:wrap;font-size:0.9em;color:#0f172a !important">';
            foreach ( $facts as $f ) {
                $html .= '<span style="background:#f1f5f9;padding:4px 10px;border-radius:999px">' . esc_html( $f ) . '</span>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * JobPosting card.
     */
    private static function render_jobposting_card( array $b ): string {
        if ( empty( $b['title'] ) || empty( $b['description'] ) ) return '';
        if ( empty( $b['date_posted'] ) || empty( $b['hiring_organization'] ) ) return '';
        $title       = esc_html( $b['title'] );
        $org         = esc_html( $b['hiring_organization'] );
        $description = esc_html( $b['description'] );
        $location    = ! empty( $b['job_location'] ) ? esc_html( $b['job_location'] ) : '';
        $employment  = ! empty( $b['employment_type'] ) ? esc_html( str_replace( '_', ' ', strtolower( $b['employment_type'] ) ) ) : '';
        $salary      = ! empty( $b['salary'] ) ? esc_html( $b['salary'] ) : '';
        $url         = ! empty( $b['apply_url'] ) ? esc_url( $b['apply_url'] ) : '';

        $html  = '<div class="sb-jobposting-card" style="border:1px solid #e5e7eb;border-radius:12px;padding:1.25em 1.5em;margin:1.5em 0;background:#ffffff !important;color:#1e293b !important;line-height:1.65;box-shadow:0 1px 3px rgba(0,0,0,0.04)">';
        $html .= '<div style="font-size:0.8em;color:#64748b !important;text-transform:uppercase;letter-spacing:0.06em;font-weight:600;margin-bottom:0.25em">' . $org . '</div>';
        $html .= '<div style="font-size:1.2em;font-weight:700;color:#0f172a !important;margin-bottom:0.5em">' . $title . '</div>';
        $meta = array_filter( [ $location, $employment, $salary ] );
        if ( ! empty( $meta ) ) {
            $html .= '<div style="display:flex;gap:0.5em;flex-wrap:wrap;font-size:0.85em;color:#0f172a !important;margin-bottom:0.75em">';
            foreach ( $meta as $m ) {
                $html .= '<span style="background:#f1f5f9;padding:4px 10px;border-radius:999px">' . $m . '</span>';
            }
            $html .= '</div>';
        }
        $html .= '<p style="margin:0 0 1em;color:#334155 !important;font-size:0.95em">' . $description . '</p>';
        if ( $url !== '' ) {
            $html .= '<a href="' . $url . '" target="_blank" rel="noopener" style="display:inline-block;background:#0369a1 !important;color:#fff !important;text-decoration:none;padding:8px 16px;border-radius:8px;font-size:0.9em;font-weight:600">Apply →</a>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Helper — format the postal address fields into 1-3 display lines.
     * Line 1: street_address. Line 2: locality, region postal_code. Line 3: country.
     * Skips empty fields cleanly.
     */
    private static function format_address_lines( array $b ): array {
        $lines = [];
        if ( ! empty( $b['street_address'] ) ) $lines[] = (string) $b['street_address'];
        $second = trim( implode( ' ', array_filter( [
            ! empty( $b['locality'] ) ? (string) $b['locality'] : '',
            ! empty( $b['region'] )   ? (string) $b['region']   : '',
            ! empty( $b['postal_code'] ) ? (string) $b['postal_code'] : '',
        ] ) ) );
        if ( $second !== '' ) {
            // "Locality, Region Postal" — comma separates locality from region
            if ( ! empty( $b['locality'] ) && ( ! empty( $b['region'] ) || ! empty( $b['postal_code'] ) ) ) {
                $rest = trim( implode( ' ', array_filter( [
                    ! empty( $b['region'] )      ? (string) $b['region']      : '',
                    ! empty( $b['postal_code'] ) ? (string) $b['postal_code'] : '',
                ] ) ) );
                $second = $b['locality'] . ( $rest !== '' ? ', ' . $rest : '' );
            }
            $lines[] = $second;
        }
        if ( ! empty( $b['country'] ) ) $lines[] = (string) $b['country'];
        return $lines;
    }

    /**
     * Helper — build a Google Maps directions URL.
     *
     * v1.5.216.62.39 — preference flipped: street address wins over lat/lng.
     *
     * Pre-fix the URL passed `destination=<lat>,<lng>` whenever coordinates
     * existed. Google Maps reverse-geocodes that pair to whatever POI it has
     * indexed at those coordinates — frequently a different business name
     * than the one we want to show. User-reported example: a business at
     * 50 William St, Darlinghurst with OSM-resolved coordinates rendered
     * the destination as "Christian Adam, Darlinghurst NSW 2010" (a
     * different business sharing the same coords on Google's index)
     * instead of "50 William St, Darlinghurst NSW 2010".
     *
     * Fix: pass the address string as `destination` so Google forward-geocodes
     * it and shows the address text the user typed. Lat/lng still narrows
     * the result via the optional `destination_place_id` companion — but
     * Maps URL API doesn't accept raw lat/lng, so coordinates are now a
     * fallback only (used when no address is filled in).
     */
    private static function build_maps_directions_url( array $b ): string {
        $parts = array_filter( [
            $b['street_address'] ?? '',
            $b['locality'] ?? '',
            $b['region'] ?? '',
            $b['postal_code'] ?? '',
            $b['country'] ?? '',
        ] );
        if ( ! empty( $parts ) ) {
            return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( implode( ', ', $parts ) );
        }
        if ( ! empty( $b['latitude'] ) && ! empty( $b['longitude'] ) ) {
            return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $b['latitude'] . ',' . $b['longitude'] );
        }
        return '';
    }

    /**
     * Helper — convert a Schema.org @type like "Restaurant" / "BarOrPub" /
     * "FoodEstablishment" into a readable label for the card header.
     */
    private static function humanize_business_type( string $type ): string {
        // v1.5.216.62.38 — extended from 50 → 96 entries to match the
        // expanded business_type dropdown in assets/js/schema-blocks.js
        // DEFS.localbusiness. Card header shows a friendly label; stored
        // value remains the bare Schema.org token (preserves JSON-LD).
        $map = [
            // General
            'LocalBusiness'      => 'Local Business',
            'ProfessionalService'=> 'Professional Service',

            // Food & drink
            'Restaurant'         => 'Restaurant',
            'FastFoodRestaurant' => 'Fast-Food Restaurant',
            'Cafe'               => 'Café',
            'CafeOrCoffeeShop'   => 'Café',
            'BarOrPub'           => 'Bar / Pub',
            'Bakery'             => 'Bakery',
            'IceCreamShop'       => 'Ice Cream Shop',
            'Brewery'            => 'Brewery',
            'Winery'             => 'Winery',
            'Distillery'         => 'Distillery',
            'FoodEstablishment'  => 'Food Establishment',

            // Lodging
            'Hotel'              => 'Hotel',
            'Motel'              => 'Motel',
            'BedAndBreakfast'    => 'Bed & Breakfast',
            'Hostel'             => 'Hostel',
            'Resort'             => 'Resort',
            'SkiResort'          => 'Ski Resort',
            'Campground'         => 'Campground',
            'RVPark'             => 'RV Park',
            'LodgingBusiness'    => 'Lodging',

            // Stores / retail
            'Store'              => 'Store',
            'ClothingStore'      => 'Clothing Store',
            'MensClothingStore'  => "Men's Clothing",
            'ShoeStore'          => 'Shoe Store',
            'JewelryStore'       => 'Jeweller',
            'GroceryStore'       => 'Grocery',
            'ConvenienceStore'   => 'Convenience Store',
            'LiquorStore'        => 'Liquor Store',
            'Florist'            => 'Florist',
            'BookStore'          => 'Bookstore',
            'MusicStore'         => 'Music Store',
            'HobbyShop'          => 'Hobby Shop',
            'ToyStore'           => 'Toy Store',
            'SportingGoodsStore' => 'Sporting Goods',
            'BikeStore'          => 'Bike Store',
            'PetStore'           => 'Pet Store',
            'ElectronicsStore'   => 'Electronics Store',
            'ComputerStore'      => 'Computer Store',
            'MobilePhoneStore'   => 'Mobile-Phone Store',
            'OfficeEquipmentStore'=> 'Office Equipment',
            'FurnitureStore'     => 'Furniture Store',
            'HomeGoodsStore'     => 'Home Goods',
            'HardwareStore'      => 'Hardware Store',
            'GardenStore'        => 'Garden Centre',
            'DepartmentStore'    => 'Department Store',
            'OutletStore'        => 'Outlet Store',
            'WholesaleStore'     => 'Wholesale Store',
            'PawnShop'           => 'Pawn Shop',
            'TireShop'           => 'Tire Shop',
            'AutoPartsStore'     => 'Auto Parts',
            'ShoppingCenter'     => 'Shopping Centre',

            // Health
            'Hospital'           => 'Hospital',
            'MedicalClinic'      => 'Medical Clinic',
            'Physician'          => 'Doctor',
            'Dentist'            => 'Dentist',
            'Pharmacy'           => 'Pharmacy',
            'Optician'           => 'Optician',
            'VeterinaryCare'     => 'Veterinary Care',

            // Beauty
            'BeautySalon'        => 'Beauty Salon',
            'HairSalon'          => 'Hair Salon',
            'NailSalon'          => 'Nail Salon',
            'DaySpa'             => 'Spa',
            'HealthClub'         => 'Gym',
            'TattooParlor'       => 'Tattoo Parlour',

            // Auto
            'AutoDealer'         => 'Auto Dealer',
            'AutoRepair'         => 'Auto Repair',
            'AutoBodyShop'       => 'Auto Body Shop',
            'AutoRental'         => 'Auto Rental',
            'AutoWash'           => 'Car Wash',
            'GasStation'         => 'Gas Station',
            'MotorcycleDealer'   => 'Motorcycle Dealer',
            'MotorcycleRepair'   => 'Motorcycle Repair',

            // Home services
            'Plumber'            => 'Plumber',
            'Electrician'        => 'Electrician',
            'HVACBusiness'       => 'HVAC',
            'HousePainter'       => 'House Painter',
            'RoofingContractor'  => 'Roofing Contractor',
            'GeneralContractor'  => 'General Contractor',
            'Locksmith'          => 'Locksmith',
            'MovingCompany'      => 'Moving Company',

            // Financial / legal
            'BankOrCreditUnion'  => 'Bank',
            'AutomatedTeller'    => 'ATM',
            'AccountingService'  => 'Accounting Service',
            'InsuranceAgency'    => 'Insurance Agency',
            'FinancialService'   => 'Financial Service',
            'Attorney'           => 'Attorney',
            'Notary'             => 'Notary',
            'LegalService'       => 'Legal Service',

            // Travel / real estate
            'TravelAgency'       => 'Travel Agency',
            'TouristInformationCenter' => 'Tourist Information Centre',
            'RealEstateAgent'    => 'Real Estate Agent',

            // Sports & recreation
            'BowlingAlley'       => 'Bowling Alley',
            'GolfCourse'         => 'Golf Course',
            'SportsClub'         => 'Sports Club',
            'StadiumOrArena'     => 'Stadium / Arena',
            'TennisComplex'      => 'Tennis Complex',
            'PublicSwimmingPool' => 'Swimming Pool',
            'ExerciseGym'        => 'Exercise Gym',

            // Entertainment
            'MovieTheater'       => 'Cinema',
            'AmusementPark'      => 'Amusement Park',
            'ArtGallery'         => 'Art Gallery',
            'Casino'             => 'Casino',
            'ComedyClub'         => 'Comedy Club',
            'NightClub'          => 'Night Club',
            'EntertainmentBusiness' => 'Entertainment',

            // Civic
            'Library'            => 'Library',
            'Museum'             => 'Museum',
            'TouristAttraction'  => 'Tourist Attraction',
            'GovernmentOffice'   => 'Government Office',
            'PostOffice'         => 'Post Office',
            'FireStation'        => 'Fire Station',
            'PoliceStation'      => 'Police Station',
            'EmergencyService'   => 'Emergency Service',
            'AnimalShelter'      => 'Animal Shelter',
            'RecyclingCenter'    => 'Recycling Centre',
            'PlaceOfWorship'     => 'Place of Worship',
            'Church'             => 'Church',
            'Mosque'             => 'Mosque',
            'Synagogue'          => 'Synagogue',
            'HinduTemple'        => 'Hindu Temple',
            'BuddhistTemple'     => 'Buddhist Temple',
            'CatholicChurch'     => 'Catholic Church',
            'Cemetery'           => 'Cemetery',
            'FuneralParlor'      => 'Funeral Parlour',
            'Park'               => 'Park',

            // Education
            'School'             => 'School',
            'Preschool'          => 'Preschool',
            'ElementarySchool'   => 'Elementary School',
            'MiddleSchool'       => 'Middle School',
            'HighSchool'         => 'High School',
            'CollegeOrUniversity'=> 'College / University',
            'ChildCare'          => 'Childcare',

            // Other
            'EmploymentAgency'   => 'Employment Agency',
            'DryCleaningOrLaundry'=> 'Dry Cleaning / Laundry',
            'SelfStorage'        => 'Self Storage',
            'InternetCafe'       => 'Internet Café',
            'RadioStation'       => 'Radio Station',
            'TelevisionStation'  => 'Television Station',
            'ArchiveOrganization'=> 'Archive Organisation',
        ];
        return $map[ $type ] ?? str_replace( '_', ' ', ucfirst( $type ) );
    }
}
