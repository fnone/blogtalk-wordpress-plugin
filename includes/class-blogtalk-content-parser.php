<?php
/**
 * BlogTalk Content Parser
 * 
 * Intelligente Analyse von Blog-Posts zur Erkennung von Charakteren,
 * Story-Elementen und narrativen Strukturen
 * 
 * @package BlogTalk
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BlogTalk_Content_Parser {
    
    /**
     * Plugin-Einstellungen
     */
    private array $settings;
    
    /**
     * Regex-Patterns für Character-Erkennung
     */
    private array $dialog_patterns = [
        '/["""„]([^"""„]+)["""„]\s*,?\s*(sagte|meinte|flüsterte|rief|fragte|antwortete|erwiderte)\s+([A-Z][a-zA-ZäöüßÄÖÜ]+)/',
        '/([A-Z][a-zA-ZäöüßÄÖÜ]+)\s+(sagte|meinte|flüsterte|rief|fragte|antwortete|erwiderte):\s*["""„]([^"""„]+)["""„]/',
        '/([A-Z][a-zA-ZäöüßÄÖÜ]+)\s*:\s*["""„]([^"""„]+)["""„]/',
    ];
    
    /**
     * Patterns für Personennamen
     */
    private array $name_patterns = [
        '/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s+(ging|lief|schaute|sah|dachte|fühlte|war|hatte)/',
        '/\b(Herr|Frau|Dr\.|Prof\.)\s+([A-Z][a-zA-ZäöüßÄÖÜ]+)/',
        '/\b([A-Z][a-zA-ZäöüßÄÖÜ]{2,})\s+(war|ist|wurde|hatte|bekam|machte)/',
    ];
    
    /**
     * Stopwords für bessere Erkennung
     */
    private array $stopwords = [
        'Der', 'Die', 'Das', 'Ein', 'Eine', 'Einen', 'Einer', 'Eines',
        'Und', 'Oder', 'Aber', 'Wenn', 'Dann', 'Also', 'Jedoch',
        'Sie', 'Er', 'Es', 'Wir', 'Ihr', 'Ich', 'Du',
        'WordPress', 'Plugin', 'Blog', 'Post', 'Seite', 'Website'
    ];
    
    /**
     * Character-Typen basierend auf Häufigkeit
     */
    private array $character_types = [
        'protagonist' => 10,    // Mindestens 10 Erwähnungen
        'supporting' => 3,      // 3-9 Erwähnungen
        'minor' => 1           // 1-2 Erwähnungen
    ];
    
    public function __construct(array $settings) {
        $this->settings = $settings;
    }
    
    /**
     * Analysiert einen Post und extrahiert Charaktere
     */
    public function analyze_post(int $post_id): array {
        $post = get_post($post_id);
        
        if (!$post) {
            return [];
        }
        
        // Prüfe ob es sich um eine Geschichte handelt
        if (!$this->is_narrative_content($post)) {
            blogtalk_debug_log("Post {$post_id} scheint keine Geschichte zu sein.");
            return [];
        }
        
        $content = $this->prepare_content($post);
        $characters = $this->extract_characters($content);
        $characters = $this->classify_characters($characters, $content);
        $characters = $this->enrich_character_data($characters, $content, $post);
        
        // Speichere Charaktere in der Datenbank
        $this->save_characters($post_id, $characters);
        
        blogtalk_debug_log("Post {$post_id} analysiert. " . count($characters) . " Charaktere gefunden.");
        
        return $characters;
    }
    
    /**
     * Prüft ob Content narrativ ist
     */
    private function is_narrative_content(WP_Post $post): bool {
        $content = $post->post_content . ' ' . $post->post_title;
        $content = strip_tags($content);
        $content = strtolower($content);
        
        // Narrative Indikatoren
        $narrative_indicators = [
            // Dialog-Indikatoren
            'sagte', 'meinte', 'flüsterte', 'rief', 'fragte', 'antwortete',
            // Story-Indikatoren
            'es war einmal', 'eines tages', 'plötzlich', 'dann geschah',
            // Zeitliche Marker
            'am nächsten tag', 'später', 'währenddessen', 'schließlich',
            // Perspektive-Marker
            'ich dachte', 'er sah', 'sie fühlte', 'wir gingen'
        ];
        
        $narrative_score = 0;
        foreach ($narrative_indicators as $indicator) {
            $narrative_score += substr_count($content, $indicator);
        }
        
        // Faktuelle Content-Indikatoren (reduzieren Score)
        $factual_indicators = [
            'wordpress', 'plugin', 'tutorial', 'anleitung', 'howto',
            'beispiel:', 'schritt', 'lösung', 'problem', 'fehler',
            'version', 'update', 'code', 'function', 'class'
        ];
        
        foreach ($factual_indicators as $indicator) {
            $narrative_score -= substr_count($content, $indicator) * 2;
        }
        
        // Sensitivity-Einstellung berücksichtigen
        $threshold = match($this->settings['character_detection_sensitivity']) {
            'high' => 1,
            'medium' => 3,
            'low' => 5,
            default => 3
        };
        
        return $narrative_score >= $threshold;
    }
    
    /**
     * Bereitet Content für Analyse vor
     */
    private function prepare_content(WP_Post $post): string {
        // Kombiniere Title und Content
        $content = $post->post_title . "\n\n" . $post->post_content;
        
        // Entferne WordPress-spezifische Shortcodes
        $content = preg_replace('/\[.*?\]/', '', $content);
        
        // Entferne HTML-Tags aber behalte Struktur
        $content = wp_strip_all_tags($content, true);
        
        // Bereinige überschüssige Whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        
        return $content;
    }
    
    /**
     * Extrahiert Charaktere aus dem Content
     */
    private function extract_characters(string $content): array {
        $characters = [];
        
        // 1. Dialog-basierte Extraktion
        $dialog_characters = $this->extract_from_dialogs($content);
        
        // 2. Action-basierte Extraktion
        $action_characters = $this->extract_from_actions($content);
        
        // 3. Direkte Namensnennung
        $mentioned_characters = $this->extract_mentioned_names($content);
        
        // Kombiniere alle gefundenen Charaktere
        $all_characters = array_merge($dialog_characters, $action_characters, $mentioned_characters);
        
        // Zähle Häufigkeiten und bereinige
        foreach ($all_characters as $character) {
            $clean_name = $this->clean_character_name($character['name']);
            
            if ($this->is_valid_character_name($clean_name)) {
                if (!isset($characters[$clean_name])) {
                    $characters[$clean_name] = [
                        'name' => $clean_name,
                        'mentions' => 0,
                        'contexts' => [],
                        'dialog_count' => 0,
                        'action_count' => 0
                    ];
                }
                
                $characters[$clean_name]['mentions']++;
                $characters[$clean_name]['contexts'][] = $character['context'] ?? '';
                
                if ($character['type'] === 'dialog') {
                    $characters[$clean_name]['dialog_count']++;
                } elseif ($character['type'] === 'action') {
                    $characters[$clean_name]['action_count']++;
                }
            }
        }
        
        return $characters;
    }
    
    /**
     * Extrahiert Charaktere aus Dialogen
     */
    private function extract_from_dialogs(string $content): array {
        $characters = [];
        
        foreach ($this->dialog_patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $speaker = '';
                    $dialog_text = '';
                    $context = $match[0];
                    
                    // Bestimme Sprecher je nach Pattern
                    if (isset($match[3])) {
                        $speaker = $match[3];
                        $dialog_text = $match[1];
                    } elseif (isset($match[1])) {
                        $speaker = $match[1];
                        $dialog_text = $match[3] ?? '';
                    }
                    
                    if (!empty($speaker)) {
                        $characters[] = [
                            'name' => $speaker,
                            'type' => 'dialog',
                            'context' => $context,
                            'dialog_text' => $dialog_text
                        ];
                    }
                }
            }
        }
        
        return $characters;
    }
    
    /**
     * Extrahiert Charaktere aus Handlungen
     */
    private function extract_from_actions(string $content): array {
        $characters = [];
        
        foreach ($this->name_patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $name = $match[1];
                    $action = $match[2] ?? '';
                    $context = $match[0];
                    
                    $characters[] = [
                        'name' => $name,
                        'type' => 'action',
                        'context' => $context,
                        'action' => $action
                    ];
                }
            }
        }
        
        return $characters;
    }
    
    /**
     * Extrahiert direkt erwähnte Namen
     */
    private function extract_mentioned_names(string $content): array {
        $characters = [];
        
        // Einfache Großbuchstaben-Namen
        if (preg_match_all('/\b([A-Z][a-zA-ZäöüßÄÖÜ]{2,})\b/', $content, $matches)) {
            foreach ($matches[1] as $name) {
                if (!in_array($name, $this->stopwords)) {
                    $characters[] = [
                        'name' => $name,
                        'type' => 'mention',
                        'context' => ''
                    ];
                }
            }
        }
        
        return $characters;
    }
    
    /**
     * Klassifiziert Charaktere nach Wichtigkeit
     */
    private function classify_characters(array $characters, string $content): array {
        foreach ($characters as $name => &$character) {
            $mentions = $character['mentions'];
            $dialog_count = $character['dialog_count'];
            $action_count = $character['action_count'];
            
            // Berechne Wichtigkeits-Score
            $importance_score = $mentions + ($dialog_count * 2) + ($action_count * 1.5);
            
            // Bestimme Character-Typ
            if ($importance_score >= $this->character_types['protagonist']) {
                $character['type'] = 'protagonist';
            } elseif ($importance_score >= $this->character_types['supporting']) {
                $character['type'] = 'supporting';
            } else {
                $character['type'] = 'minor';
            }
            
            $character['importance_score'] = $importance_score;
            
            // Prüfe auf Titel-/Namen-Erwähnung (erhöht Wichtigkeit)
            if (stripos($content, $name) !== false) {
                if (stripos($content, $name) < 200) { // In den ersten 200 Zeichen
                    $character['importance_score'] += 5;
                    if ($character['type'] === 'minor') {
                        $character['type'] = 'supporting';
                    }
                }
            }
        }
        
        return $characters;
    }
    
    /**
     * Reichert Character-Daten an
     */
    private function enrich_character_data(array $characters, string $content, WP_Post $post): array {
        foreach ($characters as $name => &$character) {
            // Extrahiere Persönlichkeitsmerkmale aus Kontext
            $character['personality_traits'] = $this->extract_personality_traits($character, $content);
            
            // Erstelle Beschreibung
            $character['description'] = $this->generate_character_description($character, $content);
            
            // Sammle Story-Kontext
            $character['story_context'] = $this->extract_story_context($content, $post);
            
            // Bereite AI-Kontext vor
            $character['ai_context'] = $this->prepare_ai_context($character, $content, $post);
        }
        
        return $characters;
    }
    
    /**
     * Extrahiert Persönlichkeitsmerkmale
     */
    private function extract_personality_traits(array $character, string $content): array {
        $traits = [];
        $name = $character['name'];
        
        // Suche nach beschreibenden Adjektiven in der Nähe des Namens
        $trait_patterns = [
            "/\b{$name}\s+(?:war|ist|wirkte|schien)\s+([\w\säöüßÄÖÜ]+)/i",
            "/\bder\s+([\w\säöüßÄÖÜ]+)\s+{$name}/i",
            "/\b{$name},?\s+(?:ein|eine|der|die)\s+([\w\säöüßÄÖÜ]+)/i"
        ];
        
        foreach ($trait_patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $potential_trait) {
                    $trait = trim(strtolower($potential_trait));
                    if (strlen($trait) > 3 && strlen($trait) < 20) {
                        $traits[] = $trait;
                    }
                }
            }
        }
        
        // Entferne Duplikate und limitiere
        $traits = array_unique($traits);
        return array_slice($traits, 0, 5);
    }
    
    /**
     * Generiert Character-Beschreibung
     */
    private function generate_character_description(array $character, string $content): string {
        $name = $character['name'];
        $type = $character['type'];
        $mentions = $character['mentions'];
        $traits = $character['personality_traits'] ?? [];
        
        $description = sprintf(
            '%s ist ein %s in dieser Geschichte',
            $name,
            match($type) {
                'protagonist' => 'Hauptcharakter',
                'supporting' => 'wichtiger Nebencharakter',
                'minor' => 'Nebencharakter',
                default => 'Charakter'
            }
        );
        
        if (!empty($traits)) {
            $description .= ' und wird als ' . implode(', ', array_slice($traits, 0, 3)) . ' beschrieben';
        }
        
        $description .= sprintf('. %s wird %d Mal in der Geschichte erwähnt.', $name, $mentions);
        
        return $description;
    }
    
    /**
     * Extrahiert Story-Kontext
     */
    private function extract_story_context(string $content, WP_Post $post): array {
        $context = [
            'title' => $post->post_title,
            'excerpt' => wp_trim_words($content, 50),
            'setting' => $this->extract_setting($content),
            'genre' => $this->detect_genre($content),
            'time_period' => $this->extract_time_period($content)
        ];
        
        return $context;
    }
    
    /**
     * Extrahiert Setting/Schauplatz
     */
    private function extract_setting(string $content): array {
        $settings = [];
        
        // Ortsangaben
        $location_patterns = [
            '/\bin\s+(der|dem|einer|einem)\s+([A-Z][a-zA-ZäöüßÄÖÜ\s]+)/',
            '/\bam\s+([A-Z][a-zA-ZäöüßÄÖÜ\s]+)/',
            '/\bbei\s+([A-Z][a-zA-ZäöüßÄÖÜ\s]+)/'
        ];
        
        foreach ($location_patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches as $match_group) {
                    foreach ($match_group as $location) {
                        if (strlen($location) > 3 && strlen($location) < 50) {
                            $settings[] = trim($location);
                        }
                    }
                }
            }
        }
        
        return array_unique($settings);
    }
    
    /**
     * Erkennt Genre
     */
    private function detect_genre(string $content): string {
        $content_lower = strtolower($content);
        
        $genre_indicators = [
            'fantasy' => ['magie', 'zauber', 'drache', 'elf', 'zwerg', 'hexe'],
            'krimi' => ['mord', 'detective', 'verdächtig', 'verbrechen', 'polizei'],
            'romance' => ['liebe', 'herz', 'kuss', 'romantisch', 'verliebt'],
            'science-fiction' => ['raumschiff', 'alien', 'zukunft', 'roboter', 'technologie'],
            'horror' => ['angst', 'schrecken', 'blut', 'tot', 'geist'],
            'abenteuer' => ['reise', 'expedition', 'gefahr', 'entdeckung', 'schatz']
        ];
        
        $genre_scores = [];
        foreach ($genre_indicators as $genre => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                $score += substr_count($content_lower, $keyword);
            }
            $genre_scores[$genre] = $score;
        }
        
        arsort($genre_scores);
        $detected_genre = array_key_first($genre_scores);
        
        return $genre_scores[$detected_genre] > 0 ? $detected_genre : 'allgemein';
    }
    
    /**
     * Extrahiert Zeitperiode
     */
    private function extract_time_period(string $content): string {
        $time_indicators = [
            'mittelalter' => ['ritter', 'burg', 'schwert', 'könig', 'prinzessin'],
            'modern' => ['handy', 'computer', 'auto', 'internet', 'smartphone'],
            'zukunft' => ['jahr 2', 'zukunft', 'jahr 3', 'raumschiff', 'kolonie'],
            'vergangenheit' => ['damals', 'früher', 'einst', 'vor jahren', 'alt']
        ];
        
        $content_lower = strtolower($content);
        $period_scores = [];
        
        foreach ($time_indicators as $period => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                $score += substr_count($content_lower, $keyword);
            }
            $period_scores[$period] = $score;
        }
        
        arsort($period_scores);
        $detected_period = array_key_first($period_scores);
        
        return $period_scores[$detected_period] > 0 ? $detected_period : 'unbestimmt';
    }
    
    /**
     * Bereitet AI-Kontext vor
     */
    private function prepare_ai_context(array $character, string $content, WP_Post $post): array {
        $ai_context = [
            'character_name' => $character['name'],
            'character_type' => $character['type'],
            'story_title' => $post->post_title,
            'story_excerpt' => wp_trim_words($content, 100),
            'personality_traits' => $character['personality_traits'] ?? [],
            'story_context' => $character['story_context'] ?? [],
            'sample_dialogs' => array_slice($character['contexts'], 0, 3),
            'writing_style' => $this->analyze_writing_style($content)
        ];
        
        return $ai_context;
    }
    
    /**
     * Analysiert Schreibstil
     */
    private function analyze_writing_style(string $content): array {
        $style = [
            'tone' => 'neutral',
            'complexity' => 'medium',
            'perspective' => 'third-person'
        ];
        
        // Tonalität
        $formal_indicators = ['jedoch', 'sowie', 'diesbezüglich', 'folglich'];
        $casual_indicators = ['echt', 'mega', 'krass', 'ey'];
        
        $formal_count = 0;
        $casual_count = 0;
        
        foreach ($formal_indicators as $indicator) {
            $formal_count += substr_count(strtolower($content), $indicator);
        }
        
        foreach ($casual_indicators as $indicator) {
            $casual_count += substr_count(strtolower($content), $indicator);
        }
        
        if ($formal_count > $casual_count) {
            $style['tone'] = 'formal';
        } elseif ($casual_count > $formal_count) {
            $style['tone'] = 'casual';
        }
        
        // Komplexität (basierend auf Satzlänge)
        $sentences = preg_split('/[.!?]+/', $content);
        $avg_length = array_sum(array_map('strlen', $sentences)) / count($sentences);
        
        if ($avg_length > 100) {
            $style['complexity'] = 'high';
        } elseif ($avg_length < 50) {
            $style['complexity'] = 'low';
        }
        
        // Perspektive
        if (substr_count(strtolower($content), ' ich ') > 3) {
            $style['perspective'] = 'first-person';
        } elseif (substr_count(strtolower($content), ' du ') > 3) {
            $style['perspective'] = 'second-person';
        }
        
        return $style;
    }
    
    /**
     * Bereinigt Character-Namen
     */
    private function clean_character_name(string $name): string {
        // Entferne Titel
        $name = preg_replace('/^(Herr|Frau|Dr\.|Prof\.)\s+/', '', $name);
        
        // Entferne Satzzeichen
        $name = preg_replace('/[.,;:!?]/', '', $name);
        
        // Normalisiere Whitespace
        $name = preg_replace('/\s+/', ' ', trim($name));
        
        return $name;
    }
    
    /**
     * Validiert Character-Namen
     */
    private function is_valid_character_name(string $name): bool {
        // Mindest- und Maximallänge
        if (strlen($name) < 2 || strlen($name) > 50) {
            return false;
        }
        
        // Muss mit Großbuchstaben beginnen
        if (!preg_match('/^[A-ZÄÖÜ]/', $name)) {
            return false;
        }
        
        // Keine Zahlen
        if (preg_match('/\d/', $name)) {
            return false;
        }
        
        // Nicht in Stopwords
        if (in_array($name, $this->stopwords)) {
            return false;
        }
        
        // Keine WordPress-spezifischen Begriffe
        $wp_terms = ['WordPress', 'Plugin', 'Widget', 'Admin', 'User', 'Post', 'Page'];
        if (in_array($name, $wp_terms)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Speichert Charaktere in der Datenbank
     */
    private function save_characters(int $post_id, array $characters): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'blogtalk_characters';
        
        // Lösche alte Charaktere für diesen Post
        $wpdb->delete($table, ['post_id' => $post_id], ['%d']);
        
        // Speichere neue Charaktere
        foreach ($characters as $character) {
            $wpdb->insert(
                $table,
                [
                    'post_id' => $post_id,
                    'character_name' => $character['name'],
                    'character_type' => $character['type'],
                    'description' => $character['description'],
                    'personality_traits' => json_encode($character['personality_traits'] ?? []),
                    'context_data' => json_encode($character['ai_context'])
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s']
            );
        }
        
        // Cache invalidieren
        delete_transient("blogtalk_characters_post_{$post_id}");
        
        blogtalk_debug_log("Charaktere für Post {$post_id} gespeichert: " . count($characters));
    }
    
    /**
     * Manuelle Character-Analyse für bestimmten Content
     */
    public function analyze_content_string(string $content): array {
        if (empty($content)) {
            return [];
        }
        
        $characters = $this->extract_characters($content);
        $characters = $this->classify_characters($characters, $content);
        
        // Vereinfachte Anreicherung ohne Post-Kontext
        foreach ($characters as $name => &$character) {
            $character['personality_traits'] = $this->extract_personality_traits($character, $content);
            $character['description'] = $this->generate_character_description($character, $content);
        }
        
        return $characters;
    }
    
    /**
     * Prüft ob ein Post bereits analysiert wurde
     */
    public function is_post_analyzed(int $post_id): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'blogtalk_characters';
        $count = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE post_id = %d", $post_id)
        );
        
        return $count > 0;
    }
    
    /**
     * Re-analysiert einen Post (forciert)
     */
    public function reanalyze_post(int $post_id): array {
        // Cache leeren
        delete_transient("blogtalk_characters_post_{$post_id}");
        
        // Neu analysieren
        return $this->analyze_post($post_id);
    }
}
