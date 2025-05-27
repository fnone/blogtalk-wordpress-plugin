<?php
/**
 * BlogTalk Frontend
 *
 * Integration des Character-Chat-Widgets ins Frontend.
 * Beinhaltet Shortcode, AJAX-Handler, Asset-Loading und Widget-HTML.
 *
 * @package BlogTalk
 * @subpackage Public
 * @since 1.0.0
 */

namespace BlogTalk\Public;

if (!defined('ABSPATH')) {
    exit;
}

class BlogTalk_Frontend
{
    /**
     * Plugin-Einstellungen
     *
     * @var array
     */
    private array $settings;

    /**
     * Konstruktor: Initialisiert Hooks.
     *
     * @param array $settings
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;

        // Frontend-Assets laden
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Shortcode registrieren
        add_action('init', [$this, 'register_shortcode']);

        // AJAX-Handler für eingeloggte User
        add_action('wp_ajax_blogtalk_chat_message', [$this, 'handle_chat_message']);
        // AJAX-Handler für Gäste
        add_action('wp_ajax_nopriv_blogtalk_chat_message', [$this, 'handle_chat_message']);
    }

    /**
     * Registriert den [blogtalk_chat] Shortcode.
     */
    public function register_shortcode(): void
    {
        add_shortcode('blogtalk_chat', [$this, 'render_chat_shortcode']);
    }

    /**
     * Lädt CSS und JS für das Chat-Widget.
     */
    public function enqueue_assets(): void
    {
        // Nur auf Einzelansicht von Beiträgen/Seiten laden
        if (!(is_single() || is_page())) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'blogtalk-frontend',
            BLOGTALK_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            BLOGTALK_VERSION
        );

        // JS
        wp_enqueue_script(
            'blogtalk-frontend',
            BLOGTALK_PLUGIN_URL . 'assets/js/frontend.js',
            ['jquery'],
            BLOGTALK_VERSION,
            true
        );

