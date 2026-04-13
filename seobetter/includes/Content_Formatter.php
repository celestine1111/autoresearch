<?php

namespace SEOBetter;

/**
 * Content Formatter.
 *
 * Converts AI-generated Markdown into visually appealing WordPress content.
 * Two modes:
 * - Gutenberg: Uses WordPress block markup (columns, cover, group, separator, etc.)
 * - Classic: Uses styled HTML with inline CSS for themes without Gutenberg
 *
 * Makes articles look professional and engaging, not like plain text dumps.
 */
class Content_Formatter {

    /**
     * Format content for WordPress.
     *
     * @param string $markdown Raw markdown from AI.
     * @param string $mode     'gutenberg' or 'classic'.
     * @param array  $options  Formatting options.
     * @return string Formatted WordPress content.
     */
    public function format( string $markdown, string $mode = 'auto', array $options = [] ): string {
        if ( $mode === 'auto' ) {
            $mode = $this->detect_editor();
        }

        // Parse markdown into structured sections
        $sections = $this->parse_markdown( $markdown );

        if ( $mode === 'hybrid' ) {
            return $this->format_hybrid( $sections, $options );
        }

        if ( $mode === 'gutenberg' ) {
            return $this->format_gutenberg( $sections, $options );
        }

        return $this->format_classic( $sections, $options );
    }

    /**
     * Detect if site uses Gutenberg or Classic editor.
     *
     * Default to 'classic' (styled HTML) because it works reliably in BOTH
     * Gutenberg and Classic Editor. Gutenberg block markup is fragile —
     * even minor formatting issues cause "Block contains unexpected content" errors.
     * Classic HTML renders perfectly inside Gutenberg as a Classic block.
     */
    private function detect_editor(): string {
        // Always default to classic — it works everywhere
        return 'classic';
    }

    /**
     * Parse markdown into structured sections for formatting.
     */
    private function parse_markdown( string $markdown ): array {
        $lines = explode( "\n", $markdown );
        $sections = [];
        $current = [ 'type' => 'paragraph', 'content' => [], 'level' => 0 ];
        $in_table = false;
        $table_rows = [];
        $in_list = false;
        $list_items = [];
        $list_type = 'ul';
        $in_blockquote = false;
        $quote_lines = [];
        $in_faq = false;

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );

            // Empty line — flush current
            if ( $trimmed === '' ) {
                if ( $in_list ) {
                    $sections[] = [ 'type' => 'list', 'list_type' => $list_type, 'items' => $list_items ];
                    $list_items = [];
                    $in_list = false;
                }
                if ( $in_blockquote ) {
                    $sections[] = [ 'type' => 'quote', 'content' => implode( "\n", $quote_lines ) ];
                    $quote_lines = [];
                    $in_blockquote = false;
                }
                if ( ! empty( $current['content'] ) ) {
                    $sections[] = [ 'type' => 'paragraph', 'content' => implode( "\n", $current['content'] ) ];
                    $current['content'] = [];
                }
                continue;
            }

            // Table
            if ( preg_match( '/^\|.*\|$/', $trimmed ) ) {
                // Flush any pending paragraph
                if ( ! empty( $current['content'] ) ) {
                    $sections[] = [ 'type' => 'paragraph', 'content' => implode( "\n", $current['content'] ) ];
                    $current['content'] = [];
                }
                if ( ! $in_table ) {
                    $in_table = true;
                    $table_rows = [];
                }
                // Skip separator rows
                if ( ! preg_match( '/^\|[\s\-:|]+\|$/', $trimmed ) ) {
                    $cells = array_map( 'trim', explode( '|', trim( $trimmed, '| ' ) ) );
                    $table_rows[] = $cells;
                }
                continue;
            } elseif ( $in_table ) {
                $sections[] = [ 'type' => 'table', 'rows' => $table_rows ];
                $table_rows = [];
                $in_table = false;
            }

            // Headings
            if ( preg_match( '/^(#{1,6})\s+(.+)$/', $trimmed, $m ) ) {
                if ( ! empty( $current['content'] ) ) {
                    $sections[] = [ 'type' => 'paragraph', 'content' => implode( "\n", $current['content'] ) ];
                    $current['content'] = [];
                }
                $level = strlen( $m[1] );
                $text = $this->inline_markdown( $m[2] );

                // Detect FAQ section
                if ( preg_match( '/faq|frequently\s*asked/i', $text ) ) {
                    $in_faq = true;
                }

                $sections[] = [ 'type' => 'heading', 'level' => $level, 'content' => $text, 'is_faq' => $in_faq ];
                continue;
            }

            // Image: ![alt](url)
            if ( preg_match( '/^!\[([^\]]*)\]\(([^)]+)\)$/', $trimmed, $m ) ) {
                if ( ! empty( $current['content'] ) ) {
                    $sections[] = [ 'type' => 'paragraph', 'content' => implode( "\n", $current['content'] ) ];
                    $current['content'] = [];
                }
                $sections[] = [ 'type' => 'image', 'alt' => $m[1], 'url' => $m[2] ];
                continue;
            }

            // Blockquote
            if ( preg_match( '/^>\s*(.*)$/', $trimmed, $m ) ) {
                if ( ! empty( $current['content'] ) ) {
                    $sections[] = [ 'type' => 'paragraph', 'content' => implode( "\n", $current['content'] ) ];
                    $current['content'] = [];
                }
                $in_blockquote = true;
                $quote_lines[] = $this->inline_markdown( $m[1] );
                continue;
            }

            // Unordered list
            if ( preg_match( '/^[-*]\s+(.+)$/', $trimmed, $m ) ) {
                if ( ! empty( $current['content'] ) ) {
                    $sections[] = [ 'type' => 'paragraph', 'content' => implode( "\n", $current['content'] ) ];
                    $current['content'] = [];
                }
                $in_list = true;
                $list_type = 'ul';
                $list_items[] = $this->inline_markdown( $m[1] );
                continue;
            }

