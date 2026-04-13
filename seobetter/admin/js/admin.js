/**
 * SEOBetter Admin Scripts
 *
 * v1.5.19 — The async article generation handler that USED to live in this
 * file (lines 15-253 of the v1.5.18 version) was a duplicate of the inline
 * script in admin/views/content-generator.php. Both files attached click
 * handlers to #seobetter-async-generate, both polled /generate/step, both
 * called fetchResult, and both called their own renderResult — racing each
 * other to write into #seobetter-async-result. The result the user saw
 * depended on which one finished last, and the legacy admin.js renderResult
 * was a stripped-down v1.5.10-era version with no graph, no bar charts, no
 * fix buttons, no headline radio selector, AND a Save Draft button that
 * submitted to the legacy seobetter_create_draft handler that v1.5.12 already
 * deleted (so the button silently no-opped).
 *
 * The duplicate has been DELETED. The inline script in content-generator.php
 * is now the only async generator handler. This file is reduced to its
 * legitimate jobs: the API key field show/hide and any future settings-page
 * helpers.
 *
 * If you need to add admin-wide JS, add it here. Do NOT add anything that
 * touches #seobetter-async-generate, #seobetter-async-result, or
 * /seobetter/v1/generate/* — those belong in content-generator.php.
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Toggle API key visibility (only on the Settings page)
        $('input[name="api_key"]').on('focus', function() {
            $(this).attr('type', 'text');
        }).on('blur', function() {
            $(this).attr('type', 'password');
        });
    });

})(jQuery);
