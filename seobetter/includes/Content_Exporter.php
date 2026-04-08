<?php

namespace SEOBetter;

/**
 * Content Exporter.
 *
 * Exports generated articles as downloadable files.
 * Supports: Plain text, HTML, and Markdown formats.
 *
 * Pro feature.
 */
class Content_Exporter {

    /**
     * Export content as a downloadable HTML file.
     */
    public static function export_html( string $content, string $title, string $keyword = '' ): string {
        $date = wp_date( 'F j, Y' );
        $site = get_bloginfo( 'name' );

        return "<!DOCTYPE html>
<html lang=\"en\">
<head>
<meta charset=\"UTF-8\">
<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
<title>" . esc_html( $title ) . "</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; color: #333; line-height: 1.7; }
h1 { color: #764ba2; font-size: 2em; border-bottom: 2px solid #764ba222; padding-bottom: 10px; }
h2 { color: #764ba2; margin-top: 2em; }
h3 { color: #555; }
blockquote { border-left: 4px solid #764ba2; margin: 1.5em 0; padding: 12px 20px; background: #f9f9f9; font-style: italic; }
table { width: 100%; border-collapse: collapse; margin: 1.5em 0; }
th { background: #764ba2; color: #fff; padding: 10px 14px; text-align: left; }
td { padding: 10px 14px; border-bottom: 1px solid #eee; }
tr:nth-child(even) td { background: #f8f8f8; }
.meta { color: #888; font-size: 0.85em; margin-bottom: 2em; }
</style>
</head>
<body>
<div class=\"meta\">
<strong>Keyword:</strong> " . esc_html( $keyword ) . " | <strong>Generated:</strong> {$date} | <strong>Site:</strong> " . esc_html( $site ) . "
</div>
{$content}
</body>
</html>";
    }

    /**
     * Export content as Markdown.
     */
    public static function export_markdown( string $markdown, string $keyword = '' ): string {
        $date = wp_date( 'F j, Y' );
        $site = get_bloginfo( 'name' );

        $header = "---\n";
        $header .= "keyword: " . $keyword . "\n";
        $header .= "generated: " . $date . "\n";
        $header .= "site: " . $site . "\n";
        $header .= "---\n\n";

        return $header . $markdown;
    }

    /**
     * Export content as plain text.
     */
    public static function export_text( string $content, string $title, string $keyword = '' ): string {
        $text = wp_strip_all_tags( $content );
        $date = wp_date( 'F j, Y' );

        $header = strtoupper( $title ) . "\n";
        $header .= str_repeat( '=', mb_strlen( $title ) ) . "\n";
        $header .= "Keyword: {$keyword} | Generated: {$date}\n\n";

        return $header . $text;
    }

    /**
     * Serve a file download.
     */
    public static function serve_download( string $content, string $filename, string $mime_type = 'text/html' ): void {
        header( 'Content-Type: ' . $mime_type . '; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        header( 'Content-Length: ' . strlen( $content ) );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        echo $content;
        exit;
    }
}
