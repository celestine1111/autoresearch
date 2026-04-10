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

                    // Check if this follows a Key Takeaways heading — use styled wp:html
                    $prev_heading = '';
                    for ( $j = $i - 1; $j >= 0; $j-- ) {
                        if ( $sections[ $j ]['type'] === 'heading' ) {
                            $prev_heading = $sections[ $j ]['content'];
                            break;
                        }
                        if ( $sections[ $j ]['type'] === 'paragraph' && ! empty( trim( $sections[ $j ]['content'] ) ) ) break;
                    }

                    $is_takeaways = preg_match( '/key\s*takeaway/i', $prev_heading );
                    if ( $is_takeaways ) {
                        // Styled key takeaways — wp:html to preserve styling
                        $html = '<div style="border-left:4px solid ' . $accent . ';background:linear-gradient(135deg,#f8f9ff 0%,#f0f0ff 100%);padding:1.25em 1.5em;border-radius:0 8px 8px 0;margin:1.5em 0">';
                        $html .= "<{$tag} style=\"line-height:1.8;padding-left:1.5em;margin:0;color:#374151\">";
                        foreach ( $section['items'] as $item ) {
                            $html .= "<li style=\"margin-bottom:0.5em\">{$item}</li>";
                        }
                        $html .= "</{$tag}></div>";
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
                    // Styled blockquote — wp:html to preserve styling
                    $text = $this->inline_markdown( $section['content'] );
                    $html = "<blockquote style=\"border-left:4px solid {$accent};margin:1.5em 0;padding:1em 1.5em;background:#f9fafb;border-radius:0 8px 8px 0;font-style:italic;font-size:1.05em;color:#4b5563;line-height:1.7\"><p style=\"margin:0\">{$text}</p></blockquote>";
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
        $css = "<style>.{$uid}{font-family:ui-sans-serif,system-ui,-apple-system,sans-serif;color:#1f2937;line-height:1.7;max-width:65ch;margin:0 auto}";
        $css .= ".{$uid} h1,.{$uid} h2,.{$uid} h3{font-family:ui-serif,Georgia,serif;text-wrap:balance;color:#111827}";
        $css .= ".{$uid} h1{font-size:clamp(1.8em,4vw,2.4em);font-weight:800;line-height:1.2;margin:0 0 0.5em}";
        $css .= ".{$uid} h2{font-size:clamp(1.3em,3vw,1.6em);font-weight:700;line-height:1.3;color:{$accent};margin:2em 0 0.75em;padding-bottom:0.4em;border-bottom:2px solid {$accent}22}";
        $css .= ".{$uid} h3{font-size:1.15em;font-weight:600;margin:1.5em 0 0.5em;color:#374151}";
        $css .= ".{$uid} p{line-height:1.75;margin:0 0 1.25em;text-wrap:pretty;font-size:1.05em}";
        $css .= ".{$uid} p:first-of-type::first-letter{float:left;font-size:3.2em;line-height:0.8;font-weight:700;color:{$accent};margin:0.05em 0.1em 0 0;font-family:ui-serif,Georgia,serif}";
        $css .= ".{$uid} ul,.{$uid} ol{line-height:1.8;padding-left:1.5em;margin:1em 0;color:#374151}";
        $css .= ".{$uid} li{margin-bottom:0.4em}";
        $css .= ".{$uid} ul li{list-style-type:'\\2022  ';color:#374151}";
        $css .= ".{$uid} ul li::marker{color:{$accent};font-weight:700}";
        $css .= ".{$uid} blockquote{border-left:4px solid {$accent};margin:1.5em 0;padding:1em 1.5em;background:#f9fafb;border-radius:0 8px 8px 0;font-style:italic;font-size:1.05em;color:#4b5563;line-height:1.7}";
        $css .= ".{$uid} blockquote p{margin:0}";
        $css .= ".{$uid} table{width:100%;border-collapse:collapse;font-size:0.95em;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);margin:1.5em 0}";
        $css .= ".{$uid} thead th{background:{$accent};color:#fff;padding:0.75em 1em;text-align:left;font-weight:600;font-size:0.9em;letter-spacing:0.03em}";
        $css .= ".{$uid} tbody td{padding:0.75em 1em;border-bottom:1px solid #e5e7eb;color:#374151}";
        $css .= ".{$uid} tbody tr:nth-child(even){background:#f9fafb}";
        $css .= ".{$uid} img{max-width:100%;height:auto;border-radius:8px;margin:1.5em auto;display:block}";
        $css .= ".{$uid} hr{border:none;border-top:2px solid #e5e7eb;margin:2.5em 0}";
        $css .= ".{$uid} a{color:{$accent};text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px}";
        $css .= ".{$uid} a:hover{text-decoration-thickness:2px}";
        $css .= ".{$uid} code{background:#f3f4f6;padding:0.15em 0.4em;border-radius:4px;font-size:0.9em}";
        $css .= ".{$uid} pre{background:#1f2937;color:#e5e7eb;padding:1.25em;border-radius:8px;overflow-x:auto;font-size:0.9em;line-height:1.6;margin:1.5em 0}";
        // Callout boxes
        $css .= ".{$uid} .sb-callout{padding:1em 1.25em;border-radius:0 8px 8px 0;margin:1.5em 0;line-height:1.7;font-size:0.95em}";
        $css .= ".{$uid} .sb-callout-tip{background:#eff6ff;border-left:4px solid #3b82f6;color:#1e3a5f}";
        $css .= ".{$uid} .sb-callout-note{background:#fffbeb;border-left:4px solid #f59e0b;color:#78350f}";
        $css .= ".{$uid} .sb-callout-warn{background:#fef2f2;border-left:4px solid #ef4444;color:#991b1b}";
        // Key takeaways box
        $css .= ".{$uid} .sb-takeaways{border-left:4px solid {$accent};background:linear-gradient(135deg,#f8f9ff 0%,#f0f0ff 100%);padding:1.25em 1.5em;border-radius:0 8px 8px 0;margin:1.5em 0}";
        // Pros/cons
        $css .= ".{$uid} .sb-pros{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:1em 1.5em;margin:1em 0}";
        $css .= ".{$uid} .sb-cons{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:1em 1.5em;margin:1em 0}";
        $css .= ".{$uid} .sb-ingredients{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:1em 1.5em;margin:1em 0}";
        // Dark mode
        $css .= "@media(prefers-color-scheme:dark){.{$uid}{color:#e5e7eb;background:#111827}";
        $css .= ".{$uid} h1,.{$uid} h2,.{$uid} h3{color:#f3f4f6}";
        $css .= ".{$uid} h2{border-bottom-color:{$accent}44}";
        $css .= ".{$uid} p,.{$uid} li{color:#d1d5db}";
        $css .= ".{$uid} blockquote{background:#1f2937;color:#9ca3af}";
        $css .= ".{$uid} tbody td{border-bottom-color:#374151;color:#d1d5db}";
        $css .= ".{$uid} tbody tr:nth-child(even){background:#1f293780}";
        $css .= ".{$uid} code{background:#374151;color:#e5e7eb}";
        $css .= ".{$uid} hr{border-top-color:#374151}";
        $css .= ".{$uid} a{color:#93c5fd}}";
        $css .= "</style>";

        $output[] = $css;
        $output[] = "<article class=\"{$uid}\">";

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

        $output[] = '</article>';

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
        // Links
        $text = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text );
        // Inline code
        $text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

        return $text;
    }
}
