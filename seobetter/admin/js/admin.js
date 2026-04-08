/**
 * SEOBetter Admin Scripts
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Toggle API key visibility
        $('input[name="api_key"]').on('focus', function() {
            $(this).attr('type', 'text');
        }).on('blur', function() {
            $(this).attr('type', 'password');
        });
    });

})(jQuery);
