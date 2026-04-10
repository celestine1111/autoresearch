/**
 * SEOBetter Gutenberg Editor Sidebar Panel.
 *
 * Displays real-time GEO Score in the block editor sidebar.
 */
(function(wp) {
    const { registerPlugin } = wp.plugins;
    const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
    const { PanelBody, PanelRow, Button, Spinner } = wp.components;
    const { useState, useEffect } = wp.element;
    const { select } = wp.data;
    const apiFetch = wp.apiFetch;

    const GEOScoreMeter = ({ score, grade }) => {
        let color = '#dc3545'; // poor
        if (score >= 80) color = '#28a745'; // good
        else if (score >= 60) color = '#ffc107'; // ok

        const circumference = 2 * Math.PI * 45;
        const offset = circumference - (score / 100) * circumference;

        return wp.element.createElement('div', { style: { textAlign: 'center', padding: '16px 0' } },
            wp.element.createElement('svg', { width: 120, height: 120, viewBox: '0 0 120 120' },
                wp.element.createElement('circle', {
                    cx: 60, cy: 60, r: 45,
                    fill: 'none', stroke: '#e9ecef', strokeWidth: 8
                }),
                wp.element.createElement('circle', {
                    cx: 60, cy: 60, r: 45,
                    fill: 'none', stroke: color, strokeWidth: 8,
                    strokeDasharray: circumference,
                    strokeDashoffset: offset,
                    strokeLinecap: 'round',
                    transform: 'rotate(-90 60 60)',
                    style: { transition: 'stroke-dashoffset 0.5s ease' }
                }),
                wp.element.createElement('text', {
                    x: 60, y: 55, textAnchor: 'middle',
                    fontSize: 28, fontWeight: 700, fill: color
                }, score),
                wp.element.createElement('text', {
                    x: 60, y: 75, textAnchor: 'middle',
                    fontSize: 12, fill: '#666'
                }, grade)
            )
        );
    };

    const CheckItem = ({ label, score, detail }) => {
        let color = '#dc3545';
        if (score >= 80) color = '#28a745';
        else if (score >= 60) color = '#ffc107';

        return wp.element.createElement(PanelRow, {},
            wp.element.createElement('div', { style: { width: '100%' } },
                wp.element.createElement('div', { style: { display: 'flex', justifyContent: 'space-between', marginBottom: 4 } },
                    wp.element.createElement('span', { style: { fontSize: 12 } }, label),
                    wp.element.createElement('span', {
                        style: { fontSize: 12, fontWeight: 600, color }
                    }, score + '/100')
                ),
                wp.element.createElement('div', {
                    style: { height: 4, background: '#e9ecef', borderRadius: 2 }
                },
                    wp.element.createElement('div', {
                        style: {
                            height: '100%', width: score + '%',
                            background: color, borderRadius: 2,
                            transition: 'width 0.3s ease'
                        }
                    })
                )
            )
        );
    };

    const SuggestionItem = ({ suggestion }) => {
        const icon = suggestion.priority === 'high' ? 'warning' : 'info-outline';
        return wp.element.createElement('div', {
            style: {
                padding: '8px 10px', marginBottom: 6,
                borderLeft: '3px solid ' + (suggestion.priority === 'high' ? '#dc3545' : '#ffc107'),
                background: suggestion.priority === 'high' ? '#fff5f5' : '#fffdf0',
                fontSize: 12, borderRadius: '0 3px 3px 0'
            }
        },
            wp.element.createElement('strong', {}, '[' + suggestion.type + '] '),
            suggestion.message
        );
    };

    const SEOBetterSidebar = () => {
        const [analysis, setAnalysis] = useState(null);
        const [loading, setLoading] = useState(false);

        const runAnalysis = () => {
            const postId = select('core/editor').getCurrentPostId();
            if (!postId) return;

            setLoading(true);
            apiFetch({ path: '/seobetter/v1/analyze/' + postId })
                .then(data => {
                    setAnalysis(data);
                    setLoading(false);
                })
                .catch(() => setLoading(false));
        };

        useEffect(() => {
            const postId = select('core/editor').getCurrentPostId();
            if (postId) runAnalysis();
        }, []);

        return wp.element.createElement(
            PluginSidebar,
            {
                name: 'seobetter-sidebar',
                title: 'SEOBetter GEO',
                icon: 'chart-line'
            },
            wp.element.createElement(PanelBody, { title: 'GEO Score', initialOpen: true },
                loading
                    ? wp.element.createElement('div', { style: { textAlign: 'center', padding: 20 } },
                        wp.element.createElement(Spinner, {})
                      )
                    : analysis
                        ? wp.element.createElement('div', {},
                            wp.element.createElement(GEOScoreMeter, {
                                score: analysis.geo_score,
                                grade: analysis.grade
                            }),
                            wp.element.createElement(PanelRow, {},
                                wp.element.createElement('span', {}, 'Word Count'),
                                wp.element.createElement('span', {}, analysis.word_count?.toLocaleString())
                            )
                          )
                        : wp.element.createElement('p', {}, 'Save the post to see your GEO score.'),
                wp.element.createElement(PanelRow, {},
                    wp.element.createElement(Button, {
                        variant: 'secondary',
                        onClick: runAnalysis,
                        disabled: loading,
                        style: { width: '100%', justifyContent: 'center' }
                    }, 'Re-analyze')
                )
            ),
            analysis && analysis.checks
                ? wp.element.createElement(PanelBody, { title: 'GEO Checks', initialOpen: false },
                    Object.entries(analysis.checks).map(([key, check]) =>
                        wp.element.createElement(CheckItem, {
                            key,
                            label: key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
                            score: check.score,
                            detail: check.detail
                        })
                    )
                  )
                : null,
            analysis && analysis.suggestions && analysis.suggestions.length > 0
                ? wp.element.createElement(PanelBody, { title: 'Suggestions (' + analysis.suggestions.length + ')', initialOpen: false },
                    analysis.suggestions.map((s, i) =>
                        wp.element.createElement(SuggestionItem, { key: i, suggestion: s })
                    )
                  )
                : null
        );
    };

    // Pre-publish panel — shows in the publish confirmation screen
    const { PluginPrePublishPanel } = wp.editPost;

    const SEOBetterPrePublish = () => {
        const [analysis, setAnalysis] = useState(null);
        const [loading, setLoading] = useState(true);
        const isPro = window.seobetterData?.isPro || false;

        useEffect(() => {
            const postId = select('core/editor').getCurrentPostId();
            if (!postId) { setLoading(false); return; }

            apiFetch({ path: '/seobetter/v1/analyze/' + postId })
                .then(data => { setAnalysis(data); setLoading(false); })
                .catch(() => setLoading(false));
        }, []);

        if (loading) {
            return wp.element.createElement(PluginPrePublishPanel, {
                title: 'SEOBetter: Analyzing...',
                icon: 'chart-line',
                initialOpen: true
            }, wp.element.createElement('div', { style: { textAlign: 'center', padding: 12 } },
                wp.element.createElement(Spinner, {})
            ));
        }

        if (!analysis) {
            return wp.element.createElement(PluginPrePublishPanel, {
                title: 'SEOBetter',
                icon: 'chart-line',
                initialOpen: true
            }, wp.element.createElement('p', { style: { fontSize: 13, color: '#666' } },
                'Generate an article with SEOBetter to see your GEO score here.'
            ));
        }

        const score = analysis.geo_score || 0;
        const scoreColor = score >= 80 ? '#22c55e' : (score >= 60 ? '#f59e0b' : '#ef4444');
        const checks = analysis.checks || {};
        const suggestions = analysis.suggestions || [];
        const highPri = suggestions.filter(s => s.priority === 'high');

        // Build score items
        const items = [
            { label: 'GEO Score', value: score + '/100 (' + (analysis.grade || '?') + ')', color: scoreColor, ok: score >= 70 },
            { label: 'Citations', value: (checks.citations?.count || 0) + ' found', ok: (checks.citations?.count || 0) >= 5 },
            { label: 'Expert Quotes', value: (checks.expert_quotes?.count || 0) + ' found', ok: (checks.expert_quotes?.count || 0) >= 2 },
            { label: 'Readability', value: 'Grade ' + (checks.readability?.grade || '?'), ok: (checks.readability?.score || 0) >= 70 },
            { label: 'Schema', value: analysis.schema_types || 'Article', ok: true },
        ];

        return wp.element.createElement(PluginPrePublishPanel, {
            title: 'SEOBetter: ' + (score >= 70 ? 'Ready to publish' : 'Needs improvement'),
            icon: 'chart-line',
            initialOpen: true
        },
            // Score items
            items.map((item, i) =>
                wp.element.createElement('div', {
                    key: i,
                    style: {
                        display: 'flex', justifyContent: 'space-between', alignItems: 'center',
                        padding: '8px 0', borderBottom: i < items.length - 1 ? '1px solid #e5e7eb' : 'none'
                    }
                },
                    wp.element.createElement('span', { style: { fontSize: 13 } },
                        wp.element.createElement('span', {
                            style: { marginRight: 6, fontSize: 14 }
                        }, item.ok ? '✅' : '❌'),
                        item.label
                    ),
                    wp.element.createElement('span', {
                        style: { fontSize: 13, fontWeight: 600, color: item.ok ? '#22c55e' : '#ef4444' }
                    }, item.value)
                )
            ),

            // High priority issues
            highPri.length > 0
                ? wp.element.createElement('div', { style: { marginTop: 12 } },
                    wp.element.createElement('p', {
                        style: { fontSize: 12, fontWeight: 600, color: '#991b1b', margin: '0 0 6px' }
                    }, highPri.length + ' issue' + (highPri.length > 1 ? 's' : '') + ' to fix:'),
                    highPri.slice(0, 3).map((s, i) =>
                        wp.element.createElement('div', {
                            key: i,
                            style: {
                                fontSize: 11, padding: '4px 8px', marginBottom: 3,
                                background: '#fef2f2', borderLeft: '2px solid #ef4444',
                                borderRadius: '0 4px 4px 0', color: '#991b1b'
                            }
                        }, s.message)
                    )
                )
                : null,

            // Pro upsell
            !isPro && score < 80
                ? wp.element.createElement('div', {
                    style: {
                        marginTop: 12, padding: '10px 12px',
                        background: 'linear-gradient(135deg, #eef2ff, #e0e7ff)',
                        borderRadius: 6, textAlign: 'center'
                    }
                },
                    wp.element.createElement('p', {
                        style: { fontSize: 12, color: '#312e81', margin: '0 0 6px', fontWeight: 600 }
                    }, 'Fix all issues with one click'),
                    wp.element.createElement(Button, {
                        variant: 'primary',
                        href: window.seobetterData?.settingsUrl || '/wp-admin/admin.php?page=seobetter-settings',
                        style: {
                            background: 'linear-gradient(135deg, #764ba2, #667eea)',
                            border: 'none', fontSize: 12, height: 30
                        }
                    }, 'Upgrade to Pro →')
                )
                : null
        );
    };

    registerPlugin('seobetter', {
        render: () => wp.element.createElement(wp.element.Fragment, {},
            wp.element.createElement(SEOBetterSidebar, {}),
            wp.element.createElement(SEOBetterPrePublish, {})
        ),
        icon: 'chart-line'
    });

})(window.wp);
