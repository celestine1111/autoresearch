/**
 * SEOBetter Gutenberg Editor Integration.
 *
 * 1. Score badge in top toolbar (like AIOSEO's score icon)
 * 2. Post sidebar panel with GEO stats
 * 3. Full sidebar with detailed checks
 * 4. Pre-publish panel with publish readiness
 */
(function(wp) {
    const { registerPlugin } = wp.plugins;
    const { PluginSidebar, PluginSidebarMoreMenuItem, PluginPrePublishPanel } = wp.editPost;
    const { PanelBody, PanelRow, Button, Spinner, Fill } = wp.components;
    const { useState, useEffect, createElement: el, Fragment } = wp.element;
    const { select, subscribe } = wp.data;
    const apiFetch = wp.apiFetch;
    const PluginDocumentSettingPanel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);

    // ============================================================
    // Shared analysis hook
    // ============================================================
    let cachedAnalysis = null;
    let lastPostId = null;

    function useAnalysis() {
        const [analysis, setAnalysis] = useState(cachedAnalysis);
        const [loading, setLoading] = useState(!cachedAnalysis);

        const runAnalysis = () => {
            const postId = select('core/editor').getCurrentPostId();
            if (!postId) { setLoading(false); return; }
            lastPostId = postId;
            setLoading(true);
            apiFetch({ path: '/seobetter/v1/analyze/' + postId })
                .then(data => { cachedAnalysis = data; setAnalysis(data); setLoading(false); })
                .catch(() => setLoading(false));
        };

        useEffect(() => {
            const postId = select('core/editor').getCurrentPostId();
            if (postId && postId !== lastPostId) runAnalysis();
            else if (cachedAnalysis) { setAnalysis(cachedAnalysis); setLoading(false); }
            else runAnalysis();
        }, []);

        return { analysis, loading, runAnalysis };
    }

    // ============================================================
    // Score color helper
    // ============================================================
    function scoreColor(score) {
        if (score >= 80) return '#22c55e';
        if (score >= 60) return '#f59e0b';
        return '#ef4444';
    }

    // ============================================================
    // 1. TOOLBAR SCORE BADGE (top of editor, like AIOSEO)
    // ============================================================
    const ToolbarBadge = () => {
        const { analysis } = useAnalysis();
        if (!analysis) return null;

        const score = analysis.geo_score || 0;
        const color = scoreColor(score);
        const grade = analysis.grade || '?';

        // Inject into the editor header via a portal-style approach
        useEffect(() => {
            // Find the editor header toolbar
            const injectBadge = () => {
                const toolbar = document.querySelector('.edit-post-header__toolbar') ||
                                document.querySelector('.editor-header__toolbar') ||
                                document.querySelector('.edit-post-header');
                if (!toolbar) return;

                // Don't add twice
                if (document.getElementById('seobetter-toolbar-badge')) return;

                const badge = document.createElement('div');
                badge.id = 'seobetter-toolbar-badge';
                badge.title = 'SEOBetter GEO Score: ' + score + '/100 (' + grade + ')';
                badge.style.cssText = 'display:flex;align-items:center;gap:6px;padding:0 12px;cursor:pointer;height:36px;border-radius:4px;margin-left:8px;background:' + color + '14;border:1px solid ' + color + '33;';
                badge.onclick = () => {
                    // Open SEOBetter sidebar
                    wp.data.dispatch('core/edit-post').openGeneralSidebar('seobetter/seobetter-sidebar');
                };

                // Score circle
                const circle = document.createElement('div');
                circle.style.cssText = 'width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;background:' + color + ';';
                circle.textContent = score;

                // Label
                const label = document.createElement('span');
                label.style.cssText = 'font-size:12px;font-weight:600;color:' + color + ';';
                label.textContent = 'GEO ' + grade;

                badge.appendChild(circle);
                badge.appendChild(label);
                toolbar.appendChild(badge);
            };

            // Try immediately and on short delay (editor may not be fully rendered)
            injectBadge();
            const t1 = setTimeout(injectBadge, 500);
            const t2 = setTimeout(injectBadge, 1500);

            return () => { clearTimeout(t1); clearTimeout(t2); };
        }, [analysis]);

        return null; // Renders via DOM injection, not React
    };

    // ============================================================
    // 2. POST SIDEBAR PANEL (right column, Post tab)
    // ============================================================
    const SEOBetterDocPanel = () => {
        if (!PluginDocumentSettingPanel) return null;
        const { analysis, loading, runAnalysis } = useAnalysis();

        if (loading) {
            return el(PluginDocumentSettingPanel, {
                name: 'seobetter-doc-panel',
                title: 'SEOBetter GEO Score',
                icon: 'chart-line'
            }, el('div', { style: { textAlign: 'center', padding: 8 } }, el(Spinner)));
        }

        if (!analysis) {
            return el(PluginDocumentSettingPanel, {
                name: 'seobetter-doc-panel',
                title: 'SEOBetter',
                icon: 'chart-line'
            }, el('p', { style: { fontSize: 13, color: '#666', margin: 0 } },
                'Save the post to see GEO score.'));
        }

        const score = analysis.geo_score || 0;
        const color = scoreColor(score);
        const checks = analysis.checks || {};
        const isPro = window.seobetterData?.isPro || false;

        // Calculate read time
        const words = analysis.word_count || 0;
        const readTime = Math.max(1, Math.ceil(words / 200));

        // Get schema types
        const schemaTypes = [];
        if (checks.tables?.count > 0) schemaTypes.push('Table');
        if (analysis.grade) schemaTypes.push('Article');
        const faqCheck = Object.keys(checks).find(k => k.includes('faq'));
        if (faqCheck || true) schemaTypes.push('FAQ');

        const items = [
            { label: 'GEO Score', value: score + '/100 (' + (analysis.grade || '?') + ')', ok: score >= 70, icon: '📊' },
            { label: 'Words', value: words.toLocaleString(), ok: words >= 800, icon: '📝' },
            { label: 'Read Time', value: readTime + ' min', ok: true, icon: '⏱️' },
            { label: 'Readability', value: 'Grade ' + Math.round(checks.readability?.flesch_grade || 0), ok: (checks.readability?.score || 0) >= 60, icon: '📖' },
            { label: 'Citations', value: (checks.citations?.count || 0) + '/5', ok: (checks.citations?.count || 0) >= 5, icon: '🔗' },
            { label: 'Expert Quotes', value: (checks.expert_quotes?.count || 0) + '/2', ok: (checks.expert_quotes?.count || 0) >= 2, icon: '💬' },
            { label: 'Tables', value: (checks.tables?.count || 0) + ' found', ok: (checks.tables?.count || 0) >= 1, icon: '📋' },
            { label: 'Freshness', value: (checks.freshness?.score || 0) >= 100 ? 'Yes' : 'Missing', ok: (checks.freshness?.score || 0) >= 100, icon: '🕐' },
            { label: 'Schema', value: schemaTypes.join(', ') || 'Article', ok: true, icon: '🏗️' },
        ];

        return el(PluginDocumentSettingPanel, {
            name: 'seobetter-doc-panel',
            title: 'SEOBetter: ' + score + '/100 (' + (analysis.grade || '?') + ')',
            icon: 'chart-line'
        },
            // Score header with colored bar
            el('div', {
                style: { marginBottom: 12, padding: '8px 12px', background: color + '10', borderLeft: '4px solid ' + color, borderRadius: '0 4px 4px 0' }
            },
                el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } },
                    el('span', { style: { fontSize: 18, fontWeight: 700, color } }, score + '/100'),
                    el('span', { style: { fontSize: 13, fontWeight: 600, color, padding: '2px 8px', background: color + '20', borderRadius: 4 } }, analysis.grade || '?')
                )
            ),

            // Stats list
            items.map((item, i) =>
                el('div', {
                    key: i,
                    style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '5px 0', borderBottom: i < items.length - 1 ? '1px solid #f0f0f0' : 'none', fontSize: 13 }
                },
                    el('span', {}, item.icon + ' ' + item.label),
                    el('span', { style: { fontWeight: 600, color: item.ok ? '#22c55e' : '#ef4444' } },
                        (item.ok ? '✓ ' : '✗ ') + item.value
                    )
                )
            ),

            // Re-analyze button
            el('div', { style: { marginTop: 10 } },
                el(Button, {
                    variant: 'secondary',
                    onClick: () => { cachedAnalysis = null; lastPostId = null; runAnalysis(); },
                    isSmall: true,
                    style: { width: '100%', justifyContent: 'center', fontSize: 12 }
                }, 'Re-analyze')
            ),

            // Pro upsell
            !isPro && score < 80 ? el('div', {
                style: { marginTop: 8, padding: '6px 10px', background: '#eef2ff', borderRadius: 4, textAlign: 'center', fontSize: 11 }
            },
                el('a', {
                    href: window.seobetterData?.settingsUrl || '#',
                    style: { color: '#4338ca', fontWeight: 600, textDecoration: 'none' }
                }, '⚡ Upgrade to Pro — fix all issues →')
            ) : null
        );
    };

    // ============================================================
    // 3. FULL SIDEBAR (toolbar icon click)
    // ============================================================
    const GEOScoreRing = ({ score, grade }) => {
        const color = scoreColor(score);
        const circ = 2 * Math.PI * 45;
        const offset = circ - (score / 100) * circ;

        return el('div', { style: { textAlign: 'center', padding: '16px 0' } },
            el('svg', { width: 120, height: 120, viewBox: '0 0 120 120' },
                el('circle', { cx: 60, cy: 60, r: 45, fill: 'none', stroke: '#e9ecef', strokeWidth: 8 }),
                el('circle', { cx: 60, cy: 60, r: 45, fill: 'none', stroke: color, strokeWidth: 8,
                    strokeDasharray: circ, strokeDashoffset: offset, strokeLinecap: 'round',
                    transform: 'rotate(-90 60 60)', style: { transition: 'stroke-dashoffset 0.5s ease' }
                }),
                el('text', { x: 60, y: 55, textAnchor: 'middle', fontSize: 28, fontWeight: 700, fill: color }, score),
                el('text', { x: 60, y: 75, textAnchor: 'middle', fontSize: 12, fill: '#666' }, grade)
            )
        );
    };

    const CheckBar = ({ label, score }) => {
        const color = scoreColor(score);
        return el(PanelRow, {},
            el('div', { style: { width: '100%' } },
                el('div', { style: { display: 'flex', justifyContent: 'space-between', marginBottom: 4 } },
                    el('span', { style: { fontSize: 12 } }, label),
                    el('span', { style: { fontSize: 12, fontWeight: 600, color } }, score + '/100')
                ),
                el('div', { style: { height: 4, background: '#e9ecef', borderRadius: 2 } },
                    el('div', { style: { height: '100%', width: score + '%', background: color, borderRadius: 2, transition: 'width 0.3s ease' } })
                )
            )
        );
    };

    const SEOBetterSidebar = () => {
        const { analysis, loading, runAnalysis } = useAnalysis();

        return el(PluginSidebar, { name: 'seobetter-sidebar', title: 'SEOBetter GEO', icon: 'chart-line' },
            el(PanelBody, { title: 'GEO Score', initialOpen: true },
                loading
                    ? el('div', { style: { textAlign: 'center', padding: 20 } }, el(Spinner))
                    : analysis
                        ? el(Fragment, {},
                            el(GEOScoreRing, { score: analysis.geo_score, grade: analysis.grade }),
                            el(PanelRow, {},
                                el('span', {}, 'Word Count'),
                                el('span', {}, (analysis.word_count || 0).toLocaleString())
                            )
                          )
                        : el('p', {}, 'Save the post to see your GEO score.'),
                el(PanelRow, {},
                    el(Button, {
                        variant: 'secondary', onClick: () => { cachedAnalysis = null; lastPostId = null; runAnalysis(); },
                        disabled: loading, style: { width: '100%', justifyContent: 'center' }
                    }, 'Re-analyze')
                )
            ),
            analysis && analysis.checks
                ? el(PanelBody, { title: 'GEO Checks', initialOpen: false },
                    Object.entries(analysis.checks).map(([key, check]) =>
                        el(CheckBar, { key, label: key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()), score: check.score })
                    )
                  )
                : null,
            analysis && analysis.suggestions && analysis.suggestions.length > 0
                ? el(PanelBody, { title: 'Suggestions (' + analysis.suggestions.length + ')', initialOpen: false },
                    analysis.suggestions.map((s, i) =>
                        el('div', { key: i, style: {
                            padding: '8px 10px', marginBottom: 6, fontSize: 12,
                            borderLeft: '3px solid ' + (s.priority === 'high' ? '#ef4444' : '#f59e0b'),
                            background: s.priority === 'high' ? '#fef2f2' : '#fffbeb',
                            borderRadius: '0 3px 3px 0'
                        } },
                            el('strong', {}, '[' + s.type + '] '), s.message
                        )
                    )
                  )
                : null
        );
    };

    // ============================================================
    // 4. PRE-PUBLISH PANEL
    // ============================================================
    const SEOBetterPrePublish = () => {
        const { analysis, loading } = useAnalysis();
        const isPro = window.seobetterData?.isPro || false;

        if (loading) {
            return el(PluginPrePublishPanel, { title: 'SEOBetter: Analyzing...', icon: 'chart-line', initialOpen: true },
                el('div', { style: { textAlign: 'center', padding: 12 } }, el(Spinner)));
        }
        if (!analysis) {
            return el(PluginPrePublishPanel, { title: 'SEOBetter', icon: 'chart-line', initialOpen: true },
                el('p', { style: { fontSize: 13, color: '#666' } }, 'Generate an article with SEOBetter to see your GEO score here.'));
        }

        const score = analysis.geo_score || 0;
        const color = scoreColor(score);
        const checks = analysis.checks || {};
        const highPri = (analysis.suggestions || []).filter(s => s.priority === 'high');

        const items = [
            { label: 'GEO Score', value: score + '/100 (' + (analysis.grade || '?') + ')', ok: score >= 70 },
            { label: 'Citations', value: (checks.citations?.count || 0) + ' found', ok: (checks.citations?.count || 0) >= 5 },
            { label: 'Expert Quotes', value: (checks.expert_quotes?.count || 0) + ' found', ok: (checks.expert_quotes?.count || 0) >= 2 },
            { label: 'Readability', value: 'Grade ' + Math.round(checks.readability?.flesch_grade || 0), ok: (checks.readability?.score || 0) >= 60 },
        ];

        return el(PluginPrePublishPanel, {
            title: 'SEOBetter: ' + (score >= 70 ? 'Ready to publish' : 'Needs improvement'),
            icon: 'chart-line', initialOpen: true
        },
            items.map((item, i) =>
                el('div', { key: i, style: { display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: i < items.length - 1 ? '1px solid #e5e7eb' : 'none' } },
                    el('span', { style: { fontSize: 13 } }, (item.ok ? '✅ ' : '❌ ') + item.label),
                    el('span', { style: { fontSize: 13, fontWeight: 600, color: item.ok ? '#22c55e' : '#ef4444' } }, item.value)
                )
            ),
            highPri.length > 0
                ? el('div', { style: { marginTop: 12 } },
                    el('p', { style: { fontSize: 12, fontWeight: 600, color: '#991b1b', margin: '0 0 6px' } },
                        highPri.length + ' issue' + (highPri.length > 1 ? 's' : '') + ' to fix:'),
                    highPri.slice(0, 3).map((s, i) =>
                        el('div', { key: i, style: { fontSize: 11, padding: '4px 8px', marginBottom: 3, background: '#fef2f2', borderLeft: '2px solid #ef4444', borderRadius: '0 4px 4px 0', color: '#991b1b' } }, s.message)
                    )
                ) : null,
            !isPro && score < 80
                ? el('div', { style: { marginTop: 12, padding: '10px 12px', background: 'linear-gradient(135deg,#eef2ff,#e0e7ff)', borderRadius: 6, textAlign: 'center' } },
                    el('p', { style: { fontSize: 12, color: '#312e81', margin: '0 0 6px', fontWeight: 600 } }, 'Fix all issues with one click'),
                    el(Button, { variant: 'primary', href: window.seobetterData?.settingsUrl || '#', style: { background: 'linear-gradient(135deg,#764ba2,#667eea)', border: 'none', fontSize: 12, height: 30 } }, 'Upgrade to Pro →')
                ) : null
        );
    };

    // ============================================================
    // Register all components
    // ============================================================
    registerPlugin('seobetter', {
        render: () => el(Fragment, {},
            el(ToolbarBadge),
            el(SEOBetterDocPanel),
            el(SEOBetterSidebar),
            el(SEOBetterPrePublish)
        ),
        icon: 'chart-line'
    });

})(window.wp);
