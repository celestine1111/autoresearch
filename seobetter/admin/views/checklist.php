<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 40+ Point SEO Checklist based on SEO PowerSuite research + GEO optimization.
 * Organized by category with automated + manual checks.
 */

$auditor = new SEOBetter\Technical_SEO_Auditor();
$site_audit = $auditor->audit_site();

// Build the checklist
$checklist = [
    'Technical Foundation' => [
        [ 'id' => 'ssl', 'label' => 'HTTPS/SSL enabled', 'auto' => true, 'pass' => $site_audit['checks']['ssl']['pass'] ?? false, 'detail' => $site_audit['checks']['ssl']['detail'] ?? '' ],
        [ 'id' => 'sitemap', 'label' => 'XML sitemap exists', 'auto' => true, 'pass' => $site_audit['checks']['sitemap']['pass'] ?? false, 'detail' => $site_audit['checks']['sitemap']['detail'] ?? '' ],
        [ 'id' => 'robots', 'label' => 'robots.txt configured', 'auto' => true, 'pass' => $site_audit['checks']['robots_txt']['pass'] ?? false, 'detail' => $site_audit['checks']['robots_txt']['detail'] ?? '' ],
        [ 'id' => 'permalinks', 'label' => 'SEO-friendly permalinks (/%postname%/)', 'auto' => true, 'pass' => $site_audit['checks']['permalink_structure']['pass'] ?? false, 'detail' => $site_audit['checks']['permalink_structure']['detail'] ?? '' ],
        [ 'id' => 'depth', 'label' => 'All pages within 3 clicks of homepage', 'auto' => true, 'pass' => $site_audit['checks']['site_depth']['pass'] ?? false, 'detail' => $site_audit['checks']['site_depth']['detail'] ?? '' ],
        [ 'id' => 'gsc', 'label' => 'Google Search Console connected', 'auto' => false ],
        [ 'id' => 'ga', 'label' => 'Google Analytics installed', 'auto' => false ],
        [ 'id' => 'cwv', 'label' => 'Core Web Vitals passing (LCP < 2.5s, INP < 200ms, CLS < 0.1)', 'auto' => false ],
        [ 'id' => 'mobile', 'label' => 'Mobile-friendly responsive design', 'auto' => false ],
        [ 'id' => 'speed', 'label' => 'Page load time under 2 seconds', 'auto' => false ],
    ],
    'On-Page SEO' => [
        [ 'id' => 'dup_titles', 'label' => 'No duplicate title tags', 'auto' => true, 'pass' => $site_audit['checks']['duplicate_titles']['pass'] ?? false, 'detail' => $site_audit['checks']['duplicate_titles']['detail'] ?? '' ],
        [ 'id' => 'meta_desc', 'label' => 'All pages have meta descriptions', 'auto' => true, 'pass' => $site_audit['checks']['missing_meta']['pass'] ?? false, 'detail' => $site_audit['checks']['missing_meta']['detail'] ?? '' ],
        [ 'id' => 'orphans', 'label' => 'No orphan pages', 'auto' => true, 'pass' => $site_audit['checks']['orphan_pages']['pass'] ?? false, 'detail' => $site_audit['checks']['orphan_pages']['detail'] ?? '' ],
        [ 'id' => 'title_len', 'label' => 'Title tags 30-60 characters', 'auto' => false ],
        [ 'id' => 'desc_len', 'label' => 'Meta descriptions 120-160 characters', 'auto' => false ],
        [ 'id' => 'h1_unique', 'label' => 'One unique H1 per page', 'auto' => false ],
        [ 'id' => 'heading_hier', 'label' => 'Proper heading hierarchy (H1 > H2 > H3)', 'auto' => false ],
        [ 'id' => 'canonical', 'label' => 'Canonical tags on all pages', 'auto' => false ],
        [ 'id' => 'internal', 'label' => 'Internal links (3+ per page, 1 per 300 words)', 'auto' => false ],
        [ 'id' => 'external', 'label' => 'Outbound links (2-4 per 1000 words)', 'auto' => false ],
        [ 'id' => 'keyword_first', 'label' => 'Focus keyword in first 100 words', 'auto' => false ],
        [ 'id' => 'url_clean', 'label' => 'Clean, keyword-rich URLs (no dates, no special chars)', 'auto' => false ],
    ],
    'Content Quality' => [
        [ 'id' => 'word_count', 'label' => 'Content length 1,500+ words (match competitors)', 'auto' => false ],
        [ 'id' => 'readability', 'label' => 'Readability grade 6-8 (Flesch-Kincaid)', 'auto' => false ],
        [ 'id' => 'no_stuff', 'label' => 'No keyword stuffing (natural keyword placement)', 'auto' => false ],
        [ 'id' => 'multimedia', 'label' => 'Images/video included (21% more images = higher ranking)', 'auto' => false ],
        [ 'id' => 'content_fresh', 'label' => 'Content updated within last year', 'auto' => false ],
        [ 'id' => 'eeat_author', 'label' => 'Author bio with credentials (E-E-A-T)', 'auto' => false ],
        [ 'id' => 'eeat_sources', 'label' => 'Cited credible sources with dates', 'auto' => false ],
    ],
    'GEO Optimization (AI Search)' => [
        [ 'id' => 'bluf', 'label' => 'Key Takeaways section at top (BLUF)', 'auto' => false ],
        [ 'id' => 'section_40_60', 'label' => 'H2/H3 sections open with 40-60 word paragraphs', 'auto' => false ],
        [ 'id' => 'island_test', 'label' => 'Island Test passed (no pronoun paragraph starts)', 'auto' => false ],
        [ 'id' => 'stats', 'label' => '3+ statistics per 1000 words (+30% AI visibility)', 'auto' => false ],
        [ 'id' => 'quotes', 'label' => '2+ expert quotes (+41% AI visibility)', 'auto' => false ],
        [ 'id' => 'citations', 'label' => '5+ inline citations (+28% AI visibility)', 'auto' => false ],
        [ 'id' => 'tables', 'label' => 'Comparison tables included (30-40% more AI citations)', 'auto' => false ],
        [ 'id' => 'freshness_sig', 'label' => '"Last Updated" date signal present', 'auto' => false ],
        [ 'id' => 'schema', 'label' => 'JSON-LD schema markup (Article + FAQ)', 'auto' => false ],
        [ 'id' => 'llms_txt', 'label' => 'llms.txt enabled for AI crawlers', 'auto' => false ],
    ],
    'Image SEO' => [
        [ 'id' => 'alt_text', 'label' => 'All images have descriptive alt text', 'auto' => false ],
        [ 'id' => 'img_dims', 'label' => 'Width/height attributes set (prevents CLS)', 'auto' => false ],
        [ 'id' => 'lazy_load', 'label' => 'Lazy loading on offscreen images', 'auto' => false ],
        [ 'id' => 'webp', 'label' => 'WebP format for images (25-35% smaller)', 'auto' => false ],
        [ 'id' => 'featured', 'label' => 'Featured image set (1200px+ for Google Discover)', 'auto' => false ],
        [ 'id' => 'img_names', 'label' => 'Descriptive keyword-rich filenames', 'auto' => false ],
    ],
    'Social & Snippets' => [
        [ 'id' => 'og_tags', 'label' => 'Open Graph tags (og:title, og:image, og:description)', 'auto' => false ],
        [ 'id' => 'twitter_card', 'label' => 'Twitter Card meta tags', 'auto' => false ],
        [ 'id' => 'question_h2', 'label' => 'Question-format headings (targets PAA + snippets)', 'auto' => false ],
        [ 'id' => 'faq_schema', 'label' => 'FAQPage schema for Q&A content', 'auto' => false ],
        [ 'id' => 'snippet_paras', 'label' => 'Snippable paragraphs (40-50 words after headings)', 'auto' => false ],
    ],
];

