<?php
/**
 * Plugin Name: OCTO USER COPY
 * Plugin URI: https://octonove.com
 * Description: Sincroniza usuarios entre sitios WordPress de forma segura y automatizada
 * Version: 1.0.0
 * Author: octonove
 * Author URI: https://octonove.com
 * License: GPL v2 or later
 * Text Domain: octo-user-copy
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Constantes del plugin
define('OCTO_UC_VERSION', '1.0.0');
define('OCTO_UC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OCTO_UC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OCTO_UC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Cargar archivos principales
require_once OCTO_UC_PLUGIN_DIR . 'includes/settings.php';
require_once OCTO_UC_PLUGIN_DIR . 'includes/sync-emitter.php';
require_once OCTO_UC_PLUGIN_DIR . 'includes/sync-receiver.php';

// Clase principal del plugin
class OctoUserCopy {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hooks de activación/desactivación
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Inicializar componentes
        add_action('init', [$this, 'init']);
        
        // Registrar endpoints REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Programar cron si es receptor
        add_action('octo_uc_sync_event', [$this, 'run_sync']);
        
        // Añadir enlace en el menú de herramientas
        add_action('admin_menu', [$this, 'add_tools_menu']);
    }
    
    public function activate() {
        // Crear tabla de logs si no existe
        $this->create_logs_table();
        
        // Establecer valores por defecto
        if (!get_option('octo_uc_mode')) {
            update_option('octo_uc_mode', 'receiver');
        }
        
        // Solo generar clave API automáticamente si es emisor
        $mode = get_option('octo_uc_mode');
        if ($mode === 'emitter' && !get_option('octo_uc_api_key')) {
            update_option('octo_uc_api_key', wp_generate_password(32, false));
        }
        
        // Programar cron inicial si es receptor
        if ($mode === 'receiver') {
            $this->schedule_sync();
        }
        
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Limpiar cron
        wp_clear_scheduled_hook('octo_uc_sync_event');
        flush_rewrite_rules();
    }
    
    public function init() {
        // Inicializar componentes según el modo
        $mode = get_option('octo_uc_mode', 'receiver');
        
        if ($mode === 'emitter') {
            OctoUC_Sync_Emitter::get_instance();
        } else {
            OctoUC_Sync_Receiver::get_instance();
        }
        
        // Re-programar cron si cambió la configuración
        if ($mode === 'receiver') {
            $this->schedule_sync();
        }
    }
    
    public function register_rest_routes() {
        $mode = get_option('octo_uc_mode', 'receiver');
        
        if ($mode === 'emitter') {
            // Endpoint para usuarios
            register_rest_route('usercopy/v1', '/users', [
                'methods' => 'GET',
                'callback' => [OctoUC_Sync_Emitter::get_instance(), 'get_users_endpoint'],
                'permission_callback' => [OctoUC_Sync_Emitter::get_instance(), 'verify_api_key']
            ]);
            
            // Endpoint para roles
            register_rest_route('usercopy/v1', '/roles', [
                'methods' => 'GET',
                'callback' => [OctoUC_Sync_Emitter::get_instance(), 'get_roles_endpoint'],
                'permission_callback' => [OctoUC_Sync_Emitter::get_instance(), 'verify_api_key']
            ]);
            
            // Endpoint de debug
            register_rest_route('usercopy/v1', '/debug', [
                'methods' => 'GET',
                'callback' => [OctoUC_Sync_Emitter::get_instance(), 'get_debug_endpoint'],
                'permission_callback' => [OctoUC_Sync_Emitter::get_instance(), 'verify_api_key']
            ]);
        }
    }
    
    public function add_tools_menu() {
        add_management_page(
            __('OCTO USER COPY', 'octo-user-copy'),
            __('OCTO USER COPY', 'octo-user-copy'),
            'manage_options',
            'octo-user-copy',
            [OctoUC_Settings::get_instance(), 'render_settings_page']
        );
    }
    
    public function run_sync() {
        if (get_option('octo_uc_mode') === 'receiver') {
            OctoUC_Sync_Receiver::get_instance()->sync_users();
        }
    }
    
    private function schedule_sync() {
        $frequency = get_option('octo_uc_cron_frequency', 'daily');
        
        // Limpiar cron anterior
        wp_clear_scheduled_hook('octo_uc_sync_event');
        
        // Programar nuevo cron
        if (!wp_next_scheduled('octo_uc_sync_event')) {
            wp_schedule_event(time(), $frequency, 'octo_uc_sync_event');
        }
    }
    
    private function create_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'octo_uc_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            type varchar(50) NOT NULL,
            message text NOT NULL,
            details longtext,
            PRIMARY KEY (id),
            KEY type_idx (type),
            KEY timestamp_idx (timestamp)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    // Método helper para registrar logs
    public static function log($type, $message, $details = null) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'octo_uc_logs',
            [
                'type' => $type,
                'message' => $message,
                'details' => $details ? json_encode($details) : null
            ]
        );
    }
}

// Inicializar plugin
OctoUserCopy::get_instance();