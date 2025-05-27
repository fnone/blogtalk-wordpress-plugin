<?php
/**
 * BlogTalk Admin Interface
 * 
 * WordPress Admin Dashboard Integration mit Settings-Seite,
 * Plugin-Status, Usage-Analytics und Character-Management
 * 
 * @package BlogTalk
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BlogTalk_Admin {
    
    /**
     * Plugin-Einstellungen
     */
    private array $settings;
    
    /**
     * AI-Integration-Instanz
     */
    private ?BlogTalk_Ai_Integration $ai_integration = null;
    
    /**
     * Content-Parser-Instanz
     */
    private ?BlogTalk_Content_Parser $content_parser = null;
    
    /**
     * Admin-Seiten-Hooks
     */
    private array $admin_pages = [];
    
    public function __construct(array $settings) {
        $this->settings = $settings;
        $this->init_admin_hooks();
        $this->load_dependencies();
    }
    
    /**
     * Lade Abhängigkeiten
     */
    private function load_dependencies(): void {
        if (class_exists('BlogTalk_Ai_Integration')) {
            $this->ai_integration = new BlogTalk_Ai_Integration($this->settings);
        }
        
        if (class_exists('BlogTalk_Content_Parser')) {
            $this->content_parser = new BlogTalk_Content_Parser($this->settings);
        }
    }
    
    /**
     * Initialisiere Admin-Hooks
     */
    private function init_admin_hooks(): void {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'display_admin_notices']);
        add_action('admin_post_blogtalk_test_api', [$this, 'handle_api_test']);
        add_action('admin_post_blogtalk_analyze_post', [$this, 'handle_analyze_post']);
        add_action('admin_post_blogtalk_clear_cache', [$this, 'handle_clear_cache']);
        
        // AJAX-Hooks für Admin
        add_action('wp_ajax_blogtalk_get_analytics', [$this, 'ajax_get_analytics']);
        add_action('wp_ajax_blogtalk_update_character', [$this, 'ajax_update_character']);
        add_action('wp_ajax_blogtalk_delete_character', [$this, 'ajax_delete_character']);
        
        // Post-Editor-Integration
        add_action('add_meta_boxes', [$this, 'add_character_meta_box']);
        add_action('save_post', [$this, 'save_character_meta_box']);
        
        // Plugin-Links
        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
    }
    
    /**
     * Admin-Menü hinzufügen
     */
    public function add_admin_menu(): void {
        // Haupt-Menüpunkt
        $this->admin_pages['main'] = add_menu_page(
            __('BlogTalk', 'blogtalk'),
            __('BlogTalk', 'blogtalk'),
            'manage_options',
            'blogtalk',
            [$this, 'render_main_page'],
            'dashicons-admin-comments',
            30
        );
        
        // Unterseiten
        $this->admin_pages['settings'] = add_submenu_page(
            'blogtalk',
            __('BlogTalk Einstellungen', 'blogtalk'),
            __('Einstellungen', 'blogtalk'),
            'manage_options',
            'blogtalk-settings',
            [$this, 'render_settings_page']
        );
        
        $this->admin_pages['characters'] = add_submenu_page(
            'blogtalk',
            __('Charaktere verwalten', 'blogtalk'),
            __('Charaktere', 'blogtalk'),
            'manage_options',
            'blogtalk-characters',
            [$this, 'render_characters_page']
        );
        
        $this->admin_pages['analytics'] = add_submenu_page(
            'blogtalk',
            __('BlogTalk Analytics', 'blogtalk'),
            __('Analytics', 'blogtalk'),
            'manage_options',
            'blogtalk-analytics',
            [$this, 'render_analytics_page']
        );
        
        $this->admin_pages['help'] = add_submenu_page(
            'blogtalk',
            __('BlogTalk Hilfe', 'blogtalk'),
            __('Hilfe', 'blogtalk'),
            'manage_options',
            'blogtalk-help',
            [$this, 'render_help_page']
        );
    }
    
    /**
     * Registriere Einstellungen
     */
    public function register_settings(): void {
        // Haupt-Einstellungen
        register_setting('blogtalk_settings', 'blogtalk_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);
        
        // API-Key (separat für Sicherheit)
        register_setting('blogtalk_settings', 'blogtalk_api_key', [
            'sanitize_callback' => [$this, 'sanitize_api_key']
        ]);
        
        // Settings-Sektionen
        add_settings_section(
            'blogtalk_api_section',
            __('API-Konfiguration', 'blogtalk'),
            [$this, 'render_api_section_info'],
            'blogtalk_settings'
        );
        
        add_settings_section(
            'blogtalk_character_section',
            __('Character-Erkennung', 'blogtalk'),
            [$this, 'render_character_section_info'],
            'blogtalk_settings'
        );
        
        add_settings_section(
            'blogtalk_widget_section',
            __('Chat-Widget', 'blogtalk'),
            [$this, 'render_widget_section_info'],
            'blogtalk_settings'
        );
        
        // Settings-Felder
        $this->add_settings_fields();
    }
    
    /**
     * Settings-Felder hinzufügen
     */
    private function add_settings_fields(): void {
        // API-Felder
        add_settings_field(
            'api_provider',
            __('AI-Provider', 'blogtalk'),
            [$this, 'render_api_provider_field'],
            'blogtalk_settings',
            'blogtalk_api_section'
        );
        
        add_settings_field(
            'api_key',
            __('API-Key', 'blogtalk'),
            [$this, 'render_api_key_field'],
            'blogtalk_settings',
            'blogtalk_api_section'
        );
        
        add_settings_field(
            'ai_model',
            __('AI-Model', 'blogtalk'),
            [$this, 'render_ai_model_field'],
            'blogtalk_settings',
            'blogtalk_api_section'
        );
        
        // Character-Felder
        add_settings_field(
            'character_detection_sensitivity',
            __('Erkennungs-Sensitivität', 'blogtalk'),
            [$this, 'render_sensitivity_field'],
            'blogtalk_settings',
            'blogtalk_character_section'
        );
        
        add_settings_field(
            'max_characters_per_post',
            __('Max. Charakter