$saved_checks = get_option( 'seobetter_checklist_manual', [] );

// Handle save
if ( isset( $_POST['seobetter_save_checklist'] ) && check_admin_referer( 'seobetter_checklist_nonce' ) ) {
    $saved_checks = array_map( 'sanitize_text_field', $_POST['checklist'] ?? [] );
    update_option( 'seobetter_checklist_manual', $saved_checks );
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Checklist saved.', 'seobetter' ) . '</p></div>';
}

// Count totals
$total_items = 0;
$completed = 0;
foreach ( $checklist as $items ) {
    foreach ( $items as $item ) {
        $total_items++;
        if ( ( $item['auto'] ?? false ) && ( $item['pass'] ?? false ) ) {
            $completed++;
        } elseif ( isset( $saved_checks[ $item['id'] ] ) ) {
            $completed++;
        }
    }
}
$progress = $total_items > 0 ? round( ( $completed / $total_items ) * 100 ) : 0;
?>
<div class="wrap seobetter-dashboard">
    <h1><?php esc_html_e( 'SEO Checklist', 'seobetter' ); ?></h1>
    <p class="description"><?php printf( esc_html__( '%d of %d items completed (%d%%)', 'seobetter' ), $completed, $total_items, $progress ); ?></p>

    <!-- Progress bar -->
    <div style="background:#e9ecef;border-radius:4px;height:24px;margin:15px 0;overflow:hidden">
        <div style="background:<?php echo $progress >= 80 ? '#28a745' : ( $progress >= 50 ? '#ffc107' : '#dc3545' ); ?>;height:100%;width:<?php echo esc_attr( $progress ); ?>%;transition:width 0.3s;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:12px">
            <?php echo esc_html( $progress . '%' ); ?>
        </div>
    </div>

    <form method="post">
        <?php wp_nonce_field( 'seobetter_checklist_nonce' ); ?>

        <?php foreach ( $checklist as $category => $items ) : ?>
        <div class="seobetter-card" style="margin-bottom:20px">
            <h2><?php echo esc_html( $category ); ?></h2>
            <table class="widefat">
                <tbody>
                    <?php foreach ( $items as $item ) :
                        $is_auto = $item['auto'] ?? false;
                        $is_checked = $is_auto ? ( $item['pass'] ?? false ) : isset( $saved_checks[ $item['id'] ] );
                    ?>
                    <tr>
                        <td style="width:40px;text-align:center">
                            <?php if ( $is_auto ) : ?>
                                <?php if ( $is_checked ) : ?>
                                    <span class="dashicons dashicons-yes-alt" style="color:green" title="Auto-detected"></span>
                                <?php else : ?>
                                    <span class="dashicons dashicons-dismiss" style="color:red" title="Auto-detected issue"></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <input type="checkbox" name="checklist[<?php echo esc_attr( $item['id'] ); ?>]" value="1" <?php checked( $is_checked ); ?> />
                            <?php endif; ?>
                        </td>
                        <td>
                            <label <?php echo ! $is_auto ? 'for="checklist_' . esc_attr( $item['id'] ) . '"' : ''; ?>>
                                <?php echo esc_html( $item['label'] ); ?>
                            </label>
                            <?php if ( $is_auto && ! empty( $item['detail'] ) ) : ?>
                                <br><small style="color:#666"><?php echo esc_html( $item['detail'] ); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <?php submit_button( __( 'Save Checklist Progress', 'seobetter' ), 'primary', 'seobetter_save_checklist' ); ?>
    </form>
</div>
