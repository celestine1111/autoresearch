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

})();
