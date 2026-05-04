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
                // 2026-05-04 — single date input, raw HTML5. Bypass TextControl
                // since its onChange wrapper is unreliable for date/datetime
                // input types (value doesn't propagate to React state).
                return el( 'div', { key: fieldKey, className: 'components-base-control', style: { marginBottom: '24px' } },
                    el( 'div', { className: 'components-base-control__field' },
                        el( 'label', {
                            className: 'components-base-control__label',
                            style: { display: 'block', marginBottom: '8px', fontSize: '11px', fontWeight: 500, lineHeight: 1.4, textTransform: 'uppercase' }
                        }, label ),
                        el( 'input', {
                            type: 'date',
                            value: value || '',
                            onChange: function ( e ) { onChange( e.target.value ); },
                            style: {
                                display: 'block', width: '100%', padding: '6px 8px',
                                fontSize: '13px', lineHeight: '20px', background: '#fff',
                                border: '1px solid #757575', borderRadius: '2px', boxSizing: 'border-box'
                            }
                        } )
                    )
                );
            case 'datetime-local': {
                // 2026-05-04 v62.37 — split into TWO inputs (date + time)
                // side-by-side instead of one HTML5 datetime-local field.
                //
                // Pre-fix UX trap: native datetime-local requires BOTH parts
                // filled before the value propagates. Users naturally pick the
                // date first, see "--:-- --" remaining, hit save, get
                // "Required: Start" warning — confusing because the date IS
                // visibly entered. Two reports of this on Event block before
                // we fixed it.
                //
                // New behavior:
                //   - Left input: native <input type="date"> (calendar picker)
                //   - Right input: native <input type="time"> (clock/spinner)
                //   - When user picks a date, time DEFAULTS to "09:00" so the
                //     combined value becomes valid immediately. User can still
                //     change the time.
                //   - When user changes time, combine with current date.
                //   - Stored format unchanged: "YYYY-MM-DDTHH:mm" (so PHP
                //     iso_datetime() parses it the same way).
                //
                // Also covers `end_date` on Event and `start_date`/`end_date`
                // on any future block that uses datetime-local.
                var dateValue = '';
                var timeValue = '';
                if ( value && typeof value === 'string' && value.indexOf( 'T' ) > -1 ) {
                    var parts = value.split( 'T' );
                    dateValue = parts[0] || '';
                    timeValue = parts[1] ? parts[1].slice( 0, 5 ) : '';  // strip seconds if present
                }
                var combineDateTime = function ( d, t ) {
                    if ( ! d ) return '';
                    return d + 'T' + ( t || '09:00' );
                };
                return el( 'div', { key: fieldKey, className: 'components-base-control', style: { marginBottom: '24px' } },
                    el( 'div', { className: 'components-base-control__field' },
                        el( 'label', {
                            className: 'components-base-control__label',
                            style: { display: 'block', marginBottom: '8px', fontSize: '11px', fontWeight: 500, lineHeight: 1.4, textTransform: 'uppercase' }
                        }, label ),
                        el( 'div', { style: { display: 'flex', gap: '8px' } },
                            el( 'input', {
                                type: 'date',
                                value: dateValue,
                                onChange: function ( e ) {
                                    // When date is picked, default time to 09:00
                                    // if user hasn't set one yet — makes the
                                    // combined value valid immediately.
                                    onChange( combineDateTime( e.target.value, timeValue ) );
                                },
                                style: {
                                    flex: '1 1 auto', minWidth: 0, padding: '6px 8px',
                                    fontSize: '13px', lineHeight: '20px', background: '#fff',
                                    border: '1px solid #757575', borderRadius: '2px', boxSizing: 'border-box'
                                }
                            } ),
                            el( 'input', {
                                type: 'time',
                                value: timeValue,
                                onChange: function ( e ) {
                                    onChange( combineDateTime( dateValue, e.target.value ) );
                                },
                                style: {
                                    flex: '0 0 auto', width: '110px', padding: '6px 8px',
                                    fontSize: '13px', lineHeight: '20px', background: '#fff',
                                    border: '1px solid #757575', borderRadius: '2px', boxSizing: 'border-box'
                                }
                            } )
                        ),
                        ! def.required ? null : el( 'p', {
                            style: { fontSize: '11px', color: '#6b7280', marginTop: '4px' }
                        }, 'Pick a date first; time defaults to 09:00. Both required.' )
                    )
                );
            }
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
            case 'opening_hours': {
                // v1.5.216.62.38 — Day-by-day grid replaces free-text textarea
                // for LocalBusiness opening hours. Each day has an open/closed
                // toggle plus two HTML5 time pickers. "Copy Mon → weekdays"
                // and "Copy Mon → all open days" buttons speed up common
                // cases. Wire format unchanged: schema.org strings like
                // "Mo-Fr 09:00-17:00\nSa 10:00-14:00" — newline-separated.
                // PHP parser at Schema_Blocks_Manager::build_localbusiness_jsonld()
                // splits on \n unchanged. Consecutive days with identical
                // hours collapse to "Mo-Fr 09:00-17:00" automatically when
                // the value is rebuilt. Existing free-text values still
                // parse round-trip — every line matched by the regex below
                // populates the grid; unmatched lines are silently dropped
                // (only on next save), so users with custom strings see them
                // wiped — but that's the intended migration: the UI is now
                // the source of truth.
                var DAYS = [
                    { id: 'Mo', short: 'Mon' },
                    { id: 'Tu', short: 'Tue' },
                    { id: 'We', short: 'Wed' },
                    { id: 'Th', short: 'Thu' },
                    { id: 'Fr', short: 'Fri' },
                    { id: 'Sa', short: 'Sat' },
                    { id: 'Su', short: 'Sun' }
                ];
                var dayIdxOf = function ( id ) {
                    for ( var k = 0; k < DAYS.length; k++ ) if ( DAYS[ k ].id === id ) return k;
                    return -1;
                };
                var parseHours = function ( str ) {
                    var grid = {};
                    DAYS.forEach( function ( d ) { grid[ d.id ] = null; } );
                    if ( ! str ) return grid;
                    str.split( /[\r\n]+/ ).forEach( function ( raw ) {
                        var line = ( raw || '' ).trim();
                        if ( ! line ) return;
                        var m = line.match( /^([A-Za-z]{2})(?:-([A-Za-z]{2}))?\s+(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/ );
                        if ( ! m ) return;
                        var startIdx = dayIdxOf( m[1] );
                        if ( startIdx === -1 ) return;
                        var endIdx = m[2] ? dayIdxOf( m[2] ) : startIdx;
                        if ( endIdx === -1 ) endIdx = startIdx;
                        for ( var i = startIdx; i <= endIdx; i++ ) {
                            grid[ DAYS[ i ].id ] = { open: m[3], close: m[4] };
                        }
                    } );
                    return grid;
                };
                var formatHours = function ( grid ) {
                    var lines = [];
                    var i = 0;
                    while ( i < DAYS.length ) {
                        var hours = grid[ DAYS[ i ].id ];
                        if ( ! hours ) { i++; continue; }
                        var j = i;
                        while (
                            j + 1 < DAYS.length &&
                            grid[ DAYS[ j + 1 ].id ] &&
                            grid[ DAYS[ j + 1 ].id ].open  === hours.open &&
                            grid[ DAYS[ j + 1 ].id ].close === hours.close
                        ) j++;
                        lines.push(
                            ( j === i ? DAYS[ i ].id : DAYS[ i ].id + '-' + DAYS[ j ].id ) +
                            ' ' + hours.open + '-' + hours.close
                        );
                        i = j + 1;
                    }
                    return lines.join( '\n' );
                };
                var grid = parseHours( value );
                var pushGrid = function ( g ) { onChange( formatHours( g ) ); };
                var setDay = function ( dayId, hours ) {
                    var g = {};
                    DAYS.forEach( function ( d ) { g[ d.id ] = grid[ d.id ]; } );
                    g[ dayId ] = hours;
                    pushGrid( g );
                };
                var copySource = function () {
                    // Find first open day to use as source for "copy" buttons.
                    for ( var k = 0; k < DAYS.length; k++ ) if ( grid[ DAYS[ k ].id ] ) return DAYS[ k ];
                    return null;
                };
                var copyToWeekdays = function () {
                    var src = copySource(); if ( ! src ) return;
                    var g = {};
                    DAYS.forEach( function ( d ) { g[ d.id ] = grid[ d.id ]; } );
                    [ 'Mo', 'Tu', 'We', 'Th', 'Fr' ].forEach( function ( id ) {
                        g[ id ] = { open: grid[ src.id ].open, close: grid[ src.id ].close };
                    } );
                    pushGrid( g );
                };
                var copyToAllOpen = function () {
                    var src = copySource(); if ( ! src ) return;
                    var g = {};
                    DAYS.forEach( function ( d ) {
                        g[ d.id ] = grid[ d.id ]
                            ? { open: grid[ src.id ].open, close: grid[ src.id ].close }
                            : null;
                    } );
                    pushGrid( g );
                };
                var inputStyle = {
                    flex: '0 0 auto', width: '90px', padding: '4px 6px',
                    fontSize: '12px', lineHeight: '18px', background: '#fff',
                    border: '1px solid #757575', borderRadius: '2px', boxSizing: 'border-box'
                };
                return el( 'div', { key: fieldKey, className: 'components-base-control', style: { marginBottom: '24px' } },
                    el( 'label', {
                        className: 'components-base-control__label',
                        style: { display: 'block', marginBottom: '8px', fontSize: '11px', fontWeight: 500, lineHeight: 1.4, textTransform: 'uppercase' }
                    }, label ),
                    el( 'div', { style: { background: '#fafafa', border: '1px solid #e5e7eb', borderRadius: '4px', padding: '8px' } },
                        DAYS.map( function ( d ) {
                            var hours  = grid[ d.id ];
                            var isOpen = !! hours;
                            return el( 'div', {
                                key: d.id,
                                style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px', minHeight: '28px' }
                            },
                                el( 'label', {
                                    style: { display: 'flex', alignItems: 'center', gap: '6px', minWidth: '90px', fontSize: '12px', cursor: 'pointer' }
                                },
                                    el( 'input', {
                                        type: 'checkbox',
                                        checked: isOpen,
                                        onChange: function ( e ) {
                                            setDay( d.id, e.target.checked ? { open: '09:00', close: '17:00' } : null );
                                        }
                                    } ),
                                    el( 'span', { style: { fontWeight: isOpen ? 600 : 400 } }, d.short )
                                ),
                                isOpen ? el( 'input', {
                                    type: 'time',
                                    value: hours.open,
                                    onChange: function ( e ) {
                                        setDay( d.id, { open: e.target.value, close: hours.close } );
                                    },
                                    style: inputStyle
                                } ) : null,
                                isOpen ? el( 'span', { style: { color: '#888', fontSize: '12px' } }, '–' ) : el( 'span', { style: { color: '#888', fontSize: '12px', fontStyle: 'italic' } }, 'Closed' ),
                                isOpen ? el( 'input', {
                                    type: 'time',
                                    value: hours.close,
                                    onChange: function ( e ) {
                                        setDay( d.id, { open: hours.open, close: e.target.value } );
                                    },
                                    style: inputStyle
                                } ) : null
                            );
                        } )
                    ),
                    el( 'div', { style: { marginTop: '8px', display: 'flex', gap: '6px', flexWrap: 'wrap' } },
                        el( Button, { variant: 'tertiary', isSmall: true, onClick: copyToWeekdays }, 'Copy first row → Mon-Fri' ),
                        el( Button, { variant: 'tertiary', isSmall: true, onClick: copyToAllOpen }, 'Copy first row → all open days' )
                    ),
                    el( 'p', { style: { fontSize: '11px', color: '#6b7280', marginTop: '6px' } },
                        'Schema.org wire format: ' + ( formatHours( grid ).replace( /\n/g, ' • ' ) || '(none — closed all week)' )
                    )
                );
            }
            case 'geo_coordinates': {
                // v1.5.216.62.38 — Combined latitude + longitude widget with
                // a "Get from address" button that calls the OpenStreetMap
                // Nominatim public geocoder (free, no API key, attribution
                // required which we provide via the help-text link). The
                // field key is `latitude` for storage purposes; this widget
                // also writes `longitude` as a sibling attribute on the
                // same setAttr call. The standalone `longitude` field-def
                // is marked type:'hidden' so it's still declared as a
                // block attribute but doesn't render its own row.
                //
                // Why OSM not Google Maps Geocoding: Google requires a
                // billing-enabled API key per user-install. Nominatim is
                // free and the accuracy for street-level postal addresses
                // is good enough for "near me" search structured-data.
                // Rate limit: 1 req/sec per IP — fine for single-button
                // user-triggered lookups.
                var lat = attrs.latitude  || '';
                var lng = attrs.longitude || '';
                var lookupBusy = false;
                var coordInputStyle = {
                    flex: '1 1 0', minWidth: 0, padding: '6px 8px',
                    fontSize: '13px', lineHeight: '20px', background: '#fff',
                    border: '1px solid #757575', borderRadius: '2px', boxSizing: 'border-box'
                };
                var doLookup = function ( e ) {
                    if ( e && e.preventDefault ) e.preventDefault();
                    if ( lookupBusy ) return;
                    var parts = [
                        attrs.street_address, attrs.locality, attrs.region, attrs.postal_code, attrs.country
                    ].filter( function ( p ) { return p && String( p ).trim(); } );
                    if ( parts.length === 0 ) {
                        window.alert( 'Fill in the address fields above first (street, city, country).' );
                        return;
                    }
                    var query = parts.join( ', ' );
                    lookupBusy = true;
                    fetch( 'https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent( query ) + '&format=json&limit=1&addressdetails=0', {
                        headers: { 'Accept': 'application/json' }
                    } )
                        .then( function ( r ) { return r.ok ? r.json() : []; } )
                        .then( function ( results ) {
                            lookupBusy = false;
                            if ( results && results.length > 0 ) {
                                setAttr( {
                                    latitude:  String( results[0].lat || '' ),
                                    longitude: String( results[0].lon || '' )
                                } );
                            } else {
                                window.alert( 'No coordinates found for "' + query + '". Try a more specific address.' );
                            }
                        } )
                        .catch( function () {
                            lookupBusy = false;
                            window.alert( 'OpenStreetMap lookup failed. Check your internet connection and try again.' );
                        } );
                };
                return el( 'div', { key: fieldKey, className: 'components-base-control', style: { marginBottom: '24px' } },
                    el( 'label', {
                        className: 'components-base-control__label',
                        style: { display: 'block', marginBottom: '8px', fontSize: '11px', fontWeight: 500, lineHeight: 1.4, textTransform: 'uppercase' }
                    }, 'Coordinates (lat, lng)' ),
                    el( Button, {
                        variant: 'secondary',
                        onClick: doLookup,
                        style: { marginBottom: '8px' }
                    }, 'Get coordinates from address' ),
                    el( 'div', { style: { display: 'flex', gap: '8px' } },
                        el( 'input', {
                            type: 'text',
                            placeholder: 'Latitude',
                            value: lat,
                            onChange: function ( e ) { setAttr( { latitude: e.target.value } ); },
                            style: coordInputStyle
                        } ),
                        el( 'input', {
                            type: 'text',
                            placeholder: 'Longitude',
                            value: lng,
                            onChange: function ( e ) { setAttr( { longitude: e.target.value } ); },
                            style: coordInputStyle
                        } )
                    ),
                    el( 'p', { style: { fontSize: '11px', color: '#6b7280', marginTop: '4px' } },
                        'Optional — improves Google Maps "near me" results. Coordinates from OpenStreetMap (© OpenStreetMap contributors).'
                    )
                );
            }
            case 'hidden':
                // Field is registered as a block attribute (so the value
                // round-trips through save/load) but isn't rendered in the
                // sidebar. Used by `longitude` on LocalBusiness — its UI
                // is owned by the sibling `latitude` field's geo_coordinates
                // widget. Returning null produces no DOM but keeps the
                // attribute schema valid.
                return null;
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
            // v1.5.216.62.38 — business_type expanded from 41 → 96 entries
            // covering the full Schema.org LocalBusiness taxonomy plus the
            // commonly-misclassified neighbours (Place subtypes like Museum,
            // EducationalOrganization subtypes like School). Labels are
            // category-prefixed so a flat searchable Combobox (no optgroup
            // support) still feels organized — type "food" or "auto" or
            // "store" to filter. Stored value is the bare Schema.org token
            // (preserves JSON-LD validity); friendly card-header label is
            // produced by Schema_Blocks_Manager::humanize_business_type().
            business_type:    { label: 'Business type',  type: 'select',   searchable: true, options: [
                { value: 'LocalBusiness',           label: 'General — Local Business' },
                { value: 'ProfessionalService',     label: 'General — Professional Service' },

                // Food & drink
                { value: 'Restaurant',              label: 'Food & drink — Restaurant' },
                { value: 'FastFoodRestaurant',      label: 'Food & drink — Fast-food restaurant' },
                { value: 'CafeOrCoffeeShop',        label: 'Food & drink — Café / coffee shop' },
                { value: 'BarOrPub',                label: 'Food & drink — Bar / pub' },
                { value: 'Bakery',                  label: 'Food & drink — Bakery' },
                { value: 'IceCreamShop',            label: 'Food & drink — Ice cream shop' },
                { value: 'Brewery',                 label: 'Food & drink — Brewery' },
                { value: 'Winery',                  label: 'Food & drink — Winery' },
                { value: 'Distillery',              label: 'Food & drink — Distillery' },
                { value: 'FoodEstablishment',       label: 'Food & drink — Food establishment (generic)' },

                // Lodging
                { value: 'Hotel',                   label: 'Lodging — Hotel' },
                { value: 'Motel',                   label: 'Lodging — Motel' },
                { value: 'BedAndBreakfast',         label: 'Lodging — Bed & breakfast' },
                { value: 'Hostel',                  label: 'Lodging — Hostel' },
                { value: 'Resort',                  label: 'Lodging — Resort' },
                { value: 'SkiResort',               label: 'Lodging — Ski resort' },
                { value: 'Campground',              label: 'Lodging — Campground' },
                { value: 'RVPark',                  label: 'Lodging — RV park' },
                { value: 'LodgingBusiness',         label: 'Lodging — Lodging (generic)' },

                // Stores / retail
                { value: 'Store',                   label: 'Store — Store (generic)' },
                { value: 'ClothingStore',           label: 'Store — Clothing store' },
                { value: 'MensClothingStore',       label: "Store — Men's clothing" },
                { value: 'ShoeStore',               label: 'Store — Shoe store' },
                { value: 'JewelryStore',            label: 'Store — Jeweller' },
                { value: 'GroceryStore',            label: 'Store — Grocery store' },
                { value: 'ConvenienceStore',        label: 'Store — Convenience store' },
                { value: 'LiquorStore',             label: 'Store — Liquor store' },
                { value: 'Florist',                 label: 'Store — Florist' },
                { value: 'BookStore',               label: 'Store — Bookstore' },
                { value: 'MusicStore',              label: 'Store — Music store' },
                { value: 'HobbyShop',               label: 'Store — Hobby shop' },
                { value: 'ToyStore',                label: 'Store — Toy store' },
                { value: 'SportingGoodsStore',      label: 'Store — Sporting goods' },
                { value: 'BikeStore',               label: 'Store — Bike store' },
                { value: 'PetStore',                label: 'Store — Pet store' },
                { value: 'ElectronicsStore',        label: 'Store — Electronics' },
                { value: 'ComputerStore',           label: 'Store — Computer store' },
                { value: 'MobilePhoneStore',        label: 'Store — Mobile-phone store' },
                { value: 'OfficeEquipmentStore',    label: 'Store — Office equipment' },
                { value: 'FurnitureStore',          label: 'Store — Furniture' },
                { value: 'HomeGoodsStore',          label: 'Store — Home goods' },
                { value: 'HardwareStore',           label: 'Store — Hardware' },
                { value: 'GardenStore',             label: 'Store — Garden centre' },
                { value: 'DepartmentStore',         label: 'Store — Department store' },
                { value: 'OutletStore',             label: 'Store — Outlet store' },
                { value: 'WholesaleStore',          label: 'Store — Wholesale' },
                { value: 'PawnShop',                label: 'Store — Pawn shop' },
                { value: 'TireShop',                label: 'Store — Tire shop' },
                { value: 'AutoPartsStore',          label: 'Store — Auto parts' },
                { value: 'ShoppingCenter',          label: 'Store — Shopping centre' },

                // Health & medical
                { value: 'Hospital',                label: 'Health — Hospital' },
                { value: 'MedicalClinic',           label: 'Health — Medical clinic' },
                { value: 'Physician',               label: 'Health — Doctor' },
                { value: 'Dentist',                 label: 'Health — Dentist' },
                { value: 'Pharmacy',                label: 'Health — Pharmacy' },
                { value: 'Optician',                label: 'Health — Optician' },
                { value: 'VeterinaryCare',          label: 'Health — Veterinary clinic' },

                // Beauty & personal care
                { value: 'BeautySalon',             label: 'Beauty — Beauty salon' },
                { value: 'HairSalon',               label: 'Beauty — Hair salon' },
                { value: 'NailSalon',               label: 'Beauty — Nail salon' },
                { value: 'DaySpa',                  label: 'Beauty — Day spa' },
                { value: 'HealthClub',              label: 'Beauty — Gym / health club' },
                { value: 'TattooParlor',            label: 'Beauty — Tattoo parlour' },

                // Auto
                { value: 'AutoDealer',              label: 'Auto — Auto dealer' },
                { value: 'AutoRepair',              label: 'Auto — Auto repair' },
                { value: 'AutoBodyShop',            label: 'Auto — Auto body shop' },
                { value: 'AutoRental',              label: 'Auto — Auto rental' },
                { value: 'AutoWash',                label: 'Auto — Car wash' },
                { value: 'GasStation',              label: 'Auto — Gas station' },
                { value: 'MotorcycleDealer',        label: 'Auto — Motorcycle dealer' },
                { value: 'MotorcycleRepair',       label: 'Auto — Motorcycle repair' },

                // Home / construction services
                { value: 'Plumber',                 label: 'Home services — Plumber' },
                { value: 'Electrician',             label: 'Home services — Electrician' },
                { value: 'HVACBusiness',            label: 'Home services — HVAC' },
                { value: 'HousePainter',            label: 'Home services — House painter' },
                { value: 'RoofingContractor',       label: 'Home services — Roofing contractor' },
                { value: 'GeneralContractor',       label: 'Home services — General contractor' },
                { value: 'Locksmith',               label: 'Home services — Locksmith' },
                { value: 'MovingCompany',           label: 'Home services — Moving company' },

                // Financial / professional / legal
                { value: 'BankOrCreditUnion',       label: 'Financial — Bank / credit union' },
                { value: 'AutomatedTeller',         label: 'Financial — ATM' },
                { value: 'AccountingService',       label: 'Financial — Accounting service' },
                { value: 'InsuranceAgency',         label: 'Financial — Insurance agency' },
                { value: 'FinancialService',        label: 'Financial — Financial service (generic)' },
                { value: 'Attorney',                label: 'Legal — Attorney / law firm' },
                { value: 'Notary',                  label: 'Legal — Notary' },
                { value: 'LegalService',            label: 'Legal — Legal service (generic)' },

                // Travel / tourism
                { value: 'TravelAgency',            label: 'Travel — Travel agency' },
                { value: 'TouristInformationCenter', label: 'Travel — Tourist information centre' },

                // Real estate
                { value: 'RealEstateAgent',         label: 'Real estate — Real estate agent' },

                // Sports & recreation
                { value: 'BowlingAlley',            label: 'Sports — Bowling alley' },
                { value: 'GolfCourse',              label: 'Sports — Golf course' },
                { value: 'SportsClub',              label: 'Sports — Sports club' },
                { value: 'StadiumOrArena',          label: 'Sports — Stadium / arena' },
                { value: 'TennisComplex',           label: 'Sports — Tennis complex' },
                { value: 'PublicSwimmingPool',      label: 'Sports — Swimming pool' },
                { value: 'ExerciseGym',             label: 'Sports — Exercise gym' },

                // Entertainment
                { value: 'MovieTheater',            label: 'Entertainment — Cinema' },
                { value: 'AmusementPark',           label: 'Entertainment — Amusement park' },
                { value: 'ArtGallery',              label: 'Entertainment — Art gallery' },
                { value: 'Casino',                  label: 'Entertainment — Casino' },
                { value: 'ComedyClub',              label: 'Entertainment — Comedy club' },
                { value: 'NightClub',               label: 'Entertainment — Night club' },
                { value: 'EntertainmentBusiness',   label: 'Entertainment — Entertainment (generic)' },

                // Civic / community
                { value: 'Library',                 label: 'Civic — Library' },
                { value: 'Museum',                  label: 'Civic — Museum' },
                { value: 'TouristAttraction',       label: 'Civic — Tourist attraction' },
                { value: 'GovernmentOffice',        label: 'Civic — Government office' },
                { value: 'PostOffice',              label: 'Civic — Post office' },
                { value: 'FireStation',             label: 'Civic — Fire station' },
                { value: 'PoliceStation',           label: 'Civic — Police station' },
                { value: 'EmergencyService',        label: 'Civic — Emergency service' },
                { value: 'AnimalShelter',           label: 'Civic — Animal shelter' },
                { value: 'RecyclingCenter',         label: 'Civic — Recycling centre' },
                { value: 'PlaceOfWorship',          label: 'Civic — Place of worship (generic)' },
                { value: 'Church',                  label: 'Civic — Church' },
                { value: 'Mosque',                  label: 'Civic — Mosque' },
                { value: 'Synagogue',               label: 'Civic — Synagogue' },
                { value: 'HinduTemple',             label: 'Civic — Hindu temple' },
                { value: 'BuddhistTemple',          label: 'Civic — Buddhist temple' },
                { value: 'CatholicChurch',          label: 'Civic — Catholic church' },
                { value: 'Cemetery',                label: 'Civic — Cemetery' },
                { value: 'FuneralParlor',           label: 'Civic — Funeral parlour' },
                { value: 'Park',                    label: 'Civic — Park' },

                // Education
                { value: 'School',                  label: 'Education — School (generic)' },
                { value: 'Preschool',               label: 'Education — Preschool' },
                { value: 'ElementarySchool',        label: 'Education — Elementary school' },
                { value: 'MiddleSchool',            label: 'Education — Middle school' },
                { value: 'HighSchool',              label: 'Education — High school' },
                { value: 'CollegeOrUniversity',     label: 'Education — College / university' },
                { value: 'ChildCare',               label: 'Education — Childcare' },

                // Other services
                { value: 'EmploymentAgency',        label: 'Other — Employment agency' },
                { value: 'DryCleaningOrLaundry',    label: 'Other — Dry cleaning / laundry' },
                { value: 'SelfStorage',             label: 'Other — Self storage' },
                { value: 'InternetCafe',            label: 'Other — Internet café' },
                { value: 'RadioStation',            label: 'Other — Radio station' },
                { value: 'TelevisionStation',       label: 'Other — Television station' },
                { value: 'ArchiveOrganization',     label: 'Other — Archive organisation' }
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
            // v62.38 — opening_hours uses the day-grid widget (renderField
            // case 'opening_hours'). Wire format unchanged for PHP.
            opening_hours:    { label: 'Opening hours',  type: 'opening_hours' },
            price_range:      { label: 'Price range',    type: 'text',     placeholder: '$$' },
            // v62.38 — latitude renders the combined coords + OSM-lookup
            // widget; longitude is stored but not rendered separately.
            latitude:         { label: 'Coordinates',    type: 'geo_coordinates' },
            longitude:        { label: 'Longitude',      type: 'hidden' }
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
