/**
 * SEOBetter Gutenberg Editor v1.4.1
 * Only uses PluginDocumentSettingPanel (proven to work).
 * Full headline analyzer built into the panel.
 */
(function() {
    if (typeof wp === 'undefined') return;
    if (!wp.plugins || !wp.plugins.registerPlugin) return;
    if (!wp.element || !wp.element.createElement) return;

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var Fragment = wp.element.Fragment;
    var registerPlugin = wp.plugins.registerPlugin;

    var _e = wp.editor || {};
    var _ep = wp.editPost || {};
    var DocPanel = _e.PluginDocumentSettingPanel || _ep.PluginDocumentSettingPanel;
    if (!DocPanel) return;

    var PanelBody = wp.components.PanelBody;
    var Button = wp.components.Button;

    var cachedData = null;

    function useAnalysis() {
        var s1 = useState(cachedData); var data = s1[0]; var setData = s1[1];
        var s2 = useState(!cachedData); var loading = s2[0]; var setLoading = s2[1];
        var runAnalysis = function() {
            try {
                cachedData = null;
                var postId = wp.data.select('core/editor').getCurrentPostId();
                if (!postId) { setLoading(false); return; }
                setLoading(true);
                wp.apiFetch({ path: '/seobetter/v1/analyze/' + postId })
                    .then(function(r) { cachedData = r; setData(r); setLoading(false); })
                    .catch(function() { setLoading(false); });
            } catch(e) { setLoading(false); }
        };
        useEffect(function() {
            if (cachedData) { setData(cachedData); setLoading(false); } else runAnalysis();
        }, []);
        return { data: data, loading: loading, runAnalysis: runAnalysis };
    }

    function sc(s) { return s >= 80 ? '#22c55e' : (s >= 60 ? '#f59e0b' : '#ef4444'); }

    // ============================================================
    // Toolbar badge (DOM injection — non-React, won't crash plugin)
    // ============================================================
    function injectToolbarBadge(score) {
        try {
            if (document.getElementById('seobetter-toolbar-badge')) {
                document.getElementById('seobetter-toolbar-badge').querySelector('span:last-child').textContent = score + '/100';
                return;
            }
            var toolbar = document.querySelector('.edit-post-header__settings') ||
                          document.querySelector('.editor-header__settings');
            if (!toolbar) return;

            var color = sc(score);
            var badge = document.createElement('div');
            badge.id = 'seobetter-toolbar-badge';
            badge.title = 'SEOBetter GEO Score';
            badge.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:0 10px;height:32px;border-radius:4px;margin-right:8px;border:1px solid ' + color + ';background:' + color + '12;cursor:pointer;';
            badge.onclick = function() {
                // Open the Post tab in the sidebar if not already open
                try {
                    var postTab = document.querySelector('.edit-post-sidebar__panel-tab[data-label="Post"]') ||
                                  document.querySelector('button[aria-label="Post"]') ||
                                  document.querySelector('.editor-sidebar__panel-tabs button:first-child');
                    if (postTab) postTab.click();
                } catch(e) {}
                // Scroll to the SEOBetter panel
                setTimeout(function() {
                    var panel = document.querySelector('[data-wb-id="seobetter-panel"]') ||
                                document.querySelector('.components-panel__body:has([class*="seobetter"])');
                    // Fallback: find by title text
                    if (!panel) {
                        var allPanels = document.querySelectorAll('.components-panel__body');
                        for (var i = 0; i < allPanels.length; i++) {
                            var btn = allPanels[i].querySelector('.components-panel__body-title button');
                            if (btn && btn.textContent.indexOf('SEOBetter') !== -1) {
                                panel = allPanels[i];
                                break;
                            }
                        }
                    }
                    if (panel) {
                        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        // Open the panel if it's collapsed
                        var toggleBtn = panel.querySelector('.components-panel__body-title button');
                        if (toggleBtn && panel.querySelector('.components-panel__body-toggle-icon svg[style*="rotate"]')) {
                            toggleBtn.click();
                        }
                    }
                }, 200);
            };

            var icon = document.createElement('span');
            icon.textContent = '📊';
            icon.style.cssText = 'font-size:14px;';

            var text = document.createElement('span');
            text.style.cssText = 'font-size:13px;font-weight:700;color:' + color + ';';
            text.textContent = score + '/100';

            badge.appendChild(icon);
            badge.appendChild(text);
            toolbar.insertBefore(badge, toolbar.firstChild);
        } catch(e) {}
    }

    // ============================================================
    // Headline Analyzer
    // ============================================================
    function analyzeHeadline(title) {
        if (!title) return null;
        var words = title.split(/\s+/).filter(function(w) { return w.length > 0; });
        var wc = words.length;
        var cc = title.length;

        var type = 'General';
        if (/^\d+\s/.test(title) || /top\s+\d+/i.test(title)) type = 'List';
        else if (/^how\s+to/i.test(title)) type = 'How-to';
        else if (/\?$/.test(title)) type = 'Question';
        else if (/\bvs\.?\b|\bversus\b/i.test(title)) type = 'Comparison';

        var pw = ['best','top','ultimate','complete','essential','proven','expert','guide','review','amazing','free','luxury','premium','powerful','guaranteed','secret'];
        var ew = ['love','hate','fear','amazing','shocking','exciting','inspiring','terrible','brilliant','stunning'];
        var cw = ['the','a','an','in','on','at','to','for','of','and','or','but','is','are','it','you','your','we','what','how','why','when','where','which','that','this','with'];

        var foundPW = words.filter(function(w) { return pw.indexOf(w.toLowerCase()) !== -1; });
        var foundEW = words.filter(function(w) { return ew.indexOf(w.toLowerCase()) !== -1; });
        var foundCW = words.filter(function(w) { return cw.indexOf(w.toLowerCase()) !== -1; });

        var posW = ['best','good','great','amazing','love','excellent','top','premium','luxury','essential','proven','powerful','super','ultimate'];
        var negW = ['worst','bad','terrible','hate','awful','avoid','never','danger','warning'];
        var pos = words.filter(function(w) { return posW.indexOf(w.toLowerCase()) !== -1; }).length;
        var neg = words.filter(function(w) { return negW.indexOf(w.toLowerCase()) !== -1; }).length;
        var sentiment = pos > neg ? 'Positive 😊' : (neg > pos ? 'Negative 😟' : 'Neutral 😐');

        return {
            type: type, cc: cc, wc: wc,
            ccOk: cc >= 45 && cc <= 65,
            wcOk: wc >= 6 && wc <= 12,
            powerPct: Math.round((foundPW.length / wc) * 100),
            emotionalPct: Math.round((foundEW.length / wc) * 100),
            commonPct: Math.round((foundCW.length / wc) * 100),
            foundPW: foundPW, foundEW: foundEW,
            sentiment: sentiment,
            begin: words.slice(0, 3).join(' '),
            end: words.slice(-3).join(' ')
        };
    }

    // ============================================================
    // Main Panel
    // ============================================================
    function SEOBetterPanel() {
        var r = useAnalysis();
        var data = r.data;
        var loading = r.loading;
        var expanded = useState(false);
        var showHL = expanded[0];
        var setShowHL = expanded[1];
        var rpState = useState(false);
        var showRP = rpState[0];
        var setShowRP = rpState[1];

        // Inject toolbar badge
        useEffect(function() {
            if (data && data.geo_score !== undefined) {
                injectToolbarBadge(data.geo_score);
                setTimeout(function() { injectToolbarBadge(data.geo_score); }, 1000);
                setTimeout(function() { injectToolbarBadge(data.geo_score); }, 3000);
            }
        }, [data]);

        if (loading) return el(DocPanel, { name: 'seobetter-panel', title: 'SEOBetter', initialOpen: true },
            el('p', { style: { textAlign: 'center', fontSize: 13, color: '#666' } }, 'Analyzing...'));

        if (!data || data.geo_score === undefined) return el(DocPanel, { name: 'seobetter-panel', title: 'SEOBetter', initialOpen: true },
            el('p', { style: { fontSize: 13, color: '#666', margin: 0 } }, 'Save the post to see GEO score.'));

        var score = data.geo_score || 0;
        var color = sc(score);
        var checks = data.checks || {};
        var words = data.word_count || 0;
        var readTime = Math.max(1, Math.ceil(words / 200));

        // Score ring (compact)
        var circ = 2 * Math.PI * 36;
        var offset = circ - (score / 100) * circ;
        var rating = score >= 90 ? 'Excellent! 🔥🔥🔥' : (score >= 80 ? 'Great! 🔥🔥' : (score >= 70 ? 'Good 🔥' : (score >= 60 ? 'Needs work' : 'Improve this')));

        // Get title for headline analyzer
        var title = '';
        try { title = wp.data.select('core/editor').getEditedPostAttribute('title') || ''; } catch(e) {}
        var hl = analyzeHeadline(title);

        return el(DocPanel, { name: 'seobetter-panel', title: 'SEOBetter: ' + score + '/100', initialOpen: true },
            // Score ring
            el('div', { style: { textAlign: 'center', padding: '8px 0' } },
                el('svg', { width: 100, height: 100, viewBox: '0 0 100 100' },
                    el('circle', { cx: 50, cy: 50, r: 36, fill: 'none', stroke: '#e5e7eb', strokeWidth: 8 }),
                    el('circle', { cx: 50, cy: 50, r: 36, fill: 'none', stroke: color, strokeWidth: 8,
                        strokeDasharray: circ, strokeDashoffset: offset, strokeLinecap: 'round',
                        transform: 'rotate(-90 50 50)', style: { transition: 'stroke-dashoffset 0.8s ease' } }),
                    el('text', { x: 50, y: 47, textAnchor: 'middle', fontSize: 22, fontWeight: 800, fill: color }, score),
                    el('text', { x: 50, y: 62, textAnchor: 'middle', fontSize: 9, fill: '#9ca3af' }, '/ 100')
                ),
                el('div', { style: { fontSize: 13, fontWeight: 600, color: color } }, rating)
            ),

            // Stats
            el('div', { style: { fontSize: 13, borderTop: '1px solid #f3f4f6', paddingTop: 6 } },
                stat('📝 Words', words.toLocaleString(), words >= 800),
                stat('⏱ Read', readTime + ' min', true),
                stat('📖 Grade', Math.round(checks.readability && checks.readability.flesch_grade || 0) + '', checks.readability && checks.readability.score >= 60),
                stat('🔗 Cites', (checks.citations && checks.citations.count || 0) + '/5', checks.citations && checks.citations.count >= 5),
                stat('💬 Quotes', (checks.expert_quotes && checks.expert_quotes.count || 0) + '/2', checks.expert_quotes && checks.expert_quotes.count >= 2),
                stat('📋 Tables', (checks.tables && checks.tables.count || 0) + '', checks.tables && checks.tables.count >= 1),
                stat('🕐 Fresh', checks.freshness && checks.freshness.score >= 100 ? 'Yes' : 'No', checks.freshness && checks.freshness.score >= 100)
            ),

            // Headline Analyzer toggle
            hl ? el('div', { style: { borderTop: '1px solid #f3f4f6', marginTop: 6, paddingTop: 6 } },
                el('div', {
                    style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', cursor: 'pointer', fontSize: 13, fontWeight: 600 },
                    onClick: function() { setShowHL(!showHL); }
                },
                    el('span', null, '📰 Headline Analyzer'),
                    el('span', { style: { fontSize: 11, color: '#9ca3af' } }, showHL ? '▲' : '▼')
                ),
                showHL ? el('div', { style: { marginTop: 8, fontSize: 12 } },
                    hlRow('Type', hl.type, true),
                    hlRow('Characters', hl.cc + ' chars', hl.ccOk),
                    hlRow('Words', hl.wc + ' words', hl.wcOk),
                    hlRow('Common', hl.commonPct + '% (goal 20-30%)', hl.commonPct >= 20 && hl.commonPct <= 35),
                    hlRow('Power Words', hl.powerPct + '%' + (hl.foundPW.length ? ' — ' + hl.foundPW.join(', ') : ''), hl.foundPW.length >= 1),
                    hlRow('Emotional', hl.emotionalPct + '%' + (hl.foundEW.length ? ' — ' + hl.foundEW.join(', ') : ''), hl.emotionalPct >= 5),
                    hlRow('Sentiment', hl.sentiment, hl.sentiment.indexOf('Positive') !== -1),
                    el('div', { style: { marginTop: 6, padding: '4px 0', borderTop: '1px solid #f3f4f6' } },
                        el('div', { style: { fontSize: 11, color: '#6b7280' } }, 'First 3: '),
                        el('span', { style: { background: '#f3f4f6', borderRadius: 3, padding: '1px 6px', fontSize: 11 } }, hl.begin),
                        el('div', { style: { fontSize: 11, color: '#6b7280', marginTop: 4 } }, 'Last 3: '),
                        el('span', { style: { background: '#f3f4f6', borderRadius: 3, padding: '1px 6px', fontSize: 11 } }, hl.end)
                    )
                ) : null
            ) : null,

            // Rich Results Preview (v1.5.133)
            data.rich_preview ? renderRichPreview(data.rich_preview, showRP, setShowRP) : null,

            // Re-analyze
            el('div', { style: { marginTop: 8, borderTop: '1px solid #f3f4f6', paddingTop: 8 } },
                el(Button, {
                    variant: 'secondary', isSmall: true,
                    onClick: function() { cachedData = null; r.runAnalysis(); },
                    style: { width: '100%', justifyContent: 'center', fontSize: 12 }
                }, 'Re-analyze')
            )
        );
    }

    // ============================================================
    // Rich Results Preview (v1.5.133)
    // ============================================================
    function renderRichPreview(rp, show, setShow) {
        var typeCount = (rp.rich_types || []).length;
        var validIcon = rp.validation && rp.validation.valid ? '✅' : '⚠️';
        var validText = rp.validation ? (rp.validation.errors + ' errors, ' + rp.validation.warnings + ' warnings') : '';

        return el('div', { style: { borderTop: '1px solid #f3f4f6', marginTop: 6, paddingTop: 6 } },
            el('div', {
                style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', cursor: 'pointer', fontSize: 13, fontWeight: 600 },
                onClick: function() { setShow(!show); }
            },
                el('span', null, '🔍 Rich Results Preview'),
                el('span', { style: { fontSize: 11, color: '#9ca3af' } }, show ? '▲' : '▼')
            ),
            show ? el('div', { style: { marginTop: 8 } },
                // SERP Preview card
                el('div', { style: { background: '#fff', border: '1px solid #e5e7eb', borderRadius: 8, padding: 12, marginBottom: 10 } },
                    el('div', { style: { fontSize: 10, color: '#70757a', fontFamily: 'Arial, sans-serif', marginBottom: 2 } },
                        (rp.breadcrumbs && rp.breadcrumbs.length > 0) ? rp.breadcrumbs.join(' > ') : rp.site_name
                    ),
                    el('div', { style: { fontSize: 16, color: '#1a0dab', fontFamily: 'Arial, sans-serif', fontWeight: 400, lineHeight: '1.3', marginBottom: 2, cursor: 'pointer' } },
                        rp.title || 'Untitled'
                    ),
                    el('div', { style: { fontSize: 12, color: '#4d5156', fontFamily: 'Arial, sans-serif', lineHeight: '1.4' } },
                        (rp.description || '').substring(0, 160) + (rp.description && rp.description.length > 160 ? '...' : '')
                    ),
                    // FAQ dropdowns preview
                    (rp.rich_types || []).some(function(t) { return t.type === 'FAQ'; }) ?
                        el('div', { style: { marginTop: 6, borderTop: '1px solid #e8eaed', paddingTop: 6 } },
                            el('div', { style: { fontSize: 12, color: '#1a73e8', fontFamily: 'Arial, sans-serif' } }, '▼ People also ask')
                        ) : null,
                    // Recipe preview
                    (rp.rich_types || []).some(function(t) { return t.type === 'Recipe'; }) ?
                        el('div', { style: { marginTop: 4, fontSize: 11, color: '#70757a', fontFamily: 'Arial, sans-serif' } },
                            '⭐⭐⭐⭐⭐ · ' + ((rp.rich_types || []).find(function(t) { return t.type === 'Recipe'; }) || {}).detail
                        ) : null
                ),

                // Schema types active
                el('div', { style: { fontSize: 12, fontWeight: 600, color: '#374151', marginBottom: 4 } },
                    typeCount + ' rich result type' + (typeCount !== 1 ? 's' : '') + ' active:'
                ),
                el('div', { style: { marginBottom: 8 } },
                    (rp.rich_types || []).map(function(rt, i) {
                        return el('div', { key: i, style: { display: 'flex', alignItems: 'center', gap: 6, padding: '2px 0', fontSize: 12 } },
                            el('span', { style: { color: '#22c55e' } }, '✓'),
                            el('span', { style: { color: '#374151' } }, rt.label),
                            rt.detail ? el('span', { style: { color: '#9ca3af', fontSize: 11 } }, '(' + rt.detail + ')') : null
                        );
                    })
                ),

                // Impact estimates
                (rp.impact_stats || []).length > 0 ? el('div', { style: { marginBottom: 8 } },
                    el('div', { style: { fontSize: 12, fontWeight: 600, color: '#374151', marginBottom: 4 } }, 'Schema Impact Estimate:'),
                    (rp.impact_stats || []).map(function(s, i) {
                        return el('div', { key: i, style: { fontSize: 11, color: '#6b7280', padding: '1px 0' } }, '📊 ' + s);
                    })
                ) : null,

                // Validation
                el('div', { style: { display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, padding: '4px 0', borderTop: '1px solid #f3f4f6', marginTop: 4, paddingTop: 6 } },
                    el('span', null, validIcon),
                    el('span', { style: { color: rp.validation && rp.validation.valid ? '#22c55e' : '#ef4444' } },
                        rp.validation && rp.validation.valid ? 'Schema valid' : 'Schema has issues'
                    ),
                    el('span', { style: { color: '#9ca3af', fontSize: 11 } }, '(' + validText + ')')
                ),
                el('a', {
                    href: 'https://search.google.com/test/rich-results?url=' + encodeURIComponent(rp.url || ''),
                    target: '_blank',
                    rel: 'noopener',
                    style: { display: 'block', fontSize: 11, color: '#764ba2', marginTop: 4 }
                }, '🔗 Test in Google Rich Results Test →')
            ) : null
        );
    }

    function stat(label, value, ok) {
        return el('div', { style: { display: 'flex', justifyContent: 'space-between', padding: '4px 0', borderBottom: '1px solid #f9fafb' } },
            el('span', null, label),
            el('span', { style: { fontWeight: 600, color: ok ? '#22c55e' : '#ef4444' } }, (ok ? '✓ ' : '✗ ') + value)
        );
    }

    function hlRow(label, value, ok) {
        return el('div', { style: { display: 'flex', justifyContent: 'space-between', padding: '3px 0', borderBottom: '1px solid #f9fafb' } },
            el('span', { style: { color: '#374151' } }, label),
            el('span', { style: { fontWeight: 600, color: ok ? '#22c55e' : '#f59e0b' } }, (ok ? '✅ ' : '⚠️ ') + value)
        );
    }

    // Register single plugin only
    registerPlugin('seobetter', {
        render: SEOBetterPanel,
        icon: 'chart-line'
    });

    // ============================================================
    // v1.5.216.54 — Freshness Diagnostic panel (separate plugin so the
    // fetch is independent of the GEO analysis cache). Tier-gated inside
    // /seobetter/v1/freshness/diagnostic/{post_id} — Free/Pro-without-
    // freshness_diagnostic see an upsell card.
    // ============================================================
    function FreshnessPanel() {
        var s1 = useState(null); var data = s1[0]; var setData = s1[1];
        var s2 = useState(true); var loading = s2[0]; var setLoading = s2[1];

        useEffect(function() {
            try {
                var postId = wp.data.select('core/editor').getCurrentPostId();
                if (!postId) { setLoading(false); return; }
                wp.apiFetch({ path: '/seobetter/v1/freshness/diagnostic/' + postId })
                    .then(function(r) { setData(r); setLoading(false); })
                    .catch(function() { setLoading(false); });
            } catch(e) { setLoading(false); }
        }, []);

        function copy(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() { showToast('Copied: ' + text); });
            } else {
                var ta = document.createElement('textarea');
                ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); showToast('Copied: ' + text); } catch(e) {}
                ta.remove();
            }
        }

        function showToast(msg) {
            var t = document.createElement('div');
            t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;padding:10px 18px;border-radius:6px;font-size:13px;z-index:99999;opacity:0;transition:opacity .2s';
            t.textContent = msg; document.body.appendChild(t);
            requestAnimationFrame(function() { t.style.opacity = '1'; });
            setTimeout(function() { t.style.opacity = '0'; setTimeout(function(){ t.remove(); }, 250); }, 1800);
        }

        if (loading) return el(DocPanel, { name: 'seobetter-freshness', title: 'Freshness Diagnostic', initialOpen: false },
            el('p', { style: { textAlign: 'center', fontSize: 13, color: '#666' } }, 'Loading…'));

        if (!data) return el(DocPanel, { name: 'seobetter-freshness', title: 'Freshness Diagnostic', initialOpen: false },
            el('p', { style: { fontSize: 13, color: '#666', margin: 0 } }, 'Save the post to see freshness diagnostic.'));

        if (data.locked) {
            var tier = data.tier_required === 'pro_plus' ? 'Pro+' : 'Pro';
            return el(DocPanel, { name: 'seobetter-freshness', title: 'Freshness Diagnostic 🔒', initialOpen: false },
                el('div', { style: { padding: '16px', background: 'linear-gradient(135deg,#faf5ff 0%,#ede9fe 100%)', border: '1px solid #ddd6fe', borderRadius: '8px', textAlign: 'center' } },
                    el('div', { style: { fontSize: 11, fontWeight: 600, letterSpacing: '0.05em', background: '#8b5cf6', color: '#fff', padding: '3px 8px', borderRadius: '4px', display: 'inline-block' } }, tier.toUpperCase() + ' FEATURE'),
                    el('h3', { style: { margin: '12px 0 6px', fontSize: 14, color: '#5b21b6' } }, 'Freshness diagnostic is a Pro feature'),
                    el('p', { style: { margin: 0, fontSize: 12, color: '#4c1d95', lineHeight: 1.5 } }, 'See exactly why this post needs refreshing — outdated year mentions, missing freshness signal, GSC click decay, top queries to target.'),
                    el('a', { href: 'https://seobetter.com/pricing', target: '_blank', className: 'button button-primary', style: { marginTop: 10, display: 'inline-block' } }, 'See Pro pricing →')
                )
            );
        }

        var pri = data.priority || 0;
        var pcolor = pri >= 60 ? '#dc2626' : (pri >= 30 ? '#f59e0b' : '#10b981');
        var children = [];

        children.push(el('div', { key: 'head', style: { display: 'flex', gap: 8, alignItems: 'center', padding: '6px 0 10px', borderBottom: '1px solid #f3f4f6' } },
            el('div', { style: { fontSize: 11, textTransform: 'uppercase', color: '#64748b' } }, 'Priority'),
            el('div', { style: { padding: '3px 10px', borderRadius: 12, background: pcolor + '1a', color: pcolor, fontWeight: 700, fontSize: 13 } }, pri),
            el('div', { style: { fontSize: 11, color: '#64748b', marginLeft: 'auto' } }, (data.word_count || 0) + ' words · ' + (data.age_days || 0) + 'd')
        ));

        var signals = data.signals || [];
        if (signals.length) {
            signals.forEach(function(s, idx) {
                var sev = s.severity || 'info';
                var bg = sev === 'critical' ? '#fef2f2' : (sev === 'warning' ? '#fef3c7' : '#eff6ff');
                var bd = sev === 'critical' ? '#fecaca' : (sev === 'warning' ? '#fcd34d' : '#bfdbfe');
                var sChildren = [];
                sChildren.push(el('div', { key: 'h', style: { display: 'flex', justifyContent: 'space-between', gap: 8, fontWeight: 600, fontSize: 12, color: '#0f172a', marginBottom: 4 } },
                    el('div', null, s.label),
                    el('div', { style: { fontSize: 10, color: '#64748b', background: '#fff', padding: '1px 6px', borderRadius: 10, border: '1px solid #e2e8f0', whiteSpace: 'nowrap' } }, '+' + (s.contributes || 0))
                ));
                if (s.detail) sChildren.push(el('div', { key: 'd', style: { fontSize: 11, color: '#475569', lineHeight: 1.5, marginBottom: 6 } }, s.detail));
                if (s.action && (s.action.type === 'copy' || s.action.type === 'find_in_post')) {
                    var payload = s.action.type === 'find_in_post' ? (s.action.years || []).join(', ') : s.action.payload;
                    sChildren.push(el('button', { key: 'b', type: 'button', className: 'button button-small', onClick: function() { copy(payload); } }, s.action.label));
                }
                children.push(el('div', { key: 's' + idx, style: { padding: '10px 12px', borderRadius: 8, marginTop: 6, border: '1px solid ' + bd, background: bg } }, sChildren));
            });
        } else {
            children.push(el('p', { key: 'ok', style: { color: '#64748b', textAlign: 'center', fontSize: 12, padding: '14px 0', margin: 0 } }, 'No urgent signals — this post is in good shape.'));
        }

        if (data.has_gsc && data.top_queries && data.top_queries.length) {
            children.push(el('h4', { key: 'qh', style: { margin: '14px 0 6px', fontSize: 12, color: '#0f172a' } }, 'Top queries — last 28 days'));
            var qRows = data.top_queries.map(function(q, i) {
                var qChildren = [
                    el('div', { key: 'q', style: { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }, title: q.query },
                        q.query,
                        q.striking_distance ? el('span', { style: { background: '#dcfce7', color: '#166534', fontSize: 9, padding: '1px 5px', borderRadius: 6, fontWeight: 600, marginLeft: 4 } }, 'STRIKING') : null
                    ),
                    el('div', { key: 'p', style: { color: '#475569' } }, 'pos ' + q.position.toFixed(1)),
                    el('div', { key: 'c', style: { color: '#475569' } }, q.clicks)
                ];
                return el('div', { key: i, style: { padding: '6px 8px', borderBottom: '1px solid #f1f5f9', display: 'grid', gridTemplateColumns: '1fr auto auto', gap: 8, fontSize: 11 } }, qChildren);
            });
            children.push(el('div', { key: 'qb', style: { border: '1px solid #e2e8f0', borderRadius: 6, overflow: 'hidden' } }, qRows));
        } else if (!data.gsc_connected) {
            children.push(el('div', { key: 'gsc-off', style: { marginTop: 12, padding: 10, background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 6, fontSize: 11, color: '#475569' } }, 'Connect Google Search Console (Pro+) to see top queries.'));
        } else if (!data.can_use_gsc) {
            children.push(el('div', { key: 'pro-up', style: { marginTop: 12, padding: 10, background: 'linear-gradient(135deg,#faf5ff 0%,#ede9fe 100%)', border: '1px solid #ddd6fe', borderRadius: 6, fontSize: 11, color: '#5b21b6' } },
                el('span', { style: { background: '#8b5cf6', color: '#fff', fontSize: 9, padding: '1px 5px', borderRadius: 3, letterSpacing: '0.05em', fontWeight: 600 } }, 'PRO+'),
                ' Upgrade to see top queries + click decay.'
            ));
        }

        return el(DocPanel, { name: 'seobetter-freshness', title: 'Freshness: ' + pri, initialOpen: false }, children);
    }

    registerPlugin('seobetter-freshness', {
        render: FreshnessPanel,
        icon: 'update'
    });

})();
