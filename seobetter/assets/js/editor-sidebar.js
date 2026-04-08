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

    registerPlugin('seobetter', {
        render: SEOBetterSidebar,
        icon: 'chart-line'
    });

})(window.wp);
