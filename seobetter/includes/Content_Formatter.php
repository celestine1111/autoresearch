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
    private function format_gutenberg( array $sections, array $options ): string {
        $output = [];
        $is_first_heading = true;
        $accent = $options['accent_color'] ?? '#764ba2';

        foreach ( $sections as $i => $section ) {
            switch ( $section['type'] ) {

                case 'heading':
                    $level = $section['level'];
                    $text = $section['content'];

                    if ( $is_first_heading && $level <= 2 ) {
                        // First H1/H2: styled title with accent color
                        $output[] = "<!-- wp:heading {\"level\":{$level},\"style\":{\"typography\":{\"fontSize\":\"2.2rem\"},\"color\":{\"text\":\"{$accent}\"}}} -->";
                        $output[] = "<h{$level} class=\"wp-block-heading\" style=\"color:{$accent};font-size:2.2rem\">{$text}</h{$level}>";
                        $output[] = "<!-- /wp:heading -->";
                        $output[] = "<!-- wp:separator {\"className\":\"is-style-wide\"} -->";
                        $output[] = '<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/>';
                        $output[] = "<!-- /wp:separator -->";
                        $is_first_heading = false;
                    } elseif ( $level === 2 ) {
                        // H2: add spacer before for visual breathing room + accent color
                        $output[] = '<!-- wp:spacer {"height":"30px"} -->';
                        $output[] = '<div style="height:30px" aria-hidden="true" class="wp-block-spacer"></div>';
                        $output[] = '<!-- /wp:spacer -->';
                        $output[] = "<!-- wp:heading {\"level\":2,\"style\":{\"color\":{\"text\":\"{$accent}\"}}} -->";
                        $output[] = "<h2 class=\"wp-block-heading\" style=\"color:{$accent}\">{$text}</h2>";
                        $output[] = "<!-- /wp:heading -->";
                    } else {
                        $output[] = "<!-- wp:heading {\"level\":{$level}} -->";
                        $output[] = "<h{$level} class=\"wp-block-heading\">{$text}</h{$level}>";
                        $output[] = "<!-- /wp:heading -->";
                    }
                    break;

                case 'paragraph':
                    $text = $this->inline_markdown( $section['content'] );
                    if ( empty( trim( $text ) ) ) continue 2;

                    // Key Takeaways box — wrap in a styled group
                    if ( $i > 0 && ( $sections[ $i - 1 ]['type'] ?? '' ) === 'heading'
                        && preg_match( '/key\s*takeaway/i', $sections[ $i - 1 ]['content'] ?? '' ) ) {
                        // The list after Key Takeaways will be wrapped instead
                    }

                    // Check if this is a "Last Updated" line
                    if ( preg_match( '/^last\s*updated/i', strip_tags( $text ) ) ) {
                        $output[] = '<!-- wp:paragraph {"style":{"typography":{"fontSize":"0.85rem"},"color":{"text":"#888"}}} -->';
                        $output[] = "<p style=\"color:#888;font-size:0.85rem\">{$text}</p>";
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

                    // Check if previous section is Key Takeaways heading
                    $prev_heading = '';
                    for ( $j = $i - 1; $j >= 0; $j-- ) {
                        if ( $sections[ $j ]['type'] === 'heading' ) {
                            $prev_heading = $sections[ $j ]['content'];
                            break;
                        }
                        if ( $sections[ $j ]['type'] === 'paragraph' && ! empty( trim( $sections[ $j ]['content'] ) ) ) break;
                    }

                    if ( preg_match( '/key\s*takeaway/i', $prev_heading ) ) {
                        // Wrap in a styled group box
                        $output[] = '<!-- wp:group {"style":{"border":{"left":{"color":"' . $accent . '","width":"4px"}},"spacing":{"padding":{"top":"20px","right":"24px","bottom":"20px","left":"24px"}}},"backgroundColor":"light-gray"} -->';
                        $output[] = '<div class="wp-block-group has-light-gray-background-color has-background" style="border-left-color:' . $accent . ';border-left-width:4px;padding-top:20px;padding-right:24px;padding-bottom:20px;padding-left:24px">';
                        $output[] = "<!-- wp:list -->";
                        $output[] = "<{$tag}>{$items_html}</{$tag}>";
                        $output[] = "<!-- /wp:list -->";
                        $output[] = '</div>';
                        $output[] = '<!-- /wp:group -->';
                    } else {
                        $block_name = $tag === 'ol' ? 'list {"ordered":true}' : 'list';
                        $output[] = "<!-- wp:{$block_name} -->";
                        $output[] = "<{$tag}>{$items_html}</{$tag}>";
                        $output[] = "<!-- /wp:list -->";
                    }
                    break;

                case 'quote':
                    $text = $this->inline_markdown( $section['content'] );
                    $output[] = '<!-- wp:quote {"className":"is-style-large"} -->';
                    $output[] = '<blockquote class="wp-block-quote is-style-large"><p>' . $text . '</p></blockquote>';
                    $output[] = '<!-- /wp:quote -->';
                    break;

                case 'table':
                    $rows = $section['rows'];
                    if ( empty( $rows ) ) continue 2;

                    $output[] = '<!-- wp:table {"hasFixedLayout":true,"className":"is-style-stripes"} -->';
                    $output[] = '<figure class="wp-block-table is-style-stripes"><table class="has-fixed-layout"><thead><tr>';

                    // First row as header
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
                    $output[] = '<!-- wp:separator {"className":"is-style-wide"} -->';
                    $output[] = '<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/>';
                    $output[] = '<!-- /wp:separator -->';
                    break;

                case 'image':
                    $output[] = Stock_Image_Inserter::markdown_image_to_gutenberg( $section['alt'], $section['url'] );
                    break;
            }
        }

        return implode( "\n", $output );
    }

    /**
     * Format as styled HTML for Classic Editor (no Gutenberg blocks).
     */
    private function format_classic( array $sections, array $options ): string {
        $output = [];
        $accent = $options['accent_color'] ?? '#764ba2';

        // Add inline styles at the top
        $output[] = '<style>
.seobetter-article h2 { color: ' . $accent . '; border-bottom: 2px solid ' . $accent . '22; padding-bottom: 8px; margin-top: 2em; }
.seobetter-article h3 { color: #333; margin-top: 1.5em; }
.seobetter-article blockquote { border-left: 4px solid ' . $accent . '; margin: 1.5em 0; padding: 16px 24px; background: #f9f9f9; border-radius: 0 8px 8px 0; font-style: italic; font-size: 1.05em; }
.seobetter-article table { width: 100%; border-collapse: collapse; margin: 1.5em 0; font-size: 0.95em; }
.seobetter-article th { background: ' . $accent . '; color: #fff; padding: 12px 16px; text-align: left; }
.seobetter-article td { padding: 10px 16px; border-bottom: 1px solid #eee; }
.seobetter-article tr:nth-child(even) td { background: #f8f8f8; }
.seobetter-article .sb-takeaways { border-left: 4px solid ' . $accent . '; background: linear-gradient(135deg, #f8f9ff 0%, #f0f0ff 100%); padding: 20px 24px; border-radius: 0 8px 8px 0; margin: 1.5em 0; }
.seobetter-article .sb-takeaways ul { margin: 0; padding-left: 20px; }
.seobetter-article .sb-takeaways li { margin-bottom: 8px; line-height: 1.6; }
.seobetter-article .sb-updated { color: #888; font-size: 0.85em; font-style: italic; }
.seobetter-article ul, .seobetter-article ol { line-height: 1.8; }
.seobetter-article hr { border: none; border-top: 2px solid #eee; margin: 2em 0; }
</style>';
        $output[] = '<div class="seobetter-article">';

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

                    if ( preg_match( '/^last\s*updated/i', strip_tags( $text ) ) ) {
                        $output[] = "<p class=\"sb-updated\">{$text}</p>";
                    } else {
                        $output[] = "<p>{$text}</p>";
                    }
                    break;

                case 'list':
                    $tag = $section['list_type'];

                    // Check if this follows Key Takeaways
                    $prev_heading = '';
                    for ( $j = $i - 1; $j >= 0; $j-- ) {
                        if ( $sections[ $j ]['type'] === 'heading' ) {
                            $prev_heading = $sections[ $j ]['content'];
                            break;
                        }
                        if ( $sections[ $j ]['type'] === 'paragraph' && ! empty( trim( $sections[ $j ]['content'] ) ) ) break;
                    }

                    $is_takeaways = preg_match( '/key\s*takeaway/i', $prev_heading );
                    if ( $is_takeaways ) $output[] = '<div class="sb-takeaways">';

                    $output[] = "<{$tag}>";
                    foreach ( $section['items'] as $item ) {
                        $output[] = "<li>{$item}</li>";
                    }
                    $output[] = "</{$tag}>";

                    if ( $is_takeaways ) $output[] = '</div>';
                    break;

                case 'quote':
                    $text = $this->inline_markdown( $section['content'] );
                    $output[] = "<blockquote><p>{$text}</p></blockquote>";
                    break;

                case 'table':
                    $rows = $section['rows'];
                    if ( empty( $rows ) ) continue 2;

                    $output[] = '<table>';
                    $output[] = '<thead><tr>';
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
                    $output[] = '</tbody></table>';
                    break;

                case 'separator':
                    $output[] = '<hr />';
                    break;

                case 'image':
                    $output[] = Stock_Image_Inserter::markdown_image_to_classic( $section['alt'], $section['url'] );
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
        // Links
        $text = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text );
        // Inline code
        $text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

        return $text;
    }
}
