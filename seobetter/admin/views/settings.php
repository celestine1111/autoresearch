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

        <!-- Add New Provider -->
        <h3><?php esc_html_e( 'Add AI Provider', 'seobetter' ); ?></h3>
        <form method="post">
            <?php wp_nonce_field( 'seobetter_settings_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Provider', 'seobetter' ); ?></th>
                    <td>
                        <select name="provider_id" id="seobetter-provider-select">
                            <?php foreach ( $providers as $pid => $pdef ) : ?>
                                <option value="<?php echo esc_attr( $pid ); ?>"
                                    data-models="<?php echo esc_attr( wp_json_encode( $pdef['models'] ) ); ?>"
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
                <tr>
                    <th><?php esc_html_e( 'Perplexity Sonar (via OpenRouter)', 'seobetter' ); ?>
                        <span class="seobetter-score seobetter-score-good" style="font-size:10px;margin-left:6px;background:#dbeafe;color:#1e40af"><?php esc_html_e( 'RECOMMENDED', 'seobetter' ); ?></span>
                    </th>
                    <td>
                        <input type="password" name="openrouter_api_key" value="<?php echo esc_attr( $settings['openrouter_api_key'] ?? '' ); ?>" class="regular-text" placeholder="sk-or-v1-..." autocomplete="off" />
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
        models.forEach(function(m) {
            var o = document.createElement('option');
            o.value = m; o.textContent = m;
            if (m === def) o.selected = true;
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
})();
</script>
