/**
 * SEOBetter Gutenberg Editor Integration v1.4
 *
 * 1. PluginDocumentSettingPanel — Post sidebar with score + stats (always open)
 * 2. Toolbar score badge — colored pill next to Save button
 * 3. Full PluginSidebar — AIOSEO-style detailed panel with score ring,
 *    headline analyzer, GEO checks, and suggestions
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

    // Resolve components from wp.editor (WP 6.6+) or wp.editPost (older)
    var _e = wp.editor || {};
    var _ep = wp.editPost || {};
    var DocPanel = _e.PluginDocumentSettingPanel || _ep.PluginDocumentSettingPanel;
    var Sidebar = _e.PluginSidebar || _ep.PluginSidebar;
    var PrePublish = _e.PluginPrePublishPanel || _ep.PluginPrePublishPanel;

    var PanelBody = wp.components.PanelBody;
    var PanelRow = wp.components.PanelRow;
    var Button = wp.components.Button;
    var Spinner = wp.components.Spinner;

    // ============================================================
    // Shared analysis
    // ============================================================
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
            if (cachedData) { setData(cachedData); setLoading(false); }
            else runAnalysis();
        }, []);

        return { data: data, loading: loading, runAnalysis: runAnalysis };
    }

    function sc(score) { return score >= 80 ? '#22c55e' : (score >= 60 ? '#f59e0b' : '#ef4444'); }
    function ratingText(score) {
        if (score >= 90) return 'Excellent! 🔥🔥🔥';
        if (score >= 80) return 'Great! 🔥🔥';
        if (score >= 70) return 'Good 🔥';
        if (score >= 60) return 'Needs work 😐';
        return 'Poor 😟';
    }

    // ============================================================
    // Score Ring SVG (like AIOSEO's circular gauge)
    // ============================================================
    function ScoreRing(props) {
        var score = props.score || 0;
        var grade = props.grade || '?';
        var color = sc(score);
        var circ = 2 * Math.PI * 52;
        var offset = circ - (score / 100) * circ;

        return el('div', { style: { textAlign: 'center', padding: '20px 0 10px' } },
            el('svg', { width: 140, height: 140, viewBox: '0 0 140 140' },
                el('circle', { cx: 70, cy: 70, r: 52, fill: 'none', stroke: '#e5e7eb', strokeWidth: 10 }),
                el('circle', { cx: 70, cy: 70, r: 52, fill: 'none', stroke: color, strokeWidth: 10,
                    strokeDasharray: circ, strokeDashoffset: offset, strokeLinecap: 'round',
                    transform: 'rotate(-90 70 70)',
                    style: { transition: 'stroke-dashoffset 0.8s ease' }
                }),
                el('text', { x: 70, y: 65, textAnchor: 'middle', fontSize: 32, fontWeight: 800, fill: color }, score),
                el('text', { x: 70, y: 82, textAnchor: 'middle', fontSize: 12, fill: '#9ca3af' }, '/ 100')
            ),
            el('div', { style: { fontSize: 14, fontWeight: 600, color: color, marginTop: 4 } }, ratingText(score))
        );
    }

    // ============================================================
    // Stat row helper
    // ============================================================
    function StatRow(props) {
        var ok = props.ok;
        var icon = ok ? '✅' : '❌';
        return el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '7px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 } },
            el('span', null, icon + ' ' + props.label),
            el('span', { style: { fontWeight: 600, color: ok ? '#22c55e' : '#ef4444' } }, props.value)
        );
    }

    // ============================================================
    // Check bar with progress
    // ============================================================
    function CheckBar(props) {
        var score = props.score || 0;
        var color = sc(score);
        var icon = score >= 70 ? '✅' : (score >= 40 ? '⚠️' : '❌');
        return el('div', { style: { marginBottom: 10 } },
            el('div', { style: { display: 'flex', justifyContent: 'space-between', fontSize: 12, marginBottom: 3 } },
                el('span', null, icon + ' ' + props.label),
                el('span', { style: { fontWeight: 600, color: color } }, score + '%')
            ),
            el('div', { style: { height: 6, background: '#f3f4f6', borderRadius: 3 } },
                el('div', { style: { height: '100%', width: score + '%', background: color, borderRadius: 3, transition: 'width 0.5s ease' } })
            ),
            props.detail ? el('div', { style: { fontSize: 11, color: '#6b7280', marginTop: 2 } }, props.detail) : null
        );
    }

    // ============================================================
    // Headline Analyzer (like AIOSEO's)
    // ============================================================
    function HeadlineAnalyzer(props) {
        var title = props.title || '';
        if (!title) return null;

        var words = title.split(/\s+/).filter(function(w) { return w.length > 0; });
        var wordCount = words.length;
        var charCount = title.length;

        // Word count assessment
        var wordOk = wordCount >= 6 && wordCount <= 12;
        var wordLabel = wordCount <= 5 ? 'Too short' : (wordCount > 12 ? 'Too long' : 'Good');

        // Character count
        var charOk = charCount >= 45 && charCount <= 65;
        var charLabel = charCount < 45 ? 'Too short' : (charCount > 65 ? 'May truncate' : 'Good');

        // Detect headline type
        var type = 'General';
        if (/^\d+\s/.test(title) || /top\s+\d+/i.test(title)) type = 'List';
        else if (/^how\s+to/i.test(title)) type = 'How-to';
        else if (/\?$/.test(title)) type = 'Question';
        else if (/\bvs\.?\b|\bversus\b/i.test(title)) type = 'Comparison';
        else if (/\breview\b/i.test(title)) type = 'Review';

        // Power words
        var powerWords = ['best', 'top', 'ultimate', 'complete', 'essential', 'proven', 'expert', 'guide', 'review', 'amazing', 'incredible', 'exclusive', 'powerful', 'guaranteed', 'secret', 'luxury', 'premium', 'free'];
        var foundPower = words.filter(function(w) { return powerWords.indexOf(w.toLowerCase()) !== -1; });
        var powerPct = Math.round((foundPower.length / wordCount) * 100);

        // Emotional words
        var emotionalWords = ['love', 'hate', 'fear', 'joy', 'angry', 'happy', 'sad', 'exciting', 'shocking', 'amazing', 'terrible', 'brilliant', 'awful', 'stunning', 'heartbreaking', 'inspiring'];
        var foundEmotional = words.filter(function(w) { return emotionalWords.indexOf(w.toLowerCase()) !== -1; });
        var emotionalPct = Math.round((foundEmotional.length / wordCount) * 100);

        // Common words
        var commonWords = ['the', 'a', 'an', 'in', 'on', 'at', 'to', 'for', 'of', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'it', 'you', 'your', 'we', 'our', 'what', 'how', 'why', 'when', 'where', 'which', 'that', 'this', 'with'];
        var foundCommon = words.filter(function(w) { return commonWords.indexOf(w.toLowerCase()) !== -1; });
        var commonPct = Math.round((foundCommon.length / wordCount) * 100);

        // Uncommon words
        var uncommonCount = wordCount - foundCommon.length - foundPower.length - foundEmotional.length;
        var uncommonPct = Math.round((uncommonCount / wordCount) * 100);

        // Sentiment (simplified)
        var posWords = ['best', 'good', 'great', 'amazing', 'love', 'excellent', 'top', 'premium', 'luxury', 'essential', 'proven', 'powerful', 'super', 'ultimate', 'complete'];
        var negWords = ['worst', 'bad', 'terrible', 'hate', 'awful', 'avoid', 'never', 'danger', 'warning', 'risk'];
        var posCount = words.filter(function(w) { return posWords.indexOf(w.toLowerCase()) !== -1; }).length;
        var negCount = words.filter(function(w) { return negWords.indexOf(w.toLowerCase()) !== -1; }).length;
        var sentiment = posCount > negCount ? 'Positive' : (negCount > posCount ? 'Negative' : 'Neutral');
        var sentimentIcon = sentiment === 'Positive' ? '😊' : (sentiment === 'Negative' ? '😟' : '😐');
        var sentimentOk = sentiment === 'Positive';

        // Beginning & ending words
        var beginWords = words.slice(0, 3).join(' ');
        var endWords = words.slice(-3).join(' ');

        return el('div', null,
            // Headline type
            el('div', { style: { padding: '8px 0', borderBottom: '1px solid #f3f4f6' } },
                el('div', { style: { display: 'flex', justifyContent: 'space-between', fontSize: 13, fontWeight: 600 } },
                    el('span', null, 'Headline Type'),
                    el('span', null, type)
                ),
                el('div', { style: { fontSize: 11, color: '#6b7280', marginTop: 4 } },
                    type === 'List' ? 'List headlines get more engagement on average.' :
                    type === 'How-to' ? 'How-to headlines attract readers looking for solutions.' :
                    type === 'Question' ? 'Question headlines drive curiosity clicks.' :
                    'Consider using a list or how-to format for better engagement.')
            ),
            // Character count
            el('div', { style: { padding: '8px 0', borderBottom: '1px solid #f3f4f6' } },
                el('div', { style: { display: 'flex', justifyContent: 'space-between', fontSize: 13 } },
                    el('span', { style: { fontWeight: 600 } }, 'Character Count'),
                    el('span', null, charOk ? '✅' : '⚠️')
                ),
                el('div', { style: { fontSize: 20, fontWeight: 700, color: charOk ? '#22c55e' : '#f59e0b', margin: '4px 0' } }, charCount),
                el('div', { style: { fontSize: 11, color: '#6b7280' } }, charLabel + '. Headlines ~55 characters display fully in search results.')
            ),
            // Word count
            el('div', { style: { padding: '8px 0', borderBottom: '1px solid #f3f4f6' } },
                el('div', { style: { display: 'flex', justifyContent: 'space-between', fontSize: 13 } },
                    el('span', { style: { fontWeight: 600 } }, 'Word Count'),
                    el('span', null, wordOk ? '✅' : '⚠️')
                ),
                el('div', { style: { fontSize: 20, fontWeight: 700, color: wordOk ? '#22c55e' : '#f59e0b', margin: '4px 0' } }, wordCount),
                el('div', { style: { fontSize: 11, color: '#6b7280' } }, wordLabel + '. Aim for 6-12 words.')
            ),
            // Word Balance
            el('div', { style: { padding: '8px 0', borderBottom: '1px solid #f3f4f6' } },
                el('div', { style: { fontSize: 13, fontWeight: 600, marginBottom: 6 } }, 'Word Balance'),
                balanceRow('Common Words', commonPct, '20-30%', commonPct >= 20 && commonPct <= 35, foundCommon),
                balanceRow('Uncommon Words', uncommonPct, '10-20%', uncommonPct >= 10 && uncommonPct <= 30, []),
                balanceRow('Power Words', powerPct, 'At least one', foundPower.length >= 1, foundPower),
                balanceRow('Emotional Words', emotionalPct, '10-15%', emotionalPct >= 5, foundEmotional)
            ),
            // Sentiment
            el('div', { style: { padding: '8px 0', borderBottom: '1px solid #f3f4f6' } },
                el('div', { style: { display: 'flex', justifyContent: 'space-between', fontSize: 13 } },
                    el('span', { style: { fontWeight: 600 } }, 'Sentiment'),
                    el('span', null, sentimentIcon)
                ),
                el('div', { style: { fontSize: 14, fontWeight: 600, color: sentimentOk ? '#22c55e' : '#f59e0b', marginTop: 4 } }, sentiment),
                el('div', { style: { fontSize: 11, color: '#6b7280' } }, 'Positive headlines tend to get better engagement.')
            ),
            // Beginning & Ending words
            el('div', { style: { padding: '8px 0' } },
                el('div', { style: { fontSize: 13, fontWeight: 600, marginBottom: 6 } }, 'Beginning & Ending Words'),
                el('div', { style: { marginBottom: 6 } },
                    el('div', { style: { fontSize: 12, fontWeight: 600, marginBottom: 2 } }, 'Beginning Words'),
                    el('span', { style: { display: 'inline-block', padding: '2px 8px', background: '#f3f4f6', borderRadius: 4, fontSize: 12 } }, beginWords)
                ),
                el('div', null,
                    el('div', { style: { fontSize: 12, fontWeight: 600, marginBottom: 2 } }, 'Ending Words'),
                    el('span', { style: { display: 'inline-block', padding: '2px 8px', background: '#f3f4f6', borderRadius: 4, fontSize: 12 } }, endWords)
                ),
                el('div', { style: { fontSize: 11, color: '#6b7280', marginTop: 6 } }, 'Most readers only look at the first and last 3 words before deciding to click.')
            )
        );
    }

    function balanceRow(label, pct, goal, ok, foundWords) {
        var color = ok ? '#22c55e' : '#f59e0b';
        return el('div', { style: { marginBottom: 8 } },
            el('div', { style: { display: 'flex', justifyContent: 'space-between', fontSize: 12 } },
                el('span', { style: { fontWeight: 600, color: color } }, pct + '%'),
                el('span', { style: { color: '#9ca3af' } }, 'Goal: ' + goal)
            ),
            el('div', { style: { height: 4, background: '#f3f4f6', borderRadius: 2, marginTop: 2 } },
                el('div', { style: { height: '100%', width: Math.min(pct, 100) + '%', background: color, borderRadius: 2 } })
            ),
            foundWords && foundWords.length > 0 ? el('div', { style: { marginTop: 3 } },
                foundWords.map(function(w, i) {
                    return el('span', { key: i, style: { display: 'inline-block', padding: '1px 6px', background: '#f3f4f6', borderRadius: 3, fontSize: 11, marginRight: 4, marginTop: 2 } }, w.toLowerCase());
                })
            ) : null,
            el('div', { style: { fontSize: 11, color: '#6b7280', marginTop: 2 } },
                'Headlines with ' + (ok ? 'good' : 'better') + ' ' + label.toLowerCase() + ' are more likely to get clicks.')
        );
    }

    // ============================================================
    // 1. TOOLBAR BADGE
    // ============================================================
    function ToolbarBadge() {
        var r = useAnalysis();
        if (!r.data || r.data.geo_score === undefined) return null;

        var score = r.data.geo_score || 0;
        var color = sc(score);

        useEffect(function() {
            var t1, t2, t3;
            function inject() {
                try {
                    if (document.getElementById('seobetter-toolbar-badge')) return;
                    var toolbar = document.querySelector('.edit-post-header__settings') ||
                                  document.querySelector('.editor-header__settings');
                    if (!toolbar) return;

                    var badge = document.createElement('div');
                    badge.id = 'seobetter-toolbar-badge';
                    badge.title = 'SEOBetter GEO Score';
                    badge.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:0 10px;height:32px;border-radius:4px;margin-right:8px;border:1px solid ' + color + ';background:' + color + '12;cursor:pointer;';
                    badge.onclick = function() {
                        try { wp.data.dispatch('core/edit-post').openGeneralSidebar('seobetter-full/seobetter-full-sidebar'); } catch(e) {}
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
            inject();
            t1 = setTimeout(inject, 800);
            t2 = setTimeout(inject, 2000);
            t3 = setTimeout(inject, 4000);
            return function() { clearTimeout(t1); clearTimeout(t2); clearTimeout(t3); };
        }, [r.data]);

        return null;
    }

    // ============================================================
    // 2. DOCUMENT SETTINGS PANEL (Post tab — always open)
    // ============================================================
    function DocSettingsPanel() {
        if (!DocPanel) return null;
        var r = useAnalysis();
        var data = r.data;
        var loading = r.loading;

        if (loading) return el(DocPanel, { name: 'seobetter-panel', title: 'SEOBetter', initialOpen: true },
            el('p', { style: { textAlign: 'center', fontSize: 13, color: '#666' } }, 'Analyzing...'));

        if (!data || data.geo_score === undefined) return el(DocPanel, { name: 'seobetter-panel', title: 'SEOBetter', initialOpen: true },
            el('p', { style: { fontSize: 13, color: '#666', margin: 0 } }, 'Save the post to see GEO score.'));

        var score = data.geo_score || 0;
        var color = sc(score);
        var checks = data.checks || {};
        var words = data.word_count || 0;

        return el(DocPanel, { name: 'seobetter-panel', title: 'SEOBetter: ' + score + '/100', initialOpen: true },
            el('div', { style: { padding: '6px 10px', marginBottom: 8, background: color + '10', borderLeft: '4px solid ' + color, borderRadius: '0 4px 4px 0' } },
                el('div', { style: { display: 'flex', justifyContent: 'space-between' } },
                    el('span', { style: { fontSize: 16, fontWeight: 700, color: color } }, score + '/100'),
                    el('span', { style: { fontSize: 12, fontWeight: 600, color: color, background: color + '20', padding: '1px 6px', borderRadius: 3 } }, data.grade || '?')
                )
            ),
            el(StatRow, { label: 'Words', value: words.toLocaleString(), ok: words >= 800 }),
            el(StatRow, { label: 'Readability', value: 'G' + Math.round(checks.readability && checks.readability.flesch_grade || 0), ok: checks.readability && checks.readability.score >= 60 }),
            el(StatRow, { label: 'Citations', value: (checks.citations && checks.citations.count || 0) + '/5', ok: checks.citations && checks.citations.count >= 5 }),
            el(StatRow, { label: 'Quotes', value: (checks.expert_quotes && checks.expert_quotes.count || 0) + '/2', ok: checks.expert_quotes && checks.expert_quotes.count >= 2 }),
            el(StatRow, { label: 'Tables', value: (checks.tables && checks.tables.count || 0) + '', ok: checks.tables && checks.tables.count >= 1 }),
            el('div', { style: { marginTop: 8 } },
                el(Button, {
                    variant: 'secondary', isSmall: true,
                    onClick: function() { cachedData = null; r.runAnalysis(); },
                    style: { width: '100%', justifyContent: 'center', fontSize: 12 }
                }, 'Re-analyze')
            )
        );
    }

    // ============================================================
    // 3. FULL SIDEBAR (like AIOSEO's detailed panel)
    // ============================================================
    function FullSidebar() {
        if (!Sidebar) return null;
        var r = useAnalysis();
        var data = r.data;
        var loading = r.loading;

        // Get post title
        var title = '';
        try { title = wp.data.select('core/editor').getEditedPostAttribute('title') || ''; } catch(e) {}

        return el(Sidebar, { name: 'seobetter-full-sidebar', title: 'SEOBetter GEO', icon: 'chart-line' },
            // Score section
            el(PanelBody, { title: 'Score', initialOpen: true },
                loading
                    ? el('div', { style: { textAlign: 'center', padding: 20 } }, el(Spinner))
                    : data && data.geo_score !== undefined
                        ? el('div', null,
                            title ? el('div', { style: { textAlign: 'center', fontSize: 14, fontWeight: 600, color: '#1f2937', padding: '0 10px', marginBottom: 4 } }, '"' + title + '"') : null,
                            el(ScoreRing, { score: data.geo_score, grade: data.grade }),
                            el('div', { style: { textAlign: 'center', fontSize: 12, color: '#6b7280', marginBottom: 12 } },
                                'A very good score is between 70 and 100. Strive for 70 and above.')
                          )
                        : el('p', { style: { textAlign: 'center', color: '#666' } }, 'Save post to see score.')
            ),
            // Headline Analyzer
            title ? el(PanelBody, { title: 'Headline Analyzer', initialOpen: false },
                el(HeadlineAnalyzer, { title: title })
            ) : null,
            // GEO Checks
            data && data.checks ? el(PanelBody, { title: 'GEO Checks (' + Object.keys(data.checks).length + ')', initialOpen: false },
                Object.keys(data.checks).map(function(key) {
                    var check = data.checks[key];
                    return el(CheckBar, {
                        key: key,
                        label: key.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); }),
                        score: check.score,
                        detail: check.detail
                    });
                })
            ) : null,
            // Suggestions
            data && data.suggestions && data.suggestions.length > 0 ? el(PanelBody, { title: 'Suggestions (' + data.suggestions.length + ')', initialOpen: false },
                data.suggestions.map(function(s, i) {
                    return el('div', { key: i, style: {
                        padding: '8px 10px', marginBottom: 6, fontSize: 12,
                        borderLeft: '3px solid ' + (s.priority === 'high' ? '#ef4444' : '#f59e0b'),
                        background: s.priority === 'high' ? '#fef2f2' : '#fffbeb',
                        borderRadius: '0 4px 4px 0'
                    } },
                        el('strong', null, '[' + (s.type || 'issue') + '] '), s.message
                    );
                })
            ) : null,
            // Re-analyze
            el(PanelBody, { title: 'Actions', initialOpen: true },
                el(Button, {
                    variant: 'secondary',
                    onClick: function() { cachedData = null; r.runAnalysis(); },
                    style: { width: '100%', justifyContent: 'center' }
                }, 'Re-analyze Content')
            )
        );
    }

    // ============================================================
    // Register — use separate plugin registrations for stability
    // ============================================================
    registerPlugin('seobetter', {
        render: function() {
            return el(Fragment, null,
                el(ToolbarBadge),
                el(DocSettingsPanel)
            );
        },
        icon: 'chart-line'
    });

    // Register full sidebar as separate plugin (avoids crash cascade)
    if (Sidebar) {
        registerPlugin('seobetter-full', {
            render: FullSidebar,
            icon: 'chart-line'
        });
    }

})();
