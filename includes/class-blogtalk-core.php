<?php
/**
 * BlogTalk Core-Klasse
 * 
 * Zentrale Steuerung des BlogTalk-Plugins mit Singleton Pattern
 * Verwaltet Plugin-Initialisierung, Hook-Registration und Komponentenkoordination
 * 
 * @package BlogTalk
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BlogTalk_Core {
    
    /**
     * Singleton-Instanz
     */
    private static ?BlogTalk_Core $instance = null;
    
    /**
     * Plugin-Komponenten
     */
    private ?BlogTalk_Content_Parser $content_parser = null;
    private ?BlogTalk_Ai_Integration $ai_integration = null;
    private ?BlogTalk_Admin $admin = null;
    private ?BlogTalk_Frontend $frontend = null;
    
    /**
     * Plugin-Einstellungen
     */
    private array $settings = [];
    
    /**
     * Error-Handler
     */
    private array $errors = [];
    
    /**
     * Private Konstruktor für Singleton
     */
    private function __construct() {
        $this->load_settings();
        $this->init_hooks();
        $this->load_components();
    }
    
    /**
     * Singleton-Instanz abrufen
     */
    public static function get_instance(): BlogTalk_Core {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Verhindere Klonen der Instanz
     */
    private function __clone() {}
    
    /**
     * Verhindere Unserialisierung
     */
    public function __wakeup() {
        throw new Exception(__('Unserialisierung ist nicht erlaubt.', 'blogtalk'));
    }
    
    /**
     * Lade Plugin-Einstellungen
     */
    private function load_settings(): void {
        $default_settings = [
            'api_provider' => 'perplexity',
            'max_characters_per_post' => 5,
            'chat_widget_position' => 'bottom-right',
            'enable_typing_indicator' => true,
            'cache_duration' => 3600,
            'rate_limit_requests' => 50
        ];
        
        $saved_settings = get_option('blogtalk_settings', []);
        $this->settings = wp_parse_args($saved_settings, $default_settings);
    }
    
    /**
     * Initialisiere WordPress-Hooks
     */
    private function init_hooks(): void {
        // Plugin-Lifecycle-Hooks
        add_action('init', [$this, 'init_plugin']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // AJAX-Hooks
        add_action('wp_ajax_blogtalk_chat', [$this, 'handle_chat_request']);
        add_action('wp_ajax_nopriv_blogtalk_chat', [$this, 'handle_chat_request']);
        add_action('wp_ajax_blogtalk_get_characters', [$this, 'handle_get_characters']);
        add_action('wp_ajax_nopriv_blogtalk_get_characters', [$this, 'handle_get_characters']);
        
        // Content-Hooks
        add_action('save_post', [$this, 'analyze_post_characters'], 10, 2);
        add_filter('the_content', [$this, 'maybe_add_chat_widget']);
        
        // Shortcode-Registration
        add_shortcode('blogtalk_chat', [$this, 'render_chat_shortcode']);
        
        // REST API-Hooks
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Cleanup-Hooks
        add_action('wp_scheduled_delete', [$this, 'cleanup_old_conversations']);
    }
    
    /**
     * Lade Plugin-Komponenten
     */
    private function load_components(): void {
        try {
            // Content Parser initialisieren
            if (class_exists('BlogTalk_Content_Parser')) {
                $this->content_parser = new BlogTalk_Content_Parser($this->settings);
            }
            
            // AI Integration initialisieren
            if (class_exists('BlogTalk_Ai_Integration')) {
                $this->ai_integration = new BlogTalk_Ai_Integration($this->settings);
            }
            
            // Admin-Interface (nur im Backend)
            if (is_admin() && class_exists('BlogTalk_Admin')) {
                $this->admin = new BlogTalk_Admin($this->settings);
            }
            
            // Frontend (nur im Frontend)
            if (!is_admin() && class_exists('BlogTalk_Frontend')) {
                $this->frontend = new BlogTalk_Frontend($this->settings);
            }
            
        } catch (Exception $e) {
            $this->add_error('component_load_failed', $e->getMessage());
            blogtalk_debug_log('Fehler beim Laden der Komponenten: ' . $e->getMessage());
        }
    }
    
    /**
     * Plugin initialisieren
     */
    public function init_plugin(): void {
        // Registriere Custom Post Types falls benötigt
        // Füge Rewrite Rules hinzu
        // Initialisiere Cron Jobs
        
        // Cleanup alter Conversations (täglich)
        if (!wp_next_scheduled('blogtalk_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'blogtalk_daily_cleanup');
        }
    }
    
    /**
     * Frontend-Assets einbinden
     */
    public function enqueue_frontend_assets(): void {
        // Nur auf Seiten mit Stories laden
        if (!$this->should_load_chat_widget()) {
            return;
        }
        
        wp_enqueue_script(
            'blogtalk-frontend',
            BLOGTALK_PLUGIN_URL . 'assets/js/frontend.js',
            ['jquery'],
            BLOGTALK_VERSION,
            true
        );
        
        wp_enqueue_style(
            'blogtalk-frontend',
            BLOGTALK_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            BLOGTALK_VERSION
        );
        
        // JavaScript-Variablen für AJAX
        wp_localize_script('blogtalk-frontend', 'blogtalk_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('blogtalk_nonce'),
            'post_id' => get_the_ID(),
            'settings' => [
                'typing_indicator' => $this->settings['enable_typing_indicator'],
                'widget_position' => $this->settings['chat_widget_position']
            ],
            'strings' => [
                'loading' => __('Wird geladen...', 'blogtalk'),
                'error' => __('Fehler beim Laden der Antwort.', 'blogtalk'),
                'typing' => __('schreibt...', 'blogtalk'),
                'no_characters' => __('Keine Charaktere in dieser Geschichte gefunden.', 'blogtalk')
            ]
        ]);
    }
    
    /**
     * Admin-Assets einbinden
     */
    public function enqueue_admin_assets(string $hook): void {
        // Nur auf BlogTalk-Admin-Seiten
        if (strpos($hook, 'blogtalk') === false) {
            return;
        }
        
        wp_enqueue_script(
            'blogtalk-admin',
            BLOGTALK_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            BLOGTALK_VERSION,
            true
        );
        
        wp_enqueue_style(
            'blogtalk-admin',
            BLOGTALK_PLUGIN_URL . 'assets/css/admin.css',
            [],
            BLOGTALK_VERSION
        );
    }
    
    /**
     * AJAX: Chat-Anfrage verarbeiten
     */
    public function handle_chat_request(): void {
        // Security-Check
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'blogtalk_nonce')) {
            wp_die(__('Sicherheitsprüfung fehlgeschlagen.', 'blogtalk'));
        }
        
        // Rate Limiting prüfen
        if (!$this->check_rate_limit()) {
            wp_send_json_error(__('Zu viele Anfragen. Bitte versuchen Sie es später erneut.', 'blogtalk'));
        }
        
        try {
            $character_id = intval($_POST['character_id'] ?? 0);
            $user_message = sanitize_textarea_field($_POST['message'] ?? '');
            $post_id = intval($_POST['post_id'] ?? 0);
            
            if (empty($user_message) || !$character_id || !$post_id) {
                wp_send_json_error(__('Ungültige Anfrage.', 'blogtalk'));
            }
            
            // Character-Daten abrufen
            $character = $this->get_character_by_id($character_id);
            if (!$character) {
                wp_send_json_error(__('Charakter nicht gefunden.', 'blogtalk'));
            }
            
            // AI-Antwort generieren
            if ($this->ai_integration) {
                $response = $this->ai_integration->generate_character_response(
                    $character,
                    $user_message,
                    $post_id
                );
                
                // Conversation speichern
                $this->save_conversation($character_id, $user_message, $response);
                
                wp_send_json_success([
                    'response' => $response,
                    'character_name' => $character['character_name']
                ]);
            } else {
                wp_send_json_error(__('AI-Integration nicht verfügbar.', 'blogtalk'));
            }
            
        } catch (Exception $e) {
            blogtalk_debug_log('Chat-Fehler: ' . $e->getMessage());
            wp_send_json_error(__('Ein Fehler ist aufgetreten.', 'blogtalk'));
        }
    }
    
    /**
     * AJAX: Charaktere für einen Post abrufen
     */
    public function handle_get_characters(): void {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'blogtalk_nonce')) {
            wp_die(__('Sicherheitsprüfung fehlgeschlagen.', 'blogtalk'));
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        
        if (!$post_id) {
            wp_send_json_error(__('Ungültige Post-ID.', 'blogtalk'));
        }
        
        $characters = $this->get_characters_by_post_id($post_id);
        wp_send_json_success($characters);
    }
    
    /**
     * Post-Charaktere bei Speicherung analysieren
     */
    public function analyze_post_characters(int $post_id, WP_Post $post): void {
        // Nur bei veröffentlichten Posts
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Nur bei bestimmten Post-Types
        if (!in_array($post->post_type, ['post', 'page'])) {
            return;
        }
        
        if ($this->content_parser) {
            $this->content_parser->analyze_post($post_id);
        }
    }
    
    /**
     * Chat-Widget zum Content hinzufügen
     */
    public function maybe_add_chat_widget(string $content): string {
        if (!$this->should_load_chat_widget()) {
            return $content;
        }
        
        $post_id = get_the_ID();
        $characters = $this->get_characters_by_post_id($post_id);
        
        if (empty($characters)) {
            return $content;
        }
        
        $widget_html = $this->render_chat_widget($characters);
        return $content . $widget_html;
    }
    
    /**
     * Shortcode für Chat-Widget rendern
     */
    public function render_chat_shortcode(array $atts): string {
        $atts = shortcode_atts([
            'post_id' => get_the_ID(),
            'character' => '',
            'position' => $this->settings['chat_widget_position']
        ], $atts, 'blogtalk_chat');
        
        $post_id = intval($atts['post_id']);
        $characters = $this->get_characters_by_post_id($post_id);
        
        if (empty($characters)) {
            return '<p>' . __('Keine Charaktere gefunden.', 'blogtalk') . '</p>';
        }
        
        // Spezifischen Charakter filtern falls angegeben
        if (!empty($atts['character'])) {
            $characters = array_filter($characters, function($char) use ($atts) {
                return strtolower($char['character_name']) === strtolower($atts['character']);
            });
        }
        
        return $this->render_chat_widget($characters, $atts['position']);
    }
    
    /**
     * REST API-Routen registrieren
     */
    public function register_rest_routes(): void {
        register_rest_route('blogtalk/v1', '/characters/(?P<post_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_characters'],
            'permission_callback' => '__return_true',
            'args' => [
                'post_id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]);
        
        register_rest_route('blogtalk/v1', '/chat', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_chat'],
            'permission_callback' => [$this, 'check_chat_permission']
        ]);
    }
    
    /**
     * Prüfe ob Chat-Widget geladen werden soll
     */
    private function should_load_chat_widget(): bool {
        // Nicht im Admin
        if (is_admin()) {
            return false;
        }
        
        // Nur auf Single Posts/Pages
        if (!is_single() && !is_page()) {
            return false;
        }
        
        // Prüfe ob Post Charaktere hat
        $post_id = get_the_ID();
        $characters = $this->get_characters_by_post_id($post_id);
        
        return !empty($characters);
    }
    
    /**
     * Rate Limiting prüfen
     */
    private function check_rate_limit(): bool {
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $cache_key = 'blogtalk_rate_limit_' . md5($user_ip);
        
        $requests = get_transient($cache_key) ?: 0;
        
        if ($requests >= $this->settings['rate_limit_requests']) {
            return false;
        }
        
        set_transient($cache_key, $requests + 1, HOUR_IN_SECONDS);
        return true;
    }
    
    /**
     * Charakter nach ID abrufen
     */
    private function get_character_by_id(int $character_id): ?array {
        global $wpdb;
        
        $table = $wpdb->prefix . 'blogtalk_characters';
        $character = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $character_id),
            ARRAY_A
        );
        
        return $character ?: null;
    }
    
    /**
     * Charaktere nach Post-ID abrufen
     */
    private function get_characters_by_post_id(int $post_id): array {
        global $wpdb;
        
        $cache_key = "blogtalk_characters_post_{$post_id}";
        $characters = get_transient($cache_key);
        
        if ($characters === false) {
            $table = $wpdb->prefix . 'blogtalk_characters';
            $characters = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE post_id = %d ORDER BY character_type ASC, character_name ASC",
                    $post_id
                ),
                ARRAY_A
            );
            
            set_transient($cache_key, $characters, $this->settings['cache_duration']);
        }
        
        return $characters ?: [];
    }
    
    /**
     * Conversation speichern
     */
    private function save_conversation(int $character_id, string $user_message, string $ai_response): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'blogtalk_conversations';
        $wpdb->insert(
            $table,
            [
                'character_id' => $character_id,
                'user_message' => $user_message,
                'ai_response' => $ai_response,
                'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }
    
    /**
     * Chat-Widget HTML rendern
     */
    private function render_chat_widget(array $characters, string $position = ''): string {
        if (empty($characters)) {
            return '';
        }
        
        $position = $position ?: $this->settings['chat_widget_position'];
        
        ob_start();
        ?>
        <div id="blogtalk-chat-widget" class="blogtalk-widget blogtalk-position-<?php echo esc_attr($position); ?>">
            <div class="blogtalk-header">
                <h4><?php _e('Mit Charakteren sprechen', 'blogtalk'); ?></h4>
                <button class="blogtalk-minimize" aria-label="<?php _e('Chat minimieren', 'blogtalk'); ?>">−</button>
            </div>
            
            <div class="blogtalk-character-selector">
                <select id="blogtalk-character-select">
                    <option value=""><?php _e('Charakter wählen...', 'blogtalk'); ?></option>
                    <?php foreach ($characters as $character): ?>
                        <option value="<?php echo esc_attr($character['id']); ?>" 
                                data-type="<?php echo esc_attr($character['character_type']); ?>">
                            <?php echo esc_html($character['character_name']); ?>
                            <?php if ($character['character_type'] === 'protagonist'): ?>
                                ⭐
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="blogtalk-conversation" id="blogtalk-conversation">
                <div class="blogtalk-welcome-message">
                    <?php _e('Wählen Sie einen Charakter aus, um das Gespräch zu beginnen.', 'blogtalk'); ?>
                </div>
            </div>
            
            <div class="blogtalk-input-area">
                <input type="text" 
                       id="blogtalk-message-input" 
                       placeholder="<?php _e('Ihre Nachricht...', 'blogtalk'); ?>"
                       disabled>
                <button id="blogtalk-send-button" disabled>
                    <?php _e('Senden', 'blogtalk'); ?>
                </button>
            </div>
            
            <div class="blogtalk-typing-indicator" id="blogtalk-typing" style="display: none;">
                <span></span><span></span><span></span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Alte Conversations bereinigen
     */
    public function cleanup_old_conversations(): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'blogtalk_conversations';
        $days_to_keep = 30; // Conversations 30 Tage behalten
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days_to_keep
            )
        );
    }
    
    /**
     * Fehler hinzufügen
     */
    private function add_error(string $code, string $message): void {
        $this->errors[$code] = $message;
    }
    
    /**
     * Alle Fehler abrufen
     */
    public function get_errors(): array {
        return $this->errors;
    }
    
    /**
     * Einstellungen abrufen
     */
    public function get_settings(): array {
        return $this->settings;
    }
    
    /**
     * Einzelne Einstellung abrufen
     */
    public function get_setting(string $key, $default = null) {
        return $this->settings[$key] ?? $default;
    }
    
    /**
     * Einstellungen speichern
     */
    public function update_settings(array $new_settings): bool {
        $this->settings = wp_parse_args($new_settings, $this->settings);
        return update_option('blogtalk_settings', $this->settings);
    }
}
