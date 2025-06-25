<?php
/**
 * OCTO USER COPY - Configuración y ajustes
 * 
 * @package OctoUserCopy
 */

if (!defined('ABSPATH')) {
    exit;
}

class OctoUC_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_octo_uc_force_sync', [$this, 'ajax_force_sync']);
        add_action('wp_ajax_octo_uc_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_octo_uc_generate_api_key', [$this, 'ajax_generate_api_key']);
    }
    
    public function register_settings() {
        // Registrar opciones
        register_setting('octo_uc_settings', 'octo_uc_mode', [
            'sanitize_callback' => [$this, 'sanitize_mode']
        ]);
        register_setting('octo_uc_settings', 'octo_uc_api_key');
        register_setting('octo_uc_settings', 'octo_uc_emitter_url');
        register_setting('octo_uc_settings', 'octo_uc_cron_frequency');
        register_setting('octo_uc_settings', 'octo_uc_exclude_roles');
        register_setting('octo_uc_settings', 'octo_uc_only_active_users');
    }
    
    public function sanitize_mode($mode) {
        // Si cambiamos a modo emisor y no hay clave API, generar una
        if ($mode === 'emitter' && !get_option('octo_uc_api_key')) {
            update_option('octo_uc_api_key', wp_generate_password(32, false));
        }
        return $mode;
    }
    
    public function render_settings_page() {
        $mode = get_option('octo_uc_mode', 'receiver');
        $api_key = get_option('octo_uc_api_key', '');
        $emitter_url = get_option('octo_uc_emitter_url', '');
        $cron_frequency = get_option('octo_uc_cron_frequency', 'daily');
        $exclude_roles = get_option('octo_uc_exclude_roles', []);
        $only_active = get_option('octo_uc_only_active_users', false);
        
        // Obtener logs recientes
        global $wpdb;
        $logs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}octo_uc_logs 
             ORDER BY timestamp DESC 
             LIMIT 50"
        );
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('OCTO USER COPY', 'octo-user-copy'); ?></h1>
            
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Configuración guardada correctamente.', 'octo-user-copy'); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="octo-uc-container">
                <div class="octo-uc-main">
                    <form method="post" action="options.php">
                        <?php settings_fields('octo_uc_settings'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="octo_uc_mode"><?php _e('Modo del sitio', 'octo-user-copy'); ?></label>
                                </th>
                                <td>
                                    <select name="octo_uc_mode" id="octo_uc_mode">
                                        <option value="emitter" <?php selected($mode, 'emitter'); ?>>
                                            <?php _e('Emisor (Exporta usuarios)', 'octo-user-copy'); ?>
                                        </option>
                                        <option value="receiver" <?php selected($mode, 'receiver'); ?>>
                                            <?php _e('Receptor (Importa usuarios)', 'octo-user-copy'); ?>
                                        </option>
                                    </select>
                                    <p class="description">
                                        <?php _e('Define si este sitio exporta o importa usuarios.', 'octo-user-copy'); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="octo_uc_api_key"><?php _e('Clave API', 'octo-user-copy'); ?></label>
                                </th>
                                <td>
                                    <?php if ($mode === 'emitter'): ?>
                                        <!-- EMISOR: Campo de solo lectura con botón copiar -->
                                        <input type="text" 
                                               name="octo_uc_api_key" 
                                               id="octo_uc_api_key" 
                                               value="<?php echo esc_attr($api_key); ?>" 
                                               class="regular-text code" 
                                               readonly />
                                        <button type="button" 
                                                class="button" 
                                                onclick="document.getElementById('octo_uc_api_key').select(); document.execCommand('copy');">
                                            <?php _e('Copiar', 'octo-user-copy'); ?>
                                        </button>
                                        <?php if (empty($api_key)): ?>
                                            <button type="button" 
                                                    class="button" 
                                                    id="generate-api-key">
                                                <?php _e('Generar clave', 'octo-user-copy'); ?>
                                            </button>
                                        <?php endif; ?>
                                        <p class="description">
                                            <?php _e('Esta es tu clave API. Compártela con los sitios receptores.', 'octo-user-copy'); ?>
                                        </p>
                                    <?php else: ?>
                                        <!-- RECEPTOR: Campo editable -->
                                        <input type="text" 
                                               name="octo_uc_api_key" 
                                               id="octo_uc_api_key" 
                                               value="<?php echo esc_attr($api_key); ?>" 
                                               class="regular-text code" 
                                               placeholder="<?php _e('Pega aquí la clave API del sitio emisor', 'octo-user-copy'); ?>" />
                                        <p class="description">
                                            <?php _e('Ingresa la clave API proporcionada por el sitio emisor.', 'octo-user-copy'); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            
                            <tr class="octo-uc-receiver-only" <?php echo $mode === 'emitter' ? 'style="display:none;"' : ''; ?>>
                                <th scope="row">
                                    <label for="octo_uc_emitter_url"><?php _e('URL del sitio emisor', 'octo-user-copy'); ?></label>
                                </th>
                                <td>
                                    <input type="url" 
                                           name="octo_uc_emitter_url" 
                                           id="octo_uc_emitter_url" 
                                           value="<?php echo esc_url($emitter_url); ?>" 
                                           class="regular-text" 
                                           placeholder="https://ejemplo.com" />
                                    <button type="button" class="button" id="test-connection">
                                        <?php _e('Probar conexión', 'octo-user-copy'); ?>
                                    </button>
                                    <p class="description">
                                        <?php _e('URL completa del sitio WordPress emisor (sin barra al final).', 'octo-user-copy'); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr class="octo-uc-receiver-only" <?php echo $mode === 'emitter' ? 'style="display:none;"' : ''; ?>>
                                <th scope="row">
                                    <label for="octo_uc_cron_frequency"><?php _e('Frecuencia de sincronización', 'octo-user-copy'); ?></label>
                                </th>
                                <td>
                                    <select name="octo_uc_cron_frequency" id="octo_uc_cron_frequency">
                                        <option value="hourly" <?php selected($cron_frequency, 'hourly'); ?>>
                                            <?php _e('Cada hora', 'octo-user-copy'); ?>
                                        </option>
                                        <option value="twicedaily" <?php selected($cron_frequency, 'twicedaily'); ?>>
                                            <?php _e('Dos veces al día', 'octo-user-copy'); ?>
                                        </option>
                                        <option value="daily" <?php selected($cron_frequency, 'daily'); ?>>
                                            <?php _e('Diariamente', 'octo-user-copy'); ?>
                                        </option>
                                        <option value="weekly" <?php selected($cron_frequency, 'weekly'); ?>>
                                            <?php _e('Semanalmente', 'octo-user-copy'); ?>
                                        </option>
                                    </select>
                                    <p class="description">
                                        <?php _e('Con qué frecuencia se sincronizarán los usuarios.', 'octo-user-copy'); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label><?php _e('Roles a excluir', 'octo-user-copy'); ?></label>
                                </th>
                                <td>
                                    <?php
                                    $all_roles = wp_roles()->roles;
                                    foreach ($all_roles as $role_key => $role_info):
                                    ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox" 
                                                   name="octo_uc_exclude_roles[]" 
                                                   value="<?php echo esc_attr($role_key); ?>" 
                                                   <?php checked(in_array($role_key, (array)$exclude_roles)); ?> />
                                            <?php echo esc_html($role_info['name']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <p class="description">
                                        <?php _e('Marca los roles que NO quieres sincronizar.', 'octo-user-copy'); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="octo_uc_only_active_users">
                                        <?php _e('Solo usuarios activos', 'octo-user-copy'); ?>
                                    </label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" 
                                               name="octo_uc_only_active_users" 
                                               id="octo_uc_only_active_users" 
                                               value="1" 
                                               <?php checked($only_active, true); ?> />
                                        <?php _e('Sincronizar solo usuarios que han iniciado sesión en los últimos 90 días', 'octo-user-copy'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button(); ?>
                    </form>
                    
                    <?php if ($mode === 'receiver'): ?>
                        <div class="octo-uc-actions">
                            <h3><?php _e('Acciones', 'octo-user-copy'); ?></h3>
                            <button type="button" class="button button-primary" id="force-sync">
                                <?php _e('Forzar sincronización ahora', 'octo-user-copy'); ?>
                            </button>
                            <span class="spinner"></span>
                            <div id="sync-message"></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($mode === 'emitter' && !empty($api_key)): ?>
                        <div class="octo-uc-endpoints">
                            <h3><?php _e('Endpoints disponibles', 'octo-user-copy'); ?></h3>
                            <p><?php _e('Los siguientes endpoints están disponibles para el sitio receptor:', 'octo-user-copy'); ?></p>
                            <ul>
                                <li>
                                    <code><?php echo esc_url(home_url('/wp-json/usercopy/v1/users?key=' . $api_key)); ?></code>
                                    <br><small><?php _e('Lista de usuarios', 'octo-user-copy'); ?></small>
                                </li>
                                <li>
                                    <code><?php echo esc_url(home_url('/wp-json/usercopy/v1/roles?key=' . $api_key)); ?></code>
                                    <br><small><?php _e('Lista de roles y capacidades', 'octo-user-copy'); ?></small>
                                </li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="octo-uc-sidebar">
                    <div class="octo-uc-logs">
                        <h3><?php _e('Registro de actividad', 'octo-user-copy'); ?></h3>
                        <div class="logs-container">
                            <?php if ($logs): ?>
                                <table class="widefat">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Fecha', 'octo-user-copy'); ?></th>
                                            <th><?php _e('Tipo', 'octo-user-copy'); ?></th>
                                            <th><?php _e('Mensaje', 'octo-user-copy'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td><?php echo esc_html($log->timestamp); ?></td>
                                                <td>
                                                    <span class="log-type log-type-<?php echo esc_attr($log->type); ?>">
                                                        <?php echo esc_html($log->type); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo esc_html($log->message); ?>
                                                    <?php if ($log->details): ?>
                                                        <details>
                                                            <summary><?php _e('Ver detalles', 'octo-user-copy'); ?></summary>
                                                            <pre><?php echo esc_html($log->details); ?></pre>
                                                        </details>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p><?php _e('No hay registros disponibles.', 'octo-user-copy'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            .octo-uc-container {
                display: flex;
                gap: 20px;
                margin-top: 20px;
            }
            .octo-uc-main {
                flex: 1;
                background: #fff;
                padding: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .octo-uc-sidebar {
                width: 400px;
            }
            .octo-uc-logs {
                background: #fff;
                padding: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .logs-container {
                max-height: 500px;
                overflow-y: auto;
            }
            .log-type {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .log-type-info { background: #d1ecf1; color: #0c5460; }
            .log-type-success { background: #d4edda; color: #155724; }
            .log-type-warning { background: #fff3cd; color: #856404; }
            .log-type-error { background: #f8d7da; color: #721c24; }
            .octo-uc-actions {
                margin-top: 30px;
                padding-top: 30px;
                border-top: 1px solid #ddd;
            }
            .octo-uc-endpoints {
                margin-top: 30px;
                padding-top: 30px;
                border-top: 1px solid #ddd;
            }
            .octo-uc-endpoints code {
                display: block;
                padding: 10px;
                background: #f3f4f5;
                margin: 5px 0;
                word-break: break-all;
            }
            #sync-message {
                display: inline-block;
                margin-left: 10px;
            }
            details {
                margin-top: 5px;
            }
            details pre {
                margin: 5px 0;
                padding: 10px;
                background: #f3f4f5;
                overflow-x: auto;
            }
        </style>
        <?php
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'tools_page_octo-user-copy') {
            return;
        }
        
        wp_enqueue_script(
            'octo-uc-admin',
            OCTO_UC_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            OCTO_UC_VERSION,
            true
        );
        
        wp_localize_script('octo-uc-admin', 'octoUC', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('octo-uc-ajax'),
            'strings' => [
                'syncing' => __('Sincronizando...', 'octo-user-copy'),
                'testing' => __('Probando conexión...', 'octo-user-copy'),
                'success' => __('¡Éxito!', 'octo-user-copy'),
                'error' => __('Error:', 'octo-user-copy'),
                'generating' => __('Generando...', 'octo-user-copy'),
                'copy' => __('Copiar', 'octo-user-copy')
            ]
        ]);
    }
    
    public function ajax_force_sync() {
        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'octo-uc-ajax')) { 
            wp_send_json_error(['message' => __('Error de seguridad', 'octo-user-copy')]);
            // NO usar wp_die() - wp_send_json_error ya termina la ejecución
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sin permisos suficientes', 'octo-user-copy')]);
            // NO usar wp_die()
        }
        
        // Log del inicio
        OctoUserCopy::log('info', 'Sincronización manual iniciada por el usuario');
        
        try {
            // Ejecutar sincronización
            $result = OctoUC_Sync_Receiver::get_instance()->sync_users();
            
            // Debug: Log del resultado completo
            OctoUserCopy::log('debug', 'Resultado de sincronización', [
                'result_type' => gettype($result),
                'result_keys' => is_array($result) ? array_keys($result) : 'not_array'
            ]);
            
            // Verificar que tenemos un resultado válido
            if (!is_array($result)) {
                OctoUserCopy::log('error', 'Resultado de sincronización inválido', ['result' => $result]);
                wp_send_json_error([
                    'message' => __('Error interno: respuesta inválida del sincronizador', 'octo-user-copy')
                ]);
                // NO usar wp_die()
            }
            
            // Verificar el resultado
            if (isset($result['success']) && $result['success'] === true) {
                // Limpiar datos antes de enviar para evitar problemas de codificación
                $clean_stats = [];
                if (isset($result['stats']) && is_array($result['stats'])) {
                    foreach ($result['stats'] as $key => $value) {
                        $clean_stats[$key] = intval($value);
                    }
                }
                
                // Preparar respuesta limpia
                $response_data = [
                    'message' => wp_kses_post($result['message'] ?? __('Sincronización completada', 'octo-user-copy')),
                    'stats' => $clean_stats,
                    'total_processed' => intval($result['total_processed'] ?? 0)
                ];
                
                // Verificar que la respuesta sea JSON válido
                $json_test = json_encode($response_data);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    OctoUserCopy::log('error', 'Error al codificar JSON', [
                        'json_error' => json_last_error_msg()
                    ]);
                    wp_send_json_error([
                        'message' => __('Error al procesar la respuesta', 'octo-user-copy')
                    ]);
                    // NO usar wp_die()
                }
                
                // Enviar respuesta exitosa
                wp_send_json_success($response_data);
                // NO usar wp_die() - wp_send_json_success ya termina la ejecución
                
            } else {
                // Error
                $error_message = wp_kses_post($result['message'] ?? __('Error desconocido durante la sincronización', 'octo-user-copy'));
                wp_send_json_error([
                    'message' => $error_message
                ]);
                // NO usar wp_die()
            }
            
        } catch (Exception $e) {
            OctoUserCopy::log('error', 'Excepción durante sincronización manual', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            wp_send_json_error([
                'message' => sprintf(__('Error crítico: %s', 'octo-user-copy'), esc_html($e->getMessage()))
            ]);
            // NO usar wp_die()
        }
    }

    public function ajax_test_connection() {
        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'octo-uc-ajax')) {
            wp_send_json_error(['message' => __('Error de seguridad', 'octo-user-copy')]);
            // NO usar wp_die()
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sin permisos suficientes', 'octo-user-copy')]);
            // NO usar wp_die()
        }
        
        $emitter_url = get_option('octo_uc_emitter_url');
        $api_key = get_option('octo_uc_api_key');
        
        if (!$emitter_url) {
            wp_send_json_error(['message' => __('URL del emisor no configurada', 'octo-user-copy')]);
            // NO usar wp_die()
        }
        
        if (!$api_key) {
            wp_send_json_error(['message' => __('Clave API no configurada', 'octo-user-copy')]);
            // NO usar wp_die()
        }
        
        // Limpiar la URL (quitar barras finales y espacios)
        $emitter_url = untrailingslashit(trim($emitter_url));
        
        // Construir la URL del endpoint
        $test_url = $emitter_url . '/wp-json/usercopy/v1/roles?key=' . urlencode($api_key);
        
        // Log para debug
        OctoUserCopy::log('info', 'Probando conexión', [
            'url' => $test_url,
            'emitter_url' => $emitter_url
        ]);
        
        try {
            // Configurar la petición con más opciones
            $args = [
                'timeout' => 30,
                'redirection' => 5,
                'httpversion' => '1.1',
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url') . ' - OCTO USER COPY',
                'blocking' => true,
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'cookies' => [],
                'sslverify' => false, // Desactivar verificación SSL para desarrollo local
            ];
            
            // Realizar la petición
            $response = wp_remote_get($test_url, $args);
            
            // Verificar errores de WP
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                
                // Log del error
                OctoUserCopy::log('error', 'Error en prueba de conexión', [
                    'error' => $error_message,
                    'url' => $test_url
                ]);
                
                // Mensajes más descriptivos según el tipo de error
                if (strpos($error_message, 'cURL error 7') !== false || strpos($error_message, 'Failed to connect') !== false) {
                    wp_send_json_error(['message' => __('No se pudo conectar con el servidor. Verifica que la URL sea correcta y accesible.', 'octo-user-copy')]);
                } elseif (strpos($error_message, 'cURL error 60') !== false || strpos($error_message, 'SSL') !== false) {
                    wp_send_json_error(['message' => __('Error de certificado SSL. Esto es común en desarrollo local.', 'octo-user-copy')]);
                } else {
                    wp_send_json_error(['message' => sprintf(__('Error de conexión: %s', 'octo-user-copy'), $error_message)]);
                }
                // NO usar wp_die()
                return;
            }
            
            // Obtener código de respuesta
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            // Log de la respuesta
            OctoUserCopy::log('info', 'Respuesta de prueba de conexión', [
                'code' => $code,
                'body_length' => strlen($body)
            ]);
            
            // Evaluar respuesta
            if ($code === 200) {
                // Verificar que la respuesta sea JSON válido
                $data = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $roles_count = is_array($data) ? count($data) : 0;
                    wp_send_json_success([
                        'message' => sprintf(__('✓ Conexión exitosa - %d roles encontrados', 'octo-user-copy'), $roles_count)
                    ]);
                } else {
                    wp_send_json_error(['message' => __('Conexión establecida pero la respuesta no es válida', 'octo-user-copy')]);
                }
            } else if ($code === 403) {
                wp_send_json_error(['message' => __('Clave API incorrecta o sin permisos', 'octo-user-copy')]);
            } else if ($code === 404) {
                wp_send_json_error(['message' => __('Endpoint no encontrado. Verifica que el plugin esté activo en el emisor y en modo Emisor.', 'octo-user-copy')]);
            } else if ($code === 0) {
                wp_send_json_error(['message' => __('No se recibió respuesta. Posible problema de red o firewall.', 'octo-user-copy')]);
            } else {
                wp_send_json_error(['message' => sprintf(__('Error HTTP %d - Verifica la configuración del servidor', 'octo-user-copy'), $code)]);
            }
            
        } catch (Exception $e) {
            OctoUserCopy::log('error', 'Excepción en prueba de conexión', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => sprintf(__('Error crítico: %s', 'octo-user-copy'), $e->getMessage())
            ]);
        }
        // NO usar wp_die()
    }
    
    public function ajax_generate_api_key() {
        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'octo-uc-ajax')) {
            wp_send_json_error(['message' => __('Error de seguridad', 'octo-user-copy')]);
            // NO usar wp_die()
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sin permisos suficientes', 'octo-user-copy')]);
            // NO usar wp_die()
        }
        
        $new_key = wp_generate_password(32, false);
        update_option('octo_uc_api_key', $new_key);
        
        wp_send_json_success(['key' => $new_key]);
        // NO usar wp_die() - wp_send_json_success ya termina la ejecución
    }
}