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
        // v1.5.82 — pre-process: split inline Unicode bullets into separate lines
        // and convert to standard markdown markers. AI models output:
        //   "• item1 • item2 • item3" (all on one line)
        //   "• item1\n• item2" (line-starting Unicode bullets)
        // Both need to become "- item1\n- item2\n- item3"
        $markdown = preg_replace( '/([^\n])[ \t]+[•●◦▪▸►][ \t]+/u', "$1\n- ", $markdown );
        $markdown = preg_replace( '/^[ \t]*[•●◦▪▸►][ \t]*/mu', '- ', $markdown );
        // v1.5.103 — Convert emoji bullets (✅ ✓ 📌 🔍 etc.) to - markers
        $markdown = preg_replace( '/^[ \t]*[\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{25A0}-\x{27BF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{1F000}-\x{1FFFF}]+[ \t]+/mu', '- ', $markdown );
        // v1.5.103 — Clean mangled emoji (?? at line starts)
        $markdown = preg_replace( '/^[ \t]*\?{2,4}[ \t]+(?=[A-Z])/m', '- ', $markdown );
        // v1.5.103 — Strip ALL remaining emoji (article_design.md: "No emoji in article body")
        $markdown = preg_replace( '/[\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{25A0}-\x{27BF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{1F000}-\x{1FFFF}]+/u', '', $markdown );
        // v1.5.103 — Convert em-dashes (—) and en-dashes (–) to short dashes (-)
        $markdown = str_replace( [ '—', '–' ], '-', $markdown );
        // Also convert 4-space indented text to list items (AI rewrite artefact)
        $markdown = preg_replace( '/^[ \t]{4,}(?!```)([\w"\'(].+)$/m', '- $1', $markdown );

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

            // Unordered list — v1.5.82: also recognise Unicode bullets (•●◦▪▸►)
            // and + marker. AI models frequently output • instead of - for lists.
            if ( preg_match( '/^[-*+•●◦▪▸►]\s+(.+)$/u', $trimmed, $m ) ) {
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
        $in_recipe_card = false; // v1.5.120 — track recipe card wrapper state
        // v1.5.20 — cap stat callouts at 3 per article so percent-heavy
        // articles don't end up with 8+ visual-noise stat cards
        $stat_count = 0;

        foreach ( $sections as $i => $section ) {
            switch ( $section['type'] ) {

                case 'heading':
                    $level = $section['level'];
                    $text = $section['content'];

                    // v1.5.64 — suppress the H2 "References" heading because
                    // the next list section will emit a styled wp:html block
                    // with its own "References" eyebrow. Without this suppress,
                    // the output would show two References headers stacked.
                    if ( $level === 2 && preg_match( '/^(references|sources|bibliography|further\s*reading|citations)\s*$/i', $text ) ) {
                        // Check next section — if it's an ordered list, the
                        // list handler will emit the styled References block.
                        // Skip emitting the H2 so we don't double up.
                        $next = $sections[ $i + 1 ] ?? null;
                        if ( $next && $next['type'] === 'list' && ( $next['list_type'] ?? '' ) === 'ol' ) {
                            break; // skip this heading entirely
                        }
                    }

                    // v1.5.122 — Recipe Card styling ONLY for recipe content type.
                    // Yellow background cards only wrap recipe sections in recipe articles.
                    // Other article types never get yellow recipe cards.
                    $is_recipe_heading = false;
                    if ( ( $options['content_type'] ?? '' ) === 'recipe' ) {
                        // In recipe articles, any H2 that's NOT a generic section is likely a recipe
                        $is_recipe_heading = ! preg_match( '/^(key\s*takeaway|why\s*this|quick\s*comparison|what\s*ingredient|pros|cons|faq|frequently|reference|safety)/i', $text );
                    }

                    // Close previous recipe card if open
                    if ( ! empty( $in_recipe_card ) && $level === 2 ) {
                        $output[] = "<!-- wp:html -->\n</div>\n<!-- /wp:html -->";
                        $in_recipe_card = false;
                    }

                    // Open new recipe card
                    if ( $is_recipe_heading && $level === 2 ) {
                        $output[] = "<!-- wp:html -->\n<div style=\"background:linear-gradient(135deg, #fefce8 0%, #fef3c7 100%) !important;border:2px solid #fde68a;border-radius:16px;padding:1.5em 2em;margin:1.5em 0;box-shadow:0 2px 8px rgba(251,191,36,0.1)\">\n<!-- /wp:html -->";
                        $in_recipe_card = true;
                    }

                    // v1.5.18 — apply accent color to H2 headings
                    if ( $level === 2 ) {
                        $hex = $accent;
                        $output[] = '<!-- wp:heading {"style":{"color":{"text":"' . esc_attr( $hex ) . '"}}} -->';
                        $output[] = '<h2 class="wp-block-heading has-text-color" style="color:' . esc_attr( $hex ) . '">' . $text . '</h2>';
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
                    // v1.5.25 — strip the "Tip:" / "Note:" / "Warning:" prefix from the body
                    // before injecting it next to the bold label, otherwise the rendered output
                    // shows "Note: Note: ..." (the formatter's label PLUS the AI's literal prefix).
                    // We match against $section['content'] (raw markdown) so inline links survive
                    // the re-render via inline_markdown().
                    } elseif ( preg_match( '/^(?:\*\*)?(pro\s*tip|tip)(?:\*\*)?\s*[:—-]\s*(.*)$/is', $section['content'], $tip_match ) ) {
                        $body_text = $this->inline_markdown( trim( $tip_match[2] ) );
                        if ( empty( trim( $body_text ) ) ) continue 2;
                        $icon = $this->sb_icon( 'tip' );
                        $html = "<div style=\"background:#eff6ff !important;border-left:4px solid #3b82f6;padding:0.75em 1em;border-radius:0 6px 6px 0;margin:1em 0;color:#1e3a5f !important;line-height:1.7\">{$icon}<strong>Tip:</strong> {$body_text}</div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( preg_match( '/^(?:\*\*)?(note|important)(?:\*\*)?\s*[:—-]\s*(.*)$/is', $section['content'], $note_match ) ) {
                        $body_text = $this->inline_markdown( trim( $note_match[2] ) );
                        if ( empty( trim( $body_text ) ) ) continue 2;
                        $icon = $this->sb_icon( 'note' );
                        $html = "<div style=\"background:#fffbeb !important;border-left:4px solid #f59e0b;padding:0.75em 1em;border-radius:0 6px 6px 0;margin:1em 0;color:#78350f !important;line-height:1.7\">{$icon}<strong>Note:</strong> {$body_text}</div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( preg_match( '/^(?:\*\*)?(warning|caution)(?:\*\*)?\s*[:—-]\s*(.*)$/is', $section['content'], $warn_match ) ) {
                        $body_text = $this->inline_markdown( trim( $warn_match[2] ) );
                        if ( empty( trim( $body_text ) ) ) continue 2;
                        $icon = $this->sb_icon( 'warning' );
                        $html = "<div style=\"background:#fef2f2 !important;border-left:4px solid #ef4444;padding:0.75em 1em;border-radius:0 6px 6px 0;margin:1em 0;color:#991b1b !important;line-height:1.7\">{$icon}<strong>Warning:</strong> {$body_text}</div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    }
                    // v1.5.14 — Did You Know box: paragraph starts with "Did you know" or "Fun fact"
                    elseif ( preg_match( '/^(did\s*you\s*know|fun\s*fact)\??\s*[:—-]?\s*(.*)$/is', $plain, $dyk_match ) ) {
                        $body_text = $this->inline_markdown( trim( $dyk_match[2] ) ) ?: $text;
                        $icon = $this->sb_icon( 'didyouknow' );
                        $html = '<div style="background:#fefce8 !important;border-left:4px solid #eab308;padding:1em 1.25em;border-radius:0 8px 8px 0;margin:1.25em 0;color:#713f12 !important;line-height:1.7">';
                        $html .= '<div style="font-size:0.75em;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#a16207 !important;margin-bottom:0.35em;display:flex;align-items:center">' . $icon . 'Did you know?</div>';
                        $html .= '<div>' . $body_text . '</div>';
                        $html .= '</div>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    }
                    // v1.5.14 — Definition box: paragraph starts with **Term**: definition
                    elseif ( preg_match( '/^<strong>([^<]{2,40})<\/strong>\s*[:—-]\s*(.+)$/s', $text, $def_match ) ) {
                        $term = $def_match[1];
                        $body_text = trim( $def_match[2] );
                        $icon = $this->sb_icon( 'definition' );
                        $html = '<div style="background:#f8fafc !important;border:1px solid #e2e8f0;border-radius:8px;padding:1em 1.25em;margin:1.25em 0;line-height:1.7;color:' . $accent . '">';
                        $html .= $icon;
                        $html .= '<span style="color:' . $accent . ' !important;font-weight:700;font-size:1.05em">' . $term . '</span>';
                        $html .= '<span style="color:#374151 !important"> &middot; ' . $body_text . '</span>';
                        $html .= '</div>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    }
                    // v1.5.14 — Highlight sentence: entire paragraph is one bold sentence
                    elseif ( preg_match( '/^<strong>([^<].*?)<\/strong>[\s.!?]*$/s', $text, $hl_match ) && strpos( $hl_match[1], '<' ) === false ) {
                        $inner = $hl_match[1];
                        $icon = $this->sb_icon( 'highlight' );
                        $html = '<div style="border-left:6px solid ' . $accent . ';background:#faf5ff !important;padding:1em 1.5em;margin:1.5em 0;border-radius:0 8px 8px 0;font-size:1.15em;line-height:1.6;color:' . $accent . ' !important;font-weight:600">';
                        $html .= $icon;
                        $html .= '<span style="color:#1e293b">' . $inner . '</span>';
                        $html .= '</div>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    }
                    // v1.5.14 — Expert quote: "Quote text" — Name, Title
                    elseif ( preg_match( '/^[\"\x{201C}]([^\"\x{201D}]{20,})[\"\x{201D}]\s*[\x{2014}\x{2013}\-]\s*([A-Z][a-zA-Z\s.\']+?)(?:,\s*(.+?))?[.\s]*$/u', $plain, $q_match ) ) {
                        $quote_text = $this->inline_markdown( trim( $q_match[1] ) );
                        $author = trim( $q_match[2] );
                        $title_part = isset( $q_match[3] ) ? trim( $q_match[3] ) : '';
                        $icon = $this->sb_icon( 'quote' );
                        $html = '<blockquote style="border-left:4px solid ' . $accent . ';margin:1.5em 0;padding:1em 1.5em;background:#f9fafb !important;border-radius:0 8px 8px 0;font-style:italic;color:' . $accent . '">';
                        $html .= '<div style="margin-bottom:0.4em">' . $icon . '</div>';
                        $html .= '<p style="margin:0 0 0.5em 0;color:#1e293b !important;font-size:1.1em;line-height:1.6">&ldquo;' . $quote_text . '&rdquo;</p>';
                        $html .= '<footer style="font-style:normal;font-size:0.9em;color:#64748b !important">&mdash; <strong>' . esc_html( $author ) . '</strong>';
                        if ( $title_part ) {
                            $html .= ', ' . esc_html( $title_part );
                        }
                        $html .= '</footer>';
                        $html .= '</blockquote>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    }
                    // v1.5.14 — Stat callout: paragraph LEADS with a prominent statistic
                    // v1.5.20 — Tightened: stat must appear in the first 60 chars of the
                    // paragraph (so it's the lead, not buried mid-prose) AND we cap at
                    // 3 stat callouts per article so percent-heavy articles don't get
                    // visually spammed with 8+ pulled-out cards. Articles with lots of
                    // numbers now get 3 prominent stat cards + the rest stay as prose.
                    // v1.5.48 — regex bugfix. The old greedy `.{0,60}` consumed
                    // the first digit of the number before the `%` sign, so
                    // "approximately 65% of households" produced a badge reading
                    // "5%" instead of "65%". Switched to `[^0-9]{0,60}` which
                    // cannot eat digits, forcing the capture group to start at
                    // the first full number. Same fix applied to the "X in Y"
                    // pattern where ranges like "15-20%" previously captured
                    // "0%". Ranges no longer trigger a callout at all, which
                    // is correct behavior.
                    elseif (
                        $stat_count < 3
                        && (
                            preg_match( '/^[^0-9]{0,60}(\d{1,3}(?:[.,]\d+)?\s*%)/', $plain, $stat_match )
                            || preg_match( '/^[^0-9]{0,60}(\d{1,3})\s+(?:out\s+of|in)\s+(\d{1,4})\b/i', $plain, $stat_match )
                        )
                    ) {
                        $stat_value = trim( $stat_match[1] );
                        if ( isset( $stat_match[2] ) && ! str_contains( $stat_value, '%' ) ) {
                            // X out of Y / X in Y form — show as fraction
                            $stat_value = $stat_value . '/' . trim( $stat_match[2] );
                        }
                        $icon = $this->sb_icon( 'stat', 18 );
                        $html = '<div style="display:flex;gap:1.25em;align-items:center;background:#faf5ff !important;border-left:4px solid ' . $accent . ';padding:1em 1.25em;margin:1.25em 0;border-radius:0 8px 8px 0;color:' . $accent . '">';
                        $html .= '<div style="flex-shrink:0;font-size:2em;font-weight:800;color:' . $accent . ' !important;line-height:1;letter-spacing:-0.02em;display:flex;align-items:center;gap:0.3em">' . $icon . esc_html( $stat_value ) . '</div>';
                        $html .= '<div style="flex:1;color:#374151 !important;line-height:1.65;font-size:0.97em">' . $text . '</div>';
                        $html .= '</div>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                        $stat_count++;
                    }
                    else {
                        // v1.5.20 — dropcap removed. Was visually overbearing and the
                        // user reported it appearing on too many sentences/paragraphs.
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
                    // v1.5.64 — detect References section so we can render it
                    // with styled numbered badges (purple circles + hover
                    // effect) instead of plain Gutenberg ordered list.
                    // Documented in article_design.md §10.
                    $is_references = (bool) preg_match( '/^(references|sources|bibliography|further\s*reading|citations)\b/i', $prev_context );

                    // v1.5.14 — HowTo step boxes: when content_type is how_to AND
                    // the list is ordered AND it's not already classified as
                    // takeaways/pros/cons/ingredients, render as numbered step cards.
                    $content_type = $options['content_type'] ?? '';
                    $is_howto_steps = ( $content_type === 'how_to' && $tag === 'ol' && ! $is_takeaways && ! $is_pros && ! $is_cons && ! $is_ingredients );

                    if ( $is_references ) {
                        // v1.5.64 — styled References section per
                        // article_design.md §10 LOCKED FORMAT. Renders the
                        // numbered list with purple circle badges, clean
                        // typography, hover underline on links. Replaces
                        // plain Gutenberg ordered list which looked default.
                        $icon = $this->sb_icon( 'social', 18 );
                        $html = '<div style="background:#faf5ff !important;border:1px solid #e9d5ff;border-radius:12px;padding:1.5em 1.75em;margin:2em 0 1em;color:#1e293b !important">';
                        $html .= '<div style="font-size:0.72em;font-weight:700;text-transform:uppercase;letter-spacing:0.12em;color:' . $accent . ' !important;margin-bottom:1em;display:flex;align-items:center">' . $icon . 'References</div>';
                        $html .= '<ol style="list-style:none;counter-reset:sb-ref;padding:0;margin:0">';
                        $n = 1;
                        foreach ( $section['items'] as $item ) {
                            $item_html = $this->inline_markdown( $item );
                            $html .= '<li style="display:flex;align-items:flex-start;gap:0.75em;margin-bottom:0.65em;padding-bottom:0.65em;border-bottom:1px solid #f3e8ff;font-size:0.95em;line-height:1.55;color:#374151 !important">';
                            $html .= '<span style="flex-shrink:0;width:24px;height:24px;border-radius:50%;background:' . $accent . ';color:#ffffff !important;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:0.75em;line-height:1">' . $n . '</span>';
                            $html .= '<span style="flex:1;color:#374151 !important;word-break:break-word">' . $item_html . '</span>';
                            $html .= '</li>';
                            $n++;
                        }
                        $html .= '</ol>';
                        $html .= '</div>';
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( $is_takeaways ) {
                        $icon = $this->sb_icon( 'takeaways', 18 );
                        $html = '<div style="border-left:4px solid ' . $accent . ';background:linear-gradient(135deg,#f8f9ff 0%,#f0f0ff 100%);padding:1.25em 1.5em;border-radius:0 8px 8px 0;margin:1.5em 0;color:' . $accent . '">';
                        $html .= '<div style="font-size:0.72em;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:' . $accent . ' !important;margin-bottom:0.6em;display:flex;align-items:center">' . $icon . 'Key Takeaways</div>';
                        $html .= "<{$tag} style=\"line-height:1.8;padding-left:1.5em;margin:0;color:#374151 !important\">";
                        foreach ( $section['items'] as $item ) {
                            $html .= "<li style=\"margin-bottom:0.5em;color:#374151 !important\">{$item}</li>";
                        }
                        $html .= "</{$tag}></div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( $is_pros ) {
                        $icon = $this->sb_icon( 'pros', 18 );
                        $html = '<div style="background:#f0fdf4 !important;border:1px solid #bbf7d0;border-radius:8px;padding:1em 1.5em;margin:1em 0;color:#166534">';
                        $html .= '<div style="font-size:0.72em;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#166534 !important;margin-bottom:0.5em;display:flex;align-items:center">' . $icon . 'Pros</div>';
                        $html .= "<{$tag} style=\"line-height:1.8;padding-left:1.5em;margin:0;color:#374151 !important\">";
                        foreach ( $section['items'] as $item ) {
                            $html .= "<li style=\"margin-bottom:0.5em;color:#166534 !important\">{$item}</li>";
                        }
                        $html .= "</{$tag}></div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( $is_cons ) {
                        $icon = $this->sb_icon( 'cons', 18 );
                        $html = '<div style="background:#fef2f2 !important;border:1px solid #fecaca;border-radius:8px;padding:1em 1.5em;margin:1em 0;color:#991b1b">';
                        $html .= '<div style="font-size:0.72em;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#991b1b !important;margin-bottom:0.5em;display:flex;align-items:center">' . $icon . 'Cons</div>';
                        $html .= "<{$tag} style=\"line-height:1.8;padding-left:1.5em;margin:0;color:#374151 !important\">";
                        foreach ( $section['items'] as $item ) {
                            $html .= "<li style=\"margin-bottom:0.5em;color:#991b1b !important\">{$item}</li>";
                        }
                        $html .= "</{$tag}></div>";
                        $output[] = "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
                    } elseif ( $is_ingredients ) {
                        $icon = $this->sb_icon( 'ingredients', 18 );
                        $html = '<div style="background:#fffbeb !important;border:1px solid #fde68a;border-radius:8px;padding:1em 1.5em;margin:1em 0;color:#92400e">';
                        $html .= '<div style="font-size:0.72em;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#92400e !important;margin-bottom:0.5em;display:flex;align-items:center">' . $icon . 'What you\'ll need</div>';
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

                        $social_icon = $this->sb_icon( 'social' );
                        $html  = '<div style="background:#f1f5f9 !important;border:1px solid #cbd5e1;border-left:4px solid #64748b;border-radius:0 8px 8px 0;padding:1em 1.25em;margin:1.5em 0;color:#dc2626">';
                        $html .= '<div style="font-size:0.7em;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#dc2626 !important;margin-bottom:0.6em;display:flex;align-items:center">' . $social_icon . 'Social media citation &mdash; review before publishing</div>';
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

        // v1.5.120 — Close any open recipe card at end of content
        if ( $in_recipe_card ) {
            $output[] = "<!-- wp:html -->\n</div>\n<!-- /wp:html -->";
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
        // v1.5.21 — format_classic is now a THIN WRAPPER around format_hybrid.
        //
        // Why: through v1.5.20 these two formatters had drifted significantly.
        // format_hybrid had 14 styled block branches with custom SVG icons,
        // eyebrow headers, and v1.5.14/v1.5.17 features (Did You Know,
        // Definition, Highlight, Expert Quote, Stat callout, Social Citation,
        // HowTo Step Boxes). format_classic still had only 4 branches (tip,
        // note, warning, takeaways/pros/cons/ingredients) using CSS classes
        // instead of inline styles. Result: the result-panel preview (which
        // uses classic mode) showed a stripped-down version of the article
        // that didn't match the saved draft (which uses hybrid mode).
        //
        // Rather than port every hybrid branch into classic and maintain two
        // copies of the same logic, classic now CALLS hybrid, strips the
        // Gutenberg block comments (browsers ignore them anyway, but cleaner),
        // and wraps the result in a scoped CSS container that styles the
        // plain prose elements (h1, h2, h3, p, ul, table, etc). The wp:html
        // blocks inside hybrid output already have inline styles so they
        // render the same with or without the wrapper — which means preview
        // pixel-matches the saved draft for every styled block.
        //
        // The wrapper CSS only adds typography (font, line-height, size,
        // accent H2 color, paragraph max-width, table base styles) for the
        // plain elements. Every styled wp:html block is self-contained.

        $accent = $options['accent_color'] ?? '#764ba2';
        $uid = 'sb-' . substr( md5( uniqid() ), 0, 6 );

        // Get the full hybrid output — has all 14 styled block branches
        // with icons, eyebrow headers, and inline styles
        $hybrid_html = $this->format_hybrid( $sections, $options );

        // Strip Gutenberg block comments. Browsers ignore HTML comments so
        // they wouldn't be visible anyway, but stripping them gives cleaner
        // preview HTML and avoids the chance of a future browser quirk.
        $hybrid_html = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $hybrid_html );

        // Typography fonts (article_design.md §3, SEO-GEO-AI-GUIDELINES §12B)
        $sans  = "ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif";
        $serif = "ui-serif,Georgia,'Times New Roman',serif";

        // Scoped CSS — only styles the plain prose elements. The wp:html
        // styled blocks have their own inline styles and override these where
        // they overlap. !important on color/background to defeat WP admin CSS.
        $css  = "<style>";
        $css .= ".{$uid}{font-family:{$sans};color:#1f2937 !important;line-height:1.7;background:#fff !important;padding:2em;border-radius:12px;max-width:100%}";
        $css .= ".{$uid} h1,.{$uid} h2,.{$uid} h3,.{$uid} h4{font-family:{$serif};text-wrap:balance}";
        $css .= ".{$uid} h1{font-size:clamp(1.8em,4vw,2.4em);font-weight:800;line-height:1.2;margin:0 0 0.5em;color:#111827 !important;text-transform:capitalize}";
        // h2 colour is set inline by format_hybrid via Gutenberg style attrs;
        // this is a fallback for any h2 that didn't get inline styles
        $css .= ".{$uid} h2{font-size:clamp(1.3em,3vw,1.6em);font-weight:700;line-height:1.3;margin:2em 0 0.75em;padding-bottom:0.4em;border-bottom:2px solid {$accent}22;color:{$accent} !important}";
        $css .= ".{$uid} h3{font-size:1.15em;font-weight:600;line-height:1.4;margin:1.5em 0 0.5em;color:#374151 !important}";
        $css .= ".{$uid} p{line-height:1.75;margin:0 0 1.25em;font-size:1.05em;color:#374151 !important;text-wrap:pretty;max-width:65ch}";
        // v1.5.72 — upgraded list styling. Previous basic padding-left looked
        // unstyled in the preview. New design: rounded background card,
        // accent left border, accent-colored bullet markers, comfortable
        // padding. Matches the visual weight of the styled boxes (takeaways,
        // pros/cons) without being context-specific.
        $css .= ".{$uid} ul,.{$uid} ol{line-height:1.8;padding:0.75em 1em 0.75em 2em;margin:1em 0;color:#374151 !important;background:#f8fafc;border-left:3px solid {$accent};border-radius:0 8px 8px 0}";
        $css .= ".{$uid} li{margin-bottom:0.5em;padding-left:0.3em;color:#374151 !important}";
        $css .= ".{$uid} ul li::marker{color:{$accent} !important;font-weight:700;font-size:1.1em}";
        $css .= ".{$uid} ol li::marker{color:{$accent} !important;font-weight:700}";
        $css .= ".{$uid} a{color:{$accent} !important;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px}";
        $css .= ".{$uid} a:hover{text-decoration-thickness:2px}";
        $css .= ".{$uid} hr{border:none;border-top:2px solid #e5e7eb;margin:2.5em 0}";
        $css .= ".{$uid} img,.{$uid} figure img{max-width:100%;height:auto;border-radius:8px;margin:1.5em auto;display:block}";
        $css .= ".{$uid} code{background:#f3f4f6;padding:0.15em 0.4em;border-radius:4px;font-size:0.9em;color:#374151 !important}";
        // Drop-cap and dark-mode rules removed in v1.5.20.
        $css .= "</style>";

        return $css . "<div class=\"{$uid}\">" . $hybrid_html . "</div>";
    }

    /**
     * Convert inline markdown (bold, italic, links) to HTML.
     */
    /**
     * v1.5.20 — Custom hand-drawn SEOBetter icon set.
     *
     * 13 unique inline SVG icons for the styled wp:html block headers and
     * callout corners. NOT from any third-party library (Lucide, Heroicons,
     * Phosphor, Font Awesome, Noun Project, etc.) — the path data was hand-
     * drawn for SEOBetter so no other site uses these exact icons. Each is
     * an 18x18 viewBox stroke-only outline using `currentColor` so the icon
     * automatically inherits the parent box's text color, which means a tip
     * callout's icon is blue, a warning's is red, a pros box's is green, etc.
     *
     * Per article_design.md §6 these icons appear ONLY in callout corners and
     * styled box headers — NEVER inline with body prose. The "no icons in body
     * copy" rule still stands.
     *
     * @param string $name  One of: tip, note, warning, didyouknow, definition,
     *                      highlight, stat, quote, social, takeaways, pros,
     *                      cons, ingredients
     * @param int    $size  Pixel size for width/height (default 16)
     */
    private function sb_icon( string $name, int $size = 16 ): string {
        $icons = [
            // Diamond with 4 rays — "spark of insight"
            'tip'         => '<path d="M9 6L11 9L9 12L7 9Z" fill="currentColor"/><path d="M9 3v1.5M9 13.5v1.5M3 9h1.5M13.5 9h1.5"/>',
            // Tag with hole — "labeled note"
            'note'        => '<path d="M3 3h7l5 5-7 7L3 10V3z"/><circle cx="6" cy="6" r="0.8" fill="currentColor"/>',
            // Diamond with exclamation — rotated square warning
            'warning'     => '<path d="M9 2L16 9L9 16L2 9Z"/><line x1="9" y1="6" x2="9" y2="10"/><circle cx="9" cy="12.5" r="0.7" fill="currentColor"/>',
            // 4-point compass star — curved-arm starburst
            'didyouknow'  => '<path d="M9 2C9.5 6 12 8.5 16 9C12 9.5 9.5 12 9 16C8.5 12 6 9.5 2 9C6 8.5 8.5 6 9 2Z"/>',
            // Stacked text lines with underline on last — "defined term"
            'definition'  => '<line x1="3" y1="5" x2="15" y2="5"/><line x1="3" y1="9" x2="11" y2="9"/><line x1="3" y1="13" x2="13" y2="13"/><line x1="3" y1="14.8" x2="9" y2="14.8" stroke-width="1.2"/>',
            // Highlighter pen stroke — diagonal marker with translucent fill
            'highlight'   => '<path d="M3 14L11 6L13 8L5 16Z" fill="currentColor" fill-opacity="0.18"/><path d="M11 6L14 3L16 5L13 8"/>',
            // 3 ascending bars + dot above tallest — growth/stat
            'stat'        => '<line x1="4" y1="14" x2="4" y2="11"/><line x1="9" y1="14" x2="9" y2="8"/><line x1="14" y1="14" x2="14" y2="5"/><circle cx="14" cy="3" r="0.9" fill="currentColor"/>',
            // Two L-shapes — Western quote marks
            'quote'       => '<path d="M5 5v4h3M12 5v4h3"/>',
            // Shield with exclamation — citation alert
            'social'      => '<path d="M9 2L3 4v5c0 4 6 7 6 7s6-3 6-7V4l-6-2z"/><line x1="9" y1="6.5" x2="9" y2="10"/><circle cx="9" cy="12" r="0.6" fill="currentColor"/>',
            // 3 bullet circles + lines — "key list"
            'takeaways'   => '<circle cx="5" cy="5" r="1" fill="currentColor"/><line x1="8" y1="5" x2="15" y2="5"/><circle cx="5" cy="9" r="1" fill="currentColor"/><line x1="8" y1="9" x2="13" y2="9"/><circle cx="5" cy="13" r="1" fill="currentColor"/><line x1="8" y1="13" x2="14" y2="13"/>',
            // Curved checkmark — pros
            'pros'        => '<path d="M3 9L7 13L15 5"/>',
            // X mark — cons
            'cons'        => '<line x1="4" y1="4" x2="14" y2="14"/><line x1="14" y1="4" x2="4" y2="14"/>',
            // 3 stacked rounded rectangles — ingredient containers
            'ingredients' => '<rect x="3" y="3" width="12" height="3" rx="0.5"/><rect x="3" y="7.5" width="12" height="3" rx="0.5"/><rect x="3" y="12" width="12" height="3" rx="0.5"/>',
        ];

        $inner = $icons[ $name ] ?? '';
        if ( ! $inner ) {
            return '';
        }

        return '<svg viewBox="0 0 18 18" width="' . $size . '" height="' . $size
            . '" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"'
            . ' style="display:inline-block;vertical-align:-3px;margin-right:0.4em;flex-shrink:0" aria-hidden="true">'
            . $inner . '</svg>';
    }

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
