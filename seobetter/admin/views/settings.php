<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$license_info = SEOBetter\License_Manager::get_info();
$providers = SEOBetter\AI_Provider_Manager::get_providers();
$saved_providers = SEOBetter\AI_Provider_Manager::get_saved_providers();

// Handle license activation
if ( isset( $_POST['seobetter_activate_license'] ) && check_admin_referer( 'seobetter_settings_nonce' ) ) {
    $result = SEOBetter\License_Manager::activate( $_POST['license_key'] ?? '' );
    if ( $result['success'] ) {
        echo '<div class="notice notice-success"><p>' . esc_html( $result['message'] ) . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . esc_html( $result['message'] ) . '</p></div>';
    }
    $license_info = SEOBetter\License_Manager::get_info();
}

// Handle license deactivation
if ( isset( $_POST['seobetter_deactivate_license'] ) && check_admin_referer( 'seobetter_settings_nonce' ) ) {
    SEOBetter\License_Manager::deactivate();
    $license_info = SEOBetter\License_Manager::get_info();
    echo '<div class="notice notice-info"><p>' . esc_html__( 'License deactivated.', 'seobetter' ) . '</p></div>';
}

// Handle AI provider save
if ( isset( $_POST['seobetter_save_provider'] ) && check_admin_referer( 'seobetter_settings_nonce' ) ) {
    $provider_id = sanitize_text_field( $_POST['provider_id'] ?? '' );
    $saved = SEOBetter\AI_Provider_Manager::save_provider( $provider_id, [
        'api_key' => $_POST['provider_api_key'] ?? '',
        'model'   => $_POST['provider_model'] ?? '',
        'api_url' => $_POST['provider_api_url'] ?? '',
    ] );
    if ( $saved ) {
        echo '<div class="notice notice-success"><p>' . esc_html__( 'AI provider saved.', 'seobetter' ) . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to save. Free tier allows 1 provider only. Upgrade to Pro for unlimited.', 'seobetter' ) . '</p></div>';
    }
    $saved_providers = SEOBetter\AI_Provider_Manager::get_saved_providers();
}

// Handle AI provider removal
if ( isset( $_POST['seobetter_remove_provider'] ) && check_admin_referer( 'seobetter_settings_nonce' ) ) {
    SEOBetter\AI_Provider_Manager::remove_provider( sanitize_text_field( $_POST['remove_provider_id'] ?? '' ) );
    $saved_providers = SEOBetter\AI_Provider_Manager::get_saved_providers();
    echo '<div class="notice notice-info"><p>' . esc_html__( 'Provider removed.', 'seobetter' ) . '</p></div>';
}

// Handle general settings save
if ( isset( $_POST['seobetter_save_settings'] ) && check_admin_referer( 'seobetter_settings_nonce' ) ) {
    // v1.5.24 — preserve existing settings and merge in only the fields
    // from this form so other-form fields (e.g. places_integrations save)
    // don't get wiped when the main settings form is saved.
    // v1.5.186 — Only update fields that belong to THIS form section.
    // Previously this handler also wrote author_* fields with empty defaults,
    // wiping the Author Bio when the General Settings form was saved
    // (because author_* fields are in a SEPARATE form).
    $existing = get_option( 'seobetter_settings', [] );
    $settings = array_merge( $existing, [
        'auto_schema'        => ! empty( $_POST['auto_schema'] ),
        'auto_analyze'       => ! empty( $_POST['auto_analyze'] ),
        'target_readability' => absint( $_POST['target_readability'] ?? 7 ),
        'geo_engines'        => array_map( 'sanitize_text_field', $_POST['geo_engines'] ?? [] ),
        'llms_txt_enabled'   => ! empty( $_POST['llms_txt_enabled'] ),
        'tavily_api_key'     => sanitize_text_field( $_POST['tavily_api_key'] ?? '' ),
        'pexels_api_key'     => sanitize_text_field( $_POST['pexels_api_key'] ?? '' ),
    ] );
    // Author bio fields are NOT in this form — don't touch them
    update_option( 'seobetter_settings', $settings );
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'seobetter' ) . '</p></div>';
}

// v1.5.187 — Separate Author Bio save handler.
// Previously shared the same submit button name as General Settings,
// so clicking "Save Author Bio" ran the General Settings handler
// which didn't include author_* fields → wiped them.
if ( isset( $_POST['seobetter_save_author'] ) && check_admin_referer( 'seobetter_settings_nonce' ) ) {
    $existing = get_option( 'seobetter_settings', [] );
    $settings = array_merge( $existing, [
        'author_name'        => sanitize_text_field( $_POST['author_name'] ?? '' ),
        'author_title'       => sanitize_text_field( $_POST['author_title'] ?? '' ),
        'author_bio'         => sanitize_textarea_field( $_POST['author_bio'] ?? '' ),
        'author_image'       => esc_url_raw( $_POST['author_image'] ?? '' ),
        'author_linkedin'    => esc_url_raw( $_POST['author_linkedin'] ?? '' ),
        'author_twitter'     => esc_url_raw( $_POST['author_twitter'] ?? '' ),
        'author_facebook'    => esc_url_raw( $_POST['author_facebook'] ?? '' ),
        'author_instagram'   => esc_url_raw( $_POST['author_instagram'] ?? '' ),
        'author_youtube'     => esc_url_raw( $_POST['author_youtube'] ?? '' ),
        'author_website'     => esc_url_raw( $_POST['author_website'] ?? '' ),
        'author_credentials' => sanitize_text_field( $_POST['author_credentials'] ?? '' ),
        'author_experience'  => sanitize_text_field( $_POST['author_experience'] ?? '' ),
    ] );
    update_option( 'seobetter_settings', $settings );
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Author bio saved.', 'seobetter' ) . '</p></div>';
}

// v1.5.24 — Places Integrations save handler. Lives in its own form so saving
// one section doesn't clobber the other. Stores three optional API keys that
// flow through Trend_Researcher → cloud-api for the 5-tier Places waterfall.
if ( isset( $_POST['seobetter_save_places'] ) && check_admin_referer( 'seobetter_places_nonce' ) ) {
    $existing = get_option( 'seobetter_settings', [] );
    $allowed_sonar_models = [ 'perplexity/sonar', 'perplexity/sonar-pro' ];
    $submitted_model = sanitize_text_field( $_POST['sonar_model'] ?? 'perplexity/sonar' );
    if ( ! in_array( $submitted_model, $allowed_sonar_models, true ) ) {
        $submitted_model = 'perplexity/sonar';
    }
    $settings = array_merge( $existing, [
        'foursquare_api_key'    => sanitize_text_field( $_POST['foursquare_api_key'] ?? '' ),
        'here_api_key'          => sanitize_text_field( $_POST['here_api_key'] ?? '' ),
        'google_places_api_key' => sanitize_text_field( $_POST['google_places_api_key'] ?? '' ),
        // v1.5.30 — Perplexity Sonar via OpenRouter (Tier 0)
        'openrouter_api_key'    => sanitize_text_field( $_POST['openrouter_api_key'] ?? '' ),
        'sonar_model'           => $submitted_model,
    ] );
    update_option( 'seobetter_settings', $settings );
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Places integrations saved.', 'seobetter' ) . '</p></div>';
}

// v1.5.32 — Branding + AI featured image save handler. Lives in its own form
// with its own nonce. Stores brand identity (logo ID, colors, business
// description) and the AI image provider config used by
// AI_Image_Generator::generate() to produce article featured images.
if ( isset( $_POST['seobetter_save_branding'] ) && check_admin_referer( 'seobetter_branding_nonce' ) ) {
    $existing = get_option( 'seobetter_settings', [] );
    // v1.5.216.1 — Trimmed image provider list to 3 options (free + Nano Banana
    // via two paths). Keeping the AI_Image_Generator code paths for dalle3 +
    // flux_pro intact so existing users' saved settings still work, but they're
    // hidden from the dropdown going forward to keep the picker focused.
    $allowed_providers = [ '', 'pollinations', 'openrouter', 'gemini' ];
    $provider = sanitize_text_field( $_POST['branding_provider'] ?? '' );
    if ( ! in_array( $provider, $allowed_providers, true ) ) {
        $provider = '';
    }
    $allowed_styles = [ 'realistic', 'illustration', 'flat', 'hero', 'minimalist', 'editorial', '3d' ];
    $style = sanitize_text_field( $_POST['branding_style'] ?? 'realistic' );
    if ( ! in_array( $style, $allowed_styles, true ) ) {
        $style = 'realistic';
    }
    $settings = array_merge( $existing, [
        'branding_provider'        => $provider,
        'branding_api_key'         => sanitize_text_field( $_POST['branding_api_key'] ?? '' ),
        'branding_style'           => $style,
        'branding_business_name'   => sanitize_text_field( $_POST['branding_business_name'] ?? '' ),
        'branding_description'     => sanitize_textarea_field( $_POST['branding_description'] ?? '' ),
        'branding_color_primary'   => sanitize_hex_color( $_POST['branding_color_primary'] ?? '' ),
        'branding_color_secondary' => sanitize_hex_color( $_POST['branding_color_secondary'] ?? '' ),
        'branding_color_accent'    => sanitize_hex_color( $_POST['branding_color_accent'] ?? '' ),
        'branding_logo_id'         => absint( $_POST['branding_logo_id'] ?? 0 ),
        'branding_negative_prompt' => sanitize_textarea_field( $_POST['branding_negative_prompt'] ?? '' ),
        // v1.5.216.9 — render-headline-as-text-overlay toggle. Default ON.
        'branding_text_overlay'    => isset( $_POST['branding_text_overlay'] ) ? '1' : '0',
    ] );
    update_option( 'seobetter_settings', $settings );
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Branding & AI image settings saved.', 'seobetter' ) . '</p></div>';
}

// v1.5.216.25 — Phase 1 item 6: Brand Voice save/delete handlers
if ( isset( $_POST['seobetter_save_brand_voice'] ) && check_admin_referer( 'seobetter_brand_voice_nonce' ) ) {
    $voice_id = sanitize_key( $_POST['voice_id'] ?? '' );
    $result = SEOBetter\Brand_Voice_Manager::save( $voice_id, [
        'name'            => $_POST['voice_name']           ?? '',
        'description'     => $_POST['voice_description']    ?? '',
        'sample_text'     => $_POST['voice_sample_text']    ?? '',
        'tone_directives' => $_POST['voice_tone_directives'] ?? '',
        'banned_phrases'  => $_POST['voice_banned_phrases'] ?? '',
    ] );
    if ( ! empty( $result['success'] ) ) {
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Brand voice saved.', 'seobetter' ) . '</p></div>';
        // Redirect cleanly so refresh doesn't re-submit
        echo '<script>history.replaceState(null,"",window.location.pathname+"?page=seobetter-settings#brand-voice")</script>';
    } else {
        echo '<div class="notice notice-error"><p>' . esc_html( $result['error'] ?? 'Save failed.' ) . '</p></div>';
    }
}
if ( isset( $_POST['seobetter_delete_brand_voice'] ) && check_admin_referer( 'seobetter_brand_voice_nonce' ) ) {
    $voice_id = sanitize_key( $_POST['voice_id'] ?? '' );
    if ( SEOBetter\Brand_Voice_Manager::delete( $voice_id ) ) {
        echo '<div class="notice notice-info"><p>' . esc_html__( 'Brand voice deleted.', 'seobetter' ) . '</p></div>';
    }
}

