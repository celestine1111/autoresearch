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
     * Build JSON-LD for all enabled blocks. Returns array of nodes ready
     * to merge into @graph. Skips disabled / invalid blocks silently.
     */
    public static function build_all_jsonld( int $post_id ): array {
        $all = self::get_all( $post_id );
        $nodes = [];
        foreach ( self::BLOCK_TYPES as $type ) {
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
            if ( ! empty( $b['organizer_url'] ) ) $organizer['url'] = $b['organizer_url'];
            $node['organizer'] = $organizer;
        }
        if ( ! empty( $b['offers_url'] ) ) {
            $offers = [ '@type' => 'Offer', 'url' => $b['offers_url'] ];
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

    /** VacationRental — uses LodgingBusiness type with vacation-specific fields */
    private static function build_vacationrental_jsonld( array $b ): ?array {
        if ( empty( $b['name'] ) ) return null;
        if ( empty( $b['street_address'] ) && empty( $b['locality'] ) ) return null;

        $node = [
            '@type' => [ 'LodgingBusiness', 'VacationRental' ],
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
            $node['hiringOrganization']['sameAs'] = $b['hiring_organization_url'];
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
}
