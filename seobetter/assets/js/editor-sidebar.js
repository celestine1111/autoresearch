/**
 * SEOBetter Gutenberg Editor Integration.
 *
 * 1. Post sidebar panel (PluginDocumentSettingPanel) — opens by default
 * 2. Score badge in top toolbar (DOM injection like AIOSEO)
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

    var DocPanel = null;
    if (wp.editor && wp.editor.PluginDocumentSettingPanel) {
        DocPanel = wp.editor.PluginDocumentSettingPanel;
    } else if (wp.editPost && wp.editPost.PluginDocumentSettingPanel) {
        DocPanel = wp.editPost.PluginDocumentSettingPanel;
    }

    // Shared analysis cache
    var cachedData = null;

    function useAnalysis() {
        var s1 = useState(cachedData);
        var data = s1[0];
        var setData = s1[1];
        var s2 = useState(!cachedData);
        var loading = s2[0];
        var setLoading = s2[1];

        useEffect(function() {
            if (cachedData) { setData(cachedData); setLoading(false); return; }
            try {
                var postId = wp.data.select('core/editor').getCurrentPostId();
                if (!postId) { setLoading(false); return; }
                wp.apiFetch({ path: '/seobetter/v1/analyze/' + postId })
                    .then(function(result) { cachedData = result; setData(result); setLoading(false); })
                    .catch(function() { setLoading(false); });
            } catch(e) { setLoading(false); }
        }, []);

        return { data: data, loading: loading };
    }

    // ============================================================
    // 1. TOOLBAR SCORE BADGE
    // ============================================================
    function ToolbarBadge() {
        var r = useAnalysis();
        var data = r.data;

        useEffect(function() {
            if (!data || data.geo_score === undefined) return;

            var t1, t2, t3;
            function inject() {
                try {
                    if (document.getElementById('seobetter-toolbar-badge')) return;

                    // Try multiple selectors for different WP versions
                    var toolbar = document.querySelector('.edit-post-header__settings') ||
                                  document.querySelector('.editor-header__settings') ||
                                  document.querySelector('.edit-post-header__toolbar');
                    if (!toolbar) return;

                    var score = data.geo_score || 0;
                    var color = score >= 80 ? '#22c55e' : (score >= 60 ? '#f59e0b' : '#ef4444');
                    var grade = data.grade || '?';

                    var badge = document.createElement('div');
                    badge.id = 'seobetter-toolbar-badge';
                    badge.title = 'SEOBetter GEO Score';
                    badge.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:0 10px;height:32px;border-radius:4px;margin-right:8px;border:1px solid ' + color + ';background:' + color + '12;cursor:default;';

                    var icon = document.createElement('span');
                    icon.style.cssText = 'width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;background:' + color + ';line-height:1;';
                    icon.textContent = score;

                    var text = document.createElement('span');
                    text.style.cssText = 'font-size:12px;font-weight:600;color:' + color + ';line-height:1;';
                    text.textContent = grade;

                    badge.appendChild(icon);
                    badge.appendChild(text);

                    // Insert before the first child (Save button area)
                    toolbar.insertBefore(badge, toolbar.firstChild);
                } catch(e) {}
            }

            inject();
            t1 = setTimeout(inject, 800);
            t2 = setTimeout(inject, 2000);
            t3 = setTimeout(inject, 4000);
            return function() { clearTimeout(t1); clearTimeout(t2); clearTimeout(t3); };
        }, [data]);

        return null;
    }

    // ============================================================
    // 2. POST SIDEBAR PANEL
    // ============================================================
    function SEOBetterPanel() {
        var r = useAnalysis();
        var data = r.data;
        var loading = r.loading;

        var content;
        if (loading) {
            content = el('p', { style: { fontSize: 13, color: '#666', padding: 8, textAlign: 'center' } }, 'Analyzing...');
        } else if (data && data.geo_score !== undefined) {
            var score = data.geo_score || 0;
            var grade = data.grade || '?';
            var color = score >= 80 ? '#22c55e' : (score >= 60 ? '#f59e0b' : '#ef4444');
            var words = data.word_count || 0;
            var checks = data.checks || {};
            var citations = checks.citations ? (checks.citations.count || 0) : 0;
            var quotes = checks.expert_quotes ? (checks.expert_quotes.count || 0) : 0;
            var readGrade = checks.readability ? Math.round(checks.readability.flesch_grade || 0) : 0;
            var readTime = Math.max(1, Math.ceil(words / 200));
            var tables = checks.tables ? (checks.tables.count || 0) : 0;
            var freshness = checks.freshness ? checks.freshness.score >= 100 : false;

            content = el('div', { style: { padding: '4px 0' } },
                el('div', { style: { padding: '8px 12px', marginBottom: 10, background: color + '10', borderLeft: '4px solid ' + color, borderRadius: '0 4px 4px 0' } },
                    el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } },
                        el('span', { style: { fontSize: 18, fontWeight: 700, color: color } }, score + '/100'),
                        el('span', { style: { fontSize: 13, fontWeight: 600, color: color, padding: '2px 8px', background: color + '20', borderRadius: 4 } }, grade)
                    )
                ),
                el('div', { style: { fontSize: 13 } },
                    statRow('📊 GEO Score', score + '/100', score >= 70),
                    statRow('📝 Words', words.toLocaleString(), words >= 800),
                    statRow('⏱ Read Time', readTime + ' min', true),
                    statRow('📖 Readability', 'Grade ' + readGrade, readGrade >= 6 && readGrade <= 10),
                    statRow('🔗 Citations', citations + '/5', citations >= 5),
                    statRow('💬 Quotes', quotes + '/2', quotes >= 2),
                    statRow('📋 Tables', tables + ' found', tables >= 1),
                    statRow('🕐 Freshness', freshness ? 'Yes' : 'Missing', freshness)
                )
            );
        } else {
            content = el('p', { style: { fontSize: 13, color: '#666', margin: 0 } }, 'Save the post to see GEO score.');
        }

        if (DocPanel) {
            var title = data ? ('SEOBetter: ' + (data.geo_score || 0) + '/100') : 'SEOBetter';
            return el(DocPanel, { name: 'seobetter-panel', title: title, initialOpen: true }, content);
        }
        return null;
    }

    function statRow(label, value, ok) {
        return el('div', { style: { display: 'flex', justifyContent: 'space-between', padding: '5px 0', borderBottom: '1px solid #f0f0f0' } },
            el('span', null, label),
            el('span', { style: { fontWeight: 600, color: ok ? '#22c55e' : '#ef4444' } }, (ok ? '✓ ' : '✗ ') + value)
        );
    }

    // ============================================================
    // Register
    // ============================================================
    registerPlugin('seobetter', {
        render: function() {
            return el(Fragment, null,
                el(ToolbarBadge),
                el(SEOBetterPanel)
            );
        },
        icon: 'chart-line'
    });

})();
