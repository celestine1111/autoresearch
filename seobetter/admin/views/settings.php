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
    $settings = [
        'auto_schema'        => ! empty( $_POST['auto_schema'] ),
        'auto_analyze'       => ! empty( $_POST['auto_analyze'] ),
        'target_readability' => absint( $_POST['target_readability'] ?? 7 ),
        'geo_engines'        => array_map( 'sanitize_text_field', $_POST['geo_engines'] ?? [] ),
        'llms_txt_enabled'   => ! empty( $_POST['llms_txt_enabled'] ),
    ];
    update_option( 'seobetter_settings', $settings );
    echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'seobetter' ) . '</p></div>';
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
            </table>
            <?php submit_button( __( 'Save Settings', 'seobetter' ), 'primary', 'seobetter_save_settings' ); ?>
        </form>
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
