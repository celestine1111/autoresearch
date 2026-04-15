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
    $existing = get_option( 'seobetter_settings', [] );
    $settings = array_merge( $existing, [
        'auto_schema'        => ! empty( $_POST['auto_schema'] ),
        'auto_analyze'       => ! empty( $_POST['auto_analyze'] ),
        'target_readability' => absint( $_POST['target_readability'] ?? 7 ),
        'geo_engines'        => array_map( 'sanitize_text_field', $_POST['geo_engines'] ?? [] ),
        'llms_txt_enabled'   => ! empty( $_POST['llms_txt_enabled'] ),
        'brave_api_key'      => sanitize_text_field( $_POST['brave_api_key'] ?? '' ),
        'pexels_api_key'     => sanitize_text_field( $_POST['pexels_api_key'] ?? '' ),
    ] );
    update_option( 'seobetter_settings', $settings );
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'seobetter' ) . '</p></div>';
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
    $allowed_providers = [ '', 'pollinations', 'gemini', 'dalle3', 'flux_pro' ];
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
    ] );
    update_option( 'seobetter_settings', $settings );
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Branding & AI image settings saved.', 'seobetter' ) . '</p></div>';
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

    <!-- AI Providers Section (BYOK) -->
    <div class="seobetter-card" style="margin-bottom:20px">
        <h2><?php esc_html_e( 'AI Providers (Bring Your Own Key)', 'seobetter' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Connect your own AI API key. Your key is stored locally and never sent to SEOBetter servers.', 'seobetter' ); ?></p>

        <?php if ( ! $license_info['is_pro'] ) : ?>
            <p style="color:#856404;background:#fff3cd;padding:8px 12px;border-radius:4px">
                <span class="dashicons dashicons-info-outline"></span>
                <?php esc_html_e( 'Free tier: 1 AI provider. Upgrade to Pro for unlimited providers.', 'seobetter' ); ?>
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

        <!-- v1.5.32 — Quick-Pick Model Presets -->
        <div style="margin-top:20px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
            <h3 style="margin:0 0 8px 0"><?php esc_html_e( '⚡ Quick Pick — Recommended Models', 'seobetter' ); ?></h3>
            <p class="description" style="margin:0 0 12px 0">
                <?php esc_html_e( 'Not sure which model to pick? Click a preset below and the form will auto-fill with a known-compatible model. You can edit from there. These presets are tested to follow SEOBetter\'s hallucination-prevention rules.', 'seobetter' ); ?>
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
                    <th><?php esc_html_e( 'Brave Search API Key', 'seobetter' ); ?>
                        <?php if ( ! $license_info['is_pro'] ) : ?><span class="seobetter-score seobetter-score-ok" style="font-size:10px;margin-left:6px">PRO</span><?php endif; ?>
                    </th>
                    <td>
                        <input type="password" name="brave_api_key" value="<?php echo esc_attr( $settings['brave_api_key'] ?? '' ); ?>" class="regular-text" placeholder="BSA..." />
                        <a href="https://brave.com/search/api/" target="_blank" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Get Free Key', 'seobetter' ); ?></a>
                        <p class="description"><?php esc_html_e( 'Pro feature. Adds real web statistics and verified sources to generated articles. 2,000 free queries/month.', 'seobetter' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Pexels API Key', 'seobetter' ); ?></th>
                    <td>
                        <input type="password" name="pexels_api_key" value="<?php echo esc_attr( $settings['pexels_api_key'] ?? '' ); ?>" class="regular-text" placeholder="Enter Pexels API key..." />
                        <a href="https://www.pexels.com/api/new/" target="_blank" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Get Free Key', 'seobetter' ); ?></a>
                        <p class="description"><?php esc_html_e( 'Free. Adds topic-relevant images to articles and sets featured image. 15,000 requests/month. Without this, generic placeholder images are used.', 'seobetter' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Settings', 'seobetter' ), 'primary', 'seobetter_save_settings' ); ?>
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

        <p class="description" style="padding:8px 12px;background:#f0fdf4;border-radius:4px;color:#166534;margin-top:8px">
            <span class="dashicons dashicons-info-outline"></span>
            <strong><?php esc_html_e( 'How the waterfall works:', 'seobetter' ); ?></strong>
            <?php esc_html_e( 'For any article with a local-intent keyword (e.g. "best gelato shops in Lucignano"), the plugin tries OSM → Wikidata → Foursquare → HERE → Google Places in order, stopping at the first tier returning 3+ verified places. Unconfigured tiers are skipped. If no tier returns enough data, the plugin writes a general informational article with a disclaimer — it NEVER invents business names.', 'seobetter' ); ?>
        </p>
    </div>
</div>

<?php // v1.5.32 — Branding + AI Featured Image card ?>
<div class="seobetter-dashboard-card" style="margin-top:24px">
    <div class="seobetter-card-body">
        <h2><?php esc_html_e( 'Branding & AI Featured Image', 'seobetter' ); ?></h2>
        <p class="description" style="margin-bottom:16px">
            <?php esc_html_e( 'Upload your brand assets and choose an AI image provider to auto-generate a brand-aware featured image for every article. Uses the article title + keywords + your brand colors to compose the prompt. If no provider is configured, the plugin falls back to Pexels/Picsum stock images.', 'seobetter' ); ?>
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
                            <option value="" <?php selected( $bp, '' ); ?>>— <?php esc_html_e( 'Disabled (use Pexels/Picsum fallback)', 'seobetter' ); ?> —</option>
                            <option value="pollinations" <?php selected( $bp, 'pollinations' ); ?>><?php esc_html_e( 'Pollinations.ai — FREE, no API key, no signup (Recommended to start)', 'seobetter' ); ?></option>
                            <option value="gemini" <?php selected( $bp, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini 2.5 Flash Image (Nano Banana) — ~$0.04/image, 10/day FREE on AI Studio', 'seobetter' ); ?></option>
                            <option value="dalle3" <?php selected( $bp, 'dalle3' ); ?>><?php esc_html_e( 'OpenAI DALL-E 3 — $0.04/image standard, strong prompt adherence', 'seobetter' ); ?></option>
                            <option value="flux_pro" <?php selected( $bp, 'flux_pro' ); ?>><?php esc_html_e( 'Black Forest Labs FLUX.1 Pro 1.1 (via fal.ai) — $0.055/image, best editorial quality', 'seobetter' ); ?></option>
                        </select>
                        <p class="description" style="margin-top:8px">
                            <strong><?php esc_html_e( 'Recommended for most users:', 'seobetter' ); ?></strong> <?php esc_html_e( 'Start with Pollinations (free, zero setup). Upgrade to Gemini Nano Banana or FLUX Pro once you want consistent brand-aware quality.', 'seobetter' ); ?>
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
                            <span data-provider="gemini"><?php esc_html_e( 'Get a free key at aistudio.google.com/apikey. Free tier: 10 images/day on gemini-2.5-flash-image. Paid: ~$0.04/image.', 'seobetter' ); ?> <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener"><?php esc_html_e( 'Get Gemini Key', 'seobetter' ); ?></a></span>
                            <span data-provider="dalle3"><?php esc_html_e( 'Get a key at platform.openai.com. Billing required. $0.04 standard / $0.08 HD per image.', 'seobetter' ); ?> <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener"><?php esc_html_e( 'Get OpenAI Key', 'seobetter' ); ?></a></span>
                            <span data-provider="flux_pro"><?php esc_html_e( 'Get a fal.ai API key. $5 free credit on signup. $0.055 per image. Best editorial-quality realistic images.', 'seobetter' ); ?> <a href="https://fal.ai/dashboard/keys" target="_blank" rel="noopener"><?php esc_html_e( 'Get fal.ai Key', 'seobetter' ); ?></a></span>
                        </p>
                    </td>
                </tr>

                <!-- Style preset -->
                <tr>
                    <th><?php esc_html_e( 'Image Style Preset', 'seobetter' ); ?></th>
                    <td>
                        <?php $bs = $settings['branding_style'] ?? 'realistic'; ?>
                        <select name="branding_style">
                            <option value="realistic" <?php selected( $bs, 'realistic' ); ?>><?php esc_html_e( 'Realistic Photo — editorial photojournalism', 'seobetter' ); ?></option>
                            <option value="illustration" <?php selected( $bs, 'illustration' ); ?>><?php esc_html_e( 'Vector Illustration — clean lines, minimal shading', 'seobetter' ); ?></option>
                            <option value="flat" <?php selected( $bs, 'flat' ); ?>><?php esc_html_e( 'Flat Graphic — bold shapes, solid backgrounds', 'seobetter' ); ?></option>
                            <option value="hero" <?php selected( $bs, 'hero' ); ?>><?php esc_html_e( 'Hero Banner — cinematic, dramatic lighting', 'seobetter' ); ?></option>
                            <option value="minimalist" <?php selected( $bs, 'minimalist' ); ?>><?php esc_html_e( 'Minimalist — lots of negative space', 'seobetter' ); ?></option>
                            <option value="editorial" <?php selected( $bs, 'editorial' ); ?>><?php esc_html_e( 'Editorial — magazine journalism', 'seobetter' ); ?></option>
                            <option value="3d" <?php selected( $bs, '3d' ); ?>><?php esc_html_e( '3D Render — studio lighting, product-shot style', 'seobetter' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'The style preset determines the image generation prompt template. All presets automatically weave in your brand colors and business context.', 'seobetter' ); ?></p>
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
            <?php esc_html_e( 'This setting controls the article FEATURED image only (the big hero at the top / social share preview). Inline images inside the article body still come from Pexels (if configured) or Picsum (free fallback) — AI-generated inline images would add $0.12+ per article for marginal visual gain, and Pexels already has millions of relevant real photos for free. Reserve AI generation for the one image that matters most: the hero.', 'seobetter' ); ?>
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
        $.ajax({
            url: '<?php echo esc_js( rest_url( 'seobetter/v1/test-sonar' ) ); ?>',
            method: 'POST',
            headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' },
            data: {},
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

    // Show/hide API key row based on provider (Pollinations needs no key)
    function updateBrandingKeyRow() {
        var p = $('#branding_provider').val();
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