$settings = get_option( 'seobetter_settings', [] );
?>
<div class="wrap seobetter-dashboard">
    <h1><?php esc_html_e( 'SEOBetter Settings', 'seobetter' ); ?></h1>

    <!-- License Section -->
    <div class="seobetter-card" style="margin-bottom:20px">
        <h2><?php esc_html_e( 'License', 'seobetter' ); ?>
            <span class="seobetter-score seobetter-score-<?php echo $license_info['is_pro'] ? 'good' : 'ok'; ?>" style="margin-left:10px">
                <?php echo esc_html( strtoupper( $license_info['tier'] ) ); ?>
            </span>
        </h2>

        <form method="post">
            <?php wp_nonce_field( 'seobetter_settings_nonce' ); ?>

            <?php if ( $license_info['is_pro'] ) : ?>
                <p style="color:green"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Pro license active.', 'seobetter' ); ?>
                    <?php if ( $license_info['key'] ) : ?>
                        <code><?php echo esc_html( $license_info['key'] ); ?></code>
                    <?php endif; ?>
                </p>
                <button type="submit" name="seobetter_deactivate_license" class="button"><?php esc_html_e( 'Deactivate License', 'seobetter' ); ?></button>
            <?php else : ?>
                <p><?php esc_html_e( 'Enter your Pro license key to unlock all features.', 'seobetter' ); ?>
                    <a href="https://seobetter.com/pricing" target="_blank"><?php esc_html_e( 'Get Pro', 'seobetter' ); ?> &rarr;</a>
                </p>
                <input type="text" name="license_key" placeholder="SEOBETTER-XXXX-XXXX-XXXX" class="regular-text" />
                <button type="submit" name="seobetter_activate_license" class="button button-primary"><?php esc_html_e( 'Activate', 'seobetter' ); ?></button>
            <?php endif; ?>
        </form>
    </div>

    <!-- v1.5.216 — AI generation source card (rewritten for BYOK-only free tier) -->
    <?php
    $cloud_status = SEOBetter\Cloud_API::check_status();
    $is_byok      = ! empty( $cloud_status['has_own_key'] );
    $is_pro_user  = ! empty( $license_info['is_pro'] );
    ?>
    <div class="seobetter-card" style="margin-bottom:20px;border-left:4px solid <?php echo $is_byok ? '#3b82f6' : ( $is_pro_user ? '#8b5cf6' : '#ef4444' ); ?>">
        <h2 style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
            <span style="font-size:18px"><?php echo $is_byok ? '🔑' : ( $is_pro_user ? '☁️' : '⚠️' ); ?></span>
            <?php esc_html_e( 'AI generation source', 'seobetter' ); ?>
            <span style="margin-left:auto;font-size:11px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:<?php echo $is_byok ? '#3b82f6' : ( $is_pro_user ? '#8b5cf6' : '#ef4444' ); ?>">
                <?php
                if ( $is_byok ) echo esc_html__( 'BYOK ACTIVE', 'seobetter' );
                elseif ( $is_pro_user ) echo esc_html__( 'CLOUD ACTIVE (PRO)', 'seobetter' );
                else echo esc_html__( 'NOT CONFIGURED', 'seobetter' );
                ?>
            </span>
        </h2>

        <?php if ( $is_byok ) : ?>
            <p class="description" style="margin-bottom:14px">
                <?php esc_html_e( 'Article generation runs through your own connected provider below. You pay your AI provider directly per request — unlimited generation, no SEOBetter Cloud cost.', 'seobetter' ); ?>
            </p>
        <?php elseif ( $is_pro_user ) : ?>
            <p class="description" style="margin-bottom:14px">
                <?php esc_html_e( 'Pro tier active — generation runs through SEOBetter Cloud (centralized OpenRouter + Firecrawl + Serper + Pexels stack). You don\'t need any API keys. Optional: connect your own key below to bypass the Cloud and run unlimited via your own provider.', 'seobetter' ); ?>
            </p>
        <?php else : ?>
            <!-- Free tier without BYOK: BLOCKING — user must connect a provider OR upgrade to Pro -->
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 18px;margin-bottom:14px">
                <h4 style="margin:0 0 8px 0;color:#991b1b;font-size:14px">
                    <?php esc_html_e( '⚠ Article generation is not configured yet', 'seobetter' ); ?>
                </h4>
                <p style="margin:0 0 10px 0;font-size:13px;color:#7f1d1d;line-height:1.55">
                    <?php esc_html_e( 'SEOBetter\'s free tier requires you to connect your own AI provider (OpenRouter / Anthropic / OpenAI / Gemini / Groq) below. You pay your provider directly — usually $0.01-$0.08 per article — and the plugin does the SEO + schema + GEO work for free.', 'seobetter' ); ?>
                </p>
                <p style="margin:0;font-size:13px;color:#7f1d1d;line-height:1.55">
                    <?php esc_html_e( 'Don\'t want to manage API keys?', 'seobetter' ); ?>
                    <a href="https://seobetter.com/pricing" target="_blank" style="color:#991b1b;font-weight:600;text-decoration:underline"><?php esc_html_e( 'Upgrade to Pro ($39/mo) →', 'seobetter' ); ?></a>
                    <?php esc_html_e( 'and we handle generation through SEOBetter Cloud — no keys, just generate.', 'seobetter' ); ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Pro upsell card (free tier only — refreshed v1.5.216 for BYOK-free model) -->
        <?php if ( ! $is_pro_user ) : ?>
        <div style="background:linear-gradient(135deg,#faf5ff 0%,#ede9fe 100%);border:1px solid #ddd6fe;border-radius:8px;padding:14px 18px">
            <h4 style="margin:0 0 10px 0;color:#5b21b6"><?php esc_html_e( 'What Pro adds — $39/month', 'seobetter' ); ?></h4>
            <ul style="margin:0 0 12px 0;padding:0;list-style:none;font-size:13px;color:#4c1d95">
                <li style="margin-bottom:5px">✓ <?php esc_html_e( '50 Cloud-generated articles/month — no API keys to manage, ever', 'seobetter' ); ?></li>
                <li style="margin-bottom:5px">✓ <?php esc_html_e( 'Premium tier LLM (Claude Sonnet 4.6) — best instruction-following + multilingual', 'seobetter' ); ?></li>
                <li style="margin-bottom:5px">✓ <?php esc_html_e( 'Firecrawl deep research (10× citation density) + Serper SERP intelligence', 'seobetter' ); ?></li>
                <li style="margin-bottom:5px">✓ <?php esc_html_e( 'All 21 content types + Recipe Article wrapper + Speakable voice schema', 'seobetter' ); ?></li>
                <li style="margin-bottom:5px">✓ <?php esc_html_e( 'AI featured image via Nano Banana (Pollinations free / OpenRouter / Gemini direct) + 5 schema blocks', 'seobetter' ); ?></li>
                <li>✓ <?php esc_html_e( 'Or: keep BYOK active and use Pro for the advanced features only', 'seobetter' ); ?></li>
            </ul>
            <a href="https://seobetter.com/pricing" target="_blank" class="button button-primary"><?php esc_html_e( 'See Pro plans →', 'seobetter' ); ?></a>
        </div>
        <?php endif; ?>
    </div>

    <!-- AI Providers Section (BYOK) -->
    <div class="seobetter-card" id="seobetter-byok-section" style="margin-bottom:20px">
        <h2><?php esc_html_e( 'Connect your AI provider', 'seobetter' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Free tier requires a provider connection — articles generate through your own AI account, you pay your provider directly per token (~$0.01–$0.08 per article depending on the model). Your key is stored locally and never sent to SEOBetter servers. Skip this entirely on Pro — Cloud generation is included.', 'seobetter' ); ?></p>

        <?php if ( ! $license_info['is_pro'] ) : ?>
            <p style="color:#856404;background:#fff3cd;padding:8px 12px;border-radius:4px">
                <span class="dashicons dashicons-info-outline"></span>
                <?php esc_html_e( 'Free tier: 1 connected provider at a time. Upgrade to Pro for multiple providers + Cloud generation (no key needed).', 'seobetter' ); ?>
            </p>
        <?php endif; ?>

        <!-- Connected Providers -->
        <?php if ( ! empty( $saved_providers ) ) : ?>
        <h3><?php esc_html_e( 'Connected Providers', 'seobetter' ); ?></h3>
        <table class="widefat striped" style="margin-bottom:20px">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Provider', 'seobetter' ); ?></th>
                    <th><?php esc_html_e( 'Model', 'seobetter' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'seobetter' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'seobetter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $saved_providers as $pid => $pconfig ) :
                    $pdef = $providers[ $pid ] ?? null;
                    if ( ! $pdef ) continue;
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $pdef['name'] ); ?></strong></td>
                    <td><code><?php echo esc_html( $pconfig['model'] ?? $pdef['default_model'] ); ?></code></td>
                    <td><span class="dashicons dashicons-yes-alt" style="color:green"></span> <?php esc_html_e( 'Connected', 'seobetter' ); ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field( 'seobetter_settings_nonce' ); ?>
                            <input type="hidden" name="remove_provider_id" value="<?php echo esc_attr( $pid ); ?>" />
                            <button type="submit" name="seobetter_remove_provider" class="button button-small" onclick="return confirm('Remove this provider?')"><?php esc_html_e( 'Remove', 'seobetter' ); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- v1.5.32 / v1.5.216.1 — Quick-Pick Model Presets -->
        <div style="margin-top:20px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
            <h3 style="margin:0 0 8px 0"><?php esc_html_e( '⚡ Quick Pick — Recommended Models', 'seobetter' ); ?></h3>
            <p class="description" style="margin:0 0 12px 0">
                <?php esc_html_e( 'Click a preset below to auto-fill the provider + model fields. All four are tested with SEOBetter\'s strict rule-following requirements. The "Recommended" pick (OpenRouter → Haiku 4.5) works for most users worldwide — single key, intl payment friendly, ~$0.02/article.', 'seobetter' ); ?>
            </p>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <?php foreach ( \SEOBetter\AI_Provider_Manager::get_quick_picks() as $qkey => $q ) : ?>
                    <button type="button" class="button sb-quick-pick" data-provider="<?php echo esc_attr( $q['provider'] ); ?>" data-model="<?php echo esc_attr( $q['model'] ); ?>" style="flex:1;min-width:220px;height:auto;padding:12px;text-align:left;border-left:4px solid <?php echo esc_attr( $q['badge_color'] ); ?>">
                        <strong style="display:block;font-size:13px;margin-bottom:4px"><?php echo esc_html( $q['label'] ); ?></strong>
                        <span style="font-size:11px;color:#64748b;white-space:normal;line-height:1.4"><?php echo esc_html( $q['description'] ); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <p class="description" style="margin:12px 0 0 0;font-size:11px">
                <strong>⚠️ <?php esc_html_e( 'Avoid these models:', 'seobetter' ); ?></strong>
                <?php esc_html_e( 'Llama 3.1/3.3, DeepSeek R1, DeepSeek v3, Mixtral, OpenAI o3/o4, Perplexity Sonar (research model, not a writer). These either ignore PLACES RULES under complex prompts or are not designed for structured article writing. They will produce hallucinated business names even when real data is available.', 'seobetter' ); ?>
            </p>
        </div>

        <!-- Add New Provider -->
        <h3><?php esc_html_e( 'Add AI Provider (Advanced)', 'seobetter' ); ?></h3>
        <form method="post">
            <?php wp_nonce_field( 'seobetter_settings_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Provider', 'seobetter' ); ?></th>
                    <td>
                        <select name="provider_id" id="seobetter-provider-select">
                            <?php foreach ( $providers as $pid => $pdef ) : ?>
                                <?php
                                // v1.5.32 — decorate each model with its tier badge
                                // so users see compatibility at a glance.
                                $decorated_models = [];
                                foreach ( $pdef['models'] as $m ) {
                                    $t = \SEOBetter\AI_Provider_Manager::get_model_tier( $m );
                                    $badge = [ 'green' => ' 🟢', 'amber' => ' 🟡', 'red' => ' 🔴', 'unknown' => '' ][ $t ] ?? '';
                                    $decorated_models[] = [ 'id' => $m, 'label' => $m . $badge, 'tier' => $t ];
                                }
                                ?>
                                <option value="<?php echo esc_attr( $pid ); ?>"
                                    data-models="<?php echo esc_attr( wp_json_encode( $decorated_models ) ); ?>"
                                    data-default="<?php echo esc_attr( $pdef['default_model'] ); ?>"
                                    data-help="<?php echo esc_attr( $pdef['help'] ); ?>"
                                    data-docs="<?php echo esc_attr( $pdef['docs_url'] ); ?>"
                                    data-needs-key="<?php echo $pid === 'ollama' ? '0' : '1'; ?>"
                                    data-needs-url="<?php echo $pid === 'custom' ? '1' : '0'; ?>"
                                ><?php echo esc_html( $pdef['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description" id="seobetter-provider-help"></p>
                    </td>
                </tr>
                <tr id="seobetter-key-row">
                    <th><?php esc_html_e( 'API Key', 'seobetter' ); ?></th>
                    <td>
                        <input type="password" name="provider_api_key" class="regular-text" />
                        <a href="#" id="seobetter-docs-link" target="_blank" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Get Key', 'seobetter' ); ?></a>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Model', 'seobetter' ); ?></th>
                    <td>
                        <select name="provider_model" id="seobetter-model-select"></select>
                    </td>
                </tr>
                <tr id="seobetter-url-row" style="display:none">
                    <th><?php esc_html_e( 'API URL', 'seobetter' ); ?></th>
                    <td><input type="url" name="provider_api_url" class="regular-text" placeholder="https://your-api-endpoint/v1/chat/completions" /></td>
                </tr>
            </table>
            <button type="submit" name="seobetter_save_provider" class="button button-primary"><?php esc_html_e( 'Connect Provider', 'seobetter' ); ?></button>
        </form>
    </div>

    <!-- General Settings -->
    <div class="seobetter-card" style="margin-bottom:20px">
        <h2><?php esc_html_e( 'General Settings', 'seobetter' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'seobetter_settings_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Target Readability', 'seobetter' ); ?></th>
                    <td>
                        <input type="number" name="target_readability" value="<?php echo esc_attr( $settings['target_readability'] ?? 7 ); ?>" min="4" max="12" />
                        <p class="description"><?php esc_html_e( 'Flesch-Kincaid grade level (recommended: 6-8)', 'seobetter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Auto-generate Schema', 'seobetter' ); ?></th>
                    <td><label><input type="checkbox" name="auto_schema" value="1" <?php checked( $settings['auto_schema'] ?? true ); ?> /> <?php esc_html_e( 'Generate JSON-LD schema on post save', 'seobetter' ); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Auto-analyze Content', 'seobetter' ); ?></th>
                    <td><label><input type="checkbox" name="auto_analyze" value="1" <?php checked( $settings['auto_analyze'] ?? true ); ?> /> <?php esc_html_e( 'Run GEO analysis on post save', 'seobetter' ); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Target AI Engines', 'seobetter' ); ?></th>
                    <td>
                        <?php
                        $engines = [ 'google_aio' => 'Google AI Overviews', 'perplexity' => 'Perplexity', 'searchgpt' => 'SearchGPT', 'gemini' => 'Gemini', 'claude' => 'Claude' ];
                        $selected = $settings['geo_engines'] ?? array_keys( $engines );
                        foreach ( $engines as $key => $label ) : ?>
                            <label style="display:block;margin-bottom:4px"><input type="checkbox" name="geo_engines[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'llms.txt', 'seobetter' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="llms_txt_enabled" value="1" <?php checked( $settings['llms_txt_enabled'] ?? true ); ?> /> <?php esc_html_e( 'Enable llms.txt for AI crawlers', 'seobetter' ); ?></label>
                        <p class="description"><code><?php echo esc_html( home_url( '/llms.txt' ) ); ?></code></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Tavily API Key', 'seobetter' ); ?></th>
                    <td>
                        <input type="password" name="tavily_api_key" value="<?php echo esc_attr( $settings['tavily_api_key'] ?? '' ); ?>" class="regular-text" placeholder="tvly-..." />
                        <a href="https://tavily.com" target="_blank" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Get Free Key', 'seobetter' ); ?></a>
                        <p class="description"><?php esc_html_e( 'Free (1,000/month). Powers real expert quotes and citations with verified source URLs. Extracts actual text from real web pages — zero hallucination.', 'seobetter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Pexels API Key', 'seobetter' ); ?></th>
                    <td>
                        <input type="password" name="pexels_api_key" value="<?php echo esc_attr( $settings['pexels_api_key'] ?? '' ); ?>" class="regular-text" placeholder="Enter Pexels API key..." />
                        <a href="https://www.pexels.com/api/new/" target="_blank" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Get Free Key', 'seobetter' ); ?></a>
                        <p class="description"><?php esc_html_e( 'Optional. Free Pexels key — 15,000 requests/month from your own account. If you don\'t add a key, SEOBetter Cloud automatically supplies Pexels images via a shared pool. Picsum is the last-resort fallback only when both fail.', 'seobetter' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Settings', 'seobetter' ), 'primary', 'seobetter_save_settings' ); ?>
        </form>
    </div>

    <!-- Author Bio / E-E-A-T (v1.5.139) -->
    <div class="seobetter-card" style="margin-bottom:20px">
        <h2><?php esc_html_e( 'Author Bio (E-E-A-T)', 'seobetter' ); ?></h2>
        <p class="description" style="margin-bottom:16px">
            <?php esc_html_e( 'Configure your author profile for Google E-E-A-T (Experience, Expertise, Authoritativeness, Trust). This bio is appended to every article and included in Person schema markup. Essential for YMYL topics (health, finance).', 'seobetter' ); ?>
        </p>
        <form method="post">
            <?php wp_nonce_field( 'seobetter_settings_nonce' ); ?>
            <?php
            // Preserve all existing settings so this form doesn't wipe them
            foreach ( $settings as $k => $v ) {
                if ( is_array( $v ) ) continue;
                if ( in_array( $k, [ 'author_name', 'author_title', 'author_bio', 'author_image', 'author_linkedin', 'author_twitter', 'author_facebook', 'author_instagram', 'author_youtube', 'author_website', 'author_credentials', 'author_experience' ], true ) ) continue;
                echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
            }
            // Preserve array settings
            foreach ( ( $settings['geo_engines'] ?? [] ) as $ge ) {
                echo '<input type="hidden" name="geo_engines[]" value="' . esc_attr( $ge ) . '" />';
            }
            ?>
            <table class="form-table">
                <tr>
                    <th><label for="author_name"><?php esc_html_e( 'Full Name', 'seobetter' ); ?> <span style="color:#ef4444">*</span></label></th>
                    <td>
                        <input type="text" name="author_name" id="author_name" value="<?php echo esc_attr( $settings['author_name'] ?? '' ); ?>" class="regular-text" placeholder="e.g. Sarah Chen" />
                        <p class="description"><?php esc_html_e( 'Your real name. Never use "staff" or "admin" — Google penalizes anonymous content.', 'seobetter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="author_title"><?php esc_html_e( 'Job Title', 'seobetter' ); ?></label></th>
                    <td>
                        <input type="text" name="author_title" id="author_title" value="<?php echo esc_attr( $settings['author_title'] ?? '' ); ?>" class="regular-text" placeholder="e.g. Senior Veterinary Nutritionist" />
                    </td>
                </tr>
                <tr>
                    <th><label for="author_credentials"><?php esc_html_e( 'Credentials', 'seobetter' ); ?></label></th>
                    <td>
                        <input type="text" name="author_credentials" id="author_credentials" value="<?php echo esc_attr( $settings['author_credentials'] ?? '' ); ?>" class="regular-text" placeholder="e.g. DVM, MSc Animal Nutrition" />
                        <p class="description"><?php esc_html_e( 'Degrees, certifications, awards. Shown after your name.', 'seobetter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="author_experience"><?php esc_html_e( 'Experience', 'seobetter' ); ?></label></th>
                    <td>
                        <input type="text" name="author_experience" id="author_experience" value="<?php echo esc_attr( $settings['author_experience'] ?? '' ); ?>" class="regular-text" placeholder="e.g. 12 years in veterinary practice" />
                    </td>
                </tr>
                <tr>
                    <th><label for="author_bio"><?php esc_html_e( 'Bio (100-200 words)', 'seobetter' ); ?></label></th>
                    <td>
                        <textarea name="author_bio" id="author_bio" rows="4" class="large-text" placeholder="Write in third person. e.g. Sarah Chen is a veterinary nutritionist with over 12 years of experience..."><?php echo esc_textarea( $settings['author_bio'] ?? '' ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Third person, professional tone. Explain why you are qualified to write about your topics.', 'seobetter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="author_image"><?php esc_html_e( 'Headshot URL', 'seobetter' ); ?></label></th>
                    <td>
                        <input type="url" name="author_image" id="author_image" value="<?php echo esc_attr( $settings['author_image'] ?? '' ); ?>" class="regular-text" placeholder="https://example.com/photo.jpg" />
                        <button type="button" class="button" onclick="var frame=wp.media({title:'Select Author Photo',multiple:false});frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();document.getElementById('author_image').value=a.url});frame.open()"><?php esc_html_e( 'Upload', 'seobetter' ); ?></button>
                        <p class="description"><?php esc_html_e( 'Professional photo. Use the same photo across all your profiles for Google entity recognition.', 'seobetter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Social Profiles', 'seobetter' ); ?></th>
                    <td>
                        <div style="display:grid;grid-template-columns:100px 1fr;gap:8px;align-items:center;max-width:500px">
                            <label>LinkedIn</label><input type="url" name="author_linkedin" value="<?php echo esc_attr( $settings['author_linkedin'] ?? '' ); ?>" class="regular-text" placeholder="https://linkedin.com/in/username" />
                            <label>X / Twitter</label><input type="url" name="author_twitter" value="<?php echo esc_attr( $settings['author_twitter'] ?? '' ); ?>" class="regular-text" placeholder="https://x.com/username" />
                            <label>Facebook</label><input type="url" name="author_facebook" value="<?php echo esc_attr( $settings['author_facebook'] ?? '' ); ?>" class="regular-text" placeholder="https://facebook.com/username" />
                            <label>Instagram</label><input type="url" name="author_instagram" value="<?php echo esc_attr( $settings['author_instagram'] ?? '' ); ?>" class="regular-text" placeholder="https://instagram.com/username" />
                            <label>YouTube</label><input type="url" name="author_youtube" value="<?php echo esc_attr( $settings['author_youtube'] ?? '' ); ?>" class="regular-text" placeholder="https://youtube.com/@channel" />
                            <label>Website</label><input type="url" name="author_website" value="<?php echo esc_attr( $settings['author_website'] ?? '' ); ?>" class="regular-text" placeholder="https://yourwebsite.com" />
                        </div>
                        <p class="description" style="margin-top:8px"><?php esc_html_e( 'Link to your real profiles. Google uses sameAs links to build your Knowledge Graph entity.', 'seobetter' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Author Bio', 'seobetter' ), 'primary', 'seobetter_save_author' ); ?>
        </form>
    </div>

    <!-- Places Integrations (v1.5.24) -->
    <div class="seobetter-card" style="margin-bottom:20px">
        <h2><?php esc_html_e( 'Places Integrations (Local Business Data)', 'seobetter' ); ?></h2>
        <p class="description" style="margin-bottom:16px">
            <?php esc_html_e( 'Configure optional Places API keys to get real local business data for listicles, buying guides, and reviews. Without any keys, SEOBetter uses free OpenStreetMap + Wikidata (covers ~40% of small cities globally). Adding free Foursquare + HERE keys raises coverage to ~85%. Adding Google Places raises it to ~99%.', 'seobetter' ); ?>
        </p>
        <p class="description" style="padding:8px 12px;background:#eef2ff;border-radius:4px;color:#3730a3;margin-bottom:16px">
            <span class="dashicons dashicons-info-outline"></span>
            <?php esc_html_e( 'All keys are OPTIONAL. The plugin works out of the box with free OSM + Wikidata. Only add keys if you want better coverage for small cities.', 'seobetter' ); ?>
        </p>

        <form method="post">
            <?php wp_nonce_field( 'seobetter_places_nonce' ); ?>
            <table class="form-table">

                <!-- Perplexity Sonar (v1.5.30, RECOMMENDED for small cities) -->
                <?php
                // v1.5.40 — detect if the user has an OpenRouter provider
                // configured in the AI Providers section but has NOT populated
                // this Places Sonar field. If so, show a prominent reuse banner
                // so they don't have to paste the same key twice.
                $has_ai_openrouter = false;
                $ai_providers_cfg = get_option( 'seobetter_ai_providers', [] );
                if ( is_array( $ai_providers_cfg ) && ! empty( $ai_providers_cfg['openrouter']['api_key'] ) ) {
                    $has_ai_openrouter = true;
                }
                $places_openrouter_empty = empty( $settings['openrouter_api_key'] );
                ?>
                <!-- v1.5.41 — Sonar Diagnostic Card. Always shown, always actionable. -->
                <tr>
                    <td colspan="2" style="padding:0;background:transparent">
                        <div style="padding:16px 18px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:8px;margin-bottom:12px">
                            <strong style="font-size:14px;color:#0f172a"><?php esc_html_e( '🔬 Sonar Tier 0 Diagnostic', 'seobetter' ); ?></strong>
                            <p class="description" style="margin:6px 0 10px 0;color:#475569"><?php esc_html_e( 'Current state of the Places Sonar Tier 0 setup. Click Test Connection below to run a live call against Lucignano (a known-good test case) and verify Sonar is reachable.', 'seobetter' ); ?></p>
                            <div style="display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:12px;margin-bottom:12px">
                                <strong><?php esc_html_e( 'Plugin version:', 'seobetter' ); ?></strong>
                                <span><code><?php echo esc_html( defined( 'SEOBETTER_VERSION' ) ? SEOBETTER_VERSION : '?' ); ?></code></span>
                                <strong><?php esc_html_e( 'AI Providers → OpenRouter:', 'seobetter' ); ?></strong>
                                <span><?php echo $has_ai_openrouter ? '<span style="color:#16a34a">✅ ' . esc_html__( 'Configured', 'seobetter' ) . '</span>' : '<span style="color:#dc2626">❌ ' . esc_html__( 'Not configured', 'seobetter' ) . '</span>'; ?></span>
                                <strong><?php esc_html_e( 'Places Integrations → Sonar field:', 'seobetter' ); ?></strong>
                                <span><?php echo $places_openrouter_empty ? '<span style="color:#6b7280">⚪ ' . esc_html__( 'Empty (will auto-reuse AI Providers key if v1.5.40+)', 'seobetter' ) . '</span>' : '<span style="color:#16a34a">✅ ' . esc_html__( 'Key saved', 'seobetter' ) . '</span>'; ?></span>
                                <strong><?php esc_html_e( 'Sonar model selected:', 'seobetter' ); ?></strong>
                                <span><code><?php echo esc_html( $settings['sonar_model'] ?? 'perplexity/sonar' ); ?></code></span>
                                <strong><?php esc_html_e( 'Auto-reuse from AI Providers:', 'seobetter' ); ?></strong>
                                <span><?php
                                if ( $has_ai_openrouter && $places_openrouter_empty ) {
                                    echo '<span style="color:#16a34a">✅ ' . esc_html__( 'Will auto-reuse (requires v1.5.40+)', 'seobetter' ) . '</span>';
                                } elseif ( $has_ai_openrouter && ! $places_openrouter_empty ) {
                                    echo '<span style="color:#6b7280">⚪ ' . esc_html__( 'Not needed — Places field has its own key', 'seobetter' ) . '</span>';
                                } else {
                                    echo '<span style="color:#dc2626">❌ ' . esc_html__( 'No AI Providers key to reuse', 'seobetter' ) . '</span>';
                                }
                                ?></span>
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px">
                                <input type="text" id="seobetter-sonar-test-keyword" placeholder="Test any keyword (e.g. best pet shops in mudgee nsw 2026)" style="flex:1;min-width:280px;padding:6px 10px;font-size:12px" value="" />
                                <input type="text" id="seobetter-sonar-test-country" placeholder="AU" maxlength="2" style="width:56px;padding:6px 10px;font-size:12px;text-transform:uppercase" value="" />
                            </div>
                            <p class="description" style="margin:0 0 8px 0;font-size:11px"><?php esc_html_e( 'Leave both empty to run the default Lucignano Italy sanity check. Fill in the keyword + 2-letter country code to test your own location. The test clears the cache first so every run hits the live API.', 'seobetter' ); ?></p>
                            <button type="button" id="seobetter-test-sonar" class="button button-primary" style="margin-right:8px"><?php esc_html_e( '🧪 Test Sonar Connection', 'seobetter' ); ?></button>
                            <span id="seobetter-test-sonar-status" style="font-size:12px;color:#6b7280"></span>
                            <div id="seobetter-test-sonar-result" style="margin-top:12px;display:none;padding:14px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;font-family:monospace;white-space:pre-wrap;max-height:400px;overflow-y:auto"></div>
                        </div>
                    </td>
                </tr>
                <?php if ( $has_ai_openrouter && $places_openrouter_empty ) : ?>
                <tr>
                    <td colspan="2" style="padding:0;background:transparent">
                        <div style="padding:14px 18px;background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;margin-bottom:8px">
                            <strong style="color:#92400e;font-size:14px">⚠️ <?php esc_html_e( 'Good news:', 'seobetter' ); ?></strong>
                            <?php esc_html_e( 'You already have an OpenRouter API key configured in the AI Providers section above. v1.5.40+ will AUTO-REUSE that same key for Perplexity Sonar Tier 0 below — you do NOT need to paste it twice. Just pick a Sonar model below and save. The Places waterfall will now use Sonar to find real businesses for any small city worldwide.', 'seobetter' ); ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><?php esc_html_e( 'Perplexity Sonar (via OpenRouter)', 'seobetter' ); ?>
                        <span class="seobetter-score seobetter-score-good" style="font-size:10px;margin-left:6px;background:#dbeafe;color:#1e40af"><?php esc_html_e( 'RECOMMENDED', 'seobetter' ); ?></span>
                        <?php if ( $has_ai_openrouter && $places_openrouter_empty ) : ?>
                            <br><span class="seobetter-score" style="font-size:10px;background:#dcfce7;color:#166534;margin-top:4px;display:inline-block"><?php esc_html_e( '✨ AUTO-REUSING AI PROVIDERS KEY', 'seobetter' ); ?></span>
                        <?php endif; ?>
                    </th>
                    <td>
                        <input type="password" name="openrouter_api_key" value="<?php echo esc_attr( $settings['openrouter_api_key'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo $has_ai_openrouter ? esc_attr__( 'Leave empty to auto-reuse the key from AI Providers above', 'seobetter' ) : 'sk-or-v1-...'; ?>" autocomplete="off" />
                        <a href="https://openrouter.ai/keys" target="_blank" rel="noopener" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Get OpenRouter Key', 'seobetter' ); ?></a>
                        <br><br>
                        <label for="sonar_model" style="display:inline-block;min-width:60px"><strong><?php esc_html_e( 'Model:', 'seobetter' ); ?></strong></label>
                        <select name="sonar_model" id="sonar_model">
                            <?php $sm = $settings['sonar_model'] ?? 'perplexity/sonar'; ?>
                            <option value="perplexity/sonar" <?php selected( $sm, 'perplexity/sonar' ); ?>>perplexity/sonar — ~$0.80 / 100 articles (fast, recommended)</option>
                            <option value="perplexity/sonar-pro" <?php selected( $sm, 'perplexity/sonar-pro' ); ?>>perplexity/sonar-pro — ~$6 / 100 articles (deeper search, better for small cities)</option>
                        </select>
                        <p class="description" style="background:#eff6ff;padding:10px 12px;border-left:3px solid #3b82f6;margin-top:8px">
                            <strong><?php esc_html_e( '✨ Best fix for small-city coverage.', 'seobetter' ); ?></strong>
                            <?php esc_html_e( 'Web-search LLM that pulls real businesses from TripAdvisor, Yelp, Wikivoyage, and local blogs with citations. Works for ANY city worldwide — zero per-user tier setup. 1-minute signup at openrouter.ai/keys, add $5 credit, paste the key here. Costs ~$0.008 per article on base sonar (~$0.80 per 100 articles). Runs as Tier 0 of the Places waterfall — OSM/Foursquare/HERE/Google below become fallbacks for when Sonar is rate-limited.', 'seobetter' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- OSM row (always active, no key) -->
                <tr>
                    <th><?php esc_html_e( 'OpenStreetMap + Wikidata', 'seobetter' ); ?>
                        <span class="seobetter-score seobetter-score-good" style="font-size:10px;margin-left:6px"><?php esc_html_e( 'ALWAYS ON', 'seobetter' ); ?></span>
                    </th>
                    <td>
                        <p style="margin:0"><?php esc_html_e( 'Free, no API key required. Powers the v1.5.23+ baseline Places coverage via Nominatim + Overpass + Wikidata SPARQL. Always active for every article — no setup needed.', 'seobetter' ); ?></p>
                    </td>
                </tr>

                <!-- Foursquare -->
                <tr>
                    <th><?php esc_html_e( 'Foursquare Places API Key', 'seobetter' ); ?>
                        <span class="seobetter-score seobetter-score-ok" style="font-size:10px;margin-left:6px"><?php esc_html_e( 'FREE', 'seobetter' ); ?></span>
                    </th>
                    <td>
                        <input type="password" name="foursquare_api_key" value="<?php echo esc_attr( $settings['foursquare_api_key'] ?? '' ); ?>" class="regular-text" placeholder="fsq..." autocomplete="off" />
                        <a href="https://developer.foursquare.com" target="_blank" rel="noopener" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Get Free Key', 'seobetter' ); ?></a>
                        <p class="description">
                            <?php esc_html_e( 'Free tier: 1,000 calls/day. Best small-city coverage via user check-ins — strong in Italy, Brazil, Portugal, Asia, and other non-Anglophone markets where OSM is weak. 3-minute signup at developer.foursquare.com → create a project → generate an API key → paste it here.', 'seobetter' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- HERE -->
                <tr>
                    <th><?php esc_html_e( 'HERE Places API Key', 'seobetter' ); ?>
                        <span class="seobetter-score seobetter-score-ok" style="font-size:10px;margin-left:6px"><?php esc_html_e( 'FREE', 'seobetter' ); ?></span>
                    </th>
                    <td>
                        <input type="password" name="here_api_key" value="<?php echo esc_attr( $settings['here_api_key'] ?? '' ); ?>" class="regular-text" placeholder="HERE..." autocomplete="off" />
                        <a href="https://developer.here.com" target="_blank" rel="noopener" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Get Free Key', 'seobetter' ); ?></a>
                        <p class="description">
                            <?php esc_html_e( 'Free tier: 1,000 transactions/day. Strong European and Asian tier-2 city coverage — powers Garmin, BMW, Mercedes nav systems. 5-minute signup at developer.here.com → create a Freemium app → generate API key → paste it here.', 'seobetter' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Google Places -->
                <tr>
                    <th><?php esc_html_e( 'Google Places API Key', 'seobetter' ); ?>
                        <span class="seobetter-score seobetter-score-poor" style="font-size:10px;margin-left:6px;background:#fef3c7;color:#92400e"><?php esc_html_e( 'PAID', 'seobetter' ); ?></span>
                    </th>
                    <td>
                        <input type="password" name="google_places_api_key" value="<?php echo esc_attr( $settings['google_places_api_key'] ?? '' ); ?>" class="regular-text" placeholder="AIza..." autocomplete="off" />
                        <a href="https://console.cloud.google.com/apis/library/places-backend.googleapis.com" target="_blank" rel="noopener" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Get Key', 'seobetter' ); ?></a>
                        <p class="description">
                            <?php esc_html_e( 'Paid via Google Cloud, but generous $200/month free credit = approximately 5,000 articles/month free. Best global coverage including remote villages in rural Asia, Africa, and Latin America that other providers miss. 10-minute setup at console.cloud.google.com → create a project → enable "Places API (New)" → generate API key → paste it here. Requires a Google Cloud billing account, but you will NOT be charged unless you exceed ~5,000 articles per month.', 'seobetter' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Places Integrations', 'seobetter' ), 'primary', 'seobetter_save_places' ); ?>
        </form>

        <div style="margin:16px 0 8px;padding:14px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
            <strong style="font-size:14px;color:#0f172a"><?php esc_html_e( '🧪 Test Foursquare / HERE / Google Places keys', 'seobetter' ); ?></strong>
            <p class="description" style="margin:6px 0 10px">
                <?php esc_html_e( 'Runs a diagnostic against the cloud-api using the keyword "best pet shops in sydney australia 2026". Bypasses Sonar and forces every configured tier to run so you can see per-tier counts. Use this to verify your keys are actually being called during normal article generation (where Sonar or OSM usually short-circuits the waterfall before these tiers run).', 'seobetter' ); ?>
            </p>
            <button type="button" id="seobetter-test-places-providers" class="button button-primary" style="margin-right:8px"><?php esc_html_e( '🧪 Test Places Providers', 'seobetter' ); ?></button>
            <span id="seobetter-test-places-providers-status" style="font-size:12px;color:#6b7280"></span>
            <div id="seobetter-test-places-providers-result" style="margin-top:12px;display:none;padding:14px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;font-family:monospace;white-space:pre-wrap;max-height:400px;overflow-y:auto"></div>
        </div>

        <div style="margin:16px 0 8px;padding:14px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
            <strong style="font-size:14px;color:#0f172a"><?php esc_html_e( '🧪 Test all research sources (Reddit / HN / DDG / Bluesky / Mastodon / Dev.to / Lemmy / Wikipedia / Google Trends / Tavily / Category APIs / Last30Days)', 'seobetter' ); ?></strong>
            <p class="description" style="margin:6px 0 10px">
                <?php esc_html_e( 'Calls every always-on research source independently against the keyword "small business marketing 2026" and reports per-source ok / empty / error status plus latency. Uses Promise.allSettled in the cloud-api so one flaking source cannot block the others. Also probes the local Last30Days Python skill and reports availability.', 'seobetter' ); ?>
            </p>
            <button type="button" id="seobetter-test-research-sources" class="button button-primary" style="margin-right:8px"><?php esc_html_e( '🧪 Test Research Sources', 'seobetter' ); ?></button>
            <span id="seobetter-test-research-sources-status" style="font-size:12px;color:#6b7280"></span>
            <div id="seobetter-test-research-sources-result" style="margin-top:12px;display:none;padding:14px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;font-family:monospace;white-space:pre-wrap;max-height:500px;overflow-y:auto"></div>
        </div>

        <p class="description" style="padding:8px 12px;background:#f0fdf4;border-radius:4px;color:#166534;margin-top:8px">
            <span class="dashicons dashicons-info-outline"></span>
            <strong><?php esc_html_e( 'How the waterfall works:', 'seobetter' ); ?></strong>
            <?php esc_html_e( 'For any article with a local-intent keyword, the plugin tries Perplexity Sonar → OpenStreetMap → Foursquare → HERE → Google Places in order, stopping at the first tier returning 2+ verified places. Unconfigured tiers are skipped. If no tier returns enough data, the plugin writes a general informational article — it NEVER invents business names.', 'seobetter' ); ?>
        </p>
    </div>
</div>

<?php // v1.5.216.22 — Phase 1 item 3: Google Search Console integration ?>
<?php
$gsc_status = \SEOBetter\GSC_Manager::get_status();
// Surface the OAuth callback redirect notice (?gsc=connected | error)
$gsc_flash = sanitize_text_field( $_GET['gsc'] ?? '' );
$gsc_flash_msg = sanitize_text_field( $_GET['msg'] ?? '' );
$gsc_flash_email = sanitize_email( urldecode( $_GET['email'] ?? '' ) );
?>
<div class="seobetter-card" style="margin-top:24px;margin-bottom:20px">
    <h2><?php esc_html_e( 'Google Search Console', 'seobetter' ); ?>
        <span class="seobetter-score seobetter-score-<?php echo $gsc_status['connected'] ? 'good' : 'ok'; ?>" style="font-size:11px;margin-left:8px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase">
            <?php
            if ( $gsc_status['connected'] ) echo esc_html__( 'CONNECTED', 'seobetter' );
            elseif ( ! $gsc_status['configured'] ) echo esc_html__( 'NEEDS SETUP', 'seobetter' );
            else echo esc_html__( 'NOT CONNECTED', 'seobetter' );
            ?>
        </span>
        <span class="seobetter-score" style="font-size:10px;margin-left:6px;background:#dcfce7;color:#166534;font-weight:600;letter-spacing:0.05em;text-transform:uppercase"><?php esc_html_e( 'FREE', 'seobetter' ); ?></span>
    </h2>

    <p class="description" style="margin-bottom:14px">
        <?php esc_html_e( 'Connect Google Search Console to pull last-28-day clicks/impressions/position per article. Powers the GSC-driven Freshness inventory (Pro+) and the per-post performance widget. Free tier: connect + view dashboard. Pro+ unlocks GSC-driven refresh prioritization.', 'seobetter' ); ?>
    </p>

    <?php if ( $gsc_flash === 'connected' ) : ?>
        <div class="notice notice-success inline" style="margin:10px 0 14px;padding:10px 14px">
            <p style="margin:0;font-size:13px"><strong>✓</strong> <?php
                printf(
                    /* translators: 1: account email */
                    esc_html__( 'Connected as %s. The first sync will run on the next cron tick (within an hour). You can also click "Sync now" below to test immediately.', 'seobetter' ),
                    '<code>' . esc_html( $gsc_flash_email ) . '</code>'
                ); ?></p>
        </div>
    <?php elseif ( $gsc_flash === 'error' ) : ?>
        <div class="notice notice-error inline" style="margin:10px 0 14px;padding:10px 14px">
            <p style="margin:0;font-size:13px"><strong>✗ <?php esc_html_e( 'Connect failed:', 'seobetter' ); ?></strong> <?php echo esc_html( urldecode( $gsc_flash_msg ) ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( ! $gsc_status['configured'] ) : ?>
        <!-- OAuth credentials not set in wp-config.php — show setup instructions -->
        <div style="padding:14px 18px;background:#fef3c7;border:1px solid #fbbf24;border-radius:8px">
            <strong style="color:#92400e;font-size:14px">⚠️ <?php esc_html_e( 'OAuth credentials required', 'seobetter' ); ?></strong>
            <p style="margin:8px 0 10px;font-size:13px;line-height:1.55">
                <?php esc_html_e( 'During Phase 1 testing each install registers its own Google Cloud OAuth client. Phase 2 will replace this with a centralized SEOBetter proxy so users never need their own Google credentials.', 'seobetter' ); ?>
            </p>
            <ol style="margin:0;padding-left:20px;font-size:13px;line-height:1.7">
                <li><?php
                    printf(
                        /* translators: 1: link to Google Cloud Console */
                        esc_html__( 'Go to %s and create or select a project.', 'seobetter' ),
                        '<a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>'
                    ); ?></li>
                <li><?php esc_html_e( 'APIs & Services → Library → enable "Google Search Console API".', 'seobetter' ); ?></li>
                <li><?php esc_html_e( 'Credentials → Create Credentials → OAuth 2.0 Client ID → Web application.', 'seobetter' ); ?></li>
                <li><?php esc_html_e( 'Authorized redirect URI:', 'seobetter' ); ?> <code style="background:#fff;padding:2px 6px;font-size:11px;border:1px solid #fcd34d;word-break:break-all"><?php echo esc_html( \SEOBetter\GSC_Manager::get_redirect_uri() ); ?></code></li>
                <li><?php esc_html_e( 'Add the Client ID and Client Secret to your wp-config.php:', 'seobetter' ); ?>
                    <pre style="background:#fff;padding:8px 10px;font-size:11px;border:1px solid #fcd34d;overflow-x:auto;margin:6px 0">define( 'SEOBETTER_GSC_CLIENT_ID',     'YOUR_CLIENT_ID.apps.googleusercontent.com' );
define( 'SEOBETTER_GSC_CLIENT_SECRET', 'YOUR_CLIENT_SECRET' );</pre>
                </li>
                <li><?php esc_html_e( 'Reload this page — the Connect button will appear.', 'seobetter' ); ?></li>
            </ol>
        </div>
    <?php elseif ( ! $gsc_status['connected'] ) : ?>
        <!-- Configured but not connected — show Connect button -->
        <p style="margin:0 0 12px;font-size:13px">
            <?php esc_html_e( 'OAuth credentials configured. Click below to authorize SEOBetter to read your Google Search Console data (read-only — we never write to your GSC account).', 'seobetter' ); ?>
        </p>
        <a href="<?php echo esc_url( \SEOBetter\GSC_Manager::build_auth_url() ); ?>" class="button button-primary" style="height:40px;padding:8px 18px;font-size:14px;line-height:22px">
            <span style="display:inline-block;width:14px;height:14px;background:#fff;border-radius:2px;margin-right:8px;vertical-align:middle;text-align:center;color:#4285f4;font-weight:700">G</span>
            <?php esc_html_e( 'Connect Google Search Console', 'seobetter' ); ?>
        </a>
        <p class="description" style="margin-top:8px;font-size:11px">
            <?php
            printf(
                /* translators: 1: site URL */
                esc_html__( 'We will request data for the GSC property matching: %s', 'seobetter' ),
                '<code>' . esc_html( rtrim( home_url( '/' ), '/' ) . '/' ) . '</code>'
            );
            ?>
        </p>
    <?php else : ?>
        <!-- Connected — show status + sync + disconnect -->
        <div style="display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:12px;margin-bottom:14px;background:#f8fafc;padding:14px 18px;border-radius:8px">
            <strong><?php esc_html_e( 'Account:', 'seobetter' ); ?></strong>
            <span><?php echo esc_html( $gsc_status['email'] ?: '(unknown)' ); ?></span>
            <strong><?php esc_html_e( 'Property:', 'seobetter' ); ?></strong>
            <code><?php echo esc_html( $gsc_status['site_url'] ); ?></code>
            <strong><?php esc_html_e( 'Connected:', 'seobetter' ); ?></strong>
            <span><?php echo $gsc_status['connected_at'] ? esc_html( human_time_diff( $gsc_status['connected_at'] ) . ' ' . __( 'ago', 'seobetter' ) ) : '—'; ?></span>
            <strong><?php esc_html_e( 'Last sync:', 'seobetter' ); ?></strong>
            <span><?php echo $gsc_status['last_sync'] ? esc_html( human_time_diff( $gsc_status['last_sync'] ) . ' ' . __( 'ago', 'seobetter' ) ) : '<span style="color:#94a3b8">' . esc_html__( 'never', 'seobetter' ) . '</span>'; ?></span>
            <strong><?php esc_html_e( 'URLs tracked:', 'seobetter' ); ?></strong>
            <span><?php echo esc_html( $gsc_status['urls_tracked'] ); ?></span>
        </div>

        <button type="button" id="seobetter-gsc-sync" class="button button-primary" style="margin-right:8px"><?php esc_html_e( 'Sync now', 'seobetter' ); ?></button>
        <button type="button" id="seobetter-gsc-disconnect" class="button" style="color:#991b1b"><?php esc_html_e( 'Disconnect', 'seobetter' ); ?></button>
        <span id="seobetter-gsc-status-msg" style="margin-left:12px;font-size:12px;color:#6b7280"></span>

        <script>
        jQuery(function($) {
            $('#seobetter-gsc-sync').on('click', function() {
                var $btn = $(this);
                var $msg = $('#seobetter-gsc-status-msg');
                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Syncing…', 'seobetter' ) ); ?>');
                $msg.text('').css('color', '#6b7280');
                $.ajax({
                    url: '<?php echo esc_js( rest_url( 'seobetter/v1/gsc/sync' ) ); ?>',
                    method: 'POST',
                    headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' },
                }).done(function(res) {
                    if (res && res.success) {
                        $msg.text('✓ <?php echo esc_js( __( 'Synced', 'seobetter' ) ); ?> ' + (res.urls || 0) + ' <?php echo esc_js( __( 'URLs. Reload to refresh stats.', 'seobetter' ) ); ?>').css('color', '#059669');
                    } else {
                        $msg.text('✗ ' + (res && res.error ? res.error : '<?php echo esc_js( __( 'Sync failed', 'seobetter' ) ); ?>')).css('color', '#dc2626');
                    }
                }).fail(function(xhr) {
                    var msg = '<?php echo esc_js( __( 'Sync failed', 'seobetter' ) ); ?>';
                    try { var r = JSON.parse(xhr.responseText); msg = r.error || msg; } catch(e) {}
                    $msg.text('✗ ' + msg).css('color', '#dc2626');
                }).always(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Sync now', 'seobetter' ) ); ?>');
                });
            });

            $('#seobetter-gsc-disconnect').on('click', function() {
                if (!confirm('<?php echo esc_js( __( 'Disconnect Google Search Console? Stored snapshots will remain but no new data will be pulled until you reconnect.', 'seobetter' ) ); ?>')) return;
                $.ajax({
                    url: '<?php echo esc_js( rest_url( 'seobetter/v1/gsc/disconnect' ) ); ?>',
                    method: 'POST',
                    headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' },
                }).done(function() {
                    window.location.reload();
                });
            });
        });
        </script>
    <?php endif; ?>
</div>

<?php // v1.5.216.25 — Phase 1 item 6: Brand Voice profiles ?>
<?php
$bv_voices    = \SEOBetter\Brand_Voice_Manager::all();
$bv_count     = count( $bv_voices );
$bv_cap       = \SEOBetter\Brand_Voice_Manager::tier_cap();
$bv_can_create = \SEOBetter\Brand_Voice_Manager::can_create_more();
$bv_edit_id   = sanitize_key( $_GET['edit_voice'] ?? '' );
$bv_editing   = $bv_edit_id !== '' && isset( $bv_voices[ $bv_edit_id ] ) ? $bv_voices[ $bv_edit_id ] : null;
$bv_tier_label = $bv_cap === 0 ? 'Pro' : ( $bv_cap === 1 ? 'Pro' : ( $bv_cap === 3 ? 'Pro+' : 'Agency' ) );
?>
<div class="seobetter-card" id="brand-voice" style="margin-top:24px;margin-bottom:20px">
    <h2><?php esc_html_e( 'Brand Voice Profiles', 'seobetter' ); ?>
        <span class="seobetter-score" style="font-size:10px;margin-left:8px;background:#ede9fe;color:#5b21b6;font-weight:600;letter-spacing:0.05em;text-transform:uppercase"><?php
            if ( $bv_cap === 0 )      echo esc_html__( 'PRO', 'seobetter' );
            elseif ( $bv_cap === 1 )  echo esc_html__( 'PRO', 'seobetter' );
            elseif ( $bv_cap === 3 )  echo esc_html__( 'PRO+', 'seobetter' );
            else                       echo esc_html__( 'AGENCY', 'seobetter' );
        ?></span>
        <?php if ( $bv_cap > 0 ) : ?>
            <span style="font-size:12px;color:#64748b;margin-left:8px;font-weight:400">
                <?php
                if ( $bv_cap === 999 ) {
                    /* translators: 1: voice count */
                    printf( esc_html( _n( '%d voice', '%d voices', $bv_count, 'seobetter' ) ), $bv_count );
                } else {
                    /* translators: 1: voice count, 2: cap */
                    printf( esc_html__( '%1$d of %2$d voices used', 'seobetter' ), $bv_count, $bv_cap );
                }
                ?>
            </span>
        <?php endif; ?>
    </h2>

    <p class="description" style="margin-bottom:14px">
        <?php esc_html_e( 'Define your brand\'s writing style by uploading a sample of existing posts and listing banned phrases. The AI mimics your tone + sentence rhythm + vocabulary, and never uses banned phrases. The biggest single fix for the "sounds like AI" complaint.', 'seobetter' ); ?>
    </p>

    <?php if ( $bv_cap === 0 ) : ?>
        <!-- Free tier — locked, show upsell -->
        <div style="padding:24px;background:linear-gradient(135deg,#faf5ff 0%,#ede9fe 100%);border:2px solid #8b5cf6;border-radius:8px;text-align:center">
            <div style="font-size:32px;margin-bottom:10px">🎙️</div>
            <h3 style="margin:0 0 8px;color:#5b21b6"><?php esc_html_e( 'Brand Voice — Pro', 'seobetter' ); ?></h3>
            <p style="font-size:14px;color:#4c1d95;max-width:560px;margin:0 auto 14px">
                <?php esc_html_e( 'Upload 1-3 of your existing posts as a writing sample. List phrases you NEVER use ("dive in", "in today\'s fast-paced world"). Pro: 1 voice. Pro+: 3 voices. Agency: unlimited + per-language.', 'seobetter' ); ?>
            </p>
            <a href="https://seobetter.com/pricing" target="_blank" class="button button-primary" style="height:40px;line-height:24px;padding:8px 22px"><?php esc_html_e( 'See Pro plans →', 'seobetter' ); ?></a>
        </div>
    <?php else : ?>

        <?php if ( $bv_count > 0 ) : ?>
        <!-- Existing voices list -->
        <table class="widefat striped" style="margin-bottom:18px">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'seobetter' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'seobetter' ); ?></th>
                    <th style="width:120px"><?php esc_html_e( 'Banned phrases', 'seobetter' ); ?></th>
                    <th style="width:160px"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $bv_voices as $v ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $v['name'] ); ?></strong></td>
                    <td style="font-size:13px;color:#64748b"><?php echo esc_html( wp_trim_words( $v['description'], 18, '…' ) ); ?></td>
                    <td style="font-size:12px;text-align:center"><?php echo esc_html( count( $v['banned_phrases'] ?? [] ) ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-settings&edit_voice=' . $v['id'] . '#brand-voice' ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'seobetter' ); ?></a>
                        <form method="post" style="display:inline;margin-left:4px" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this voice?', 'seobetter' ) ); ?>')">
                            <?php wp_nonce_field( 'seobetter_brand_voice_nonce' ); ?>
                            <input type="hidden" name="voice_id" value="<?php echo esc_attr( $v['id'] ); ?>" />
                            <button type="submit" name="seobetter_delete_brand_voice" class="button button-small" style="color:#991b1b"><?php esc_html_e( 'Delete', 'seobetter' ); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ( $bv_can_create || $bv_editing ) : ?>
        <!-- Add / Edit form -->
        <form method="post" style="background:#f8fafc;padding:18px;border:1px solid #e2e8f0;border-radius:8px">
            <?php wp_nonce_field( 'seobetter_brand_voice_nonce' ); ?>
            <input type="hidden" name="voice_id" value="<?php echo esc_attr( $bv_editing['id'] ?? '' ); ?>" />
            <h3 style="margin:0 0 14px;font-size:15px"><?php
                echo $bv_editing
                    ? esc_html__( 'Edit voice', 'seobetter' )
                    : esc_html__( 'Add new voice', 'seobetter' );
            ?></h3>

            <table class="form-table" style="margin:0">
                <tr>
                    <th><label for="voice_name"><?php esc_html_e( 'Name', 'seobetter' ); ?> <span style="color:#ef4444">*</span></label></th>
                    <td><input type="text" name="voice_name" id="voice_name" class="regular-text" required value="<?php echo esc_attr( $bv_editing['name'] ?? '' ); ?>" placeholder="e.g. Mindiam Pets — friendly + practical" /></td>
                </tr>
                <tr>
                    <th><label for="voice_description"><?php esc_html_e( 'Description', 'seobetter' ); ?></label></th>
                    <td>
                        <input type="text" name="voice_description" id="voice_description" class="regular-text" value="<?php echo esc_attr( $bv_editing['description'] ?? '' ); ?>" placeholder="Conversational, no corporate jargon, second-person address" />
                        <p class="description"><?php esc_html_e( 'One-line summary so future-you remembers what this voice is for.', 'seobetter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="voice_sample_text"><?php esc_html_e( 'Sample text', 'seobetter' ); ?></label></th>
                    <td>
                        <textarea name="voice_sample_text" id="voice_sample_text" rows="8" class="large-text" placeholder="Paste 500-1500 words of an existing article that exemplifies the voice. Plain text — no HTML."><?php echo esc_textarea( $bv_editing['sample_text'] ?? '' ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'The AI mirrors sentence rhythm, vocabulary, and formality from this sample. ~1500 chars used in the prompt; longer is fine but trimmed at the boundary.', 'seobetter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="voice_tone_directives"><?php esc_html_e( 'Tone directives', 'seobetter' ); ?></label></th>
                    <td>
                        <textarea name="voice_tone_directives" id="voice_tone_directives" rows="4" class="large-text" placeholder="Friendly + direct.&#10;Use second person (you/your).&#10;Avoid passive voice.&#10;Short paragraphs (max 3 sentences).&#10;No corporate jargon.&#10;Australian English spellings."><?php echo esc_textarea( $bv_editing['tone_directives'] ?? '' ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Specific rules. The AI follows these throughout the article. Be opinionated — generic guidance gets generic output.', 'seobetter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="voice_banned_phrases"><?php esc_html_e( 'Banned phrases', 'seobetter' ); ?></label></th>
                    <td>
                        <textarea name="voice_banned_phrases" id="voice_banned_phrases" rows="6" class="large-text" placeholder="dive in&#10;in today's fast-paced world&#10;let's explore&#10;at the end of the day&#10;game-changer&#10;cutting-edge&#10;leverage&#10;synergy"><?php
                            $bp = $bv_editing['banned_phrases'] ?? [];
                            echo esc_textarea( is_array( $bp ) ? implode( "\n", $bp ) : (string) $bp );
                        ?></textarea>
                        <p class="description"><?php esc_html_e( 'One per line (or comma-separated). Word-boundary matched, case-insensitive. The AI is told to avoid them; a post-process pass strips any that slip through.', 'seobetter' ); ?></p>
                    </td>
                </tr>
            </table>

            <div style="margin-top:14px">
                <button type="submit" name="seobetter_save_brand_voice" class="button button-primary"><?php
                    echo $bv_editing ? esc_html__( 'Update voice', 'seobetter' ) : esc_html__( 'Save voice', 'seobetter' );
                ?></button>
                <?php if ( $bv_editing ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-settings#brand-voice' ) ); ?>" class="button" style="margin-left:6px"><?php esc_html_e( 'Cancel', 'seobetter' ); ?></a>
                <?php endif; ?>
            </div>
        </form>
        <?php elseif ( ! $bv_can_create ) : ?>
            <!-- Cap reached for current tier -->
            <div style="padding:14px 18px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;font-size:13px;color:#92400e">
                <?php
                /* translators: 1: cap count */
                printf( esc_html__( 'You\'ve reached your tier\'s voice limit (%d). Edit existing voices above, or upgrade to add more.', 'seobetter' ), $bv_cap );
                ?>
                <a href="https://seobetter.com/pricing" target="_blank" style="margin-left:8px;color:#7c2d12;font-weight:600;text-decoration:underline"><?php esc_html_e( 'Upgrade →', 'seobetter' ); ?></a>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php // v1.5.32 — Branding + AI Featured Image card ?>
<div class="seobetter-dashboard-card" style="margin-top:24px">
    <div class="seobetter-card-body">
        <h2><?php esc_html_e( 'Branding & AI Featured Image', 'seobetter' ); ?></h2>
        <p class="description" style="margin-bottom:16px">
            <?php esc_html_e( 'Upload your brand assets and choose an AI image provider to auto-generate a brand-aware featured image for every article. Uses the article title + keywords + your brand colors to compose the prompt. If no AI provider is configured, the plugin falls back to: (1) your Pexels key, (2) SEOBetter Cloud Pexels pool, then (3) Picsum.', 'seobetter' ); ?>
        </p>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'seobetter_branding_nonce' ); ?>
            <table class="form-table">

                <!-- Business Name & Description -->
                <tr>
                    <th><?php esc_html_e( 'Brand / Business Name', 'seobetter' ); ?></th>
                    <td>
                        <input type="text" name="branding_business_name" value="<?php echo esc_attr( $settings['branding_business_name'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Mindiam Pets', 'seobetter' ); ?>" />
                        <p class="description"><?php esc_html_e( 'Your brand name. Used in the image generation prompt as aesthetic context.', 'seobetter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Business Description', 'seobetter' ); ?></th>
                    <td>
                        <textarea name="branding_description" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Australian pet shop online. Products: Pet products. Audience: pet owners. Benefits: cheap prices, high quality.', 'seobetter' ); ?>"><?php echo esc_textarea( $settings['branding_description'] ?? '' ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Short description of what your brand does. Helps the AI pick visuals that fit your industry.', 'seobetter' ); ?></p>
                    </td>
                </tr>

                <!-- Logo upload -->
                <tr>
                    <th><?php esc_html_e( 'Brand Logo', 'seobetter' ); ?></th>
                    <td>
                        <?php $logo_id = absint( $settings['branding_logo_id'] ?? 0 ); ?>
                        <input type="hidden" name="branding_logo_id" id="branding_logo_id" value="<?php echo esc_attr( $logo_id ); ?>" />
                        <div id="branding-logo-preview" style="margin-bottom:8px">
                            <?php if ( $logo_id && ( $logo_src = wp_get_attachment_image_url( $logo_id, 'medium' ) ) ) : ?>
                                <img src="<?php echo esc_url( $logo_src ); ?>" alt="Brand logo" style="max-width:200px;max-height:100px;border:1px solid #e5e7eb;border-radius:6px;padding:8px;background:#fff" />
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button" id="branding-logo-upload-btn"><?php esc_html_e( 'Upload / Choose Logo', 'seobetter' ); ?></button>
                        <button type="button" class="button" id="branding-logo-remove-btn" <?php echo $logo_id ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Remove', 'seobetter' ); ?></button>
                        <p class="description"><?php esc_html_e( 'PNG or SVG preferred. Stored in your WordPress media library. Not embedded in AI-generated images directly (AI cannot render logos accurately) — used as a brand identity reference.', 'seobetter' ); ?></p>
                    </td>
                </tr>

                <!-- Brand colors -->
                <tr>
                    <th><?php esc_html_e( 'Brand Colors', 'seobetter' ); ?></th>
                    <td>
                        <label style="display:inline-block;margin-right:16px">
                            <?php esc_html_e( 'Primary:', 'seobetter' ); ?>
                            <input type="color" name="branding_color_primary" value="<?php echo esc_attr( $settings['branding_color_primary'] ?? '#764ba2' ); ?>" />
                        </label>
                        <label style="display:inline-block;margin-right:16px">
                            <?php esc_html_e( 'Secondary:', 'seobetter' ); ?>
                            <input type="color" name="branding_color_secondary" value="<?php echo esc_attr( $settings['branding_color_secondary'] ?? '#667eea' ); ?>" />
                        </label>
                        <label style="display:inline-block">
                            <?php esc_html_e( 'Accent:', 'seobetter' ); ?>
                            <input type="color" name="branding_color_accent" value="<?php echo esc_attr( $settings['branding_color_accent'] ?? '#f59e0b' ); ?>" />
                        </label>
                        <p class="description"><?php esc_html_e( 'Hex colors woven into the AI prompt so generated images use your brand palette. Leave defaults if unsure.', 'seobetter' ); ?></p>
                    </td>
                </tr>

                <!-- AI Provider -->
                <tr>
                    <th><?php esc_html_e( 'AI Image Provider', 'seobetter' ); ?></th>
                    <td>
                        <?php $bp = $settings['branding_provider'] ?? ''; ?>
                        <select name="branding_provider" id="branding_provider">
                            <option value="" <?php selected( $bp, '' ); ?>>— <?php esc_html_e( 'Disabled (use Pexels stock images: your key → Cloud pool → Picsum)', 'seobetter' ); ?> —</option>
                            <option value="pollinations" <?php selected( $bp, 'pollinations' ); ?>><?php esc_html_e( 'Pollinations.ai — FREE, no API key, no signup (Recommended to start)', 'seobetter' ); ?></option>
                            <option value="openrouter" <?php selected( $bp, 'openrouter' ); ?>><?php esc_html_e( 'OpenRouter → Gemini Nano Banana — uses your existing OpenRouter key, ~$0.04/image', 'seobetter' ); ?></option>
                            <option value="gemini" <?php selected( $bp, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini 2.5 Flash Image (Nano Banana) direct — ~$0.04/image, 10/day FREE on AI Studio', 'seobetter' ); ?></option>
                        </select>
                        <p class="description" style="margin-top:8px">
                            <strong><?php esc_html_e( 'Recommended:', 'seobetter' ); ?></strong>
                            <?php esc_html_e( 'Start with Pollinations (free, zero setup) to test the workflow. If you already use OpenRouter for article generation, switch to "OpenRouter → Nano Banana" for higher quality without managing a second key. "Gemini direct" is the cheapest paid option (10/day free on Google AI Studio).', 'seobetter' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- API Key (hidden when Pollinations or disabled) -->
                <tr id="branding-api-key-row">
                    <th><?php esc_html_e( 'API Key', 'seobetter' ); ?></th>
                    <td>
                        <input type="password" name="branding_api_key" value="<?php echo esc_attr( $settings['branding_api_key'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Paste provider API key', 'seobetter' ); ?>" autocomplete="off" />
                        <p class="description" id="branding-api-key-help">
                            <span data-provider="pollinations"><?php esc_html_e( 'No API key required — Pollinations is free and anonymous.', 'seobetter' ); ?></span>
                            <span data-provider="openrouter"><?php esc_html_e( 'No additional key needed — uses the OpenRouter key you configured for article generation in the BYOK section above. Single OpenRouter dashboard, single bill, ~$0.04/image (pass-through pricing).', 'seobetter' ); ?> <a href="https://openrouter.ai/google/gemini-2.5-flash-image-preview" target="_blank" rel="noopener"><?php esc_html_e( 'Model details', 'seobetter' ); ?></a></span>
                            <span data-provider="gemini"><?php esc_html_e( 'Get a free key at aistudio.google.com/apikey. Free tier: 10 images/day on gemini-2.5-flash-image. Paid: ~$0.04/image.', 'seobetter' ); ?> <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener"><?php esc_html_e( 'Get Gemini Key', 'seobetter' ); ?></a></span>
                        </p>
                    </td>
                </tr>

                <!-- Style preset -->
                <tr>
                    <th><?php esc_html_e( 'Image Style Preset', 'seobetter' ); ?></th>
                    <td>
                        <?php $bs = $settings['branding_style'] ?? 'realistic'; ?>
                        <select name="branding_style">
                            <option value="realistic" <?php selected( $bs, 'realistic' ); ?>><?php esc_html_e( '📰 Magazine Cover (recommended) — bottom-third headline overlay, NYT/Wired editorial photo', 'seobetter' ); ?></option>
                            <option value="editorial" <?php selected( $bs, 'editorial' ); ?>><?php esc_html_e( '🗞️ Classic Editorial — title top with horizontal divider, photo below (NYT/Atlantic style)', 'seobetter' ); ?></option>
                            <option value="hero" <?php selected( $bs, 'hero' ); ?>><?php esc_html_e( '🎬 Cinematic Hero — full-bleed photo with centered title + cinema black bars', 'seobetter' ); ?></option>
                            <option value="illustration" <?php selected( $bs, 'illustration' ); ?>><?php esc_html_e( '🎨 Modern Illustration — upper-left dark headline on flat editorial illustration', 'seobetter' ); ?></option>
                            <option value="flat" <?php selected( $bs, 'flat' ); ?>><?php esc_html_e( '⬜ Title-led Flat — split layout: large headline left, abstract icon right', 'seobetter' ); ?></option>
                            <option value="minimalist" <?php selected( $bs, 'minimalist' ); ?>><?php esc_html_e( '◽ Minimalist — small corner title, image dominant (Kinfolk/Cereal magazine)', 'seobetter' ); ?></option>
                            <option value="3d" <?php selected( $bs, '3d' ); ?>><?php esc_html_e( '🎯 3D Hero — studio-rendered scene with floating centered title overlay', 'seobetter' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'The style preset determines the image generation prompt template. All presets automatically weave in your brand colors and business context.', 'seobetter' ); ?></p>
                    </td>
                </tr>

                <!-- v1.5.216.9 — Text overlay toggle -->
                <tr>
                    <th><?php esc_html_e( 'Article Title Text Overlay', 'seobetter' ); ?></th>
                    <td>
                        <?php
                        // Default ON for backward compat. Existing users who haven't seen this setting yet → checked.
                        $text_overlay = isset( $settings['branding_text_overlay'] ) ? (string) $settings['branding_text_overlay'] : '1';
                        ?>
                        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                            <input type="checkbox" name="branding_text_overlay" value="1" <?php checked( $text_overlay, '1' ); ?> style="margin-top:3px" />
                            <span>
                                <strong><?php esc_html_e( 'Render the article title as text overlay on the featured image', 'seobetter' ); ?></strong>
                                <span class="description" style="display:block;margin-top:4px">
                                    <?php esc_html_e( 'CHECKED (default): magazine-cover banner with the article headline rendered as bold sans-serif text overlay (positioned per the chosen style preset). Ready-to-post on social media.', 'seobetter' ); ?>
                                    <br/>
                                    <?php esc_html_e( 'UNCHECKED: clean photographic image with no text rendered. Choose this if you prefer to add your own typography in the WP Block editor or via a separate text-overlay plugin.', 'seobetter' ); ?>
                                </span>
                            </span>
                        </label>
                    </td>
                </tr>

                <!-- Negative prompt -->
                <tr>
                    <th><?php esc_html_e( 'Things to Avoid (optional)', 'seobetter' ); ?></th>
                    <td>
                        <textarea name="branding_negative_prompt" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'e.g. no text overlay, no watermarks, no people\'s faces, no competitor logos', 'seobetter' ); ?>"><?php echo esc_textarea( $settings['branding_negative_prompt'] ?? '' ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Comma-separated list of things the AI should NOT include. Appended to the prompt as an "Avoid:" clause.', 'seobetter' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Branding & AI Image Settings', 'seobetter' ), 'primary', 'seobetter_save_branding' ); ?>
        </form>

        <p class="description" style="padding:10px 14px;background:#eff6ff;border-left:3px solid #3b82f6;border-radius:4px;color:#1e3a5f;margin-top:8px">
            <strong><?php esc_html_e( 'ℹ️ Featured image vs inline images:', 'seobetter' ); ?></strong>
            <?php esc_html_e( 'This setting controls the article FEATURED image only (the big hero at the top / social share preview). Inline images inside the article body come from a 3-tier fallback chain: (1) your own Pexels key if configured, (2) the SEOBetter Cloud Pexels pool (free for all users — added v1.5.212), or (3) Picsum as last resort. AI-generated inline images would add $0.12+ per article for marginal visual gain, and Pexels already has millions of relevant real photos. Reserve AI generation for the one image that matters most: the hero.', 'seobetter' ); ?>
        </p>
    </div>
</div>

<script>
jQuery(function($) {
    // v1.5.41 — Test Sonar Connection button handler
    $('#seobetter-test-sonar').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $status = $('#seobetter-test-sonar-status');
        var $result = $('#seobetter-test-sonar-result');
        $btn.prop('disabled', true).text('Testing... (up to 60s)');
        $status.text('Calling cloud-api with Lucignano test keyword...');
        $result.hide().empty();
        var customKw = $('#seobetter-sonar-test-keyword').val().trim();
        var customCountry = $('#seobetter-sonar-test-country').val().trim().toUpperCase();
        $.ajax({
            url: '<?php echo esc_js( rest_url( 'seobetter/v1/test-sonar' ) ); ?>',
            method: 'POST',
            headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' },
            data: {
                keyword: customKw,
                country: customCountry,
                domain: 'general'
            },
            timeout: 70000
        }).done(function(res) {
            var verdict = res.verdict || 'No verdict returned';
            var lines = [];
            lines.push('═══ SONAR DIAGNOSTIC REPORT ═══');
            lines.push('');
            lines.push('VERDICT: ' + verdict);
            lines.push('');
            lines.push('─── KEY SOURCE ───');
            lines.push('Plugin version:           ' + (res.plugin_version || '?'));
            lines.push('Key source:               ' + (res.key_source || 'unknown'));
            lines.push('Key preview:              ' + (res.key_preview || '(none)'));
            lines.push('Has Places field key:     ' + (res.has_places_field_key ? 'YES' : 'NO'));
            lines.push('Has AI Providers key:     ' + (res.has_ai_providers_key ? 'YES' : 'NO'));
            lines.push('Auto-discover would fire: ' + (res.auto_discover_would_fire ? 'YES' : 'NO'));
            lines.push('Sonar model configured:   ' + (res.sonar_model_configured || '?'));
            lines.push('');
            lines.push('─── CLOUD-API RESPONSE ───');
            lines.push('Test keyword:             ' + (res.test_keyword || '?'));
            lines.push('Research source:          ' + (res.research_source || '?'));
            lines.push('is_local_intent:          ' + JSON.stringify(res.is_local_intent));
            lines.push('places_count:             ' + (res.places_count || 0));
            lines.push('places_provider_used:     ' + (res.places_provider_used || 'null'));
            lines.push('Sonar was tried:          ' + (res.sonar_was_tried ? 'YES' : 'NO ← PROBLEM'));
            lines.push('Sonar result count:       ' + (res.sonar_result_count || 0));
            lines.push('');
            lines.push('─── PROVIDERS TRIED ───');
            (res.places_providers_tried || []).forEach(function(p) {
                var line = '  • ' + (p.name || '?') + ': ' + (p.count || 0) + ' places';
                if (p.error) {
                    line += '\n      ❌ ERROR: ' + p.error;
                }
                lines.push(line);
            });
            if (res.places_sample && res.places_sample.length) {
                lines.push('');
                lines.push('─── PLACES SAMPLE (first 3) ───');
                res.places_sample.forEach(function(p, i) {
                    lines.push('  ' + (i+1) + '. ' + (p.name || '?'));
                    if (p.address) lines.push('     ' + p.address);
                    if (p.source) lines.push('     via ' + p.source);
                });
            }
            if (res.research_error) {
                lines.push('');
                lines.push('─── ERROR ───');
                lines.push(res.research_error);
            }
            if (res.error) {
                lines.push('');
                lines.push('─── PHP EXCEPTION ───');
                lines.push(res.error);
            }
            $result.text(lines.join('\n')).show();
            $status.text('Test complete.');
            $btn.prop('disabled', false).text('🧪 Test Sonar Connection');
        }).fail(function(xhr, status, err) {
            $result.text('Request failed: ' + status + ' ' + err + '\n\nResponse: ' + (xhr.responseText || 'empty')).show();
            $status.text('Failed.');
            $btn.prop('disabled', false).text('🧪 Test Sonar Connection');
        });
    });

    // v1.5.49 — Test Places Providers (Foursquare / HERE / Google) button handler
    $('#seobetter-test-places-providers').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $status = $('#seobetter-test-places-providers-status');
        var $result = $('#seobetter-test-places-providers-result');
        $btn.prop('disabled', true).text('Testing... (up to 60s)');
        $status.text('Calling cloud-api with Sydney test keyword (all tiers forced)...');
        $result.hide().empty();
        $.ajax({
            url: '<?php echo esc_js( rest_url( 'seobetter/v1/test-places-providers' ) ); ?>',
            method: 'POST',
            headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' },
            data: {},
            timeout: 70000
        }).done(function(res) {
            var lines = [];
            lines.push('═══ PLACES PROVIDERS DIAGNOSTIC ═══');
            lines.push('');
            lines.push('Plugin version: ' + (res.plugin_version || '?'));
            lines.push('Test keyword:   ' + (res.test_keyword || '(n/a)'));
            lines.push('Total places:   ' + (res.places_count || 0));
            lines.push('Provider used:  ' + (res.places_provider_used || 'null'));
            lines.push('');
            lines.push('─── KEYS CONFIGURED ───');
            if (res.configured) {
                lines.push('Foursquare: ' + (res.configured.foursquare ? 'YES' : 'NO'));
                lines.push('HERE:       ' + (res.configured.here ? 'YES' : 'NO'));
                lines.push('Google:     ' + (res.configured.google ? 'YES' : 'NO'));
            }
            lines.push('');
            lines.push('─── VERDICT ───');
            if (res.verdict) {
                lines.push(res.verdict);
            }
            if (res.error) {
                lines.push('');
                lines.push('─── ERROR ───');
                lines.push(res.error);
            }
            if (res.per_tier) {
                lines.push('');
                lines.push('─── RAW PER-TIER ───');
                Object.keys(res.per_tier).forEach(function(name) {
                    var t = res.per_tier[name];
                    var line = '  • ' + name + ': ' + (t.count || 0) + ' places';
                    if (t.error) line += '  [ERROR: ' + t.error + ']';
                    lines.push(line);
                });
            }
            if (res.places_sample && res.places_sample.length) {
                lines.push('');
                lines.push('─── SAMPLE PLACES (first 5) ───');
                res.places_sample.forEach(function(p, i) {
                    lines.push('  ' + (i+1) + '. ' + (p.name || '?'));
                    if (p.address) lines.push('     ' + p.address);
                    if (p.source)  lines.push('     via ' + p.source);
                });
            }
            $result.text(lines.join('\n')).show();
            $status.text('Test complete.');
            $btn.prop('disabled', false).text('🧪 Test Places Providers');
        }).fail(function(xhr, status, err) {
            $result.text('Request failed: ' + status + ' ' + err + '\n\nResponse: ' + (xhr.responseText || 'empty')).show();
            $status.text('Failed.');
            $btn.prop('disabled', false).text('🧪 Test Places Providers');
        });
    });

    // v1.5.51 — Test all research sources button
    $('#seobetter-test-research-sources').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $status = $('#seobetter-test-research-sources-status');
        var $result = $('#seobetter-test-research-sources-result');
        $btn.prop('disabled', true).text('Testing... (up to 60s)');
        $status.text('Calling cloud-api test-all-sources + probing Last30Days...');
        $result.hide().empty();
        $.ajax({
            url: '<?php echo esc_js( rest_url( 'seobetter/v1/test-research-sources' ) ); ?>',
            method: 'POST',
            headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' },
            data: {},
            timeout: 70000
        }).done(function(res) {
            var lines = [];
            lines.push('═══ RESEARCH SOURCES DIAGNOSTIC ═══');
            lines.push('');
            lines.push('Plugin version: ' + (res.plugin_version || '?'));
            lines.push('Test keyword:   ' + (res.test_keyword || '?'));
            lines.push('Domain:         ' + (res.domain || 'general'));
            lines.push('Country:        ' + (res.country || '(none)'));
            lines.push('Tavily Search:  ' + (res.tavily_configured ? 'CONFIGURED' : 'not configured'));
            lines.push('');

            // Cloud-api sources
            lines.push('─── CLOUD API SOURCES ───');
            if (res.cloud && res.cloud.ok) {
                var s = res.cloud.summary || {};
                lines.push('Total latency: ' + (res.cloud.total_latency_ms || '?') + 'ms');
                lines.push('Summary:       ' + (s.ok || 0) + ' ok / ' + (s.empty || 0) + ' empty / ' + (s.errors || 0) + ' errors  (of ' + (s.total || 0) + ')');
                lines.push('');
                (res.cloud.sources || []).forEach(function(src) {
                    var icon, label;
                    if (!src.ok) { icon = '❌'; label = 'ERROR'; }
                    else if (src.count > 0) { icon = '✅'; label = src.count + ' items'; }
                    else { icon = '⚪'; label = 'empty'; }
                    var line = icon + ' ' + (src.name || '?') + ' — ' + label + '  [' + (src.latency_ms || 0) + 'ms]';
                    lines.push(line);
                    if (src.error) {
                        lines.push('     ↳ ' + src.error);
                    } else if (src.sample) {
                        lines.push('     ↳ ' + src.sample);
                    }
                });
            } else {
                lines.push('❌ Cloud API test failed');
                if (res.cloud && res.cloud.error) {
                    lines.push('   ' + res.cloud.error);
                }
            }

            // Last30Days local skill
            lines.push('');
            lines.push('─── LAST30DAYS (local Python skill, fallback only) ───');
            if (res.last30days) {
                var l = res.last30days;
                var icon2 = l.available ? '✅' : (l.python_found && l.script_found ? '⚠️' : '⚪');
                lines.push(icon2 + ' Available:    ' + (l.available ? 'YES' : 'NO'));
                lines.push('   Python3 found:     ' + (l.python_found ? 'YES' : 'NO'));
                lines.push('   Script file found: ' + (l.script_found ? 'YES' : 'NO'));
                if (l.message) {
                    lines.push('   ' + l.message);
                }
            }

            $result.text(lines.join('\n')).show();
            $status.text('Test complete.');
            $btn.prop('disabled', false).text('🧪 Test Research Sources');
        }).fail(function(xhr, status, err) {
            $result.text('Request failed: ' + status + ' ' + err + '\n\nResponse: ' + (xhr.responseText || 'empty')).show();
            $status.text('Failed.');
            $btn.prop('disabled', false).text('🧪 Test Research Sources');
        });
    });

    // v1.5.32 — Branding logo upload + API key row toggle
    var mediaFrame;
    $('#branding-logo-upload-btn').on('click', function(e) {
        e.preventDefault();
        if (mediaFrame) { mediaFrame.open(); return; }
        mediaFrame = wp.media({ title: 'Select Brand Logo', button: { text: 'Use this logo' }, multiple: false, library: { type: 'image' } });
        mediaFrame.on('select', function() {
            var a = mediaFrame.state().get('selection').first().toJSON();
            $('#branding_logo_id').val(a.id);
            var src = (a.sizes && a.sizes.medium && a.sizes.medium.url) || a.url;
            $('#branding-logo-preview').html('<img src="' + src + '" alt="Brand logo" style="max-width:200px;max-height:100px;border:1px solid #e5e7eb;border-radius:6px;padding:8px;background:#fff" />');
            $('#branding-logo-remove-btn').show();
        });
        mediaFrame.open();
    });
    $('#branding-logo-remove-btn').on('click', function(e) {
        e.preventDefault();
        $('#branding_logo_id').val(0);
        $('#branding-logo-preview').empty();
        $(this).hide();
    });

    // Show/hide API key row based on provider.
    // - Pollinations needs no key (anonymous, no signup)
    // - OpenRouter reuses the BYOK key configured in the AI Providers section
    //   above, so the key INPUT is hidden but the help text still shows so
    //   the user knows where the key comes from.
    function updateBrandingKeyRow() {
        var p = $('#branding_provider').val();
        if (p === '' || p === 'pollinations' || p === 'openrouter') {
            $('#branding-api-key-row .regular-text').hide();
        } else {
            $('#branding-api-key-row .regular-text').show();
        }
        // Always keep the row visible so the help text shows. Hide only the input.
        if (p === '' || p === 'pollinations') {
            $('#branding-api-key-row').hide();
        } else {
            $('#branding-api-key-row').show();
        }
        $('#branding-api-key-help span').hide();
        if (p) $('#branding-api-key-help span[data-provider="' + p + '"]').show();
    }
    $('#branding_provider').on('change', updateBrandingKeyRow);
    updateBrandingKeyRow();
});
</script>
<?php wp_enqueue_media(); ?>

<script>
(function() {
    var sel = document.getElementById('seobetter-provider-select');
    var modelSel = document.getElementById('seobetter-model-select');
    var helpEl = document.getElementById('seobetter-provider-help');
    var docsLink = document.getElementById('seobetter-docs-link');
    var keyRow = document.getElementById('seobetter-key-row');
    var urlRow = document.getElementById('seobetter-url-row');

    function updateProvider() {
        var opt = sel.options[sel.selectedIndex];
        var models = JSON.parse(opt.dataset.models || '[]');
        var def = opt.dataset.default || '';
        helpEl.textContent = opt.dataset.help || '';
        docsLink.href = opt.dataset.docs || '#';
        docsLink.style.display = opt.dataset.docs ? '' : 'none';
        keyRow.style.display = opt.dataset.needsKey === '0' ? 'none' : '';
        urlRow.style.display = opt.dataset.needsUrl === '1' ? '' : 'none';

        modelSel.innerHTML = '';
        // v1.5.32 — models are now objects { id, label, tier } with tier badges
        // in the label for compatibility visibility.
        models.forEach(function(m) {
            var o = document.createElement('option');
            // Backwards compat: if m is a string (old format), treat as id+label
            if (typeof m === 'string') {
                o.value = m; o.textContent = m;
                if (m === def) o.selected = true;
            } else {
                o.value = m.id;
                o.textContent = m.label;
                o.dataset.tier = m.tier || 'unknown';
                if (m.id === def) o.selected = true;
            }
            modelSel.appendChild(o);
        });
        if (models.length === 0) {
            var o = document.createElement('option');
            o.value = ''; o.textContent = 'Enter model name in API URL';
            modelSel.appendChild(o);
        }
    }

    sel.addEventListener('change', updateProvider);
    updateProvider();

    // v1.5.32 — Quick-pick preset handlers. Click a preset card to auto-fill
    // the provider + model fields below with a known-compatible combination.
    document.querySelectorAll('.sb-quick-pick').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var p = this.dataset.provider;
            var m = this.dataset.model;
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === p) {
                    sel.selectedIndex = i;
                    break;
                }
            }
            updateProvider();
            for (var j = 0; j < modelSel.options.length; j++) {
                if (modelSel.options[j].value === m) {
                    modelSel.selectedIndex = j;
                    break;
                }
            }
            // Scroll to the provider form so user sees the selection
            document.querySelector('[name="provider_api_key"]').focus();
        });
    });

    // v1.5.32 — Warn user if they save a red-tier model
    var providerForm = document.querySelector('button[name="seobetter_save_provider"]');
    if (providerForm) {
        providerForm.addEventListener('click', function(e) {
            var chosen = modelSel.options[modelSel.selectedIndex];
            if (chosen && chosen.dataset.tier === 'red') {
                if (!confirm('⚠️ Warning: ' + chosen.value + ' is marked as NOT recommended for SEOBetter.\n\nThis model is known to ignore the PLACES RULES and Citation Pool instructions, which means it may produce articles with hallucinated business names, fake URLs, or invented quotes — even when real data is available.\n\nWe strongly recommend using one of the green-tier models instead (Claude Sonnet 4.6, Claude Haiku 4.5, or GPT-4.1).\n\nContinue anyway?')) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    }
})();
</script>
