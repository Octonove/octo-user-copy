<?php
/**
 * OCTO USER COPY - Módulo Emisor
 * 
 * @package OctoUserCopy
 */

if (!defined('ABSPATH')) {
    exit;
}

class OctoUC_Sync_Emitter {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Constructor vacío, toda la lógica está en los endpoints
    }
    
    /**
     * Verifica la clave API
     */
    public function verify_api_key($request) {
        $provided_key = $request->get_param('key');
        $stored_key = get_option('octo_uc_api_key');
        
        if (!$provided_key || !$stored_key) {
            return false;
        }
        
        return hash_equals($stored_key, $provided_key);
    }
    
    /**
     * Endpoint para obtener usuarios
     */
    public function get_users_endpoint($request) {
        // Registrar acceso
        OctoUserCopy::log('info', 'Acceso al endpoint de usuarios', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
        
        // Obtener configuración
        $exclude_roles = get_option('octo_uc_exclude_roles', []);
        $only_active = get_option('octo_uc_only_active_users', false);
        
        // Construir argumentos de consulta
        $args = [
            'fields' => 'all',
            'number' => -1 // Sin límite
        ];
        
        // Excluir roles si está configurado
        if (!empty($exclude_roles) && is_array($exclude_roles)) {
            $args['role__not_in'] = $exclude_roles;
        }
        
        // Debug: registrar argumentos
        OctoUserCopy::log('info', 'Argumentos de consulta de usuarios', $args);
        
        // Obtener usuarios
        $users = get_users($args);
        
        // Debug: registrar cantidad de usuarios encontrados
        OctoUserCopy::log('info', sprintf('Usuarios encontrados: %d', count($users)));
        
        $export_data = [];
        
        foreach ($users as $user) {
            // Verificar si el usuario está activo (si está habilitada la opción)
            if ($only_active) {
                $last_login = get_user_meta($user->ID, 'last_login', true);
                if ($last_login) {
                    $days_inactive = (time() - strtotime($last_login)) / DAY_IN_SECONDS;
                    if ($days_inactive > 90) {
                        continue; // Saltar usuarios inactivos
                    }
                }
            }
            
            // Obtener el objeto WP_User completo
            $user_obj = get_userdata($user->ID);
            
            // Preparar datos del usuario
            $user_data = [
                'ID' => $user->ID,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email,
                'user_pass' => $user_obj->data->user_pass, // Hash de la contraseña
                'user_nicename' => $user->user_nicename,
                'user_url' => $user->user_url,
                'user_registered' => $user->user_registered,
                'display_name' => $user->display_name,
                'first_name' => get_user_meta($user->ID, 'first_name', true),
                'last_name' => get_user_meta($user->ID, 'last_name', true),
                'description' => get_user_meta($user->ID, 'description', true),
                'roles' => array_values($user_obj->roles), // Asegurar que sea un array indexado
                'capabilities' => $user_obj->allcaps,
                'meta' => $this->get_exportable_user_meta($user->ID)
            ];
            
            $export_data[] = $user_data;
        }
        
        // Registrar exportación exitosa
        OctoUserCopy::log('success', sprintf('Exportados %d usuarios', count($export_data)));
        
        // Si no hay usuarios, enviar un mensaje de debug
        if (empty($export_data)) {
            OctoUserCopy::log('warning', 'No se encontraron usuarios para exportar', [
                'exclude_roles' => $exclude_roles,
                'only_active' => $only_active,
                'total_users_in_site' => count_users()
            ]);
        }
        
        return new WP_REST_Response($export_data, 200);
    }
    
    /**
     * Endpoint para obtener roles
     */
    public function get_roles_endpoint($request) {
        // Registrar acceso
        OctoUserCopy::log('info', 'Acceso al endpoint de roles', [
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]);
        
        global $wp_roles;
        
        $roles_data = [];
        
        foreach ($wp_roles->roles as $role_key => $role_info) {
            $roles_data[$role_key] = [
                'name' => $role_info['name'],
                'capabilities' => $role_info['capabilities']
            ];
        }
        
        // Registrar exportación exitosa
        OctoUserCopy::log('success', sprintf('Exportados %d roles', count($roles_data)));
        
        return new WP_REST_Response($roles_data, 200);
    }
    
    /**
     * Obtiene meta-datos exportables del usuario
     */
    private function get_exportable_user_meta($user_id) {
        $all_meta = get_user_meta($user_id);
        $exportable_meta = [];
        
        // Lista de meta-keys que queremos exportar
        $allowed_keys = [
            'nickname',
            'rich_editing',
            'syntax_highlighting',
            'comment_shortcuts',
            'admin_color',
            'use_ssl',
            'show_admin_bar_front',
            'locale',
            'wp_capabilities',
            'wp_user_level',
            'dismissed_wp_pointers',
            'show_welcome_panel',
            'session_tokens',
            'wp_dashboard_quick_press_last_post_id',
            'community-events-location',
            'octo_uc_last_sync', // Meta propia del plugin
            'last_login' // Si existe
        ];
        
        // Permitir que otros plugins añadan sus propias claves
        $allowed_keys = apply_filters('octo_uc_exportable_user_meta', $allowed_keys);
        
        foreach ($all_meta as $key => $value) {
            // Exportar solo meta permitida
            if (in_array($key, $allowed_keys)) {
                $exportable_meta[$key] = maybe_unserialize($value[0]);
            }
            
            // También exportar meta que comience con ciertos prefijos
            $allowed_prefixes = apply_filters('octo_uc_exportable_meta_prefixes', [
                'wp_',
                'octo_',
                'woocommerce_',
                'edd_'
            ]);
            
            foreach ($allowed_prefixes as $prefix) {
                if (strpos($key, $prefix) === 0) {
                    $exportable_meta[$key] = maybe_unserialize($value[0]);
                    break;
                }
            }
        }
        
        return $exportable_meta;
    }
    
    /**
     * Endpoint de debug para diagnosticar problemas
     */
    public function get_debug_endpoint($request) {
        $debug_info = [
            'site_info' => [
                'url' => home_url(),
                'wp_version' => get_bloginfo('version'),
                'php_version' => phpversion(),
                'plugin_version' => OCTO_UC_VERSION
            ],
            'settings' => [
                'mode' => get_option('octo_uc_mode'),
                'exclude_roles' => get_option('octo_uc_exclude_roles', []),
                'only_active_users' => get_option('octo_uc_only_active_users', false)
            ],
            'users_count' => count_users(),
            'roles' => wp_roles()->roles,
            'test_query' => [
                'all_users' => count(get_users(['number' => -1])),
                'with_exclude' => count(get_users([
                    'number' => -1,
                    'role__not_in' => get_option('octo_uc_exclude_roles', [])
                ]))
            ]
        ];
        
        return new WP_REST_Response($debug_info, 200);
    }
}