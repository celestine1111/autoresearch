/**
 * SEOBetter Schema Blocks (v1.5.216.62.28)
 *
 * 5 native Gutenberg blocks for Pro+ users:
 *   - seobetter/product
 *   - seobetter/event
 *   - seobetter/local-business
 *   - seobetter/vacation-rental
 *   - seobetter/job-posting
 *
 * Each block uses InspectorControls for the form fields + ServerSideRender
 * for live editor preview. Front-end render is the SAME PHP code path
 * (Schema_Blocks_Manager::render_*_card) so editor preview pixel-matches
 * the published post.
 *
 * Pre-v62.28 these blocks lived in a metabox panel that emitted JSON-LD
 * only — no visible card on the post body, one block per type per post,
 * no inline placement. v62.28 retires the panel and replaces it with
 * native blocks that render styled cards in BOTH the editor and on the
 * published post.
 *
 * All field schemas mirror Schema_Blocks_Manager::schema_block_field_defs()
 * so the JSON-LD validation paths still work without modification.
 */
( function ( wp ) {
    if ( ! wp || ! wp.blocks || ! wp.blockEditor || ! wp.element ) {
        // Editor scripts not available — bail.
        return;
    }

    var registerBlockType = wp.blocks.registerBlockType;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var useBlockProps      = wp.blockEditor.useBlockProps || function () { return {}; };
    var ServerSideRender   = wp.serverSideRender || ( wp.editor && wp.editor.ServerSideRender );
    var TextControl        = wp.components.TextControl;
    var TextareaControl    = wp.components.TextareaControl;
    var SelectControl      = wp.components.SelectControl;
    var ToggleControl      = wp.components.ToggleControl;
    var PanelBody          = wp.components.PanelBody;
    var Placeholder        = wp.components.Placeholder;
    var el                 = wp.element.createElement;
    var Fragment           = wp.element.Fragment;

    /**
     * Render an InspectorControls form field from a field-def descriptor.
     * Mirrors the metabox-panel field-def shape from Schema_Blocks_Manager
     * so server + client agree on what fields a block accepts.
     */
    function renderField( fieldKey, def, attrs, setAttr ) {
        var label    = def.label + ( def.required ? ' *' : '' );
        var value    = attrs[ fieldKey ];
        var placeholder = def.placeholder || '';
        var onChange = function ( v ) {
            var update = {};
            update[ fieldKey ] = v;
            setAttr( update );
        };

        switch ( def.type ) {
            case 'textarea':
                return el( TextareaControl, { key: fieldKey, label: label, value: value || '', placeholder: placeholder, onChange: onChange, rows: 3 } );
            case 'select':
                var opts = ( def.options || [] ).map( function ( o ) { return { label: o, value: o }; } );
                opts.unshift( { label: '— Select —', value: '' } );
                return el( SelectControl, { key: fieldKey, label: label, value: value || '', options: opts, onChange: onChange } );
            case 'checkbox':
                return el( ToggleControl, { key: fieldKey, label: label, help: def.hint || '', checked: !! value, onChange: onChange } );
            case 'number':
                return el( TextControl, { key: fieldKey, type: 'number', label: label, value: value || '', placeholder: placeholder, onChange: onChange } );
            case 'date':
                return el( TextControl, { key: fieldKey, type: 'date', label: label, value: value || '', onChange: onChange } );
            case 'datetime-local':
                return el( TextControl, { key: fieldKey, type: 'datetime-local', label: label, value: value || '', onChange: onChange } );
            case 'url':
                return el( TextControl, { key: fieldKey, type: 'url', label: label, value: value || '', placeholder: placeholder, onChange: onChange } );
            case 'text':
            default:
                return el( TextControl, { key: fieldKey, label: label, value: value || '', placeholder: placeholder, onChange: onChange } );
        }
    }

    /**
     * Builder — produces a block edit() function that renders the field
     * panel + the ServerSideRender preview. Shared across all 5 blocks.
     */
    function makeEdit( panelTitle, fieldDefs ) {
        return function ( props ) {
            var attrs   = props.attributes;
            var setAttr = props.setAttributes;
            var blockProps = useBlockProps();

            var fields = Object.keys( fieldDefs ).map( function ( k ) {
                return renderField( k, fieldDefs[ k ], attrs, setAttr );
            } );

            // Show a placeholder if the block is enabled but missing
            // required fields, so the user knows what to fill in before
            // attempting a server-side render that would just error.
            var missingRequired = [];
            Object.keys( fieldDefs ).forEach( function ( k ) {
                if ( fieldDefs[ k ].required && ( ! attrs[ k ] || String( attrs[ k ] ).trim() === '' ) ) {
                    missingRequired.push( fieldDefs[ k ].label );
                }
            } );

            var preview;
            if ( missingRequired.length > 0 ) {
                preview = el( Placeholder, {
                    icon: 'admin-customizer',
                    label: panelTitle,
                    instructions: 'Fill in required fields in the sidebar to preview the card: ' + missingRequired.join( ', ' )
                } );
            } else if ( ! ServerSideRender ) {
                preview = el( Placeholder, {
                    icon: 'admin-customizer',
                    label: panelTitle,
                    instructions: 'Editor preview unavailable — check this preview by viewing the published post.'
                } );
            } else {
                preview = el( ServerSideRender, {
                    block: props.name,
                    attributes: attrs,
                    EmptyResponsePlaceholder: function () {
                        return el( Placeholder, {
                            icon: 'admin-customizer',
                            label: panelTitle,
                            instructions: 'Card will appear once required fields are filled in.'
                        } );
                    }
                } );
            }

            return el( Fragment, null,
                el( InspectorControls, null,
                    el( PanelBody, { title: panelTitle, initialOpen: true }, fields )
                ),
                el( 'div', blockProps, preview )
            );
        };
    }

    /**
     * Build the WordPress attributes schema from a field-defs object.
     * Booleans default to false; everything else to empty string.
     */
    function attrsFromDefs( fieldDefs ) {
        var out = { enabled: { type: 'boolean', default: true } };
        Object.keys( fieldDefs ).forEach( function ( k ) {
            out[ k ] = {
                type:    fieldDefs[ k ].type === 'checkbox' ? 'boolean' :
                         fieldDefs[ k ].type === 'number'   ? 'number'  :
                                                              'string',
                default: fieldDefs[ k ].type === 'checkbox' ? false : ''
            };
        } );
        return out;
    }

    // ================================================================
    // Field-defs (mirrors Schema_Blocks_Manager::schema_block_field_defs)
    // ================================================================

    var DEFS = {
        product: {
            name:         { label: 'Name',          type: 'text',     required: true,  placeholder: 'Acme Trail Runner Pro' },
            description:  { label: 'Description',   type: 'textarea' },
            brand:        { label: 'Brand',         type: 'text',     placeholder: 'Acme' },
            image_url:    { label: 'Image URL',     type: 'url' },
            sku:          { label: 'SKU',           type: 'text' },
            mpn:          { label: 'MPN',           type: 'text' },
            gtin:         { label: 'GTIN',          type: 'text' },
            price:        { label: 'Price',         type: 'text',     required: true, placeholder: '129.00' },
            currency:     { label: 'Currency',      type: 'text',     required: true, placeholder: 'USD' },
            availability: { label: 'Availability',  type: 'select',   options: [ 'InStock', 'OutOfStock', 'PreOrder', 'BackOrder', 'Discontinued' ] },
            condition:    { label: 'Condition',     type: 'select',   options: [ 'NewCondition', 'UsedCondition', 'RefurbishedCondition' ] },
            rating_value: { label: 'Rating value',  type: 'text',     placeholder: '4.6' },
            rating_count: { label: 'Review count',  type: 'number',   placeholder: '128' }
        },
        event: {
            name:             { label: 'Name',           type: 'text',     required: true },
            description:      { label: 'Description',    type: 'textarea' },
            start_date:       { label: 'Start',          type: 'datetime-local', required: true },
            end_date:         { label: 'End',            type: 'datetime-local' },
            event_status:     { label: 'Status',         type: 'select',   options: [ 'EventScheduled', 'EventPostponed', 'EventCancelled', 'EventMovedOnline', 'EventRescheduled' ] },
            attendance_mode:  { label: 'Mode',           type: 'select',   options: [ 'OfflineEventAttendanceMode', 'OnlineEventAttendanceMode', 'MixedEventAttendanceMode' ] },
            location_name:    { label: 'Location name',  type: 'text',     required: true },
            location_address: { label: 'Location addr',  type: 'textarea' },
            organizer_name:   { label: 'Organizer',      type: 'text' },
            organizer_url:    { label: 'Organizer URL',  type: 'url' },
            offers_url:       { label: 'Tickets URL',    type: 'url' },
            offers_price:     { label: 'Ticket price',   type: 'text' },
            offers_currency:  { label: 'Ticket curr.',   type: 'text',     placeholder: 'USD' }
        },
        localbusiness: {
            business_type:    { label: 'Type',           type: 'select',   options: [ 'LocalBusiness', 'Restaurant', 'Cafe', 'BarOrPub', 'Hotel', 'Bakery', 'Brewery', 'Winery', 'Store', 'ClothingStore', 'GroceryStore', 'BookStore', 'JewelryStore', 'PetStore', 'BeautySalon', 'HairSalon', 'NailSalon', 'DaySpa', 'HealthClub', 'AutoDealer', 'AutoRepair', 'GasStation', 'BankOrCreditUnion', 'RealEstateAgent', 'TravelAgency', 'Dentist', 'Physician', 'Hospital', 'Pharmacy', 'VeterinaryCare', 'MovieTheater', 'Museum', 'TouristAttraction', 'Plumber', 'Electrician', 'HousePainter', 'Locksmith', 'MovingCompany', 'ChildCare', 'School' ] },
            name:             { label: 'Name',           type: 'text',     required: true },
            description:      { label: 'Description',    type: 'textarea' },
            street_address:   { label: 'Street address', type: 'text',     required: true },
            locality:         { label: 'City',           type: 'text' },
            region:           { label: 'State/Region',   type: 'text' },
            postal_code:      { label: 'Postal code',    type: 'text' },
            country:          { label: 'Country (ISO)',  type: 'text',     placeholder: 'US' },
            telephone:        { label: 'Telephone',      type: 'text' },
            image_url:        { label: 'Image URL',      type: 'url' },
            opening_hours:    { label: 'Opening hours',  type: 'textarea', placeholder: 'Mo-Fr 09:00-17:00\nSa 10:00-14:00' },
            price_range:      { label: 'Price range',    type: 'text',     placeholder: '$$' },
            latitude:         { label: 'Latitude',       type: 'text',     placeholder: '40.7128' },
            longitude:        { label: 'Longitude',      type: 'text',     placeholder: '-74.0060' }
        },
        vacationrental: {
            name:             { label: 'Name',           type: 'text',     required: true },
            description:      { label: 'Description',    type: 'textarea' },
            street_address:   { label: 'Street address', type: 'text',     required: true },
            locality:         { label: 'City',           type: 'text' },
            region:           { label: 'State/Region',   type: 'text' },
            postal_code:      { label: 'Postal code',    type: 'text' },
            country:          { label: 'Country (ISO)',  type: 'text' },
            image_url:        { label: 'Image URL',      type: 'url' },
            number_of_rooms:  { label: '# of rooms',     type: 'number' },
            occupancy_max:    { label: 'Max occupancy',  type: 'number' },
            amenities:        { label: 'Amenities',      type: 'textarea', placeholder: 'WiFi\nPool\nKitchen\nParking' },
            price_range:      { label: 'Price range',    type: 'text',     placeholder: '$$$' },
            pet_friendly:     { label: 'Pet friendly',   type: 'checkbox', hint: 'Pets allowed' }
        },
        jobposting: {
            title:                   { label: 'Title',                type: 'text',     required: true },
            description:             { label: 'Description',          type: 'textarea', required: true },
            date_posted:             { label: 'Date posted',          type: 'date',     required: true },
            valid_through:           { label: 'Valid through',        type: 'date' },
            employment_type:         { label: 'Employment',           type: 'select',   options: [ 'FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER' ] },
            hiring_organization:     { label: 'Hiring company',       type: 'text',     required: true },
            hiring_organization_url: { label: 'Company URL',          type: 'url' },
            job_location_address:    { label: 'Job address',          type: 'text' },
            job_location_locality:   { label: 'Job city',             type: 'text' },
            job_location_region:     { label: 'Job region',           type: 'text' },
            job_location_country:    { label: 'Job country (ISO)',    type: 'text' },
            remote_ok:               { label: 'Remote OK',            type: 'checkbox', hint: 'Telecommute eligible' },
            salary_min:              { label: 'Min salary',           type: 'text' },
            salary_max:              { label: 'Max salary',           type: 'text' },
            salary_currency:         { label: 'Currency',             type: 'text',     placeholder: 'USD' },
            salary_unit:             { label: 'Salary unit',          type: 'select',   options: [ 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' ] }
        }
    };

    // ================================================================
    // Block registrations
    //
    // Shared `category` is set in PHP via wp_register_block_category.
    // Each block uses dynamic rendering — `save: () => null` returns no
    // static HTML; the front-end card is generated by the PHP
    // render_callback at request time. This means editor preview always
    // matches the published-post output (single source of truth).
    // ================================================================

    var BLOCKS = [
        { name: 'seobetter/product',         title: 'Product (SEOBetter)',         icon: 'cart',         keywords: [ 'product', 'price', 'schema' ],          fields: DEFS.product },
        { name: 'seobetter/event',           title: 'Event (SEOBetter)',           icon: 'calendar-alt', keywords: [ 'event', 'tickets', 'schema' ],          fields: DEFS.event },
        { name: 'seobetter/local-business',  title: 'Local Business (SEOBetter)',  icon: 'location',     keywords: [ 'local', 'business', 'address', 'schema' ], fields: DEFS.localbusiness },
        { name: 'seobetter/vacation-rental', title: 'Vacation Rental (SEOBetter)', icon: 'palmtree',     keywords: [ 'rental', 'vacation', 'schema' ],        fields: DEFS.vacationrental },
        { name: 'seobetter/job-posting',     title: 'Job Posting (SEOBetter)',     icon: 'businessman',  keywords: [ 'job', 'career', 'hiring', 'schema' ],   fields: DEFS.jobposting }
    ];

    BLOCKS.forEach( function ( b ) {
        registerBlockType( b.name, {
            apiVersion: 2,
            title:      b.title,
            description: 'Pro+ structured data block — emits JSON-LD into your @graph and renders a styled card on the page.',
            category:   'seobetter',
            icon:       b.icon,
            keywords:   b.keywords,
            supports:   { html: false, multiple: true, reusable: true },
            attributes: attrsFromDefs( b.fields ),
            edit:       makeEdit( b.title, b.fields ),
            save:       function () { return null; }
        } );
    } );

}( window.wp ) );
