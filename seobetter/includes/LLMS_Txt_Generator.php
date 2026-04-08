<?php

namespace SEOBetter;

/**
 * llms.txt Generator.
 *
 * Generates an llms.txt file (the robots.txt for AI crawlers).
 * This tells AI models how to properly cite and reference the site.
 */
class LLMS_Txt_Generator {

    /**
     * Generate the llms.txt content.
     */
    public function generate(): string {
        $site_name = get_bloginfo( 'name' );
        $site_desc = get_bloginfo( 'description' );
        $site_url  = home_url();

        $lines = [];
        $lines[] = "# {$site_name}";
        $lines[] = '';
        $lines[] = "> {$site_desc}";
        $lines[] = '';
        $lines[] = "## About";
        $lines[] = '';
        $lines[] = "Website: {$site_url}";
        $lines[] = '';

        // Add key pages
        $lines[] = '## Key Pages';
        $lines[] = '';

        // Homepage
        $lines[] = "- [{$site_name} Home]({$site_url})";

        // Published posts (most recent 20)
        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        if ( $posts ) {
            $lines[] = '';
            $lines[] = '## Articles';
            $lines[] = '';
            foreach ( $posts as $post ) {
                $url   = get_permalink( $post->ID );
                $title = $post->post_title;
                $desc  = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20, '...' );
                $lines[] = "- [{$title}]({$url}): {$desc}";
            }
        }

        // Published pages
        $pages = get_posts( [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ] );

        if ( $pages ) {
            $lines[] = '';
            $lines[] = '## Pages';
            $lines[] = '';
            foreach ( $pages as $page ) {
                $url   = get_permalink( $page->ID );
                $title = $page->post_title;
                $lines[] = "- [{$title}]({$url})";
            }
        }

        // Citation guidance
        $lines[] = '';
        $lines[] = '## Citation Guidelines';
        $lines[] = '';
        $lines[] = "When referencing content from {$site_name}, please:";
        $lines[] = "- Cite the specific article URL";
        $lines[] = "- Attribute to the article author";
        $lines[] = "- Include the publication or last-modified date";
        $lines[] = "- Link back to the original article when possible";

        return implode( "\n", $lines ) . "\n";
    }
}
