<?php
/**
 * BlogTalk AI Integration
 * 
 * Wrapper für Perplexity.ai API mit Character-Context-Injection,
 * Rate Limiting und intelligenter Response-Generierung
 * 
 * @package BlogTalk
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BlogTalk_Ai_Integration {
    
    /**
     * Plugin-Einstellungen
     */
    private array $settings;
    
    /**
     * API-Konfiguration
     */
    private array $api_config;
    
    /**
     * Rate Limiting
     */
    private int $max_requests_per_hour = 100;
    private string $rate_limit_key = 'blogtalk_api_requests';
    
    /**
     * Cache-Einstellungen
     */
    private int $cache_duration = 3600; // 1 Stunde
    private string $cache_prefix = 'blogtalk_ai_response_';
    
    /**
     * API-Endpoints
     */
    private array $api_endpoints = [
        'perplexity' => 'https://api.perplexity.ai/chat/completions',
        // Weitere APIs können hier hinzugefügt werden
        'openai' => 'https://api.openai.com/v1/chat/completions',
        'anthropic' => 'https://api.anthropic.com/v1/messages'
    ];
    
    public function __construct(array $settings) {
        $this->settings = $settings;
        $this->load_api_config();
        $this->max_requests_per_hour = $settings['rate_limit_requests'] ?? 100;
        $this->cache_duration = $settings['cache_duration'] ?? 3600;
    }
    
    /**
     * Lade API-Konfiguration
     */
    private function load_api_config(): void {
        $this->api_config = [
            'provider' => $this->settings['api_provider'] ?? 'perplexity',
            'api_key' => get_option('blogtalk_api_key', ''),
            'model' => get_option('blogtalk_ai_model', 'llama-3.1-sonar-small-128k-online'),
            'max_tokens' => 500,
            'temperature' => 0.7
        ];
        
        // API-Key verschlüsselt speichern/laden
        if (!empty($this->api_config['api_key'])) {
            $this->api_config['api_key'] = $this->decrypt_api_key($this->api_config['api_key']);
        }
    }
    
    /**
     * Generiert Character-Response für User-Message
     */
    public function generate_character_response(array $character, string $user_message, int $post_id): string {
        try {
            // Rate Limiting prüfen
            if (!$this->check_rate_limit()) {
                throw new Exception(__('Rate Limit erreicht. Bitte versuchen Sie es später erneut.', 'blogtalk'));
            }
            
            // Cache prüfen
            $cache_key = $this->generate_cache_key($character['id'], $user_message);
            $cached_response = get_transient($cache_key);
            
            if ($cached_response !== false) {
                blogtalk_debug_log('AI-Response aus Cache geladen für Character: ' . $character['character_name']);
                return $cached_response;
            }
            
            // Character-Context aufbauen
            $context = $this->build_character_context($character, $post_id);
            
            // Prompt erstellen
            $prompt = $this->create_character_prompt($context, $user_message);
            
            // API-Request senden
            $response = $this->send_api_request($prompt);
            
            // Response verarbeiten
            $processed_response = $this->process_ai_response($response, $character);
            
            // Im Cache speichern
            set_transient($cache_key, $processed_response, $this->cache_duration);
            
            // Request-Counter erhöhen
            $this->increment_request_counter();
            
            blogtalk_debug_log('AI-Response generiert für Character: ' . $character['character_name']);
            
            return $processed_response;
            
        } catch (Exception $e) {
            blogtalk_debug_log('AI-Integration Fehler: ' . $e->getMessage());
            return $this->generate_fallback_response($character, $user_message);
        }
    }
    
    /**
     * Baut Character-Context für AI auf
     */
    private function build_character_context(array $character, int $post_id): array {
        // Basis Character-Daten
        $context = [
            'name' => $character['character_name'],
            'type' => $character['character_type'],
            'description' => $character['description'] ?? '',
            'personality_traits' => json_decode($character['personality_traits'] ?? '[]', true),
        ];
        
        // Story-Context hinzufügen
        $story_context = json_decode($character['context_data'] ?? '{}', true);
        if (!empty($story_context)) {
            $context['story'] = $story_context;
        }
        
        // Post-Informationen
        $post = get_post($post_id);
        if ($post) {
            $context['story_title'] = $post->post_title;
            $context['story_excerpt'] = wp_trim_words(strip_tags($post->post_content), 100);
        }
        
        // Letzte Conversations für Kontinuität
        $context['recent_conversations'] = $this->get_recent_conversations($character['id'], 3);
        
        return $context;
    }
    
    /**
     * Erstellt Character-spezifischen Prompt
     */
    private function create_character_prompt(array $context, string $user_message): array {
        $character_name = $context['name'];
        $character_type = $context['type'];
        $personality = $context['personality_traits'] ?? [];
        $story_title = $context['story']['story_title'] ?? 'der Geschichte';
        
        // System-Prompt für Character-Roleplay
        $system_prompt = sprintf(
            "Du bist %s, ein %s aus der Geschichte '%s'. 
            
            WICHTIGE CHARAKTERREGELN:
            - Antworte IMMER als %s in der ersten Person ('Ich')
            - Bleibe im Charakter und in der Welt der Geschichte
            - Verwende die Persönlichkeitsmerkmale: %s
            - Antworte auf Deutsch in einem natürlichen, gesprächigen Ton
            - Halte Antworten zwischen 50-150 Wörtern
            - Beziehe dich auf Ereignisse und andere Charaktere aus der Geschichte
            - Wenn du etwas nicht weißt, bleibe im Charakter und improvisiere passend zur Story
            
            STORY-KONTEXT:
            %s
            
            PERSÖNLICHKEIT:
            Du bist %s und verhältst dich entsprechend deiner Rolle in der Geschichte.",
            $character_name,
            $this->get_character_type_description($character_type),
            $story_title,
            $character_name,
            implode(', ', $personality),
            $context['story']['story_excerpt'] ?? 'Keine weiteren Details verfügbar.',
            implode(', ', $personality)
        );
        
        // Conversation History hinzufügen falls vorhanden
        $messages = [
            ['role' => 'system', 'content' => $system_prompt]
        ];
        
        // Letzte Conversations für Kontext
        if (!empty($context['recent_conversations'])) {
            foreach ($context['recent_conversations'] as $conv) {
                $messages[] = ['role' => 'user', 'content' => $conv['user_message']];
                $messages[] = ['role' => 'assistant', 'content' => $conv['ai_response']];
            }
        }
        
        // Aktuelle User-Message
        $messages[] = ['role' => 'user', 'content' => $user_message];
        
        return $messages;
    }
    
    /**
     * Sendet API-Request an gewählten Provider
     */
    private function send_api_request(array $messages): array {
        $provider = $this->api_config['provider'];
        $endpoint = $this->api_endpoints[$provider] ?? $this->api_endpoints['perplexity'];
        
        $request_data = $this->prepare_request_data($messages, $provider);
        
        $response = wp_remote_post($endpoint, [
            'timeout' => 30,
            'headers' => $this->get_api_headers($provider),
            'body' => json_encode($request_data),
            'user-agent' => 'BlogTalk-WordPress-Plugin/' . BLOGTALK_VERSION
        ]);
        
        if (is_wp_error($response)) {
            throw new Exception('API-Request fehlgeschlagen: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? 'Unbekannter API-Fehler';
            throw new Exception("API-Fehler ({$response_code}): {$error_message}");
        }
        
        $decoded_response = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ungültige JSON-Response von API');
        }
        
        return $decoded_response;
    }
    
    /**
     * Bereitet Request-Daten für spezifischen Provider vor
     */
    private function prepare_request_data(array $messages, string $provider): array {
        $base_data = [
            'messages' => $messages,
            'max_tokens' => $this->api_config['max_tokens'],
            'temperature' => $this->api_config['temperature'],
            'stream' => false
        ];
        
        switch ($provider) {
            case 'perplexity':
                return array_merge($base_data, [
                    'model' => $this->api_config['model'],
                    'return_citations' => false,
                    'return_images' => false
                ]);
                
            case 'openai':
                return array_merge($base_data, [
                    'model' => $this->api_config['model'] ?? 'gpt-3.5-turbo',
                    'presence_penalty' => 0.1,
                    'frequency_penalty' => 0.1
                ]);
                
            case 'anthropic':
                // Anthropic hat ein anderes Message-Format
                $system_message = '';
                $user_messages = [];
                
                foreach ($messages as $message) {
                    if ($message['role'] === 'system') {
                        $system_message = $message['content'];
                    } else {
                        $user_messages[] = $message;
                    }
                }
                
                return [
                    'model' => $this->api_config['model'] ?? 'claude-3-sonnet-20240229',
                    'max_tokens' => $this->api_config['max_tokens'],
                    'system' => $system_message,
                    'messages' => $user_messages
                ];
                
            default:
                return $base_data;
        }
    }
    
    /**
     * Erstellt API-Headers für Provider
     */
    private function get_api_headers(string $provider): array {
        $base_headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'BlogTalk/' . BLOGTALK_VERSION
        ];
        
        switch ($provider) {
            case 'perplexity':
                return array_merge($base_headers, [
                    'Authorization' => 'Bearer ' . $this->api_config['api_key']
                ]);
                
            case 'openai':
                return array_merge($base_headers, [
                    'Authorization' => 'Bearer ' . $this->api_config['api_key']
                ]);
                
            case 'anthropic':
                return array_merge($base_headers, [
                    'x-api-key' => $this->api_config['api_key'],
                    'anthropic-version' => '2023-06-01'
                ]);
                
            default:
                return array_merge($base_headers, [
                    'Authorization' => 'Bearer ' . $this->api_config['api_key']
                ]);
        }
    }
    
    /**
     * Verarbeitet AI-Response
     */
    private function process_ai_response(array $response, array $character): string {
        $provider = $this->api_config['provider'];
        
        // Extrahiere Text basierend auf Provider
        $content = '';
        
        switch ($provider) {
            case 'perplexity':
            case 'openai':
                $content = $response['choices'][0]['message']['content'] ?? '';
                break;
                
            case 'anthropic':
                $content = $response['content'][0]['text'] ?? '';
                break;
                
            default:
                $content = $response['choices'][0]['message']['content'] ?? '';
        }
        
        if (empty($content)) {
            throw new Exception('Leere Response von AI-Provider');
        }
        
        // Post-Processing
        $content = $this->post_process_response($content, $character);
        
        return $content;
    }
    
    /**
     * Post-Processing der AI-Response
     */
    private function post_process_response(string $content, array $character): string {
        // Entferne potentielle System-Nachrichten
        $content = preg_replace('/^(System:|Assistant:|Bot:)/i', '', $content);
        
        // Entferne überschüssige Whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        
        // Stelle sicher, dass die Antwort nicht zu lang ist
        if (strlen($content) > 800) {
            $content = wp_trim_words($content, 120);
        }
        
        // Stelle sicher, dass die Antwort in der ersten Person ist
        $character_name = $character['character_name'];
        
        // Ersetze Dritte Person durch Erste Person (einfache Heuristik)
        $content = preg_replace("/\b{$character_name}\s+(ist|war|hat|hatte|geht|ging)/i", 'Ich $1', $content);
        $content = preg_replace("/\b{$character_name}\s+/i", 'Ich ', $content);
        
        // Füge emotionale Nuancen hinzu basierend auf Character-Type
        if ($character['character_type'] === 'protagonist') {
            // Protagonisten sprechen selbstbewusster
            $content = $this->add_confident_tone($content);
        }
        
        return $content;
    }
    
    /**
     * Fügt selbstbewussten Ton hinzu
     */
    private function add_confident_tone(string $content): string {
        // Einfache Ersetzungen für selbstbewussteren Ton
        $replacements = [
            'ich denke' => 'ich bin mir sicher',
            'vielleicht' => 'wahrscheinlich',
            'ich glaube' => 'ich weiß',
        ];
        
        foreach ($replacements as $search => $replace) {
            $content = str_ireplace($search, $replace, $content);
        }
        
        return $content;
    }
    
    /**
     * Generiert Fallback-Response bei API-Fehlern
     */
    private function generate_fallback_response(array $character, string $user_message): string {
        $character_name = $character['character_name'];
        $character_type = $character['character_type'];
        
        $fallback_responses = [
            sprintf(
                "Entschuldigung, als %s aus der Geschichte bin ich gerade etwas verwirrt. Könntest du deine Frage anders stellen?",
                $character_name
            ),
            sprintf(
                "Es tut mir leid, aber ich als %s kann gerade nicht richtig antworten. Vielleicht versuchst du es später nochmal?",
                $character_name
            ),
            sprintf(
                "Hmm, das ist eine interessante Frage! Als %s aus der Geschichte denke ich darüber nach, aber mir fällt gerade keine passende Antwort ein.",
                $character_name
            )
        ];
        
        $response_index = crc32($user_message . $character_name) % count($fallback_responses);
        return $fallback_responses[$response_index];
    }
    
    /**
     * Rate Limiting prüfen
     */
    private function check_rate_limit(): bool {
        $current_requests = get_transient($this->rate_limit_key) ?: 0;
        
        if ($current_requests >= $this->max_requests_per_hour) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Request-Counter erhöhen
     */
    private function increment_request_counter(): void {
        $current_requests = get_transient($this->rate_limit_key) ?: 0;
        set_transient($this->rate_limit_key, $current_requests + 1, HOUR_IN_SECONDS);
    }
    
    /**
     * Cache-Key generieren
     */
    private function generate_cache_key(int $character_id, string $user_message): string {
        return $this->cache_prefix . md5($character_id . '_' . $user_message);
    }
    
    /**
     * Letzte Conversations abrufen
     */
    private function get_recent_conversations(int $character_id, int $limit = 3): array {
        global $wpdb;
        
        $table = $wpdb->prefix . 'blogtalk_conversations';
        $conversations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_message, ai_response FROM $table 
                 WHERE character_id = %d 
                 ORDER BY created_at DESC 
                 LIMIT %d",
                $character_id,
                $limit
            ),
            ARRAY_A
        );
        
        // Reihenfolge umkehren für chronologischen Kontext
        return array_reverse($conversations);
    }
    
    /**
     * Character-Type-Beschreibung
     */
    private function get_character_type_description(string $type): string {
        return match($type) {
            'protagonist' => 'Hauptcharakter',
            'supporting' => 'wichtiger Nebencharakter',
            'minor' => 'Nebencharakter',
            default => 'Charakter'
        };
    }
    
    /**
     * API-Key verschlüsseln
     */
    public function encrypt_api_key(string $api_key): string {
        if (empty($api_key)) {
            return '';
        }
        
        // Verwende WordPress-eigene Salts für Verschlüsselung
        $salt = wp_salt('auth');
        return base64_encode(openssl_encrypt($api_key, 'AES-256-CBC', $salt, 0, substr($salt, 0, 16)));
    }
    
    /**
     * API-Key entschlüsseln
     */
    private function decrypt_api_key(string $encrypted_key): string {
        if (empty($encrypted_key)) {
            return '';
        }
        
        try {
            $salt = wp_salt('auth');
            return openssl_decrypt(base64_decode($encrypted_key), 'AES-256-CBC', $salt, 0, substr($salt, 0, 16));
        } catch (Exception $e) {
            blogtalk_debug_log('API-Key Entschlüsselung fehlgeschlagen: ' . $e->getMessage());
            return '';
        }
    }
    
    /**
     * API-Verbindung testen
     */
    public function test_api_connection(): array {
        try {
            $test_messages = [
                ['role' => 'system', 'content' => 'Du bist ein Test-Assistent.'],
                ['role' => 'user', 'content' => 'Antworte nur mit "Test erfolgreich" auf Deutsch.']
            ];
            
            $response = $this->send_api_request($test_messages);
            
            return [
                'success' => true,
                'message' => __('API-Verbindung erfolgreich getestet.', 'blogtalk'),
                'response' => $response
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => sprintf(__('API-Test fehlgeschlagen: %s', 'blogtalk'), $e->getMessage()),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Aktuelle Rate Limit-Statistiken
     */
    public function get_rate_limit_stats(): array {
        $current_requests = get_transient($this->rate_limit_key) ?: 0;
        $remaining_requests = max(0, $this->max_requests_per_hour - $current_requests);
        
        return [
            'current_requests' => $current_requests,
            'max_requests' => $this->max_requests_per_hour,
            'remaining_requests' => $remaining_requests,
            'reset_time' => time() + (HOUR_IN_SECONDS - (time() % HOUR_IN_SECONDS))
        ];
    }
    
    /**
     * Cache-Statistiken
     */
    public function get_cache_stats(): array {
        global $wpdb;
        
        // Zähle Cache-Einträge
        $cache_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_{$this->cache_prefix}%'"
        );
        
        return [
            'cached_responses' => $cache_count,
            'cache_duration' => $this->cache_duration,
            'cache_prefix' => $this->cache_prefix
        ];
    }
    
    /**
     * Cache leeren
     */
    public function clear_cache(): int {
        global $wpdb;
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_' . $this->cache_prefix . '%',
                '_transient_timeout_' . $this->cache_prefix . '%'
            )
        );
        
        return $deleted;
    }
}
