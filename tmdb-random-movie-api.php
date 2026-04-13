<?php
/**
 * Plugin Name: TMDB Random Movie API
 * Plugin URI: https://devpiksel.local
 * Description: Endpoint API REST personalizzato che recupera un film casuale da The Movie Database (TMDB). Accessibile solo a utenti autenticati. Disabilita gli endpoint REST nativi di WordPress per motivi di sicurezza.
 * Version: 1.0.2
 * Author: Davide Briscese
 * Author URI: https://devpiksel.local
 * License: GPL v2 or later
 * Text Domain: tmdb-random-movie-api
 */

// Impedisci accesso diretto per sicurezza
if (!defined('ABSPATH')) {
    exit;
}

// Definizione costanti del plugin per percorsi e versioni
define('TMDB_RA_VERSION', '1.0.2');
define('TMDB_RA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TMDB_RA_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Classe principale del plugin TMDB Random Movie API
 * 
 * Gestisce la registrazione dell'endpoint REST, la configurazione admin,
 * la comunicazione con TMDB e la disabilitazione selettiva degli endpoint nativi.
 * 
 * @package TMDB_Random_Movie_API
 * @since 1.0.0
 */
class TMDB_Random_Movie_API {

    /**
     * Istanza singleton della classe
     *
     * @var TMDB_Random_Movie_API
     */
    private static $instance = null;
    
    /**
     * URL base dell'API TMDB
     */
    private const TMDB_API_BASE = 'https://api.themoviedb.org/3';
    
    /**
     * Tipi di liste supportate per la selezione casuale
     */
    private const SUPPORTED_LISTS = ['trending', 'popular', 'top_rated', 'now_playing', 'upcoming'];

    /**
     * Restituisce l'istanza singleton del plugin
     *
     * @return TMDB_Random_Movie_API
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Costruttore privato. Inizializza tutti gli hook di WordPress.
     */
    private function __construct() {
        // Hook di attivazione/disattivazione
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Inizializzazione area amministrativa
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_tmdb_ra_test_api', [$this, 'ajax_test_api_connection']);
        add_action('admin_notices', [$this, 'check_tmdb_connection_notice']);
        
        // Inizializzazione API REST
        add_action('rest_api_init', [$this, 'register_rest_endpoints']);
        
        // Sicurezza: disabilitazione endpoint nativi
        add_filter('rest_endpoints', [$this, 'disable_default_rest_endpoints']);
        add_filter('rest_authentication_errors', [$this, 'disable_rest_for_unauthenticated'], 10, 1);
        
        // Link rapidi nella pagina dei plugin
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);
        
        // Shortcode per il frontend
        add_shortcode('tmdb_random_movie', [$this, 'render_frontend_shortcode']);
    }

    /**
     * Attivazione plugin: crea opzioni di default e flush rewrite rules
     */
    public function activate() {
        if (get_option('tmdb_ra_settings') === false) {
            $defaults = [
                'tmdb_api_key' => '',
                'enable_api' => '1',
                'movie_list_type' => 'popular',
                'disable_default_rest' => '0',
                'cache_ttl' => '3600'
            ];
            add_option('tmdb_ra_settings', $defaults);
        }
        flush_rewrite_rules();
    }

    /**
     * Disattivazione plugin: flush rewrite rules
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Registra le impostazioni del plugin nel database WordPress
     */
    public function register_settings() {
        register_setting(
            'tmdb_ra_settings_group',
            'tmdb_ra_settings',
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => [
                    'tmdb_api_key' => '',
                    'enable_api' => '1',
                    'movie_list_type' => 'popular',
                    'disable_default_rest' => '0',
                    'cache_ttl' => '3600'
                ]
            ]
        );
    }

    /**
     * Sanitizzazione dei campi delle impostazioni
     *
     * @param array $input Dati in input dal form
     * @return array Dati sanitizzati
     */
    public function sanitize_settings($input) {
        $output = [];
        
        $output['tmdb_api_key'] = sanitize_text_field($input['tmdb_api_key'] ?? '');
        $output['enable_api'] = isset($input['enable_api']) && $input['enable_api'] == '1' ? '1' : '0';
        $output['movie_list_type'] = in_array($input['movie_list_type'] ?? 'popular', self::SUPPORTED_LISTS) 
            ? $input['movie_list_type'] 
            : 'popular';
        $output['disable_default_rest'] = isset($input['disable_default_rest']) && $input['disable_default_rest'] == '1' ? '1' : '0';
        $output['cache_ttl'] = absint($input['cache_ttl'] ?? 3600);
        
        if ($output['cache_ttl'] < 300) {
            $output['cache_ttl'] = 300;
        }
        if ($output['cache_ttl'] > 86400) {
            $output['cache_ttl'] = 86400;
        }
        
        return $output;
    }

    /**
     * Aggiunge la pagina delle impostazioni nel menu di amministrazione
     */
    public function add_admin_menu() {
        add_options_page(
            __('TMDB Random Movie API', 'tmdb-random-movie-api'),
            __('TMDB Random Movie', 'tmdb-random-movie-api'),
            'manage_options',
            'tmdb-random-movie-api',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Mostra un avviso se la API Key non è configurata
     */
    public function check_tmdb_connection_notice() {
        $settings = get_option('tmdb_ra_settings');
        
        if (empty($settings['tmdb_api_key'])) {
            $screen = get_current_screen();
            if ($screen && $screen->id !== 'settings_page_tmdb-random-movie-api') {
                echo '<div class="notice notice-warning is-dismissible">
                        <p>' . sprintf(
                            __('TMDB Random Movie API: <a href="%s">Inserisci la tua API Key di The Movie Database</a> per attivare l\'endpoint.', 'tmdb-random-movie-api'),
                            admin_url('options-general.php?page=tmdb-random-movie-api')
                        ) . '</p>
                      </div>';
            }
        }
    }

    /**
     * Render della pagina delle impostazioni
     */
    public function render_settings_page() {
        $settings = get_option('tmdb_ra_settings');
        $rest_url = rest_url('tmdb-random-movie/v1/random-movie');
        ?>
        <div class="wrap">
            <h1><?php _e('TMDB Random Movie API - Impostazioni', 'tmdb-random-movie-api'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('tmdb_ra_settings_group'); ?>
                <?php do_settings_sections('tmdb-random-movie-api'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="tmdb_api_key"><?php _e('TMDB API Key', 'tmdb-random-movie-api'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="tmdb_api_key" 
                                   name="tmdb_ra_settings[tmdb_api_key]" 
                                   value="<?php echo esc_attr($settings['tmdb_api_key'] ?? ''); ?>" 
                                   class="regular-text" />
                            <p class="description">
                                <?php _e('Inserisci la tua API Key di The Movie Database. Puoi ottenerla da ', 'tmdb-random-movie-api'); ?>
                                <a href="https://www.themoviedb.org/settings/api" target="_blank">https://www.themoviedb.org/settings/api</a>
                            </p>
                            <button type="button" id="tmdb_ra_test_connection" class="button button-secondary">
                                <?php _e('Test Connessione', 'tmdb-random-movie-api'); ?>
                            </button>
                            <div id="tmdb_ra_test_result" style="margin-top: 10px;"></div>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="enable_api"><?php _e('Abilita Endpoint', 'tmdb-random-movie-api'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" 
                                   id="enable_api" 
                                   name="tmdb_ra_settings[enable_api]" 
                                   value="1" 
                                   <?php checked($settings['enable_api'] ?? '1', '1'); ?> />
                            <span class="description"><?php _e('Abilita l\'endpoint API /tmdb-random-movie/v1/random-movie', 'tmdb-random-movie-api'); ?></span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="movie_list_type"><?php _e('Lista Film Predefinita', 'tmdb-random-movie-api'); ?></label>
                        </th>
                        <td>
                            <select id="movie_list_type" name="tmdb_ra_settings[movie_list_type]">
                                <option value="trending" <?php selected($settings['movie_list_type'] ?? 'popular', 'trending'); ?>><?php _e('Trending', 'tmdb-random-movie-api'); ?></option>
                                <option value="popular" <?php selected($settings['movie_list_type'] ?? 'popular', 'popular'); ?>><?php _e('Popolari', 'tmdb-random-movie-api'); ?></option>
                                <option value="top_rated" <?php selected($settings['movie_list_type'] ?? 'popular', 'top_rated'); ?>><?php _e('Più votati', 'tmdb-random-movie-api'); ?></option>
                                <option value="now_playing" <?php selected($settings['movie_list_type'] ?? 'popular', 'now_playing'); ?>><?php _e('Al Cinema', 'tmdb-random-movie-api'); ?></option>
                                <option value="upcoming" <?php selected($settings['movie_list_type'] ?? 'popular', 'upcoming'); ?>><?php _e('Prossime Uscite', 'tmdb-random-movie-api'); ?></option>
                            </select>
                            <p class="description"><?php _e('Lista di film da cui selezionare casualmente un film', 'tmdb-random-movie-api'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="disable_default_rest"><?php _e('Disabilita Endpoint REST nativi', 'tmdb-random-movie-api'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" 
                                   id="disable_default_rest" 
                                   name="tmdb_ra_settings[disable_default_rest]" 
                                   value="1" 
                                   <?php checked($settings['disable_default_rest'] ?? '0', '1'); ?> />
                            <span class="description"><?php _e('Disabilita gli endpoint REST API predefiniti di WordPress per utenti non autenticati (raccomandato per sicurezza)', 'tmdb-random-movie-api'); ?></span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="cache_ttl"><?php _e('Cache TTL (secondi)', 'tmdb-random-movie-api'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="cache_ttl" 
                                   name="tmdb_ra_settings[cache_ttl]" 
                                   value="<?php echo esc_attr($settings['cache_ttl'] ?? '3600'); ?>" 
                                   class="small-text" 
                                   min="300" 
                                   max="86400" />
                            <p class="description"><?php _e('Tempo di validità della cache per le richieste a TMDB (minimo 300 secondi, massimo 86400 = 24 ore)', 'tmdb-random-movie-api'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Salva Impostazioni', 'tmdb-random-movie-api')); ?>
            </form>
            
            <hr />
            
            <h2><?php _e('Utilizzo dell\'Endpoint API', 'tmdb-random-movie-api'); ?></h2>
            <div style="background: #f1f1f1; padding: 15px; border-radius: 5px; margin-top: 10px;">
                <h3><?php _e('Endpoint:', 'tmdb-random-movie-api'); ?></h3>
                <code><?php echo esc_url($rest_url); ?></code>
                
                <h3><?php _e('Metodo:', 'tmdb-random-movie-api'); ?></h3>
                <code>GET</code>
                
                <h3><?php _e('Autenticazione:', 'tmdb-random-movie-api'); ?></h3>
                <p><?php _e('L\'endpoint è accessibile SOLO a utenti autenticati.', 'tmdb-random-movie-api'); ?></p>
                
                <h3><?php _e('Parametri Opzionali (GET):', 'tmdb-random-movie-api'); ?></h3>
                <ul>
                    <li><code>list_type</code> - trending, popular, top_rated, now_playing, upcoming (default: popular)</li>
                    <li><code>language</code> - Lingua (es: it-IT, en-US) (default: it-IT)</li>
                    <li><code>region</code> - Regione (es: IT, US) (default: IT)</li>
                </ul>
                
                <h3><?php _e('Esempio di richiesta con cURL:', 'tmdb-random-movie-api'); ?></h3>
                <pre style="background: #2c3e50; color: #ecf0f1; padding: 15px; overflow-x: auto; border-radius: 8px; font-family: monospace;">

# ============================================================
# TMDB RANDOM MOVIE API - ISTRUZIONI
# ============================================================

# 1. RICHIESTA BASE (con cookie di sessione WordPress)
# ----------------------------------------
curl -X GET "<?php echo esc_url($rest_url); ?>" \
  --cookie "wordpress_logged_in_IL_TUO_COOKIE"

# 2. RICHIESTA CON X-WP-NONCE (consigliato per JavaScript)
# ----------------------------------------
curl -X GET "<?php echo esc_url($rest_url); ?>" \
  -H "X-WP-Nonce: IL_TUO_NONCE_QUI" \
  -H "Content-Type: application/json"

# 3. RICHIESTA CON PARAMETRI PERSONALIZZATI
# ----------------------------------------
curl -X GET "<?php echo esc_url($rest_url); ?>?list_type=top_rated&language=it-IT&region=IT" \
  -H "X-WP-Nonce: IL_TUO_NONCE_QUI"

# 4. PARAMETRI DISPONIBILI
# ----------------------------------------
# list_type   : trending, popular, top_rated, now_playing, upcoming
# language    : it-IT, en-US, fr-FR, de-DE, es-ES
# region      : IT, US, FR, DE, ES, GB

# 5. ESEMPIO DI RISPOSTA
# ----------------------------------------
# {
#     "success": true,
#     "data": {
#         "id": 12345,
#         "title": "Titolo del Film",
#         "original_title": "Original Title",
#         "overview": "Descrizione...",
#         "release_date": "2024-01-15",
#         "vote_average": 8.5,
#         "vote_count": 1200,
#         "poster_url": "https://image.tmdb.org/t/p/w500/abc.jpg",
#         "genres": ["Azione", "Drammatico"]
#     },
#     "timestamp": "2024-01-15 10:30:00"
# }

</pre>
                
                <h3><?php _e('Shortcode per il frontend:', 'tmdb-random-movie-api'); ?></h3>
                <p><code>[tmdb_random_movie]</code> - Visualizza un film casuale nella pagina.</p>
                <p><code>[tmdb_random_movie list_type="top_rated" language="it-IT"]</code> - Con parametri personalizzati.</p>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#tmdb_ra_test_connection').on('click', function(e) {
                e.preventDefault();
                var apiKey = $('#tmdb_api_key').val();
                var resultDiv = $('#tmdb_ra_test_result');
                
                if (!apiKey) {
                    resultDiv.html('<div class="notice notice-error inline" style="margin:0"><p>Inserisci una API Key prima di testare.</p></div>');
                    return;
                }
                
                resultDiv.html('<div class="notice notice-info inline" style="margin:0"><p>Test in corso...</p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'tmdb_ra_test_api',
                        api_key: apiKey,
                        nonce: '<?php echo wp_create_nonce('tmdb_ra_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            resultDiv.html('<div class="notice notice-success inline" style="margin:0"><p>✓ ' + response.data.message + '</p></div>');
                        } else {
                            resultDiv.html('<div class="notice notice-error inline" style="margin:0"><p>✗ ' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        resultDiv.html('<div class="notice notice-error inline" style="margin:0"><p>Errore durante il test.</p></div>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Carica gli asset CSS/JS per l'area admin
     *
     * @param string $hook Pagina admin corrente
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_tmdb-random-movie-api') {
            return;
        }
        wp_enqueue_script('jquery');
    }

    /**
     * Handler AJAX per testare la connessione a TMDB
     */
    public function ajax_test_api_connection() {
        check_ajax_referer('tmdb_ra_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Non autorizzato']);
        }
        
        $api_key = sanitize_text_field($_POST['api_key']);
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API Key non valida']);
        }
        
        $url = self::TMDB_API_BASE . '/configuration?api_key=' . $api_key;
        $response = wp_remote_get($url, ['timeout' => 15]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Errore di connessione: ' . $response->get_error_message()]);
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['status_code']) && $data['status_code'] === 7) {
            wp_send_json_error(['message' => 'API Key non valida!']);
        } elseif (isset($data['images']) || isset($data['change_keys'])) {
            wp_send_json_success(['message' => 'Connessione riuscita! API Key valida.']);
        } else {
            wp_send_json_error(['message' => 'Risposta imprevista da TMDB.']);
        }
    }

    /**
     * Disabilita gli endpoint REST nativi di WordPress per utenti non autenticati
     *
     * @param array $endpoints Lista degli endpoint registrati
     * @return array Lista filtrata degli endpoint
     */
    public function disable_default_rest_endpoints($endpoints) {
        $settings = get_option('tmdb_ra_settings');
        
        if (empty($settings['disable_default_rest']) || $settings['disable_default_rest'] !== '1') {
            return $endpoints;
        }
        
        if (!is_user_logged_in()) {
            $restricted_prefixes = [
                '/wp/v2/posts', '/wp/v2/pages', '/wp/v2/media', '/wp/v2/comments',
                '/wp/v2/tags', '/wp/v2/categories', '/wp/v2/users', '/wp/v2/types',
                '/wp/v2/statuses', '/wp/v2/taxonomies', '/wp/v2/blocks', '/wp/v2/settings',
                '/wp/v2/themes', '/wp/v2/plugins', '/wp/v2/search', '/oembed/1.0'
            ];
            
            foreach ($endpoints as $route => $handler) {
                foreach ($restricted_prefixes as $prefix) {
                    if (strpos($route, $prefix) === 0) {
                        unset($endpoints[$route]);
                        break;
                    }
                }
            }
        }
        
        return $endpoints;
    }

    /**
     * Blocca l'accesso all'API REST per utenti non autenticati
     * Permette solo l'endpoint personalizzato del plugin
     *
     * @param mixed $access Stato corrente dell'autenticazione
     * @return mixed
     */
    public function disable_rest_for_unauthenticated($access) {
        $settings = get_option('tmdb_ra_settings');
        
        if (empty($settings['disable_default_rest']) || $settings['disable_default_rest'] !== '1') {
            return $access;
        }
        
        if (is_user_logged_in()) {
            return $access;
        }
        
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        
        if (strpos($request_uri, '/tmdb-random-movie/v1/random-movie') !== false) {
            return $access;
        }
        
        return new WP_Error(
            'rest_disabled',
            __('L\'API REST di WordPress è disabilitata per utenti non autenticati per motivi di sicurezza.', 'tmdb-random-movie-api'),
            ['status' => 401]
        );
    }

    /**
     * Registra l'endpoint REST personalizzato del plugin
     */
    public function register_rest_endpoints() {
        $settings = get_option('tmdb_ra_settings');
        
        if (empty($settings['enable_api']) || $settings['enable_api'] !== '1') {
            return;
        }
        
        register_rest_route('tmdb-random-movie/v1', '/random-movie', [
            'methods' => 'GET',
            'callback' => [$this, 'get_random_movie'],
            'permission_callback' => [$this, 'check_authentication'],
            'args' => [
                'list_type' => [
                    'required' => false,
                    'default' => $settings['movie_list_type'] ?? 'popular',
                    'validate_callback' => function($param) {
                        return in_array($param, self::SUPPORTED_LISTS);
                    }
                ],
                'language' => [
                    'required' => false,
                    'default' => 'it-IT',
                ],
                'region' => [
                    'required' => false,
                    'default' => 'IT',
                ]
            ]
        ]);
    }

    /**
     * Verifica che l'utente sia autenticato per accedere all'endpoint
     *
     * @param WP_REST_Request $request Oggetto richiesta
     * @return bool|WP_Error
     */
    public function check_authentication($request) {
        if (is_user_logged_in()) {
            return true;
        }
        
        $nonce = $request->get_header('X-WP-Nonce');
        if (!empty($nonce) && wp_verify_nonce($nonce, 'wp_rest')) {
            return true;
        }
        
        return new WP_Error(
            'rest_not_logged_in',
            __('Devi essere autenticato per accedere a questo endpoint.', 'tmdb-random-movie-api'),
            ['status' => 401]
        );
    }

    /**
     * Recupera un film casuale da TMDB e lo restituisce in formato JSON
     *
     * @param WP_REST_Request $request Oggetto richiesta
     * @return WP_REST_Response|WP_Error
     */
    public function get_random_movie($request) {
        $settings = get_option('tmdb_ra_settings');
        $api_key = $settings['tmdb_api_key'] ?? '';
        
        if (empty($api_key)) {
            return new WP_Error(
                'tmdb_api_missing',
                __('API Key di TMDB non configurata.', 'tmdb-random-movie-api'),
                ['status' => 500]
            );
        }
        
        $list_type = $request->get_param('list_type');
        $language = $request->get_param('language');
        $region = $request->get_param('region');
        
        $url = $this->build_tmdb_url($list_type, $api_key, $language, $region);
        
        $cache_key = 'tmdb_ra_' . md5($url);
        $data = get_transient($cache_key);
        
        if ($data === false) {
            $response = wp_remote_get($url, ['timeout' => 15]);
            
            if (is_wp_error($response)) {
                return new WP_Error('tmdb_api_error', $response->get_error_message(), ['status' => 502]);
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (isset($data['status_code']) && $data['status_code'] !== 1 && $data['status_code'] !== 200) {
                return new WP_Error('tmdb_api_error', $data['status_message'] ?? 'Errore TMDB', ['status' => $data['status_code']]);
            }
            
            $ttl = intval($settings['cache_ttl'] ?? 3600);
            set_transient($cache_key, $data, $ttl);
        }
        
        $movies = $data['results'] ?? [];
        if (empty($movies)) {
            return new WP_Error('no_movies_found', __('Nessun film trovato.', 'tmdb-random-movie-api'), ['status' => 404]);
        }
        
        $random_movie = $movies[array_rand($movies)];
        $formatted = $this->format_movie_response($random_movie, $list_type);
        
        return rest_ensure_response([
            'success' => true,
            'data' => $formatted,
            'timestamp' => current_time('mysql'),
            'list_type' => $list_type,
            'total_available' => count($movies)
        ]);
    }

    /**
     * Costruisce l'URL per la chiamata all'API TMDB
     *
     * @param string $list_type Tipo di lista
     * @param string $api_key API Key TMDB
     * @param string $language Lingua
     * @param string $region Regione
     * @return string URL completo
     */
    private function build_tmdb_url($list_type, $api_key, $language, $region) {
        $base = self::TMDB_API_BASE;
        
        $endpoints = [
            'trending' => "/trending/movie/week?api_key={$api_key}&language={$language}",
            'popular' => "/movie/popular?api_key={$api_key}&language={$language}&region={$region}",
            'top_rated' => "/movie/top_rated?api_key={$api_key}&language={$language}&region={$region}",
            'now_playing' => "/movie/now_playing?api_key={$api_key}&language={$language}&region={$region}",
            'upcoming' => "/movie/upcoming?api_key={$api_key}&language={$language}&region={$region}"
        ];
        
        $url = $base . ($endpoints[$list_type] ?? $endpoints['popular']) . '&page=1';
        return $url;
    }

    /**
     * Formatta i dati del film per la risposta API
     *
     * @param array $movie Dati grezzi del film da TMDB
     * @param string $list_type Tipo di lista utilizzato
     * @return array Dati formattati
     */
    private function format_movie_response($movie, $list_type) {
        return [
            'id' => $movie['id'],
            'title' => $movie['title'] ?? $movie['name'] ?? 'N/A',
            'original_title' => $movie['original_title'] ?? $movie['original_name'] ?? 'N/A',
            'overview' => $movie['overview'] ?? '',
            'release_date' => $movie['release_date'] ?? null,
            'vote_average' => floatval($movie['vote_average'] ?? 0),
            'vote_count' => intval($movie['vote_count'] ?? 0),
            'popularity' => floatval($movie['popularity'] ?? 0),
            'poster_path' => $movie['poster_path'] ?? null,
            'backdrop_path' => $movie['backdrop_path'] ?? null,
            'poster_url' => !empty($movie['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'] : null,
            'backdrop_url' => !empty($movie['backdrop_path']) ? 'https://image.tmdb.org/t/p/original' . $movie['backdrop_path'] : null,
            'language' => $movie['original_language'] ?? null,
            'list_type' => $list_type,
            'adult' => $movie['adult'] ?? false
        ];
    }

    /**
     * Shortcode per visualizzare il film casuale nel frontend
     *
     * @param array $atts Attributi dello shortcode
     * @return string HTML da visualizzare
     */
    public function render_frontend_shortcode($atts) {
        $atts = shortcode_atts([
            'list_type' => 'popular',
            'language' => 'it-IT',
            'region' => 'IT',
            'button_text' => '🎬 Mostra un film casuale',
            'loading_text' => '⏳ Caricamento...',
            'template' => 'card'
        ], $atts, 'tmdb_random_movie');
        
        if (!is_user_logged_in()) {
            return '<div class="tmdb-error" style="background:#f8d7da;color:#721c24;padding:15px;border-radius:5px;margin:20px 0;">
                        <strong>🔒 Accesso richiesto</strong><br>
                        Devi essere <a href="' . wp_login_url(get_permalink()) . '">autenticato</a> per visualizzare i film casuali.
                    </div>';
        }
        
        $nonce = wp_create_nonce('wp_rest');
        $rest_url = rest_url('tmdb-random-movie/v1/random-movie');
        $endpoint_url = add_query_arg([
            'list_type' => $atts['list_type'],
            'language' => $atts['language'],
            'region' => $atts['region']
        ], $rest_url);
        
        ob_start();
        ?>
        <div class="tmdb-random-movie-container" 
             data-endpoint="<?php echo esc_url($endpoint_url); ?>"
             data-nonce="<?php echo esc_attr($nonce); ?>"
             data-template="<?php echo esc_attr($atts['template']); ?>">
            
            <div class="tmdb-movie-wrapper" style="min-height: 200px;">
                <div class="tmdb-loading" style="text-align:center;padding:40px;">
                    <div class="tmdb-spinner" style="display:inline-block;width:40px;height:40px;border:4px solid #f3f3f3;border-top:4px solid #3498db;border-radius:50%;animation:tmdb-spin 1s linear infinite;"></div>
                    <p><?php echo esc_html($atts['loading_text']); ?></p>
                </div>
            </div>
            
            <div class="tmdb-button-wrapper" style="text-align:center;margin-top:20px;">
                <button class="tmdb-get-movie-btn button button-primary" style="padding:10px 20px;font-size:16px;cursor:pointer;">
                    <?php echo esc_html($atts['button_text']); ?>
                </button>
            </div>
        </div>
        
        <style>
            @keyframes tmdb-spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .tmdb-movie-card {
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                overflow: hidden;
                max-width: 400px;
                margin: 0 auto;
            }
            .tmdb-movie-poster { width: 100%; height: auto; }
            .tmdb-movie-info { padding: 20px; }
            .tmdb-movie-title { font-size: 1.5rem; margin: 0 0 10px 0; color: #2c3e50; }
            .tmdb-movie-overview { color: #666; line-height: 1.6; margin-bottom: 15px; }
            .tmdb-movie-meta { display: flex; gap: 15px; flex-wrap: wrap; font-size: 0.9rem; color: #888; }
            .tmdb-movie-rating { background: #f39c12; color: #fff; padding: 4px 8px; border-radius: 20px; }
            .tmdb-error-message { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; text-align: center; }
        </style>
        
        <script>
        (function() {
            const container = document.querySelector('.tmdb-random-movie-container');
            if (!container) return;
            
            const endpoint = container.dataset.endpoint;
            const nonce = container.dataset.nonce;
            const template = container.dataset.template;
            const movieWrapper = container.querySelector('.tmdb-movie-wrapper');
            const button = container.querySelector('.tmdb-get-movie-btn');
            
            function loadRandomMovie() {
                movieWrapper.innerHTML = '<div class="tmdb-loading" style="text-align:center;padding:40px;"><div class="tmdb-spinner"></div><p>⏳ Caricamento...</p></div>';
                
                fetch(endpoint, {
                    method: 'GET',
                    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        displayMovie(data.data, template);
                    } else {
                        showError('Nessun film trovato. Riprova.');
                    }
                })
                .catch(error => {
                    showError('Errore di connessione. Verifica di essere loggato.');
                });
            }
            
            function displayMovie(movie, templateType) {
                const posterUrl = movie.poster_url || 'https://via.placeholder.com/500x750?text=No+Poster';
                const voteColor = movie.vote_average >= 7 ? '#27ae60' : (movie.vote_average >= 5 ? '#f39c12' : '#e74c3c');
                const releaseYear = movie.release_date ? new Date(movie.release_date).getFullYear() : 'N/A';
                
                let html = `<div class="tmdb-movie-card">
                    ${posterUrl ? `<img src="${posterUrl}" alt="${escapeHtml(movie.title)}" class="tmdb-movie-poster">` : ''}
                    <div class="tmdb-movie-info">
                        <h3 class="tmdb-movie-title">${escapeHtml(movie.title)}</h3>
                        <div class="tmdb-movie-meta">
                            <span>📅 ${releaseYear}</span>
                            <span>⭐ <span class="tmdb-movie-rating" style="background:${voteColor}">${movie.vote_average}/10</span></span>
                            <span>🗳️ ${movie.vote_count} voti</span>
                        </div>
                        <p class="tmdb-movie-overview">${escapeHtml(movie.overview || 'Nessuna descrizione disponibile.')}</p>
                    </div>
                </div>`;
                movieWrapper.innerHTML = html;
            }
            
            function showError(message) {
                movieWrapper.innerHTML = `<div class="tmdb-error-message">⚠️ ${escapeHtml(message)}</div>`;
            }
            
            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            loadRandomMovie();
            if (button) button.addEventListener('click', loadRandomMovie);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Aggiunge link rapidi nella pagina di gestione dei plugin
     *
     * @param array $links Link esistenti
     * @return array Link modificati
     */
    public function add_action_links($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=tmdb-random-movie-api') . '">' . __('Impostazioni', 'tmdb-random-movie-api') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

/**
 * Avvia il plugin
 */
TMDB_Random_Movie_API::get_instance();