            // Ordered list
            if ( preg_match( '/^\d+\.\s+(.+)$/', $trimmed, $m ) ) {
                if ( ! empty( $current['content'] ) ) {
                    $sections[] = [ 'type' => 'paragraph', 'content' => implode( "\n", $current['content'] ) ];
                    $current['content'] = [];
                }
                $in_list = true;
                $list_type = 'ol';
                $list_items[] = $this->inline_markdown( $m[1] );
                continue;
            }

            // Horizontal rule
            if ( preg_match( '/^(-{3,}|\*{3,}|_{3,})$/', $trimmed ) ) {
                if ( ! empty( $current['content'] ) ) {
                    $sections[] = [ 'type' => 'paragraph', 'content' => implode( "\n", $current['content'] ) ];
                    $current['content'] = [];
                }
                $sections[] = [ 'type' => 'separator' ];
                continue;
            }

            // Regular text
            $current['content'][] = $trimmed;
        }

        // Flush remaining
        if ( $in_table && ! empty( $table_rows ) ) {
            $sections[] = [ 'type' => 'table', 'rows' => $table_rows ];
        }
        if ( $in_list && ! empty( $list_items ) ) {
            $sections[] = [ 'type' => 'list', 'list_type' => $list_type, 'items' => $list_items ];
        }
        if ( $in_blockquote && ! empty( $quote_lines ) ) {
            $sections[] = [ 'type' => 'quote', 'content' => implode( "\n", $quote_lines ) ];
        }
        if ( ! empty( $current['content'] ) ) {
            $sections[] = [ 'type' => 'paragraph', 'content' => implode( "\n", $current['content'] ) ];
        }

        return $sections;
    }

    /**
     * Format as Gutenberg block markup.
     */
    /**
     * Format as Gutenberg blocks.
     *
     * Uses MINIMAL block attributes to avoid "Block contains unexpected content" errors.
     * Gutenberg is very strict — the JSON in comments must exactly match the HTML attributes.
     * Simpler blocks = fewer validation failures.
     */
    private function format_gutenberg( array $sections, array $options ): string {
        $output = [];
        $accent = $options['accent_color'] ?? '#764ba2';

        foreach ( $sections as $i => $section ) {
            switch ( $section['type'] ) {

                case 'heading':
                    $level = $section['level'];
                    $text = $section['content'];

                    if ( $level === 1 ) {
                        $output[] = '<!-- wp:heading {"level":1} -->';
                        $output[] = "<h1 class=\"wp-block-heading\">{$text}</h1>";
                        $output[] = '<!-- /wp:heading -->';
                    } elseif ( $level === 2 ) {
                        $output[] = '<!-- wp:heading -->';
                        $output[] = "<h2 class=\"wp-block-heading\">{$text}</h2>";
                        $output[] = '<!-- /wp:heading -->';
                    } else {
                        $output[] = "<!-- wp:heading {\"level\":{$level}} -->";
                        $output[] = "<h{$level} class=\"wp-block-heading\">{$text}</h{$level}>";
                        $output[] = '<!-- /wp:heading -->';
                    }
                    break;

                case 'paragraph':
                    $text = $this->inline_markdown( $section['content'] );
                    if ( empty( trim( $text ) ) ) continue 2;

                    if ( preg_match( '/^last\s*updated/i', strip_tags( $text ) ) ) {
                        $output[] = '<!-- wp:paragraph {"fontSize":"small"} -->';
                        $output[] = "<p class=\"has-small-font-size\"><em>{$text}</em></p>";
                        $output[] = '<!-- /wp:paragraph -->';
                    } else {
                        $output[] = '<!-- wp:paragraph -->';
                        $output[] = "<p>{$text}</p>";
                        $output[] = '<!-- /wp:paragraph -->';
                    }
                    break;

                case 'list':
                    $tag = $section['list_type'];
                    $items_html = '';
                    foreach ( $section['items'] as $item ) {
                        $items_html .= "<li>{$item}</li>";
                    }

                    if ( $tag === 'ol' ) {
                        $output[] = '<!-- wp:list {"ordered":true} -->';
                        $output[] = "<ol>{$items_html}</ol>";
                    } else {
                        $output[] = '<!-- wp:list -->';
                        $output[] = "<ul>{$items_html}</ul>";
                    }
                    $output[] = '<!-- /wp:list -->';
                    break;

                case 'quote':
                    $text = $this->inline_markdown( $section['content'] );
                    $output[] = '<!-- wp:quote -->';
                    $output[] = "<blockquote class=\"wp-block-quote\"><p>{$text}</p></blockquote>";
                    $output[] = '<!-- /wp:quote -->';
                    break;

                case 'table':
                    $rows = $section['rows'];
                    if ( empty( $rows ) ) continue 2;

                    $output[] = '<!-- wp:table {"hasFixedLayout":true,"className":"is-style-stripes"} -->';
                    $output[] = '<figure class="wp-block-table is-style-stripes"><table class="has-fixed-layout"><thead><tr>';
                    foreach ( $rows[0] as $cell ) {
                        $output[] = '<th>' . $this->inline_markdown( $cell ) . '</th>';
                    }
                    $output[] = '</tr></thead><tbody>';
                    for ( $r = 1; $r < count( $rows ); $r++ ) {
                        $output[] = '<tr>';
                        foreach ( $rows[ $r ] as $cell ) {
                            $output[] = '<td>' . $this->inline_markdown( $cell ) . '</td>';
                        }
                        $output[] = '</tr>';
                    }
                    $output[] = '</tbody></table></figure>';
                    $output[] = '<!-- /wp:table -->';
                    break;

                case 'separator':
                    $output[] = '<!-- wp:separator -->';
                    $output[] = '<hr class="wp-block-separator has-alpha-channel-opacity"/>';
                    $output[] = '<!-- /wp:separator -->';
                    break;

                case 'image':
                    $alt = esc_attr( $section['alt'] );
                    $url = esc_url( $section['url'] );
                    $output[] = '<!-- wp:image {"align":"center","sizeSlug":"large"} -->';
                    $output[] = "<figure class=\"wp-block-image aligncenter size-large\"><img src=\"{$url}\" alt=\"{$alt}\"/></figure>";
                    $output[] = '<!-- /wp:image -->';
                    break;
            }
        }

        return implode( "\n\n", $output );
    }

    /**
     * Format as hybrid Gutenberg blocks.
     *
     * Uses native wp:heading, wp:paragraph, wp:list, wp:image, wp:separator
     * for standard content (editable in the block editor), and wp:html blocks
     * only for styled elements that need inline CSS (key takeaways, tables,
     * blockquotes).
     */
    public function format_hybrid( array $sections, array $options ): string {
        $output = [];
        $accent = $options['accent_color'] ?? '#764ba2';
        $para_count = 0;
        $more_inserted = false;

        foreach ( $sections as $i => $section ) {
            switch ( $section['type'] ) {

                case 'heading':
                    $level = $section['level'];
                    $text = $section['content'];

                    if ( $level === 2 ) {
                        $output[] = '<!-- wp:heading -->';
                        $output[] = "<h2 class=\"wp-block-heading\">{$text}</h2>";
                        $output[] = '<!-- /wp:heading -->';
                    } elseif ( $level === 1 ) {
                        $output[] = '<!-- wp:heading {"level":1} -->';
                        $output[] = "<h1 class=\"wp-block-heading\">{$text}</h1>";
                        $output[] = '<!-- /wp:heading -->';
                    } else {
                        $output[] = "<!-- wp:heading {\"level\":{$level}} -->";
                        $output[] = "<h{$level} class=\"wp-block-heading\">{$text}</h{$level}>";
                        $output[] = '<!-- /wp:heading -->';
                    }
                    break;

                case 'paragraph':
                    $text = $this->inline_markdown( $section['content'] );
                    if ( empty( trim( $text ) ) ) continue 2;
                    $plain = strip_tags( $text );

                    if ( preg_match( '/^last\s*updated/i', $plain ) ) {
                        $output[] = '<!-- wp:paragraph {"fontSize":"small"} -->';
                        $output[] = "<p class=\"has-small-font-size\"><em>{$text}</em></p>";
                        $output[] = '<!-- /wp:paragraph -->';
                    } elseif ( preg_match( '/^(pro\s*tip|tip)\s*[:—-]/i', $plain ) ) {
                        $html = "<div style=\"background:#eff6ff !important;border-left:4px solid #3b82f6;padding:0.75em 1em;border-radius:0 6px 6px 0;margin:1em 0;color:#1e3a5f !important;line-height:1.7\"><strong>Tip:</strong> {$text}</div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( preg_match( '/^(note|important)\s*[:—-]/i', $plain ) ) {
                        $html = "<div style=\"background:#fffbeb !important;border-left:4px solid #f59e0b;padding:0.75em 1em;border-radius:0 6px 6px 0;margin:1em 0;color:#78350f !important;line-height:1.7\"><strong>Note:</strong> {$text}</div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( preg_match( '/^(warning|caution)\s*[:—-]/i', $plain ) ) {
                        $html = "<div style=\"background:#fef2f2 !important;border-left:4px solid #ef4444;padding:0.75em 1em;border-radius:0 6px 6px 0;margin:1em 0;color:#991b1b !important;line-height:1.7\"><strong>Warning:</strong> {$text}</div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    }
                    // v1.5.14 — Did You Know box: paragraph starts with "Did you know" or "Fun fact"
                    elseif ( preg_match( '/^(did\s*you\s*know|fun\s*fact)\??\s*[:—-]?\s*(.*)$/is', $plain, $dyk_match ) ) {
                        $body_text = $this->inline_markdown( trim( $dyk_match[2] ) ) ?: $text;
                        $html = '<div style="background:#fefce8 !important;border-left:4px solid #eab308;padding:1em 1.25em;border-radius:0 8px 8px 0;margin:1.25em 0;color:#713f12 !important;line-height:1.7">';
                        $html .= '<div style="font-size:0.75em;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#a16207 !important;margin-bottom:0.35em">Did you know?</div>';
                        $html .= '<div>' . $body_text . '</div>';
                        $html .= '</div>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    }
                    // v1.5.14 — Definition box: paragraph starts with **Term**: definition
                    elseif ( preg_match( '/^<strong>([^<]{2,40})<\/strong>\s*[:—-]\s*(.+)$/s', $text, $def_match ) ) {
                        $term = $def_match[1];
                        $body_text = trim( $def_match[2] );
                        $html = '<div style="background:#f8fafc !important;border:1px solid #e2e8f0;border-radius:8px;padding:1em 1.25em;margin:1.25em 0;line-height:1.7">';
                        $html .= '<span style="color:' . $accent . ' !important;font-weight:700;font-size:1.05em">' . $term . '</span>';
                        $html .= '<span style="color:#374151 !important"> &middot; ' . $body_text . '</span>';
                        $html .= '</div>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    }
                    // v1.5.14 — Highlight sentence: entire paragraph is one bold sentence
                    elseif ( preg_match( '/^<strong>([^<].*?)<\/strong>[\s.!?]*$/s', $text, $hl_match ) && strpos( $hl_match[1], '<' ) === false ) {
                        $inner = $hl_match[1];
                        $html = '<div style="border-left:6px solid ' . $accent . ';background:#faf5ff !important;padding:1em 1.5em;margin:1.5em 0;border-radius:0 8px 8px 0;font-size:1.15em;line-height:1.6;color:#1e293b !important;font-weight:600">';
                        $html .= $inner;
                        $html .= '</div>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    }
                    // v1.5.14 — Expert quote: "Quote text" — Name, Title
                    elseif ( preg_match( '/^[\"\x{201C}]([^\"\x{201D}]{20,})[\"\x{201D}]\s*[\x{2014}\x{2013}\-]\s*([A-Z][a-zA-Z\s.\']+?)(?:,\s*(.+?))?[.\s]*$/u', $plain, $q_match ) ) {
                        $quote_text = $this->inline_markdown( trim( $q_match[1] ) );
                        $author = trim( $q_match[2] );
                        $title_part = isset( $q_match[3] ) ? trim( $q_match[3] ) : '';
                        $html = '<blockquote style="border-left:4px solid ' . $accent . ';margin:1.5em 0;padding:1em 1.5em;background:#f9fafb !important;border-radius:0 8px 8px 0;font-style:italic">';
                        $html .= '<p style="margin:0 0 0.5em 0;color:#1e293b !important;font-size:1.1em;line-height:1.6">&ldquo;' . $quote_text . '&rdquo;</p>';
                        $html .= '<footer style="font-style:normal;font-size:0.9em;color:#64748b !important">&mdash; <strong>' . esc_html( $author ) . '</strong>';
                        if ( $title_part ) {
                            $html .= ', ' . esc_html( $title_part );
                        }
                        $html .= '</footer>';
                        $html .= '</blockquote>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    }
                    // v1.5.14 — Stat callout: paragraph contains a prominent statistic
                    elseif ( preg_match( '/(\d{1,3}(?:[.,]\d+)?)\s*%/', $plain, $stat_match )
                             || preg_match( '/\b(\d{1,3})\s+(?:out\s+of|in)\s+(\d{1,4})\b/i', $plain, $stat_match ) ) {
                        $stat_value = $stat_match[0];
                        $html = '<div style="display:flex;gap:1.25em;align-items:center;background:#faf5ff !important;border-left:4px solid ' . $accent . ';padding:1em 1.25em;margin:1.25em 0;border-radius:0 8px 8px 0">';
                        $html .= '<div style="flex-shrink:0;font-size:2em;font-weight:800;color:' . $accent . ' !important;line-height:1;letter-spacing:-0.02em">' . esc_html( $stat_value ) . '</div>';
                        $html .= '<div style="flex:1;color:#374151 !important;line-height:1.65;font-size:0.97em">' . $text . '</div>';
                        $html .= '</div>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    }
                    else {
                        $output[] = '<!-- wp:paragraph -->';
                        $output[] = "<p>{$text}</p>";
                        $output[] = '<!-- /wp:paragraph -->';
                        $para_count++;
                        // Insert wp:more after the 2nd regular paragraph (creates Read More break)
                        if ( $para_count === 2 && ! $more_inserted ) {
                            $output[] = "\n<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->";
                            $more_inserted = true;
                        }
                    }
                    break;

                case 'list':
                    $tag = $section['list_type'];

                    // Check if this follows a Key Takeaways heading — use styled wp:html
                    $prev_heading = '';
                    for ( $j = $i - 1; $j >= 0; $j-- ) {
                        if ( $sections[ $j ]['type'] === 'heading' ) {
                            $prev_heading = $sections[ $j ]['content'];
                            break;
                        }
                        if ( $sections[ $j ]['type'] === 'paragraph' && ! empty( trim( $sections[ $j ]['content'] ) ) ) break;
                    }

                    // v1.5.14 — widened regex catches synonyms the AI uses naturally
                    $is_takeaways = (bool) preg_match( '/key\s*takeaway|key\s*insight|main\s*point|at\s*a\s*glance|tl;?dr|what\s*to\s*know|the\s*bottom\s*line/i', $prev_heading );
                    $prev_context = strtolower( $prev_heading );
                    $is_pros = ( preg_match( '/\bpros?\b|advantage|strength|benefit|upside|highlight/i', $prev_context ) && ! preg_match( '/cons/i', $prev_context ) );
                    $is_cons = (bool) preg_match( '/\bcons?\b|disadvantage|weakness|drawback|downside|limitation|trade-?off/i', $prev_context );
                    $is_ingredients = (bool) preg_match( '/ingredient|you.ll need|what you need|supplies|materials|tools|prerequisite/i', $prev_context );

                    // v1.5.14 — HowTo step boxes: when content_type is how_to AND
                    // the list is ordered AND it's not already classified as
                    // takeaways/pros/cons/ingredients, render as numbered step cards.
                    $content_type = $options['content_type'] ?? '';
                    $is_howto_steps = ( $content_type === 'how_to' && $tag === 'ol' && ! $is_takeaways && ! $is_pros && ! $is_cons && ! $is_ingredients );

                    if ( $is_takeaways ) {
                        $html = '<div style="border-left:4px solid ' . $accent . ';background:linear-gradient(135deg,#f8f9ff 0%,#f0f0ff 100%);padding:1.25em 1.5em;border-radius:0 8px 8px 0;margin:1.5em 0">';
                        $html .= "<{$tag} style=\"line-height:1.8;padding-left:1.5em;margin:0;color:#374151 !important\">";
                        foreach ( $section['items'] as $item ) {
                            $html .= "<li style=\"margin-bottom:0.5em;color:#374151 !important\">{$item}</li>";
                        }
                        $html .= "</{$tag}></div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( $is_pros ) {
                        $html = '<div style="background:#f0fdf4 !important;border:1px solid #bbf7d0;border-radius:8px;padding:1em 1.5em;margin:1em 0">';
                        $html .= "<{$tag} style=\"line-height:1.8;padding-left:1.5em;margin:0;color:#374151 !important\">";
                        foreach ( $section['items'] as $item ) {
                            $html .= "<li style=\"margin-bottom:0.5em;color:#166534 !important\">{$item}</li>";
                        }
                        $html .= "</{$tag}></div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( $is_cons ) {
                        $html = '<div style="background:#fef2f2 !important;border:1px solid #fecaca;border-radius:8px;padding:1em 1.5em;margin:1em 0">';
                        $html .= "<{$tag} style=\"line-height:1.8;padding-left:1.5em;margin:0;color:#374151 !important\">";
                        foreach ( $section['items'] as $item ) {
                            $html .= "<li style=\"margin-bottom:0.5em;color:#991b1b !important\">{$item}</li>";
                        }
                        $html .= "</{$tag}></div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( $is_ingredients ) {
                        $html = '<div style="background:#fffbeb !important;border:1px solid #fde68a;border-radius:8px;padding:1em 1.5em;margin:1em 0">';
                        $html .= "<{$tag} style=\"line-height:1.8;padding-left:1.5em;margin:0;color:#374151 !important\">";
                        foreach ( $section['items'] as $item ) {
                            $html .= "<li style=\"margin-bottom:0.5em;color:#92400e !important\">{$item}</li>";
                        }
                        $html .= "</{$tag}></div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( $is_howto_steps ) {
                        // v1.5.14 — HowTo step boxes: each step gets a numbered circle badge
                        // No icons, no SVG — pure CSS circles with the step number inside.
                        $html = '<div style="margin:1.5em 0;display:flex;flex-direction:column;gap:0.75em">';
                        $step_num = 0;
                        foreach ( $section['items'] as $item ) {
                            $step_num++;
                            $html .= '<div style="display:flex;gap:1em;align-items:flex-start;background:#f8fafc !important;border:1px solid #e2e8f0;border-radius:10px;padding:1em 1.25em">';
                            $html .= '<div style="flex-shrink:0;width:36px;height:36px;border-radius:50%;background:' . $accent . ';color:#ffffff !important;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.95em;line-height:1">' . $step_num . '</div>';
                            $html .= '<div style="flex:1;line-height:1.7;color:#374151 !important;padding-top:0.35em">' . $item . '</div>';
                            $html .= '</div>';
                        }
                        $html .= '</div>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } else {
                        // Standard list — native Gutenberg block
                        $items_html = '';
                        foreach ( $section['items'] as $item ) {
                            $items_html .= "<li>{$item}</li>";
                        }
                        if ( $tag === 'ol' ) {
                            $output[] = '<!-- wp:list {"ordered":true} -->';
                            $output[] = "<ol>{$items_html}</ol>";
                        } else {
                            $output[] = '<!-- wp:list -->';
                            $output[] = "<ul>{$items_html}</ul>";
                        }
                        $output[] = '<!-- /wp:list -->';
                    }
                    break;

                case 'quote':
                    // v1.5.17 — Social media citation detection
                    // Pattern: > [platform @handle] quote text [optional newline + url]
                    // Rendered as a dedicated wp:html block with a review-before-publish
                    // warning so the user can visually spot and delete social citations
                    // before publishing. Social content is easily AI-faked and needs
                    // human verification.
                    $raw = $section['content'];
                    if ( preg_match( '/^\s*\[\s*(bluesky|mastodon|reddit|hacker\s*news|hn|dev\.?to|lemmy|twitter|x)\s+@?([A-Za-z0-9_.@\/\-]+)\s*\]\s*(.+?)(?:\s*\n\s*(https?:\/\/\S+))?\s*$/is', $raw, $sm ) ) {
                        $platform_raw = strtolower( trim( $sm[1] ) );
                        $platform_map = [
                            'bluesky'      => 'Bluesky',
                            'mastodon'     => 'Mastodon',
                            'reddit'       => 'Reddit',
                            'hacker news'  => 'Hacker News',
                            'hackernews'   => 'Hacker News',
                            'hn'           => 'Hacker News',
                            'dev.to'       => 'DEV.to',
                            'devto'        => 'DEV.to',
                            'lemmy'        => 'Lemmy',
                            'twitter'      => 'X (Twitter)',
                            'x'            => 'X (Twitter)',
                        ];
                        $platform = $platform_map[ $platform_raw ] ?? ucfirst( $platform_raw );
                        $handle   = trim( $sm[2] );
                        $quote_md = trim( $sm[3] );
                        $quote    = $this->inline_markdown( $quote_md );
                        $src_url  = isset( $sm[4] ) ? trim( $sm[4] ) : '';

                        $html  = '<div style="background:#f1f5f9 !important;border:1px solid #cbd5e1;border-left:4px solid #64748b;border-radius:0 8px 8px 0;padding:1em 1.25em;margin:1.5em 0">';
                        $html .= '<div style="font-size:0.7em;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#dc2626 !important;margin-bottom:0.6em">Social media citation &mdash; review before publishing</div>';
                        $html .= '<p style="margin:0 0 0.5em 0;color:#1e293b !important;line-height:1.65;font-size:0.97em">&ldquo;' . $quote . '&rdquo;</p>';
                        $html .= '<div style="font-size:0.85em;color:#64748b !important;font-style:normal">&mdash; ';
                        if ( $src_url && preg_match( '/^https?:\/\//', $src_url ) ) {
                            $html .= '<a href="' . esc_url( $src_url ) . '" target="_blank" rel="noopener nofollow" style="color:#475569;text-decoration:underline">@' . esc_html( $handle ) . '</a>';
                        } else {
                            $html .= '@' . esc_html( $handle );
                        }
                        $html .= ' on ' . esc_html( $platform );
                        $html .= '</div>';
                        $html .= '<div style="font-size:0.72em;color:#94a3b8 !important;margin-top:0.75em;padding-top:0.5em;border-top:1px dashed #cbd5e1">Social content is user-generated and may be unreliable or AI-generated. Verify the claim before publishing, or delete this block.</div>';
                        $html .= '</div>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                        break;
                    }

                    // Default: styled blockquote (expert quote / pull quote) — wp:html
                    $text = $this->inline_markdown( $section['content'] );
                    $html = "<blockquote style=\"border-left:4px solid {$accent};margin:1.5em 0;padding:1em 1.5em;background:#f9fafb;border-radius:0 8px 8px 0;font-style:italic;font-size:1.05em;color:#4b5563 !important;line-height:1.7\"><p style=\"margin:0;color:#4b5563 !important\">{$text}</p></blockquote>";
                    $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    break;

                case 'table':
                    // Styled table — wp:html to preserve styling
                    $rows = $section['rows'];
                    if ( empty( $rows ) ) continue 2;

                    $html = '<div style="overflow-x:auto;margin:1.5em 0">';
                    $html .= '<table style="width:100%;border-collapse:collapse;font-size:0.95em;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1)">';
                    $html .= '<thead><tr>';
                    foreach ( $rows[0] as $cell ) {
                        $html .= '<th style="background:' . $accent . ';color:#ffffff;padding:0.75em 1em;text-align:left;font-weight:600;font-size:0.9em;text-transform:uppercase;letter-spacing:0.05em">' . $this->inline_markdown( $cell ) . '</th>';
                    }
                    $html .= '</tr></thead><tbody>';
                    for ( $r = 1; $r < count( $rows ); $r++ ) {
                        $bg = ( $r % 2 === 0 ) ? 'background:#f9fafb;' : '';
                        $html .= '<tr>';
                        foreach ( $rows[ $r ] as $cell ) {
                            $html .= '<td style="padding:0.75em 1em;border-bottom:1px solid #e5e7eb;color:#374151;' . $bg . '">' . $this->inline_markdown( $cell ) . '</td>';
                        }
                        $html .= '</tr>';
                    }
                    $html .= '</tbody></table></div>';
                    $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    break;

                case 'separator':
                    $output[] = '<!-- wp:separator -->';
                    $output[] = '<hr class="wp-block-separator has-alpha-channel-opacity"/>';
                    $output[] = '<!-- /wp:separator -->';
                    break;

                case 'image':
                    $alt = esc_attr( $section['alt'] );
                    $url = esc_url( $section['url'] );
                    $output[] = '<!-- wp:image {"align":"center","sizeSlug":"large"} -->';
                    $output[] = "<figure class=\"wp-block-image aligncenter size-large\"><img src=\"{$url}\" alt=\"{$alt}\"/></figure>";
                    $output[] = '<!-- /wp:image -->';
                    break;
            }
        }

        return implode( "\n\n", $output );
    }

    /**
     * Format as styled HTML with inline styles.
     * Reference: seo-guidelines/article_design.md
     *
     * All styles are inline for maximum compatibility across themes,
     * Classic block editor, email, and export contexts.
     */
    private function format_classic( array $sections, array $options ): string {
        $output = [];
        $accent = $options['accent_color'] ?? '#764ba2';
        $uid = 'sb-' . substr( md5( uniqid() ), 0, 6 );

        // Self-contained scoped CSS — no external dependencies
        // Use !important on critical properties to override any theme CSS
        //
        // Typography spec (article_design.md §3, SEO-GEO-AI-GUIDELINES.md §12B):
        // - System font stacks (ui-serif for headings, ui-sans-serif for body)
        // - clamp() fluid heading sizes
        // - text-wrap: balance on headings, pretty on paragraphs
        // - max-width 65ch on body copy
        $sans = "ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif";
        $serif = "ui-serif,Georgia,'Times New Roman',serif";

        $css = "<style>.{$uid}{font-family:{$sans};color:#1f2937 !important;line-height:1.7;background:#fff !important;padding:2em;border-radius:12px;max-width:100%}";
        $css .= ".{$uid} *{color:inherit}";
        $css .= ".{$uid} h1,.{$uid} h2,.{$uid} h3,.{$uid} h4{font-family:{$serif};color:#111827 !important;text-wrap:balance}";
        $css .= ".{$uid} h1{font-size:clamp(1.8em,4vw,2.4em);font-weight:800;line-height:1.2;margin:0 0 0.5em;text-transform:capitalize}";
        $css .= ".{$uid} h2{font-size:clamp(1.3em,3vw,1.6em);font-weight:700;line-height:1.3;color:{$accent} !important;margin:2em 0 0.75em;padding-bottom:0.4em;border-bottom:2px solid {$accent}22}";
        $css .= ".{$uid} h3{font-size:1.15em;font-weight:600;line-height:1.4;margin:1.5em 0 0.5em;color:#374151 !important}";
        $css .= ".{$uid} p{line-height:1.75;margin:0 0 1.25em;font-size:1.05em;color:#374151 !important;text-wrap:pretty;max-width:65ch}";
        // Drop cap on first paragraph after an H2
        $css .= ".{$uid} h2+p::first-letter,.{$uid} h2+div+p::first-letter{float:left;font-size:3.2em;line-height:0.8;font-weight:700;color:{$accent} !important;margin:0.05em 0.1em 0 0}";
        $css .= ".{$uid} ul,.{$uid} ol{line-height:1.8;padding-left:1.5em;margin:1em 0;color:#374151 !important}";
        $css .= ".{$uid} li{margin-bottom:0.4em;color:#374151 !important}";
        $css .= ".{$uid} ul li::marker{color:{$accent} !important;font-weight:700}";
        $css .= ".{$uid} blockquote{border-left:4px solid {$accent};margin:1.5em 0;padding:1em 1.5em;background:#f9fafb !important;border-radius:0 8px 8px 0;font-style:italic;font-size:1.05em;color:#4b5563 !important;line-height:1.7}";
        $css .= ".{$uid} blockquote p{margin:0;color:#4b5563 !important}";
        $css .= ".{$uid} table{width:100%;border-collapse:collapse;font-size:0.95em;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);margin:1.5em 0}";
        $css .= ".{$uid} thead th{background:{$accent} !important;color:#fff !important;padding:0.75em 1em;text-align:left;font-weight:600;font-size:0.9em;letter-spacing:0.03em}";
        $css .= ".{$uid} tbody td{padding:0.75em 1em;border-bottom:1px solid #e5e7eb;color:#374151 !important}";
        $css .= ".{$uid} tbody tr:nth-child(even){background:#f9fafb}";
        $css .= ".{$uid} img{max-width:100%;height:auto;border-radius:8px;margin:1.5em auto;display:block}";
        $css .= ".{$uid} hr{border:none;border-top:2px solid #e5e7eb;margin:2.5em 0}";
        $css .= ".{$uid} a{color:{$accent} !important;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px}";
        $css .= ".{$uid} a:hover{text-decoration-thickness:2px}";
        $css .= ".{$uid} code{background:#f3f4f6;padding:0.15em 0.4em;border-radius:4px;font-size:0.9em;color:#374151 !important}";
        $css .= ".{$uid} pre{background:#1f2937 !important;color:#e5e7eb !important;padding:1.25em;border-radius:8px;overflow-x:auto;font-size:0.9em;line-height:1.6;margin:1.5em 0}";
        // Callout boxes
        $css .= ".{$uid} .sb-callout{padding:1em 1.25em;border-radius:0 8px 8px 0;margin:1.5em 0;line-height:1.7;font-size:0.95em}";
        $css .= ".{$uid} .sb-callout-tip{background:#eff6ff !important;border-left:4px solid #3b82f6;color:#1e3a5f !important}";
        $css .= ".{$uid} .sb-callout-note{background:#fffbeb !important;border-left:4px solid #f59e0b;color:#78350f !important}";
        $css .= ".{$uid} .sb-callout-warn{background:#fef2f2 !important;border-left:4px solid #ef4444;color:#991b1b !important}";
        // Key takeaways box
        $css .= ".{$uid} .sb-takeaways{border-left:4px solid {$accent};background:linear-gradient(135deg,#f8f9ff 0%,#f0f0ff 100%) !important;padding:1.25em 1.5em;border-radius:0 8px 8px 0;margin:1.5em 0}";
        // Pros/cons
        $css .= ".{$uid} .sb-pros{background:#f0fdf4 !important;border:1px solid #bbf7d0;border-radius:8px;padding:1em 1.5em;margin:1em 0}";
        $css .= ".{$uid} .sb-cons{background:#fef2f2 !important;border:1px solid #fecaca;border-radius:8px;padding:1em 1.5em;margin:1em 0}";
        $css .= ".{$uid} .sb-ingredients{background:#fffbeb !important;border:1px solid #fde68a;border-radius:8px;padding:1em 1.5em;margin:1em 0}";
        // Dark mode
        $css .= "@media(prefers-color-scheme:dark){.{$uid}{color:#e5e7eb !important;background:#111827 !important}";
        $css .= ".{$uid} *{color:#d1d5db}";
        $css .= ".{$uid} h1,.{$uid} h2,.{$uid} h3{color:#f3f4f6 !important}";
        $css .= ".{$uid} h2{border-bottom-color:{$accent}44;color:{$accent} !important}";
        $css .= ".{$uid} p,.{$uid} li{color:#d1d5db !important}";
        $css .= ".{$uid} blockquote{background:#1f2937 !important;color:#9ca3af !important}";
        $css .= ".{$uid} .sb-takeaways{background:linear-gradient(135deg,#1f2937 0%,#1e293b 100%) !important}";
        $css .= ".{$uid} .sb-pros{background:#052e16 !important;border-color:#166534}";
        $css .= ".{$uid} .sb-cons{background:#450a0a !important;border-color:#991b1b}";
        $css .= ".{$uid} .sb-callout-tip{background:#172554 !important;border-color:#1d4ed8;color:#93c5fd !important}";
        $css .= ".{$uid} .sb-callout-note{background:#451a03 !important;border-color:#d97706;color:#fcd34d !important}";
        $css .= ".{$uid} .sb-callout-warn{background:#450a0a !important;border-color:#dc2626;color:#fca5a5 !important}";
        $css .= ".{$uid} tbody td{border-bottom-color:#374151;color:#d1d5db !important}";
        $css .= ".{$uid} tbody tr:nth-child(even){background:#1f293780}";
        $css .= ".{$uid} thead th{background:{$accent}cc !important}";
        $css .= ".{$uid} code{background:#374151 !important;color:#e5e7eb !important}";
        $css .= ".{$uid} hr{border-top-color:#374151}";
        $css .= ".{$uid} a{color:#93c5fd !important}}";
        $css .= "</style>";

        $output[] = $css;
        $output[] = "<div class=\"{$uid}\">";

        foreach ( $sections as $i => $section ) {
            switch ( $section['type'] ) {

                case 'heading':
                    $level = $section['level'];
                    $text = $section['content'];
                    $output[] = "<h{$level}>{$text}</h{$level}>";
                    break;

                case 'paragraph':
                    $text = $this->inline_markdown( $section['content'] );
                    if ( empty( trim( $text ) ) ) continue 2;
                    $plain = strip_tags( $text );

                    if ( preg_match( '/^last\s*updated/i', $plain ) ) {
                        $output[] = "<p style=\"color:#6b7280;font-size:0.85em;font-style:italic;margin-bottom:0.5em\">{$text}</p>";
                    } elseif ( preg_match( '/^(pro\s*tip|tip)\s*[:—-]/i', $plain ) ) {
                        $output[] = "<div class=\"sb-callout sb-callout-tip\"><strong>Tip:</strong> {$text}</div>";
                    } elseif ( preg_match( '/^(note|important)\s*[:—-]/i', $plain ) ) {
                        $output[] = "<div class=\"sb-callout sb-callout-note\"><strong>Note:</strong> {$text}</div>";
                    } elseif ( preg_match( '/^(warning|caution)\s*[:—-]/i', $plain ) ) {
                        $output[] = "<div class=\"sb-callout sb-callout-warn\"><strong>Warning:</strong> {$text}</div>";
                    } else {
                        $output[] = "<p>{$text}</p>";
                    }
                    break;

                case 'list':
                    $tag = $section['list_type'];

                    // Detect context from preceding heading OR paragraph
                    $prev_context = '';
                    for ( $j = $i - 1; $j >= 0; $j-- ) {
                        if ( $sections[ $j ]['type'] === 'heading' ) {
                            $prev_context = strtolower( $sections[ $j ]['content'] );
                            break;
                        }
                        if ( $sections[ $j ]['type'] === 'paragraph' && ! empty( trim( $sections[ $j ]['content'] ) ) ) {
                            $prev_context = strtolower( strip_tags( $sections[ $j ]['content'] ) );
                            break;
                        }
                    }
                    // Also check first item for "Pro:" / "Con:" patterns
                    $first_item = strtolower( $section['items'][0] ?? '' );

                    $is_takeaways = preg_match( '/key\s*takeaway/i', $prev_context );
                    $is_pros = ( preg_match( '/\bpros?\b|advantage|strength|benefit/i', $prev_context ) && ! preg_match( '/cons/i', $prev_context ) ) || preg_match( '/^\s*pros?\s*[:—-]/i', $prev_context );
                    $is_cons = preg_match( '/\bcons?\b|disadvantage|weakness|drawback/i', $prev_context ) || preg_match( '/^\s*cons?\s*[:—-]/i', $prev_context );
                    $is_ingredients = preg_match( '/ingredient|you.ll need|what you need|supplies|materials/i', $prev_context );

                    // Wrapper class
                    $wrapper = '';
                    if ( $is_takeaways ) $wrapper = 'sb-takeaways';
                    elseif ( $is_pros ) $wrapper = 'sb-pros';
                    elseif ( $is_cons ) $wrapper = 'sb-cons';
                    elseif ( $is_ingredients ) $wrapper = 'sb-ingredients';

                    if ( $wrapper ) $output[] = "<div class=\"{$wrapper}\">";

                    $output[] = "<{$tag}>";
                    foreach ( $section['items'] as $item ) {
                        $output[] = "<li>{$item}</li>";
                    }
                    $output[] = "</{$tag}>";

                    if ( $wrapper ) $output[] = '</div>';
                    break;

                case 'quote':
                    $text = $this->inline_markdown( $section['content'] );
                    $output[] = "<blockquote><p>{$text}</p></blockquote>";
                    break;

                case 'table':
                    $rows = $section['rows'];
                    if ( empty( $rows ) ) continue 2;

                    $output[] = '<div style="overflow-x:auto;margin:1.5em 0">';
                    $output[] = '<table><thead><tr>';
                    foreach ( $rows[0] as $cell ) {
                        $output[] = '<th>' . $this->inline_markdown( $cell ) . '</th>';
                    }
                    $output[] = '</tr></thead><tbody>';
                    for ( $r = 1; $r < count( $rows ); $r++ ) {
                        $output[] = '<tr>';
                        foreach ( $rows[ $r ] as $cell ) {
                            $output[] = '<td>' . $this->inline_markdown( $cell ) . '</td>';
                        }
                        $output[] = '</tr>';
                    }
                    $output[] = '</tbody></table></div>';
                    break;

                case 'separator':
                    $output[] = '<hr />';
                    break;

                case 'image':
                    $alt = esc_attr( $section['alt'] );
                    $url = esc_url( $section['url'] );
                    $output[] = "<figure><img src=\"{$url}\" alt=\"{$alt}\" loading=\"lazy\" decoding=\"async\" style=\"aspect-ratio:16/9;object-fit:cover\" /></figure>";
                    break;
            }
        }

        $output[] = '</div>';

        return implode( "\n", $output );
    }

    /**
     * Convert inline markdown (bold, italic, links) to HTML.
     */
    private function inline_markdown( string $text ): string {
        // Bold + italic
        $text = preg_replace( '/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text );
        // Bold
        $text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
        // Italic
        $text = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $text );
        // Links — negative lookbehind for `!` so image markdown `![alt](url)`
        // is never rewritten into `<a>`. Images are handled by the image
        // section type in parse_markdown() / format_hybrid().
        //
        // External links get rel="noopener nofollow" target="_blank" per
        // SEO-GEO-AI-GUIDELINES §11 (Internal Linking Rules) and article_design.md.
        // Internal links (same host as the site) keep bare <a> tags.
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $text = preg_replace_callback(
            '/(?<!!)\[([^\]]+)\]\(([^)]+)\)/',
            function ( $m ) use ( $site_host ) {
                $anchor = $m[1];
                $url    = $m[2];
                $host   = wp_parse_url( $url, PHP_URL_HOST );
                if ( $host && $host !== $site_host ) {
                    return sprintf(
                        '<a href="%s" target="_blank" rel="noopener nofollow">%s</a>',
                        esc_url( $url ),
                        $anchor
                    );
                }
                return sprintf( '<a href="%s">%s</a>', esc_url( $url ), $anchor );
            },
            $text
        );
        // Inline code
        $text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

        return $text;
    }
}