        // AJAX- und Widget-Variablen ins JS geben
        $post_id = get_the_ID();
        $ajax_data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('blogtalk_frontend_nonce'),
            'post_id'  => $post_id,
            'strings'  => [
                'choose_character' => __('Charakter wählen...', 'blogtalk'),
                'send'            => __('Senden', 'blogtalk'),
                'typing'          => __('schreibt...', 'blogtalk'),
                'no_characters'   => __('Keine Charaktere in dieser Geschichte gefunden.', 'blogtalk'),
                'widget_title'    => __('Mit Charakteren sprechen', 'blogtalk'),
            ],
        ];
        wp_localize_script('blogtalk-frontend', 'BlogTalkData', $ajax_data);
    }

    /**
     * Rendert den Chat-Widget Shortcode.
     *
     * @param array $atts
     * @return string
     */
    public function render_chat_shortcode(array $atts): string
    {
        // Nur auf Einzelansicht und im Loop anzeigen
        if (!(is_single() || is_page()) || !in_the_loop()) {
            return '';
        }

        $post_id = get_the_ID();

        // Prüfen, ob der aktuelle Beitrag eine Story ist (Character-Cache vorhanden)
        $characters = $this->get_characters_for_post($post_id);
        if (empty($characters)) {
            return ''; // Kein Widget anzeigen, wenn keine Charaktere gefunden wurden
        }

        // Widget-HTML ausgeben
        ob_start();
        ?>
        <div id="blogtalk-chat-widget" class="blogtalk-widget blogtalk-position-<?php echo esc_attr($this->settings['chat_widget_position'] ?? 'bottom-right'); ?>">
            <div class="blogtalk-header">
                <span class="blogtalk-widget-title">
                    <?php echo esc_html(__('Mit Charakteren sprechen', 'blogtalk')); ?>
                </span>
                <button class="blogtalk-minimize" aria-label="<?php esc_attr_e('Chat minimieren', 'blogtalk'); ?>">−</button>
            </div>
            <div class="blogtalk-character-selector">
                <select id="blogtalk-character-select">
                    <option value=""><?php echo esc_html(__('Charakter wählen...', 'blogtalk')); ?></option>
                    <?php foreach ($characters as $character): ?>
                        <option value="<?php echo esc_attr($character['id']); ?>">
                            <?php
                            echo esc_html($character['character_name']);
                            if ($character['character_type'] === 'protagonist') {
                                echo ' ⭐';
                            }
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="blogtalk-conversation" id="blogtalk-conversation">
                <div class="blogtalk-welcome-message">
                    <?php echo esc_html(__('Wählen Sie einen Charakter aus, um das Gespräch zu beginnen.', 'blogtalk')); ?>
                </div>
            </div>
            <div class="blogtalk-input-area">
                <input type="text"
                       id="blogtalk-message-input"
                       placeholder="<?php echo esc_attr(__('Ihre Nachricht...', 'blogtalk')); ?>"
                       disabled>
                <button id="blogtalk-send-button" disabled>
                    <?php echo esc_html(__('Senden', 'blogtalk')); ?>
                </button>
            </div>
            <div class="blogtalk-typing-indicator" id="blogtalk-typing" style="display: none;">
                <span></span><span></span><span></span>
                <span class="blogtalk-typing-label"><?php echo esc_html(__('schreibt...', 'blogtalk')); ?></span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX-Handler: Verarbeitet Chat-Nachrichten und liefert AI-Antwort.
     */
    public function handle_chat_message(): void
    {
        // Nonce-Check für Sicherheit
        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'blogtalk_frontend_nonce')) {
            wp_send_json_error(['message' => __('Ungültige Sicherheitsanfrage.', 'blogtalk')], 403);
        }

        // Eingaben validieren und sanitizen
        $post_id      = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $character_id = isset($_POST['character_id']) ? intval($_POST['character_id']) : 0;
        $message      = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';

        if (!$post_id || !$character_id || empty($message)) {
            wp_send_json_error(['message' => __('Ungültige Anfrage.', 'blogtalk')], 400);
        }

        // Charakterdaten abrufen
        $character = $this->get_character_by_id($character_id, $post_id);
        if (!$character) {
            wp_send_json_error(['message' => __('Charakter nicht gefunden.', 'blogtalk')], 404);
        }

        // AI-Integration aufrufen (über Core)
        if (!class_exists('\BlogTalk_Core')) {
            wp_send_json_error(['message' => __('AI-Integration nicht verfügbar.', 'blogtalk')], 500);
        }

        $core = \BlogTalk_Core::get_instance();
        $ai   = method_exists($core, 'ai_integration') ? $core->ai_integration : null;
        if (!$ai && property_exists($core, 'ai_integration')) {
            $ai = $core->ai_integration;
        }
        if (!$ai) {
            // Fallback: Direkt neue Instanz (sollte nicht nötig sein)
            if (class_exists('\BlogTalk_Ai_Integration')) {
                $ai = new \BlogTalk_Ai_Integration($this->settings);
            }
        }
        if (!$ai || !method_exists($ai, 'generate_character_response')) {
            wp_send_json_error(['message' => __('AI-Integration nicht verfügbar.', 'blogtalk')], 500);
        }

        try {
            $response = $ai->generate_character_response($character, $message, $post_id);

            // Erfolgsantwort
            wp_send_json_success([
                'response'       => esc_html($response),
                'character_name' => esc_html($character['character_name']),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => __('Fehler bei der AI-Antwort: ', 'blogtalk') . $e->getMessage()], 500);
        }
    }

    /**
     * Holt alle Charaktere für einen Post aus der DB.
     *
     * @param int $post_id
     * @return array
     */
    private function get_characters_for_post(int $post_id): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'blogtalk_characters';
        $characters = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE post_id = %d ORDER BY character_type ASC, character_name ASC",
                $post_id
            ),
            ARRAY_A
        );
        return $characters ?: [];
    }

    /**
     * Holt einen Charakter anhand von ID und Post-ID.
     *
     * @param int $character_id
     * @param int $post_id
     * @return array|null
     */
    private function get_character_by_id(int $character_id, int $post_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'blogtalk_characters';
        $character = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d AND post_id = %d",
                $character_id,
                $post_id
            ),
            ARRAY_A
        );
        return $character ?: null;
    }
}
