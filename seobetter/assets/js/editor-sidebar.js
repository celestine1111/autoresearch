/**
 * SEOBetter Gutenberg Editor Integration.
 *
 * Following official WordPress SlotFill docs:
 * - PluginSidebar from wp.editor
 * - PluginDocumentSettingPanel from wp.editor
 * - PluginPrePublishPanel from wp.editor
 * - registerPlugin from wp.plugins
 */
(function(wp) {
    if (!wp || !wp.plugins || !wp.element || !wp.components || !wp.data) return;

    var registerPlugin = wp.plugins.registerPlugin;
    if (!registerPlugin) return;

    // Resolve SlotFills — these moved between packages across WP versions
    // Try: wp.editor (6.6+) → wp.editPost (older) → null
    var _e = wp.editor || {};
    var _ep = wp.editPost || {};

    var PluginSidebar = _e.PluginSidebar || _ep.PluginSidebar || null;
    var PluginDocumentSettingPanel = _e.PluginDocumentSettingPanel || _ep.PluginDocumentSettingPanel || null;
    var PluginPrePublishPanel = _e.PluginPrePublishPanel || _ep.PluginPrePublishPanel || null;

    if (!PluginSidebar && !PluginDocumentSettingPanel) return;

    var PanelBody = wp.components.PanelBody;
    var PanelRow = wp.components.PanelRow;
    var Button = wp.components.Button;
    var Spinner = wp.components.Spinner;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var select = wp.data.select;
    var apiFetch = wp.apiFetch;

    // ============================================================
    // Shared analysis state
    // ============================================================
    var cachedAnalysis = null;
    var cachedPostId = null;

    function useAnalysis() {
        var state = useState(cachedAnalysis);
        var analysis = state[0];
        var setAnalysis = state[1];
        var loadState = useState(!cachedAnalysis);
        var loading = loadState[0];
        var setLoading = loadState[1];

        var runAnalysis = function() {
            var postId = select('core/editor').getCurrentPostId();
            if (!postId) { setLoading(false); return; }
            cachedPostId = postId;
            setLoading(true);
            apiFetch({ path: '/seobetter/v1/analyze/' + postId })
                .then(function(data) { cachedAnalysis = data; setAnalysis(data); setLoading(false); })
                .catch(function() { setLoading(false); });
        };

        useEffect(function() {
            var postId = select('core/editor').getCurrentPostId();
            if (postId && postId !== cachedPostId) { runAnalysis(); }
            else if (cachedAnalysis) { setAnalysis(cachedAnalysis); setLoading(false); }
            else { runAnalysis(); }
        }, []);

        return { analysis: analysis, loading: loading, runAnalysis: runAnalysis };
    }

    function scoreColor(score) {
        if (score >= 80) return '#22c55e';
        if (score >= 60) return '#f59e0b';
        return '#ef4444';
    }

    // ============================================================
    // 1. TOOLBAR BADGE
    // ============================================================
    function ToolbarBadge() {
        var result = useAnalysis();
        var analysis = result.analysis;
        if (!analysis) return null;

        var score = analysis.geo_score || 0;
        var color = scoreColor(score);
        var grade = analysis.grade || '?';

        useEffect(function() {
            var t1, t2;
            function inject() {
                try {
                    var toolbar = document.querySelector('.edit-post-header__toolbar') ||
                                  document.querySelector('.editor-header__toolbar') ||
                                  document.querySelector('.edit-post-header');
                    if (!toolbar || document.getElementById('seobetter-toolbar-badge')) return;

                    var badge = document.createElement('div');
                    badge.id = 'seobetter-toolbar-badge';
                    badge.title = 'SEOBetter GEO Score: ' + score + '/100 (' + grade + ')';
                    badge.style.cssText = 'display:flex;align-items:center;gap:6px;padding:0 12px;cursor:pointer;height:36px;border-radius:4px;margin-left:8px;background:' + color + '14;border:1px solid ' + color + '33;';

                    var circle = document.createElement('div');
                    circle.style.cssText = 'width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;background:' + color + ';';
                    circle.textContent = score;

                    var lbl = document.createElement('span');
                    lbl.style.cssText = 'font-size:12px;font-weight:600;color:' + color + ';';
                    lbl.textContent = 'GEO ' + grade;

                    badge.appendChild(circle);
                    badge.appendChild(lbl);
                    toolbar.appendChild(badge);
                } catch(e) {}
            }
            inject();
            t1 = setTimeout(inject, 500);
            t2 = setTimeout(inject, 2000);
            return function() { clearTimeout(t1); clearTimeout(t2); };
        }, [analysis]);

        return null;
    }

    // ============================================================
    // 2. POST SIDEBAR PANEL
    // ============================================================
    function SEOBetterDocPanel() {
        if (!PluginDocumentSettingPanel) return null;

        var result = useAnalysis();
        var analysis = result.analysis;
        var loading = result.loading;
        var runAnalysis = result.runAnalysis;

        if (loading) {
            return el(PluginDocumentSettingPanel, {
                name: 'seobetter-doc-panel',
                title: 'SEOBetter GEO Score'
            }, el('div', { style: { textAlign: 'center', padding: 8 } }, el(Spinner)));
        }

        if (!analysis) {
            return el(PluginDocumentSettingPanel, {
                name: 'seobetter-doc-panel',
                title: 'SEOBetter'
            }, el('p', { style: { fontSize: 13, color: '#666', margin: 0 } },
                'Save the post to see GEO score.'));
        }

        var score = analysis.geo_score || 0;
        var color = scoreColor(score);
        var checks = analysis.checks || {};
        var words = analysis.word_count || 0;
        var readTime = Math.max(1, Math.ceil(words / 200));
        var isPro = window.seobetterData && window.seobetterData.isPro;

        var items = [
            { label: 'GEO Score', value: score + '/100 (' + (analysis.grade || '?') + ')', ok: score >= 70, icon: '📊' },
            { label: 'Words', value: words.toLocaleString(), ok: words >= 800, icon: '📝' },
            { label: 'Read Time', value: readTime + ' min', ok: true, icon: '⏱' },
            { label: 'Readability', value: 'Grade ' + Math.round(checks.readability && checks.readability.flesch_grade || 0), ok: checks.readability && checks.readability.score >= 60, icon: '📖' },
            { label: 'Citations', value: (checks.citations && checks.citations.count || 0) + '/5', ok: checks.citations && checks.citations.count >= 5, icon: '🔗' },
            { label: 'Quotes', value: (checks.expert_quotes && checks.expert_quotes.count || 0) + '/2', ok: checks.expert_quotes && checks.expert_quotes.count >= 2, icon: '💬' },
            { label: 'Tables', value: (checks.tables && checks.tables.count || 0) + ' found', ok: checks.tables && checks.tables.count >= 1, icon: '📋' },
            { label: 'Freshness', value: checks.freshness && checks.freshness.score >= 100 ? 'Yes' : 'Missing', ok: checks.freshness && checks.freshness.score >= 100, icon: '🕐' },
        ];

        return el(PluginDocumentSettingPanel, {
            name: 'seobetter-doc-panel',
            title: 'SEOBetter: ' + score + '/100 (' + (analysis.grade || '?') + ')'
        },
            el('div', {
                style: { marginBottom: 12, padding: '8px 12px', background: color + '10', borderLeft: '4px solid ' + color, borderRadius: '0 4px 4px 0' }
            },
                el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } },
                    el('span', { style: { fontSize: 18, fontWeight: 700, color: color } }, score + '/100'),
                    el('span', { style: { fontSize: 13, fontWeight: 600, color: color, padding: '2px 8px', background: color + '20', borderRadius: 4 } }, analysis.grade || '?')
                )
            ),
            items.map(function(item, i) {
                return el('div', {
                    key: i,
                    style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '5px 0', borderBottom: i < items.length - 1 ? '1px solid #f0f0f0' : 'none', fontSize: 13 }
                },
                    el('span', null, item.icon + ' ' + item.label),
                    el('span', { style: { fontWeight: 600, color: item.ok ? '#22c55e' : '#ef4444' } },
                        (item.ok ? '✓ ' : '✗ ') + item.value
                    )
                );
            }),
            el('div', { style: { marginTop: 10 } },
                el(Button, {
                    variant: 'secondary',
                    onClick: function() { cachedAnalysis = null; cachedPostId = null; runAnalysis(); },
                    isSmall: true,
                    style: { width: '100%', justifyContent: 'center', fontSize: 12 }
                }, 'Re-analyze')
            ),
            !isPro && score < 80 ? el('div', {
                style: { marginTop: 8, padding: '6px 10px', background: '#eef2ff', borderRadius: 4, textAlign: 'center', fontSize: 11 }
            },
                el('a', {
                    href: (window.seobetterData && window.seobetterData.settingsUrl) || '#',
                    style: { color: '#4338ca', fontWeight: 600, textDecoration: 'none' }
                }, 'Upgrade to Pro →')
            ) : null
        );
    }

    // ============================================================
    // 3. FULL SIDEBAR
    // ============================================================
    function SEOBetterSidebar() {
        var result = useAnalysis();
        var analysis = result.analysis;
        var loading = result.loading;
        var runAnalysis = result.runAnalysis;

        var circ = 2 * Math.PI * 45;

        return el(PluginSidebar, { name: 'seobetter-sidebar', title: 'SEOBetter GEO', icon: 'chart-line' },
            el(PanelBody, { title: 'GEO Score', initialOpen: true },
                loading
                    ? el('div', { style: { textAlign: 'center', padding: 20 } }, el(Spinner))
                    : analysis
                        ? el(Fragment, null,
                            el('div', { style: { textAlign: 'center', padding: '16px 0' } },
                                el('svg', { width: 120, height: 120, viewBox: '0 0 120 120' },
                                    el('circle', { cx: 60, cy: 60, r: 45, fill: 'none', stroke: '#e9ecef', strokeWidth: 8 }),
                                    el('circle', { cx: 60, cy: 60, r: 45, fill: 'none', stroke: scoreColor(analysis.geo_score), strokeWidth: 8,
                                        strokeDasharray: circ, strokeDashoffset: circ - (analysis.geo_score / 100) * circ,
                                        strokeLinecap: 'round', transform: 'rotate(-90 60 60)',
                                        style: { transition: 'stroke-dashoffset 0.5s ease' }
                                    }),
                                    el('text', { x: 60, y: 55, textAnchor: 'middle', fontSize: 28, fontWeight: 700, fill: scoreColor(analysis.geo_score) }, analysis.geo_score),
                                    el('text', { x: 60, y: 75, textAnchor: 'middle', fontSize: 12, fill: '#666' }, analysis.grade)
                                )
                            ),
                            el(PanelRow, null,
                                el('span', null, 'Word Count'),
                                el('span', null, (analysis.word_count || 0).toLocaleString())
                            )
                          )
                        : el('p', null, 'Save the post to see your GEO score.'),
                el(PanelRow, null,
                    el(Button, {
                        variant: 'secondary',
                        onClick: function() { cachedAnalysis = null; cachedPostId = null; runAnalysis(); },
                        disabled: loading,
                        style: { width: '100%', justifyContent: 'center' }
                    }, 'Re-analyze')
                )
            ),
            analysis && analysis.checks
                ? el(PanelBody, { title: 'GEO Checks', initialOpen: false },
                    Object.keys(analysis.checks).map(function(key) {
                        var check = analysis.checks[key];
                        var color = scoreColor(check.score);
                        return el(PanelRow, { key: key },
                            el('div', { style: { width: '100%' } },
                                el('div', { style: { display: 'flex', justifyContent: 'space-between', marginBottom: 4 } },
                                    el('span', { style: { fontSize: 12 } }, key.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); })),
                                    el('span', { style: { fontSize: 12, fontWeight: 600, color: color } }, check.score + '/100')
                                ),
                                el('div', { style: { height: 4, background: '#e9ecef', borderRadius: 2 } },
                                    el('div', { style: { height: '100%', width: check.score + '%', background: color, borderRadius: 2 } })
                                )
                            )
                        );
                    })
                  )
                : null,
            analysis && analysis.suggestions && analysis.suggestions.length > 0
                ? el(PanelBody, { title: 'Suggestions (' + analysis.suggestions.length + ')', initialOpen: false },
                    analysis.suggestions.map(function(s, i) {
                        return el('div', { key: i, style: {
                            padding: '8px 10px', marginBottom: 6, fontSize: 12,
                            borderLeft: '3px solid ' + (s.priority === 'high' ? '#ef4444' : '#f59e0b'),
                            background: s.priority === 'high' ? '#fef2f2' : '#fffbeb',
                            borderRadius: '0 3px 3px 0'
                        } },
                            el('strong', null, '[' + s.type + '] '), s.message
                        );
                    })
                  )
                : null
        );
    }

    // ============================================================
    // 4. PRE-PUBLISH PANEL
    // ============================================================
    function SEOBetterPrePublish() {
        if (!PluginPrePublishPanel) return null;

        var result = useAnalysis();
        var analysis = result.analysis;
        var loading = result.loading;
        var isPro = window.seobetterData && window.seobetterData.isPro;

        if (loading) {
            return el(PluginPrePublishPanel, { title: 'SEOBetter: Analyzing...', initialOpen: true },
                el('div', { style: { textAlign: 'center', padding: 12 } }, el(Spinner)));
        }
        if (!analysis) {
            return el(PluginPrePublishPanel, { title: 'SEOBetter', initialOpen: true },
                el('p', { style: { fontSize: 13, color: '#666' } }, 'Save post to see GEO score.'));
        }

        var score = analysis.geo_score || 0;
        var checks = analysis.checks || {};
        var highPri = (analysis.suggestions || []).filter(function(s) { return s.priority === 'high'; });

        var items = [
            { label: 'GEO Score', value: score + '/100 (' + (analysis.grade || '?') + ')', ok: score >= 70 },
            { label: 'Citations', value: (checks.citations && checks.citations.count || 0) + ' found', ok: checks.citations && checks.citations.count >= 5 },
            { label: 'Quotes', value: (checks.expert_quotes && checks.expert_quotes.count || 0) + ' found', ok: checks.expert_quotes && checks.expert_quotes.count >= 2 },
            { label: 'Readability', value: 'Grade ' + Math.round(checks.readability && checks.readability.flesch_grade || 0), ok: checks.readability && checks.readability.score >= 60 },
        ];

        return el(PluginPrePublishPanel, {
            title: 'SEOBetter: ' + (score >= 70 ? 'Ready' : 'Needs work'),
            initialOpen: true
        },
            items.map(function(item, i) {
                return el('div', { key: i, style: { display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: i < items.length - 1 ? '1px solid #e5e7eb' : 'none' } },
                    el('span', { style: { fontSize: 13 } }, (item.ok ? '✅ ' : '❌ ') + item.label),
                    el('span', { style: { fontSize: 13, fontWeight: 600, color: item.ok ? '#22c55e' : '#ef4444' } }, item.value)
                );
            }),
            highPri.length > 0
                ? el('div', { style: { marginTop: 12 } },
                    highPri.slice(0, 3).map(function(s, i) {
                        return el('div', { key: i, style: { fontSize: 11, padding: '4px 8px', marginBottom: 3, background: '#fef2f2', borderLeft: '2px solid #ef4444', borderRadius: '0 4px 4px 0', color: '#991b1b' } }, s.message);
                    })
                ) : null,
            !isPro && score < 80
                ? el('div', { style: { marginTop: 12, padding: '10px 12px', background: 'linear-gradient(135deg,#eef2ff,#e0e7ff)', borderRadius: 6, textAlign: 'center' } },
                    el(Button, { variant: 'primary', href: (window.seobetterData && window.seobetterData.settingsUrl) || '#', style: { background: 'linear-gradient(135deg,#764ba2,#667eea)', border: 'none', fontSize: 12, height: 30 } }, 'Upgrade to Pro →')
                ) : null
        );
    }

    // ============================================================
    // Register
    // ============================================================
    registerPlugin('seobetter', {
        render: function() {
            return el(Fragment, null,
                el(ToolbarBadge),
                el(SEOBetterDocPanel),
                el(SEOBetterSidebar),
                el(SEOBetterPrePublish)
            );
        },
        icon: 'chart-line'
    });

})(window.wp);
