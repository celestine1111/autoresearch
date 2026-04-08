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

        // ===== ASYNC ARTICLE GENERATION =====
        var $genBtn = $('#seobetter-async-generate');
        if (!$genBtn.length) return;

        var $panel = $('#seobetter-progress-panel');
        var $bar = $('#seobetter-progress-bar');
        var $label = $('#seobetter-progress-label');
        var $steps = $('#seobetter-progress-steps');
        var $time = $('#seobetter-progress-time');
        var $title = $('#seobetter-progress-title');
        var $error = $('#seobetter-progress-error');
        var $errorMsg = $('#seobetter-progress-error-msg');
        var $estimate = $('#seobetter-progress-estimate');
        var $result = $('#seobetter-async-result');
        var timer = null;
        var elapsed = 0;
        var currentJobId = null;
        var apiBase = (typeof wpApiSettings !== 'undefined' && wpApiSettings.root) ? wpApiSettings.root : '/wp-json/';

        function startTimer() {
            elapsed = 0;
            clearInterval(timer);
            timer = setInterval(function() {
                elapsed++;
                var m = Math.floor(elapsed / 60);
                var s = elapsed % 60;
                $time.text(m + ':' + (s < 10 ? '0' : '') + s);
            }, 1000);
        }

        function stopTimer() { clearInterval(timer); }

        function setProgress(pct, label, current, total) {
            $bar.css('width', pct + '%').text(Math.round(pct) + '%');
            if (label) $label.text(label);
            if (current && total) $steps.text('Step ' + current + ' of ' + total);
        }

        function showError(msg) {
            $error.show();
            $errorMsg.text(msg);
        }

        function hideError() { $error.hide(); }

        var formAffiliates = [];

        function collectFormData() {
            var form = $genBtn.closest('form');

            // Collect affiliate data
            formAffiliates = [];
            form.find('.sb-aff-row, [name^="affiliates"]').closest('div').each(function() {
                var $row = $(this);
                var url = $row.find('[name*="[url]"]').val();
                var keyword = $row.find('[name*="[keyword]"]').val();
                var name = $row.find('[name*="[name]"]').val();
                if (url && keyword) {
                    formAffiliates.push({ url: url, keyword: keyword, name: name || keyword });
                }
            });

            return {
                keyword: form.find('[name="primary_keyword"]').val(),
                secondary_keywords: form.find('[name="secondary_keywords"]').val(),
                lsi_keywords: form.find('[name="lsi_keywords"]').val(),
                word_count: form.find('[name="word_count"]').val(),
                tone: form.find('[name="tone"]').val(),
                domain: form.find('[name="domain"]').val(),
                audience: form.find('[name="audience"]').val(),
                accent_color: form.find('[name="accent_color"]').val()
            };
        }

        function apiCall(endpoint, method, data) {
            var opts = {
                url: apiBase + 'seobetter/v1/' + endpoint,
                method: method || 'POST',
                contentType: 'application/json',
                dataType: 'json',
                timeout: 180000, // 3 min per step
                beforeSend: function(xhr) {
                    if (typeof wpApiSettings !== 'undefined' && wpApiSettings.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
                    }
                }
            };
            if (data && method !== 'GET') opts.data = JSON.stringify(data);
            if (data && method === 'GET') opts.url += '?' + $.param(data);
            return $.ajax(opts);
        }

        function processNextStep() {
            hideError();
            apiCall('generate/step', 'POST', { job_id: currentJobId })
                .done(function(res) {
                    if (!res.success && res.error) {
                        showError(res.error);
                        if (!res.can_retry) stopTimer();
                        return;
                    }
                    setProgress(res.progress || 0, res.label || '', res.current || 0, res.total || 0);

                    if (res.done) {
                        $label.text('Loading results...');
                        fetchResult();
                    } else {
                        processNextStep();
                    }
                })
                .fail(function(xhr) {
                    var msg = 'Request failed';
                    if (xhr.status === 504 || xhr.status === 0) msg = 'Server timeout — click Retry to continue';
                    else if (xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
                    showError(msg);
                });
        }

        function fetchResult() {
            apiCall('generate/result', 'GET', { job_id: currentJobId })
                .done(function(res) {
                    stopTimer();
                    if (!res.success) {
                        showError(res.error || 'Failed to load results.');
                        return;
                    }
                    setProgress(100, 'Complete!', res.total, res.total);
                    $title.text('Article generated!');
                    renderResult(res);
                })
                .fail(function() {
                    stopTimer();
                    showError('Failed to load results. Try refreshing the page.');
                });
        }

        function renderResult(res) {
            // Build the result HTML (same structure as server-side rendering)
            var scoreClass = res.geo_score >= 80 ? 'good' : (res.geo_score >= 60 ? 'ok' : 'poor');
            var html = '<div class="seobetter-card" style="padding:20px;margin-top:16px">';
            html += '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;font-size:13px">';
            html += '<span><strong>GEO Score:</strong> <span class="seobetter-score seobetter-score-' + scoreClass + '">' + res.geo_score + ' (' + res.grade + ')</span></span>';
            html += '<span><strong>Words:</strong> ' + (res.word_count || 0).toLocaleString() + '</span>';
            html += '</div>';

            // Suggestions
            if (res.suggestions && res.suggestions.length) {
                html += '<div style="margin-bottom:16px">';
                res.suggestions.forEach(function(s) {
                    var icon = s.priority === 'high' ? 'warning' : 'info-outline';
                    html += '<div class="seobetter-suggestion seobetter-suggestion-' + s.priority + '">';
                    html += '<span class="dashicons dashicons-' + icon + '"></span>';
                    html += '<span class="seobetter-suggestion-type">[' + (s.type || 'issue') + ']</span> ';
                    html += s.message + '</div>';
                });
                html += '</div>';
            }

            // Content preview — output style tag separately
            var content = res.content || '';
            var styleMatch = content.match(/<style>[\s\S]*?<\/style>/);
            if (styleMatch) {
                html += styleMatch[0];
                content = content.replace(/<style>[\s\S]*?<\/style>/, '');
            }
            html += '<div class="seobetter-content-preview">' + content + '</div>';

            // Headlines
            if (res.headlines && res.headlines.length) {
                html += '<div style="padding:16px;background:var(--sb-primary-light,#f0f0ff);border-radius:8px;margin-top:16px">';
                html += '<h4 style="margin:0 0 10px;font-size:13px;font-weight:700">Headlines</h4>';
                res.headlines.forEach(function(h, i) {
                    html += '<div style="padding:4px 0;font-size:13px">' + (i+1) + '. ' + $('<span>').text(h).html() + ' <span style="color:#888">(' + h.length + ' chars)</span></div>';
                });
                html += '</div>';
            }

            // Save as Draft form
            html += '<form method="post" style="margin-top:16px">';
            html += '<input type="hidden" name="_wpnonce" value="' + $('[name="_wpnonce"]').val() + '">';
            html += '<input type="hidden" name="draft_content" value="' + $('<div>').text(res.content || '').html() + '">';
            html += '<input type="hidden" name="draft_markdown" value="' + $('<div>').text(res.markdown || '').html() + '">';
            html += '<input type="hidden" name="draft_keyword" value="' + $('<div>').text(res.keyword || '').html() + '">';
            html += '<input type="hidden" name="draft_title" value="' + $('<div>').text((res.headlines && res.headlines[0]) || res.keyword || '').html() + '">';
            html += '<input type="hidden" name="draft_accent_color" value="' + ($('[name="accent_color"]').val() || '#764ba2') + '">';
            html += '<input type="hidden" name="draft_affiliates" value="' + $('<div>').text(JSON.stringify(formAffiliates)).html() + '">';
            html += '<button type="submit" name="seobetter_create_draft" class="button sb-btn-primary" style="height:44px;margin-top:8px">Save as WordPress Draft</button>';
            html += '</form>';

            html += '</div>';
            $result.html(html).show();
            // Scroll to result
            $('html, body').animate({ scrollTop: $result.offset().top - 50 }, 500);
        }

        // Start generation — intercept submit, use AJAX instead
        $genBtn.on('click', function(e) {
            e.preventDefault();
            var data = collectFormData();
            if (!data.keyword) {
                alert('Please enter a primary keyword.');
                return;
            }

            $genBtn.prop('disabled', true).text('Generating...');
            $panel.show();
            $result.hide();
            hideError();
            setProgress(0, 'Starting generation...', 0, 0);
            startTimer();

            apiCall('generate/start', 'POST', data)
                .done(function(res) {
                    if (!res.success) {
                        stopTimer();
                        showError(res.error || 'Failed to start generation.');
                        $genBtn.prop('disabled', false).text('Generate Article');
                        return;
                    }
                    currentJobId = res.job_id;
                    $estimate.text('Estimated time: ~' + res.est_minutes + ' min');
                    $steps.text('Step 0 of ' + res.total_steps);
                    processNextStep();
                })
                .fail(function(xhr) {
                    stopTimer();
                    showError('Failed to connect. Check your API key in Settings.');
                    $genBtn.prop('disabled', false).text('Generate Article');
                });
        });

        // Retry button
        $(document).on('click', '#seobetter-retry-btn', function() {
            if (!currentJobId) return;
            hideError();
            $label.text('Retrying...');
            processNextStep();
        });
    });

})(jQuery);
