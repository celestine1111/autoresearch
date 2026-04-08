<?php

namespace SEOBetter;

/**
 * AI Provider Manager — BYOK (Bring Your Own Key).
 *
 * Users connect their own AI API keys. Supported providers:
 * - Anthropic Claude (API key: sk-ant-api03-*)
 * - OpenAI / ChatGPT (API key: sk-*)
 * - Google Gemini (API key)
 * - Groq (API key)
 * - OpenRouter (API key — access to 100+ models)
 * - Ollama (local, no key needed)
 * - Custom OpenAI-compatible endpoint
 *
 * NOTE: Claude OAuth tokens (sk-ant-oat01-*) are NOT supported for
 * third-party API calls. Users must use console API keys.
 */
class AI_Provider_Manager {

    private const OPTION_KEY = 'seobetter_ai_providers';

    /**
     * Supported provider definitions.
     */
    private const PROVIDERS = [
        'anthropic' => [
            'name'        => 'Anthropic (Claude)',
            'api_url'     => 'https://api.anthropic.com/v1/messages',
            'key_prefix'  => 'sk-ant-api',
            'models'      => [ 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001', 'claude-opus-4-6' ],
            'default_model' => 'claude-sonnet-4-6',
            'docs_url'    => 'https://console.anthropic.com/settings/keys',
            'help'        => 'Get your API key from console.anthropic.com. Claude OAuth tokens (sk-ant-oat) are NOT supported for API calls.',
        ],
        'openai' => [
            'name'        => 'OpenAI (ChatGPT)',
            'api_url'     => 'https://api.openai.com/v1/chat/completions',
            'key_prefix'  => 'sk-',
            'models'      => [ 'gpt-4o', 'gpt-4o-mini', 'o3', 'o4-mini' ],
            'default_model' => 'gpt-4o',
            'docs_url'    => 'https://platform.openai.com/api-keys',
            'help'        => 'Get your API key from platform.openai.com',
        ],
        'gemini' => [
            'name'        => 'Google Gemini',
            'api_url'     => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
            'key_prefix'  => '',
            'models'      => [ 'gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.0-flash' ],
            'default_model' => 'gemini-2.5-flash',
            'docs_url'    => 'https://aistudio.google.com/apikey',
            'help'        => 'Get your API key from Google AI Studio',
        ],
        'groq' => [
            'name'        => 'Groq',
            'api_url'     => 'https://api.groq.com/openai/v1/chat/completions',
            'key_prefix'  => 'gsk_',
            'models'      => [ 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768' ],
            'default_model' => 'llama-3.3-70b-versatile',
            'docs_url'    => 'https://console.groq.com/keys',
            'help'        => 'Free tier available. Get your key from console.groq.com',
        ],
        'openrouter' => [
            'name'        => 'OpenRouter (100+ models)',
            'api_url'     => 'https://openrouter.ai/api/v1/chat/completions',
            'key_prefix'  => 'sk-or-',
            'models'      => [ 'anthropic/claude-sonnet-4', 'openai/gpt-4o', 'google/gemini-2.5-pro', 'meta-llama/llama-3.3-70b' ],
            'default_model' => 'anthropic/claude-sonnet-4',
            'docs_url'    => 'https://openrouter.ai/keys',
            'help'        => 'Access 100+ models with one key. Pay per token.',
        ],
        'ollama' => [
            'name'        => 'Ollama (Local)',
            'api_url'     => 'http://localhost:11434/api/chat',
            'key_prefix'  => '',
            'models'      => [ 'llama3.3', 'mistral', 'qwen2.5', 'deepseek-r1' ],
            'default_model' => 'llama3.3',
            'docs_url'    => 'https://ollama.com',
            'help'        => 'Run models locally. No API key needed. Install Ollama first.',
        ],
        'custom' => [
            'name'        => 'Custom (OpenAI-compatible)',
            'api_url'     => '',
            'key_prefix'  => '',
            'models'      => [],
            'default_model' => '',
            'docs_url'    => '',
            'help'        => 'Any OpenAI-compatible API endpoint (LM Studio, vLLM, Together AI, etc.)',
        ],
    ];

    /**
     * Get all provider definitions.
     */
    public static function get_providers(): array {
        return self::PROVIDERS;
    }

    /**
     * Get saved provider configurations.
     */
    public static function get_saved_providers(): array {
        return get_option( self::OPTION_KEY, [] );
    }

    /**
     * Save a provider configuration.
     */
    public static function save_provider( string $provider_id, array $config ): bool {
        if ( ! isset( self::PROVIDERS[ $provider_id ] ) ) {
            return false;
        }

        // Free tier: only 1 provider allowed
        if ( ! License_Manager::can_use( 'unlimited_ai_providers' ) ) {
            $saved = self::get_saved_providers();
            $active_providers = array_filter( $saved, fn( $p ) => ! empty( $p['api_key'] ) || $provider_id === 'ollama' );
            if ( count( $active_providers ) >= 1 && ! isset( $active_providers[ $provider_id ] ) ) {
                return false; // Free tier limit reached
            }
        }

        $saved = self::get_saved_providers();
        $saved[ $provider_id ] = [
            'api_key'    => sanitize_text_field( $config['api_key'] ?? '' ),
            'model'      => sanitize_text_field( $config['model'] ?? self::PROVIDERS[ $provider_id ]['default_model'] ),
            'api_url'    => esc_url_raw( $config['api_url'] ?? self::PROVIDERS[ $provider_id ]['api_url'] ),
            'is_active'  => true,
            'added'      => current_time( 'mysql' ),
        ];

        return update_option( self::OPTION_KEY, $saved );
    }

    /**
     * Remove a provider configuration.
     */
    public static function remove_provider( string $provider_id ): bool {
        $saved = self::get_saved_providers();
        unset( $saved[ $provider_id ] );
        return update_option( self::OPTION_KEY, $saved );
    }

    /**
     * Get the active (primary) provider for making requests.
     */
    public static function get_active_provider(): ?array {
        $saved = self::get_saved_providers();

        foreach ( $saved as $id => $config ) {
            if ( ! empty( $config['is_active'] ) && ( ! empty( $config['api_key'] ) || $id === 'ollama' ) ) {
                return array_merge( self::PROVIDERS[ $id ] ?? [], $config, [ 'provider_id' => $id ] );
            }
        }

        return null;
    }

    /**
     * Test a provider connection.
     */
    public static function test_connection( string $provider_id ): array {
        $saved = self::get_saved_providers();
        $config = $saved[ $provider_id ] ?? null;
        $provider = self::PROVIDERS[ $provider_id ] ?? null;

        if ( ! $config || ! $provider ) {
            return [ 'success' => false, 'message' => 'Provider not configured.' ];
        }

        $test_prompt = 'Respond with exactly: "SEOBetter connection successful"';

        try {
            $response = self::send_request( $provider_id, $test_prompt );
            if ( $response['success'] ) {
                return [ 'success' => true, 'message' => 'Connection successful! Model: ' . ( $config['model'] ?? 'default' ) ];
            }
            return [ 'success' => false, 'message' => 'API error: ' . ( $response['error'] ?? 'Unknown error' ) ];
        } catch ( \Exception $e ) {
            return [ 'success' => false, 'message' => 'Connection failed: ' . $e->getMessage() ];
        }
    }

    /**
     * Send a request to the configured AI provider.
     */
    public static function send_request( string $provider_id, string $prompt, string $system_prompt = '', array $options = [] ): array {
        $saved = self::get_saved_providers();
        $config = $saved[ $provider_id ] ?? null;
        $provider = self::PROVIDERS[ $provider_id ] ?? null;

        if ( ! $config || ! $provider ) {
            return [ 'success' => false, 'error' => 'Provider not configured' ];
        }

        $model = $config['model'] ?? $provider['default_model'];
        $api_url = $config['api_url'] ?: $provider['api_url'];
        $api_key = $config['api_key'] ?? '';

        $max_tokens = $options['max_tokens'] ?? 4096;
        $temperature = $options['temperature'] ?? 0.7;

        return match ( $provider_id ) {
            'anthropic' => self::request_anthropic( $api_url, $api_key, $model, $prompt, $system_prompt, $max_tokens, $temperature ),
            'gemini'    => self::request_gemini( $api_url, $api_key, $model, $prompt, $system_prompt, $max_tokens, $temperature ),
            'ollama'    => self::request_ollama( $api_url, $model, $prompt, $system_prompt, $max_tokens, $temperature ),
            default     => self::request_openai_compatible( $api_url, $api_key, $model, $prompt, $system_prompt, $max_tokens, $temperature ),
        };
    }

    /**
     * Anthropic Claude API.
     */
    private static function request_anthropic( string $url, string $key, string $model, string $prompt, string $system, int $max_tokens, float $temp ): array {
        $body = [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'temperature' => $temp,
            'messages'   => [
                [ 'role' => 'user', 'content' => $prompt ],
            ],
        ];

        if ( $system ) {
            $body['system'] = $system;
        }

        $response = wp_remote_post( $url, [
            'timeout' => 120,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $key,
                'anthropic-version'  => '2023-06-01',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['error'] ) ) {
            return [ 'success' => false, 'error' => $data['error']['message'] ?? 'Unknown error' ];
        }

        return [
            'success' => true,
            'content' => $data['content'][0]['text'] ?? '',
            'model'   => $data['model'] ?? $model,
            'usage'   => $data['usage'] ?? [],
        ];
    }

    /**
     * Google Gemini API.
     */
    private static function request_gemini( string $url_template, string $key, string $model, string $prompt, string $system, int $max_tokens, float $temp ): array {
        $url = str_replace( '{model}', $model, $url_template ) . '?key=' . $key;

        $contents = [ [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ] ];
        $body = [
            'contents'         => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $max_tokens,
                'temperature'     => $temp,
            ],
        ];

        if ( $system ) {
            $body['systemInstruction'] = [ 'parts' => [ [ 'text' => $system ] ] ];
        }

        $response = wp_remote_post( $url, [
            'timeout' => 120,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['error'] ) ) {
            return [ 'success' => false, 'error' => $data['error']['message'] ?? 'Unknown error' ];
        }

        return [
            'success' => true,
            'content' => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
            'model'   => $model,
        ];
    }

    /**
     * Ollama (local) API.
     */
    private static function request_ollama( string $url, string $model, string $prompt, string $system, int $max_tokens, float $temp ): array {
        $body = [
            'model'   => $model,
            'messages' => [],
            'stream'  => false,
            'options'  => [ 'temperature' => $temp, 'num_predict' => $max_tokens ],
        ];

        if ( $system ) {
            $body['messages'][] = [ 'role' => 'system', 'content' => $system ];
        }
        $body['messages'][] = [ 'role' => 'user', 'content' => $prompt ];

        $response = wp_remote_post( $url, [
            'timeout' => 300,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return [
            'success' => true,
            'content' => $data['message']['content'] ?? '',
            'model'   => $model,
        ];
    }

    /**
     * OpenAI-compatible API (OpenAI, Groq, OpenRouter, custom).
     */
    private static function request_openai_compatible( string $url, string $key, string $model, string $prompt, string $system, int $max_tokens, float $temp ): array {
        $messages = [];
        if ( $system ) {
            $messages[] = [ 'role' => 'system', 'content' => $system ];
        }
        $messages[] = [ 'role' => 'user', 'content' => $prompt ];

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $max_tokens,
            'temperature' => $temp,
        ];

        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $key,
        ];

        $response = wp_remote_post( $url, [
            'timeout' => 120,
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['error'] ) ) {
            return [ 'success' => false, 'error' => $data['error']['message'] ?? 'Unknown error' ];
        }

        return [
            'success' => true,
            'content' => $data['choices'][0]['message']['content'] ?? '',
            'model'   => $data['model'] ?? $model,
            'usage'   => $data['usage'] ?? [],
        ];
    }
}
