<?php
/**
 * Plugin Name: BlogTalk
 * Plugin URI: https://example.com/blogtalk
 * Description: Interactive Character Chat für WordPress Storytelling-Blogs. Ermöglicht Lesern, mit Charakteren aus Geschichten zu "sprechen".
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Ihr Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: blogtalk
 * Domain Path: /languages
 * Network: false
 * 
 * @package BlogTalk
 * @version 1.0.0
 * @author Ihr Name
 * @copyright Copyright (c) 2025, BlogTalk
 * @license GPL v2 or later
 */

// Verhindere direkten Zugriff
if (!defined('ABSPATH')) {
    exit;
}

// Plugin-Konstanten definieren
define('BLOGTALK_VERSION', '1.0.0');
define('BLOGTALK_PLUGIN_FILE', __FILE__);
define('BLOGTALK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BLOGTALK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BLOGTALK_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader für Plugin-Klassen
spl_autoload_register(function ($class_name) {
    // Prüfe ob es eine BlogTalk-Klasse ist
    if (strpos($class_name, 'BlogTalk_') !== 0) {
        return;
    }
    
    // Konvertiere Klassename zu Dateiname
    $class_file = str_replace('_', '-', strtolower($class_name));
    $class_file = str_replace('blogtalk-', '', $class_file);
    
    // Mögliche Pfade für Klassendateien
    $possible_paths = [
        BLOGTALK_PLUGIN_DIR . 'includes/class-blogtalk-' . $class_file . '.php',
        BLOGTALK_PLUGIN_DIR . 'admin/class-blogtalk-' . $class_file . '.php',
        BLOGTALK_PLUGIN_DIR . 'public/class-blogtalk-' . $class_file . '.php'
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
});

/**
 * Plugin-Aktivierung
 * Erstellt notwendige Datenbanktabellen und Standardeinstellungen
 */
function blogtalk_activate() {
    // Prüfe WordPress und PHP Version
    if (version_compare(get_bloginfo('version'), '6.0', '<')) {
        wp_die(__('BlogTalk benötigt WordPress 6.0 oder höher.', 'blogtalk'));
    }
    
    if (version_compare(PHP_VERSION, '8.0.0', '<')) {
        wp_die(__('BlogTalk benötigt PHP 8.0 oder höher.', 'blogtalk'));
    }
    
    // Erstelle Datenbanktabellen
    blogtalk_create_database_tables();
    
    // Setze Standardoptionen
    blogtalk_set_default_options();
    
    // Flush Rewrite Rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'blogtalk_activate');

/**
 * Plugin-Deaktivierung
 * Bereinigt temporäre Daten
 */
function blogtalk_deactivate() {
    // Lösche Transient-Caches
    delete_transient('blogtalk_ai_cache');
    delete_transient('blogtalk_character_cache');
    
    // Flush Rewrite Rules
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'blogtalk_deactivate');

/**
 * Plugin-Deinstallation
 * Entfernt alle Plugin-Daten
 */
function blogtalk_uninstall() {
    // Lösche Plugin-Optionen
    delete_option('blogtalk_settings');
    delete_option('blogtalk_api_key');
    delete_option('blogtalk_character_cache');
    
    // Lösche Custom Tables (optional, je nach Benutzereinstellung)
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}blogtalk_characters");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}blogtalk_conversations");
}
register_uninstall_hook(__FILE__, 'blogtalk_uninstall');

/**
 * Erstellt notwendige Datenbanktabellen
 */
function blogtalk_create_database_tables(): void {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Tabelle für Character-Cache
    $characters_table = $wpdb->prefix . 'blogtalk_characters';
    $characters_sql = "CREATE TABLE $characters_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        character_name varchar(255) NOT NULL,
        character_type enum('protagonist','supporting','minor') DEFAULT 'supporting',
        description text,
        personality_traits text,
        context_data longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY post_id (post_id),
        KEY character_name (character_name)
    ) $charset_collate;";
    
    // Tabelle für Conversation-History
    $conversations_table = $wpdb->prefix . 'blogtalk_conversations';
    $conversations_sql = "CREATE TABLE $conversations_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        character_id mediumint(9) NOT NULL,
        user_message text NOT NULL,
        ai_response text NOT NULL,
        user_ip varchar(45),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY character_id (character_id),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($characters_sql);
    dbDelta($conversations_sql);
}

/**
 * Setzt Standardoptionen für das Plugin
 */
function blogtalk_set_default_options(): void {
    $default_settings = [
        'api_provider' => 'perplexity',
        'max_characters_per_post' => 5,
        'chat_widget_position' => 'bottom-right',
        'enable_typing_indicator' => true,
        'enable_character_avatars' => true,
        'cache_duration' => 3600, // 1 Stunde
        'rate_limit_requests' => 50, // Pro Stunde
        'enable_conversation_history' => true,
        'character_detection_sensitivity' => 'medium'
    ];
    
    add_option('blogtalk_settings', $default_settings);
}

/**
 * Plugin-Initialisierung
 * Lädt das Plugin nur wenn WordPress vollständig geladen ist
 */
function blogtalk_init(): void {
    // Lade Textdomain für Übersetzungen
    load_plugin_textdomain('blogtalk', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Initialisiere Haupt-Plugin-Klasse
    BlogTalk_Core::get_instance();
}
add_action('plugins_loaded', 'blogtalk_init');

/**
 * Füge Plugin-Actions Links hinzu
 */
function blogtalk_plugin_action_links(array $links): array {
    $settings_link = '<a href="' . admin_url('admin.php?page=blogtalk-settings') . '">' . __('Einstellungen', 'blogtalk') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . BLOGTALK_PLUGIN_BASENAME, 'blogtalk_plugin_action_links');

/**
 * Debug-Funktion für Entwicklung
 */
function blogtalk_debug_log(string $message, array $context = []): void {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf('[BlogTalk] %s %s', $message, !empty($context) ? json_encode($context) : ''));
    }
}
