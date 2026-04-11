/**
 * SEOBetter Gutenberg Editor — Minimal version to debug.
 */
(function() {
    // Safety checks
    if (typeof wp === 'undefined') return;
    if (!wp.plugins || !wp.plugins.registerPlugin) return;
    if (!wp.element || !wp.element.createElement) return;

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var registerPlugin = wp.plugins.registerPlugin;

    // Find PluginDocumentSettingPanel from wherever it lives
    var DocPanel = null;
    if (wp.editor && wp.editor.PluginDocumentSettingPanel) {
        DocPanel = wp.editor.PluginDocumentSettingPanel;
    } else if (wp.editPost && wp.editPost.PluginDocumentSettingPanel) {
        DocPanel = wp.editPost.PluginDocumentSettingPanel;
    }



    // Main render function
    function SEOBetterPanel() {
        var stateArr = useState(null);
        var data = stateArr[0];
        var setData = stateArr[1];
        var loadArr = useState(true);
        var loading = loadArr[0];
        var setLoading = loadArr[1];

        useEffect(function() {
            try {
                var postId = wp.data.select('core/editor').getCurrentPostId();
                if (!postId) { setLoading(false); return; }

                wp.apiFetch({ path: '/seobetter/v1/analyze/' + postId })
                    .then(function(result) {
                        setData(result);
                        setLoading(false);
                    })
                    .catch(function() {
                        setLoading(false);
                    });
            } catch(e) {
                setLoading(false);
            }
        }, []);

        // Build content
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

            content = el('div', { style: { padding: '4px 0' } },
                // Score bar
                el('div', { style: { padding: '8px 12px', marginBottom: 10, background: color + '10', borderLeft: '4px solid ' + color, borderRadius: '0 4px 4px 0' } },
                    el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } },
                        el('span', { style: { fontSize: 18, fontWeight: 700, color: color } }, score + '/100'),
                        el('span', { style: { fontSize: 13, fontWeight: 600, color: color, padding: '2px 8px', background: color + '20', borderRadius: 4 } }, grade)
                    )
                ),
                // Stats
                el('div', { style: { fontSize: 13 } },
                    statRow('📝 Words', words.toLocaleString(), words >= 800),
                    statRow('📖 Readability', 'Grade ' + readGrade, readGrade >= 6 && readGrade <= 10),
                    statRow('🔗 Citations', citations + '/5', citations >= 5),
                    statRow('💬 Quotes', quotes + '/2', quotes >= 2)
                )
            );
        } else {
            content = el('p', { style: { fontSize: 13, color: '#666', margin: 0 } }, 'Save the post to see GEO score.');
        }

        // Render in DocPanel if available, otherwise just return the content
        if (DocPanel) {
            var title = data ? ('SEOBetter: ' + (data.geo_score || 0) + '/100') : 'SEOBetter';
            return el(DocPanel, { name: 'seobetter-panel', title: title }, content);
        }

        return null;
    }

    function statRow(label, value, ok) {
        return el('div', { style: { display: 'flex', justifyContent: 'space-between', padding: '5px 0', borderBottom: '1px solid #f0f0f0' } },
            el('span', null, label),
            el('span', { style: { fontWeight: 600, color: ok ? '#22c55e' : '#ef4444' } }, (ok ? '✓ ' : '✗ ') + value)
        );
    }

    // Register
    registerPlugin('seobetter', {
        render: SEOBetterPanel,
        icon: 'chart-line'
    });

})();
