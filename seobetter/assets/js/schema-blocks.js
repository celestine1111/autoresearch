/**
 * SEOBetter Schema Blocks (v1.5.216.62.31)
 *
 * 5 native Gutenberg blocks for Pro+ users:
 *   - seobetter/product
 *   - seobetter/event
 *   - seobetter/local-business
 *   - seobetter/vacation-rental
 *   - seobetter/job-posting
 *
 * UX improvements over v62.28:
 *   - Image fields use the WordPress Media Library picker (MediaUpload),
 *     not a free-text URL input. Users can upload, browse, and select
 *     existing images instead of pasting URLs.
 *   - Schema.org enum values (InStock / NewCondition / EventScheduled /
 *     OfflineEventAttendanceMode / FULL_TIME / etc.) are mapped to plain
 *     English labels in the dropdowns. The stored value is still the
 *     Schema.org token so the JSON-LD validates; only the user-visible
 *     label changes.
 *   - "Save" hint at the top of the InspectorControls panel: "Block
 *     settings save when you click Update on the post (top right)."
 *     Resolves the recurring "no save button on the block" confusion —
 *     Gutenberg saves block attrs on post-update, not per-block.
 *   - Required fields render with a red asterisk and a missing-field
 *     hint at the top of the panel.
 */
( function ( wp ) {
    if ( ! wp || ! wp.blocks || ! wp.blockEditor || ! wp.element ) {
        return;
    }

    var registerBlockType    = wp.blocks.registerBlockType;
    var InspectorControls    = wp.blockEditor.InspectorControls;
    var MediaUpload          = wp.blockEditor.MediaUpload;
    var MediaUploadCheck     = wp.blockEditor.MediaUploadCheck;
    var useBlockProps        = wp.blockEditor.useBlockProps || function () { return {}; };
    var ServerSideRender     = wp.serverSideRender || ( wp.editor && wp.editor.ServerSideRender );
    var TextControl          = wp.components.TextControl;
    var TextareaControl      = wp.components.TextareaControl;
    var SelectControl        = wp.components.SelectControl;
    var ToggleControl        = wp.components.ToggleControl;
    var PanelBody            = wp.components.PanelBody;
    var Placeholder          = wp.components.Placeholder;
    var Notice               = wp.components.Notice;
    var Button               = wp.components.Button;
    var el                   = wp.element.createElement;
    var Fragment             = wp.element.Fragment;

    /**
     * Normalize a select-options descriptor to the [{ label, value }]
     * shape SelectControl expects. Accepts:
     *   - Array of plain strings  → used as both label and value
     *   - Array of { value, label } objects → used as-is
     */
    function normalizeOptions( raw ) {
        if ( ! raw || ! raw.length ) return [];
        return raw.map( function ( o ) {
            if ( typeof o === 'string' ) return { label: o, value: o };
            return { label: o.label || o.value, value: o.value };
        } );
    }

    /**
     * Render an InspectorControls form field from a field-def descriptor.
     * Field types: text · textarea · url · number · date · datetime-local
     * · select · checkbox · image (NEW v62.31, uses MediaUpload).
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
                var opts = normalizeOptions( def.options );
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
            case 'image':
                // v62.31 — Media Library picker. Stores the picked image's
                // URL in the attribute, plus an optional `_id` companion
                // attribute for media-library tracking. If MediaUpload is
                // unavailable (rare — gated by MediaUploadCheck), falls
                // back to a plain URL TextControl so the field still works.
                if ( ! MediaUpload || ! MediaUploadCheck ) {
                    return el( TextControl, { key: fieldKey, type: 'url', label: label + ' (URL)', value: value || '', placeholder: 'https://…', onChange: onChange } );
                }
                return el( 'div', { key: fieldKey, style: { marginBottom: 16 } },
                    el( 'div', { style: { fontSize: 11, fontWeight: 600, marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.04em' } }, label ),
                    value
                        ? el( 'img', { src: value, alt: '', style: { display: 'block', maxWidth: '100%', maxHeight: 120, marginBottom: 6, borderRadius: 4, border: '1px solid #ddd' } } )
                        : el( 'div', { style: { padding: '24px 12px', background: '#f1f1f1', textAlign: 'center', color: '#888', fontSize: 12, marginBottom: 6, borderRadius: 4 } }, 'No image selected' ),
                    el( MediaUploadCheck, null,
                        el( MediaUpload, {
                            onSelect: function ( media ) {
                                setAttr( ( function () { var u = {}; u[ fieldKey ] = media && media.url ? media.url : ''; return u; } )() );
                            },
                            allowedTypes: [ 'image' ],
                            value: 0,
                            render: function ( obj ) {
                                return el( 'div', { style: { display: 'flex', gap: 6 } },
                                    el( Button, {
                                        variant: value ? 'secondary' : 'primary',
                                        onClick: obj.open,
                                        style: { flex: 1 }
                                    }, value ? 'Replace image' : 'Upload / select image' ),
                                    value ? el( Button, {
                                        variant: 'tertiary',
                                        isDestructive: true,
                                        onClick: function () { onChange( '' ); }
                                    }, 'Remove' ) : null
                                );
                            }
                        } )
                    )
                );
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

            // Build the missing-required list before rendering fields so
            // we can show a sticky banner at the top of the panel.
            var missingRequired = [];
            Object.keys( fieldDefs ).forEach( function ( k ) {
                if ( fieldDefs[ k ].required && ( ! attrs[ k ] || String( attrs[ k ] ).trim() === '' ) ) {
                    missingRequired.push( fieldDefs[ k ].label );
                }
            } );

            var fields = Object.keys( fieldDefs ).map( function ( k ) {
                return renderField( k, fieldDefs[ k ], attrs, setAttr );
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
                    el( PanelBody, { title: panelTitle, initialOpen: true },
                        // v62.31 — sticky save-hint at the top so users
                        // know the per-block "Save" button doesn't exist
                        // (Gutenberg saves block attrs when the post is
                        // updated). Notice with status='info' renders a
                        // small inline tip with the WordPress design system.
                        Notice ? el( Notice, {
                            status: 'info',
                            isDismissible: false,
                            className: 'sb-block-save-hint'
                        }, 'Block settings save when you click Update on the post (top right). There is no per-block save button — that is normal Gutenberg behavior.' ) : null,
                        // v62.31 — required-field warning banner
                        missingRequired.length > 0 && Notice ? el( Notice, {
                            status: 'warning',
                            isDismissible: false
                        }, 'Required: ' + missingRequired.join( ', ' ) ) : null,
                        fields
                    )
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
            var t = fieldDefs[ k ].type;
            out[ k ] = {
                type:    t === 'checkbox' ? 'boolean' :
                         t === 'number'   ? 'number'  :
                                            'string',
                default: t === 'checkbox' ? false : ''
            };
        } );
        return out;
    }

    // ================================================================
    // Field-defs — each select option uses { value, label } so the
    // user sees plain English while the stored value is the Schema.org
    // token (preserves JSON-LD validity).
    // ================================================================

    var DEFS = {
        product: {
            name:         { label: 'Name',          type: 'text',     required: true,  placeholder: 'Acme Trail Runner Pro' },
            description:  { label: 'Description',   type: 'textarea' },
            brand:        { label: 'Brand',         type: 'text',     placeholder: 'Acme' },
            image_url:    { label: 'Image',         type: 'image' },
            sku:          { label: 'SKU',           type: 'text' },
            mpn:          { label: 'MPN',           type: 'text' },
            gtin:         { label: 'GTIN',          type: 'text' },
            price:        { label: 'Price',         type: 'text',     required: true, placeholder: '129.00' },
            currency:     { label: 'Currency',      type: 'select',   required: true, options: [
                { value: 'USD', label: 'USD — US Dollar' },
                { value: 'EUR', label: 'EUR — Euro' },
                { value: 'GBP', label: 'GBP — British Pound' },
                { value: 'AUD', label: 'AUD — Australian Dollar' },
                { value: 'CAD', label: 'CAD — Canadian Dollar' },
                { value: 'NZD', label: 'NZD — New Zealand Dollar' },
                { value: 'JPY', label: 'JPY — Japanese Yen' },
                { value: 'CNY', label: 'CNY — Chinese Yuan' },
                { value: 'KRW', label: 'KRW — South Korean Won' },
                { value: 'INR', label: 'INR — Indian Rupee' },
                { value: 'BRL', label: 'BRL — Brazilian Real' },
                { value: 'MXN', label: 'MXN — Mexican Peso' },
                { value: 'CHF', label: 'CHF — Swiss Franc' },
                { value: 'SEK', label: 'SEK — Swedish Krona' },
                { value: 'NOK', label: 'NOK — Norwegian Krone' },
                { value: 'DKK', label: 'DKK — Danish Krone' }
            ] },
            availability: { label: 'Availability',  type: 'select',   options: [
                { value: 'InStock',      label: 'In stock' },
                { value: 'OutOfStock',   label: 'Out of stock' },
                { value: 'PreOrder',     label: 'Pre-order' },
                { value: 'BackOrder',    label: 'Back-order' },
                { value: 'Discontinued', label: 'Discontinued' }
            ] },
            condition:    { label: 'Condition',     type: 'select',   options: [
                { value: 'NewCondition',          label: 'New' },
                { value: 'UsedCondition',         label: 'Used' },
                { value: 'RefurbishedCondition',  label: 'Refurbished' }
            ] },
            rating_value: { label: 'Rating value',  type: 'text',     placeholder: '4.6' },
            rating_count: { label: 'Review count',  type: 'number',   placeholder: '128' }
        },
        event: {
            name:             { label: 'Name',           type: 'text',     required: true },
            description:      { label: 'Description',    type: 'textarea' },
            image_url:        { label: 'Image',          type: 'image' },
            start_date:       { label: 'Start',          type: 'datetime-local', required: true },
            end_date:         { label: 'End',            type: 'datetime-local' },
            event_status:     { label: 'Status',         type: 'select',   options: [
                { value: 'EventScheduled',   label: 'Scheduled' },
                { value: 'EventPostponed',   label: 'Postponed' },
                { value: 'EventCancelled',   label: 'Cancelled' },
                { value: 'EventMovedOnline', label: 'Moved online' },
                { value: 'EventRescheduled', label: 'Rescheduled' }
            ] },
            attendance_mode:  { label: 'Mode',           type: 'select',   options: [
                { value: 'OfflineEventAttendanceMode', label: 'In-person' },
                { value: 'OnlineEventAttendanceMode',  label: 'Virtual' },
                { value: 'MixedEventAttendanceMode',   label: 'Hybrid (in-person + virtual)' }
            ] },
            location_name:    { label: 'Location name',  type: 'text' },
            location_address: { label: 'Location address', type: 'textarea' },
            organizer_name:   { label: 'Organizer',      type: 'text' },
            organizer_url:    { label: 'Organizer URL',  type: 'url' },
            offers_url:       { label: 'Tickets URL',    type: 'url' },
            offers_price:     { label: 'Ticket price',   type: 'text' },
            offers_currency:  { label: 'Ticket currency', type: 'text',    placeholder: 'USD' }
        },
        localbusiness: {
            business_type:    { label: 'Business type',  type: 'select',   options: [
                { value: 'LocalBusiness',     label: 'Local Business (generic)' },
                { value: 'Restaurant',        label: 'Restaurant' },
                { value: 'Cafe',              label: 'Café' },
                { value: 'BarOrPub',          label: 'Bar / Pub' },
                { value: 'Hotel',             label: 'Hotel' },
                { value: 'BedAndBreakfast',   label: 'Bed & Breakfast' },
                { value: 'Hostel',            label: 'Hostel' },
                { value: 'Bakery',            label: 'Bakery' },
                { value: 'Brewery',           label: 'Brewery' },
                { value: 'Winery',            label: 'Winery' },
                { value: 'Store',             label: 'Store (generic)' },
                { value: 'ClothingStore',     label: 'Clothing store' },
                { value: 'GroceryStore',      label: 'Grocery store' },
                { value: 'BookStore',         label: 'Bookstore' },
                { value: 'JewelryStore',      label: 'Jeweller' },
                { value: 'PetStore',          label: 'Pet store' },
                { value: 'BeautySalon',       label: 'Beauty salon' },
                { value: 'HairSalon',         label: 'Hair salon' },
                { value: 'NailSalon',         label: 'Nail salon' },
                { value: 'DaySpa',            label: 'Spa' },
                { value: 'HealthClub',        label: 'Gym / health club' },
                { value: 'AutoDealer',        label: 'Auto dealer' },
                { value: 'AutoRepair',        label: 'Auto repair' },
                { value: 'GasStation',        label: 'Gas station' },
                { value: 'BankOrCreditUnion', label: 'Bank' },
                { value: 'RealEstateAgent',   label: 'Real estate agent' },
                { value: 'TravelAgency',      label: 'Travel agency' },
                { value: 'Dentist',           label: 'Dentist' },
                { value: 'Physician',         label: 'Doctor' },
                { value: 'Hospital',          label: 'Hospital' },
                { value: 'Pharmacy',          label: 'Pharmacy' },
                { value: 'VeterinaryCare',    label: 'Veterinary clinic' },
                { value: 'MovieTheater',      label: 'Cinema' },
                { value: 'Museum',            label: 'Museum' },
                { value: 'TouristAttraction', label: 'Tourist attraction' },
                { value: 'Plumber',           label: 'Plumber' },
                { value: 'Electrician',       label: 'Electrician' },
                { value: 'HousePainter',      label: 'House painter' },
                { value: 'Locksmith',         label: 'Locksmith' },
                { value: 'MovingCompany',     label: 'Moving company' },
                { value: 'ChildCare',         label: 'Childcare' },
                { value: 'School',            label: 'School' }
            ] },
            name:             { label: 'Name',           type: 'text',     required: true },
            description:      { label: 'Description',    type: 'textarea' },
            image_url:        { label: 'Image',          type: 'image' },
            street_address:   { label: 'Street address', type: 'text' },
            locality:         { label: 'City',           type: 'text' },
            region:           { label: 'State / Region', type: 'text' },
            postal_code:      { label: 'Postal code',    type: 'text' },
            country:          { label: 'Country (ISO)',  type: 'text',     placeholder: 'US, GB, AU, FR…' },
            telephone:        { label: 'Telephone',      type: 'text' },
            opening_hours:    { label: 'Opening hours',  type: 'textarea', placeholder: 'Mo-Fr 09:00-17:00\nSa 10:00-14:00' },
            price_range:      { label: 'Price range',    type: 'text',     placeholder: '$$' },
            latitude:         { label: 'Latitude',       type: 'text',     placeholder: '40.7128' },
            longitude:        { label: 'Longitude',      type: 'text',     placeholder: '-74.0060' }
        },
        vacationrental: {
            name:             { label: 'Name',           type: 'text',     required: true },
            description:      { label: 'Description',    type: 'textarea' },
            image_url:        { label: 'Image',          type: 'image' },
            street_address:   { label: 'Street address', type: 'text' },
            locality:         { label: 'City',           type: 'text' },
            region:           { label: 'State / Region', type: 'text' },
            postal_code:      { label: 'Postal code',    type: 'text' },
            country:          { label: 'Country (ISO)',  type: 'text' },
            number_of_rooms:  { label: 'Number of rooms',type: 'number' },
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
            employment_type:         { label: 'Employment type',      type: 'select',   options: [
                { value: 'FULL_TIME',  label: 'Full-time' },
                { value: 'PART_TIME',  label: 'Part-time' },
                { value: 'CONTRACTOR', label: 'Contractor' },
                { value: 'TEMPORARY',  label: 'Temporary' },
                { value: 'INTERN',     label: 'Internship' },
                { value: 'VOLUNTEER',  label: 'Volunteer' },
                { value: 'PER_DIEM',   label: 'Per-diem' },
                { value: 'OTHER',      label: 'Other' }
            ] },
            hiring_organization:     { label: 'Hiring company',       type: 'text',     required: true },
            hiring_organization_url: { label: 'Company URL',          type: 'url' },
            job_location_address:    { label: 'Job address',          type: 'text' },
            job_location_locality:   { label: 'Job city',             type: 'text' },
            job_location_region:     { label: 'Job region',           type: 'text' },
            job_location_country:    { label: 'Job country (ISO)',    type: 'text' },
            remote_ok:               { label: 'Remote OK',            type: 'checkbox', hint: 'Telecommute eligible' },
            salary_min:              { label: 'Minimum salary',       type: 'text' },
            salary_max:              { label: 'Maximum salary',       type: 'text' },
            salary_currency:         { label: 'Salary currency',      type: 'text',     placeholder: 'USD' },
            salary_unit:             { label: 'Pay period',           type: 'select',   options: [
                { value: 'HOUR',  label: 'Per hour' },
                { value: 'DAY',   label: 'Per day' },
                { value: 'WEEK',  label: 'Per week' },
                { value: 'MONTH', label: 'Per month' },
                { value: 'YEAR',  label: 'Per year' }
            ] }
        }
    };

    // ================================================================
    // Block registrations
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
