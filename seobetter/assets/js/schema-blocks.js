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
    var ComboboxControl      = wp.components.ComboboxControl;  // v62.32 — searchable dropdown
    var ToggleControl        = wp.components.ToggleControl;
    var PanelBody            = wp.components.PanelBody;
    var Placeholder          = wp.components.Placeholder;
    var Notice               = wp.components.Notice;
    var Button               = wp.components.Button;
    var el                   = wp.element.createElement;
    var Fragment             = wp.element.Fragment;
    var useState             = wp.element.useState;  // v62.33 — for in-block save button state

    /**
     * v1.5.216.62.33 — In-block "Save post" button.
     *
     * Adds a small button below each block's preview area that programmatically
     * triggers the post save (same as clicking "Update" at the top of the
     * editor). Resolves the recurring "where's the save button" complaint
     * without scrolling to the top of the editor.
     *
     * Three states:
     *   idle   — green "Save post" button, clickable
     *   saving — disabled "Saving…" button while wp.data.dispatch resolves
     *   saved  — grey "Saved ✓" for 2 seconds, then back to idle
     *
     * If wp.data isn't available (extreme edge case), the component renders
     * nothing — the post-level Update button at the top still works.
     */
    function SavePostButton() {
        if ( ! wp.data || ! useState ) return null;
        var s = useState( 'idle' );
        var status = s[0];
        var setStatus = s[1];

        function onClick() {
            if ( status === 'saving' ) return;
            setStatus( 'saving' );
            try {
                var promise = wp.data.dispatch( 'core/editor' ).savePost();
                if ( promise && typeof promise.then === 'function' ) {
                    promise.then( function () {
                        setStatus( 'saved' );
                        setTimeout( function () { setStatus( 'idle' ); }, 2000 );
                    } ).catch( function () {
                        setStatus( 'idle' );
                    } );
                } else {
                    // Older WP — savePost() is fire-and-forget. Show "Saved" briefly.
                    setTimeout( function () { setStatus( 'saved' ); }, 600 );
                    setTimeout( function () { setStatus( 'idle' ); }, 2600 );
                }
            } catch ( e ) {
                setStatus( 'idle' );
            }
        }

        var label = status === 'saving' ? 'Saving…' :
                    status === 'saved'  ? 'Saved ✓' :
                                          'Save post';
        var bg    = status === 'saving' ? '#9ca3af' :
                    status === 'saved'  ? '#16a34a' :
                                          '#0f172a';

        return el( 'div', { style: { padding: '12px', textAlign: 'center', borderTop: '1px solid #e5e7eb', background: '#f9fafb', borderRadius: '0 0 8px 8px', marginTop: '-2px' } },
            el( 'button', {
                type: 'button',
                onClick: onClick,
                disabled: status === 'saving',
                style: {
                    background: bg,
                    color: '#fff',
                    border: 'none',
                    borderRadius: '6px',
                    padding: '8px 22px',
                    fontSize: '13px',
                    fontWeight: 600,
                    cursor: status === 'saving' ? 'progress' : 'pointer',
                    transition: 'background 200ms ease',
                    minWidth: '120px'
                }
            }, label ),
            el( 'div', { style: { fontSize: '11px', color: '#6b7280', marginTop: '6px' } },
                'Same as clicking Update at the top of the editor.'
            )
        );
    }

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
                // v62.32 — `searchable: true` opts in to ComboboxControl
                // (autocomplete with type-to-filter). Used for currency
                // (66 options) and business_type (41 options) where a
                // static select is unwieldy. ComboboxControl renders the
                // current selection at the top + a typeable input that
                // narrows the list. Falls back to SelectControl if the
                // ComboboxControl component isn't available (older WP).
                if ( def.searchable && ComboboxControl ) {
                    return el( ComboboxControl, {
                        key: fieldKey,
                        label: label,
                        value: value || '',
                        options: opts,
                        onChange: function ( v ) { onChange( v == null ? '' : v ); },
                        allowReset: true
                    } );
                }
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
                        // v62.31 — sticky save-hint at the top of the
                        // sidebar panel. v62.33 added an in-block "Save
                        // post" button beneath the preview so this hint
                        // is now a back-up explanation rather than the
                        // primary path. Both work — pick whichever the
                        // user finds first.
                        Notice ? el( Notice, {
                            status: 'info',
                            isDismissible: false,
                            className: 'sb-block-save-hint'
                        }, 'Use the "Save post" button below the block preview, or click Update at the top of the editor — both save the same way.' ) : null,
                        // v62.31 — required-field warning banner
                        missingRequired.length > 0 && Notice ? el( Notice, {
                            status: 'warning',
                            isDismissible: false
                        }, 'Required: ' + missingRequired.join( ', ' ) ) : null,
                        fields
                    )
                ),
                // v62.33 — wrap the preview + the new in-block Save Post
                // button in a single container so they visually belong
                // together. The button gets a subtle border-top so it
                // reads as a footer to the preview card.
                el( 'div', blockProps,
                    preview,
                    el( SavePostButton, null )
                )
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

    // v1.5.216.62.32 — Currency dropdown extended to cover every supported
    // country (80+) and language (60+). Major reserve currencies first
    // (USD / EUR / GBP / JPY / AUD / CAD / CHF / CNY / INR), then the rest
    // alphabetical by ISO 4217 code. WordPress SelectControl supports
    // type-to-jump so a long list is still searchable. Same list reused
    // by Event.offers_currency and Job Posting.salary_currency for UX
    // consistency across all 5 blocks.
    // v1.5.216.62.32 — Country dropdown matching the 80+ countries SEOBetter
    // supports in the article generator (admin/views/content-generator.php
    // sbCountries list). ISO 3166-1 alpha-2 codes stored as the value, full
    // English country name in the label. Same searchable dropdown UX as
    // currency. Used by LocalBusiness.country, VacationRental.country, and
    // JobPosting.job_location_country.
    var COUNTRY_OPTIONS = [
        // Oceania
        { value: 'AU', label: 'AU — Australia' },
        { value: 'NZ', label: 'NZ — New Zealand' },
        { value: 'FJ', label: 'FJ — Fiji / Pacific Islands' },
        // North America
        { value: 'US', label: 'US — United States' },
        { value: 'CA', label: 'CA — Canada' },
        { value: 'MX', label: 'MX — Mexico' },
        { value: 'JM', label: 'JM — Jamaica' },
        // Europe Western
        { value: 'GB', label: 'GB — United Kingdom' },
        { value: 'IE', label: 'IE — Ireland' },
        { value: 'FR', label: 'FR — France' },
        { value: 'DE', label: 'DE — Germany' },
        { value: 'ES', label: 'ES — Spain' },
        { value: 'PT', label: 'PT — Portugal' },
        { value: 'IT', label: 'IT — Italy' },
        { value: 'NL', label: 'NL — Netherlands' },
        { value: 'BE', label: 'BE — Belgium' },
        { value: 'CH', label: 'CH — Switzerland' },
        { value: 'AT', label: 'AT — Austria' },
        { value: 'LU', label: 'LU — Luxembourg' },
        { value: 'GR', label: 'GR — Greece' },
        { value: 'CY', label: 'CY — Cyprus' },
        { value: 'MT', label: 'MT — Malta' },
        // Nordic & Baltic
        { value: 'SE', label: 'SE — Sweden' },
        { value: 'NO', label: 'NO — Norway' },
        { value: 'DK', label: 'DK — Denmark' },
        { value: 'FI', label: 'FI — Finland' },
        { value: 'IS', label: 'IS — Iceland' },
        { value: 'EE', label: 'EE — Estonia' },
        { value: 'LV', label: 'LV — Latvia' },
        { value: 'LT', label: 'LT — Lithuania' },
        // Central & Eastern Europe
        { value: 'PL', label: 'PL — Poland' },
        { value: 'CZ', label: 'CZ — Czech Republic' },
        { value: 'SK', label: 'SK — Slovakia' },
        { value: 'HU', label: 'HU — Hungary' },
        { value: 'SI', label: 'SI — Slovenia' },
        { value: 'HR', label: 'HR — Croatia' },
        { value: 'RS', label: 'RS — Serbia' },
        { value: 'BG', label: 'BG — Bulgaria' },
        { value: 'RO', label: 'RO — Romania' },
        { value: 'UA', label: 'UA — Ukraine' },
        { value: 'MD', label: 'MD — Moldova' },
        { value: 'TR', label: 'TR — Turkey' },
        { value: 'RU', label: 'RU — Russia' },
        // Asia
        { value: 'JP', label: 'JP — Japan' },
        { value: 'KR', label: 'KR — South Korea' },
        { value: 'CN', label: 'CN — China' },
        { value: 'TW', label: 'TW — Taiwan' },
        { value: 'HK', label: 'HK — Hong Kong' },
        { value: 'SG', label: 'SG — Singapore' },
        { value: 'MY', label: 'MY — Malaysia' },
        { value: 'ID', label: 'ID — Indonesia' },
        { value: 'PH', label: 'PH — Philippines' },
        { value: 'TH', label: 'TH — Thailand' },
        { value: 'VN', label: 'VN — Vietnam' },
        { value: 'IN', label: 'IN — India' },
        { value: 'PK', label: 'PK — Pakistan' },
        { value: 'BD', label: 'BD — Bangladesh' },
        { value: 'LK', label: 'LK — Sri Lanka' },
        { value: 'NP', label: 'NP — Nepal' },
        { value: 'MN', label: 'MN — Mongolia' },
        { value: 'KZ', label: 'KZ — Kazakhstan' },
        { value: 'UZ', label: 'UZ — Uzbekistan' },
        // Middle East
        { value: 'IL', label: 'IL — Israel' },
        { value: 'AE', label: 'AE — UAE' },
        { value: 'SA', label: 'SA — Saudi Arabia' },
        { value: 'QA', label: 'QA — Qatar' },
        { value: 'BH', label: 'BH — Bahrain' },
        { value: 'KW', label: 'KW — Kuwait' },
        { value: 'OM', label: 'OM — Oman' },
        { value: 'JO', label: 'JO — Jordan' },
        { value: 'EG', label: 'EG — Egypt' },
        // Latin America
        { value: 'BR', label: 'BR — Brazil' },
        { value: 'AR', label: 'AR — Argentina' },
        { value: 'CL', label: 'CL — Chile' },
        { value: 'CO', label: 'CO — Colombia' },
        { value: 'PE', label: 'PE — Peru' },
        { value: 'UY', label: 'UY — Uruguay' },
        { value: 'EC', label: 'EC — Ecuador' },
        { value: 'CR', label: 'CR — Costa Rica' },
        { value: 'PA', label: 'PA — Panama' },
        { value: 'DO', label: 'DO — Dominican Republic' },
        { value: 'GT', label: 'GT — Guatemala' },
        // Africa
        { value: 'ZA', label: 'ZA — South Africa' },
        { value: 'NG', label: 'NG — Nigeria' },
        { value: 'KE', label: 'KE — Kenya' },
        { value: 'GH', label: 'GH — Ghana' },
        { value: 'TZ', label: 'TZ — Tanzania' },
        { value: 'UG', label: 'UG — Uganda' },
        { value: 'RW', label: 'RW — Rwanda' },
        { value: 'MA', label: 'MA — Morocco' },
        { value: 'TN', label: 'TN — Tunisia' },
        { value: 'SN', label: 'SN — Senegal' }
    ];

    var CURRENCY_OPTIONS = [
        // Major reserve currencies (top of list)
        { value: 'USD', label: 'USD — US Dollar' },
        { value: 'EUR', label: 'EUR — Euro' },
        { value: 'GBP', label: 'GBP — British Pound' },
        { value: 'JPY', label: 'JPY — Japanese Yen' },
        { value: 'AUD', label: 'AUD — Australian Dollar' },
        { value: 'CAD', label: 'CAD — Canadian Dollar' },
        { value: 'CHF', label: 'CHF — Swiss Franc' },
        { value: 'CNY', label: 'CNY — Chinese Yuan' },
        { value: 'INR', label: 'INR — Indian Rupee' },
        { value: 'NZD', label: 'NZD — New Zealand Dollar' },
        // Rest alphabetical by ISO code
        { value: 'AED', label: 'AED — UAE Dirham' },
        { value: 'ARS', label: 'ARS — Argentine Peso' },
        { value: 'BDT', label: 'BDT — Bangladeshi Taka' },
        { value: 'BGN', label: 'BGN — Bulgarian Lev' },
        { value: 'BHD', label: 'BHD — Bahraini Dinar' },
        { value: 'BRL', label: 'BRL — Brazilian Real' },
        { value: 'CLP', label: 'CLP — Chilean Peso' },
        { value: 'COP', label: 'COP — Colombian Peso' },
        { value: 'CRC', label: 'CRC — Costa Rican Colón' },
        { value: 'CZK', label: 'CZK — Czech Koruna' },
        { value: 'DKK', label: 'DKK — Danish Krone' },
        { value: 'DOP', label: 'DOP — Dominican Peso' },
        { value: 'EGP', label: 'EGP — Egyptian Pound' },
        { value: 'FJD', label: 'FJD — Fijian Dollar' },
        { value: 'GHS', label: 'GHS — Ghanaian Cedi' },
        { value: 'GTQ', label: 'GTQ — Guatemalan Quetzal' },
        { value: 'HKD', label: 'HKD — Hong Kong Dollar' },
        { value: 'HUF', label: 'HUF — Hungarian Forint' },
        { value: 'IDR', label: 'IDR — Indonesian Rupiah' },
        { value: 'ILS', label: 'ILS — Israeli Shekel' },
        { value: 'ISK', label: 'ISK — Icelandic Króna' },
        { value: 'JMD', label: 'JMD — Jamaican Dollar' },
        { value: 'JOD', label: 'JOD — Jordanian Dinar' },
        { value: 'KES', label: 'KES — Kenyan Shilling' },
        { value: 'KRW', label: 'KRW — South Korean Won' },
        { value: 'KWD', label: 'KWD — Kuwaiti Dinar' },
        { value: 'KZT', label: 'KZT — Kazakhstani Tenge' },
        { value: 'LKR', label: 'LKR — Sri Lankan Rupee' },
        { value: 'MAD', label: 'MAD — Moroccan Dirham' },
        { value: 'MDL', label: 'MDL — Moldovan Leu' },
        { value: 'MNT', label: 'MNT — Mongolian Tögrög' },
        { value: 'MXN', label: 'MXN — Mexican Peso' },
        { value: 'MYR', label: 'MYR — Malaysian Ringgit' },
        { value: 'NGN', label: 'NGN — Nigerian Naira' },
        { value: 'NOK', label: 'NOK — Norwegian Krone' },
        { value: 'NPR', label: 'NPR — Nepalese Rupee' },
        { value: 'OMR', label: 'OMR — Omani Rial' },
        { value: 'PEN', label: 'PEN — Peruvian Sol' },
        { value: 'PHP', label: 'PHP — Philippine Peso' },
        { value: 'PKR', label: 'PKR — Pakistani Rupee' },
        { value: 'PLN', label: 'PLN — Polish Złoty' },
        { value: 'QAR', label: 'QAR — Qatari Riyal' },
        { value: 'RON', label: 'RON — Romanian Leu' },
        { value: 'RSD', label: 'RSD — Serbian Dinar' },
        { value: 'RUB', label: 'RUB — Russian Ruble' },
        { value: 'RWF', label: 'RWF — Rwandan Franc' },
        { value: 'SAR', label: 'SAR — Saudi Riyal' },
        { value: 'SEK', label: 'SEK — Swedish Krona' },
        { value: 'SGD', label: 'SGD — Singapore Dollar' },
        { value: 'THB', label: 'THB — Thai Baht' },
        { value: 'TND', label: 'TND — Tunisian Dinar' },
        { value: 'TRY', label: 'TRY — Turkish Lira' },
        { value: 'TWD', label: 'TWD — Taiwan Dollar' },
        { value: 'TZS', label: 'TZS — Tanzanian Shilling' },
        { value: 'UAH', label: 'UAH — Ukrainian Hryvnia' },
        { value: 'UGX', label: 'UGX — Ugandan Shilling' },
        { value: 'UYU', label: 'UYU — Uruguayan Peso' },
        { value: 'UZS', label: 'UZS — Uzbekistani Som' },
        { value: 'VND', label: 'VND — Vietnamese Đồng' },
        { value: 'XOF', label: 'XOF — West African CFA Franc' },
        { value: 'ZAR', label: 'ZAR — South African Rand' }
    ];

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
            currency:     { label: 'Currency',      type: 'select',   required: true, searchable: true, options: CURRENCY_OPTIONS },
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
            offers_currency:  { label: 'Ticket currency', type: 'select', searchable: true, options: CURRENCY_OPTIONS }
        },
        localbusiness: {
            business_type:    { label: 'Business type',  type: 'select',   searchable: true, options: [
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
            country:          { label: 'Country',         type: 'select',   searchable: true, options: COUNTRY_OPTIONS },
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
            country:          { label: 'Country',         type: 'select',   searchable: true, options: COUNTRY_OPTIONS },
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
            job_location_country:    { label: 'Job country',          type: 'select',   searchable: true, options: COUNTRY_OPTIONS },
            remote_ok:               { label: 'Remote OK',            type: 'checkbox', hint: 'Telecommute eligible' },
            salary_min:              { label: 'Minimum salary',       type: 'text' },
            salary_max:              { label: 'Maximum salary',       type: 'text' },
            salary_currency:         { label: 'Salary currency',      type: 'select',   searchable: true, options: CURRENCY_OPTIONS },
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
