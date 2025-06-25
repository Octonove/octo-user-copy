<?php
/**
 * OCTO USER COPY - Módulo Receptor con inserción directa a BD
 * 
 * @package OctoUserCopy
 */

if (!defined('ABSPATH')) {
    exit;
}

class OctoUC_Sync_Receiver {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Constructor vacío, la sincronización se ejecuta por cron o manualmente
    }
    
    /**
     * Sincroniza usuarios desde el sitio emisor
     */
    public function sync_users() {
        // Verificar configuración
        $emitter_url = get_option('octo_uc_emitter_url');
        $api_key = get_option('octo_uc_api_key');
        
        if (!$emitter_url || !$api_key) {
            OctoUserCopy::log('error', 'Sincronización fallida: Configuración incompleta');
            return [
                'success' => false,
                'message' => __('Configuración incompleta. Verifica la URL del emisor y la clave API.', 'octo-user-copy')
            ];
        }
        
        // Registrar inicio de sincronización
        OctoUserCopy::log('info', 'Iniciando sincronización de usuarios');
        
        // Primero sincronizar roles
        $roles_result = $this->sync_roles($emitter_url, $api_key);
        if (!$roles_result['success']) {
            return $roles_result;
        }
        
        // Luego sincronizar usuarios
        $users_url = trailingslashit($emitter_url) . 'wp-json/usercopy/v1/users?key=' . $api_key;
        
        $response = wp_remote_get($users_url, [
            'timeout' => 30,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            OctoUserCopy::log('error', 'Error al obtener usuarios', ['error' => $error_message]);
            return [
                'success' => false,
                'message' => sprintf(__('Error al conectar con el emisor: %s', 'octo-user-copy'), $error_message)
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $users_data = json_decode($body, true);
        
        if (!is_array($users_data)) {
            OctoUserCopy::log('error', 'Respuesta inválida del emisor');
            return [
                'success' => false,
                'message' => __('Respuesta inválida del servidor emisor', 'octo-user-copy')
            ];
        }
        
        // Procesar usuarios
        $stats = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'skipped' => 0
        ];
        
        // Log del total de usuarios a procesar
        OctoUserCopy::log('info', sprintf('Procesando %d usuarios', count($users_data)));
        
        foreach ($users_data as $user_data) {
            try {
                $result = $this->process_user_direct($user_data);
                if ($result && isset($stats[$result])) {
                    $stats[$result]++;
                }
            } catch (Exception $e) {
                $stats['errors']++;
                OctoUserCopy::log('error', 'Excepción al procesar usuario', [
                    'user' => $user_data['user_login'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Registrar resultado
        $message = sprintf(
            __('Sincronización completada: %d creados, %d actualizados, %d omitidos, %d errores', 'octo-user-copy'),
            $stats['created'],
            $stats['updated'],
            $stats['skipped'],
            $stats['errors']
        );
        
        OctoUserCopy::log('success', $message, $stats);
        
        // Asegurar que siempre devolvemos un array con la estructura correcta
        return [
            'success' => true,
            'message' => $message,
            'stats' => $stats,
            'total_processed' => count($users_data)
        ];
    }
    
    /**
     * Sincroniza roles desde el sitio emisor
     */
    private function sync_roles($emitter_url, $api_key) {
        $roles_url = trailingslashit($emitter_url) . 'wp-json/usercopy/v1/roles?key=' . $api_key;
        
        $response = wp_remote_get($roles_url, [
            'timeout' => 15,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            OctoUserCopy::log('error', 'Error al obtener roles', ['error' => $error_message]);
            return [
                'success' => false,
                'message' => sprintf(__('Error al obtener roles: %s', 'octo-user-copy'), $error_message)
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $roles_data = json_decode($body, true);
        
        if (!is_array($roles_data)) {
            OctoUserCopy::log('error', 'Respuesta de roles inválida');
            return [
                'success' => false,
                'message' => __('Respuesta de roles inválida', 'octo-user-copy')
            ];
        }
        
        // Procesar roles
        global $wp_roles;
        $created_roles = 0;
        
        foreach ($roles_data as $role_key => $role_info) {
            // Saltar roles del sistema que ya existen
            if (in_array($role_key, ['administrator', 'editor', 'author', 'contributor', 'subscriber'])) {
                continue;
            }
            
            // Verificar si el rol existe
            if (!isset($wp_roles->roles[$role_key])) {
                // Crear el rol
                add_role(
                    $role_key,
                    $role_info['name'],
                    $role_info['capabilities']
                );
                $created_roles++;
                OctoUserCopy::log('info', sprintf('Rol creado: %s', $role_key));
            } else {
                // Actualizar capacidades del rol existente
                $role = get_role($role_key);
                if ($role) {
                    foreach ($role_info['capabilities'] as $cap => $grant) {
                        if ($grant) {
                            $role->add_cap($cap);
                        } else {
                            $role->remove_cap($cap);
                        }
                    }
                }
            }
        }
        
        if ($created_roles > 0) {
            OctoUserCopy::log('success', sprintf('Creados %d roles nuevos', $created_roles));
        }
        
        return ['success' => true];
    }
    
    /**
     * Procesa un usuario individual con inserción directa a BD
     */
    private function process_user_direct($user_data) {
        global $wpdb;
        
        // Verificar datos mínimos requeridos
        if (!isset($user_data['user_login']) || !isset($user_data['user_email'])) {
            OctoUserCopy::log('warning', 'Usuario sin datos requeridos', $user_data);
            return 'errors';
        }
        
        // Buscar si el usuario ya existe por login
        $existing_user_id = username_exists($user_data['user_login']);
        
        // Si no existe por login, buscar por email
        if (!$existing_user_id) {
            $existing_user_id = email_exists($user_data['user_email']);
        }
        
        // Preparar datos para la tabla wp_users
        $user_table_data = [
            'user_login' => $user_data['user_login'],
            'user_pass' => $user_data['user_pass'], // Hash directo sin procesar
            'user_nicename' => $user_data['user_nicename'] ?? sanitize_title($user_data['user_login']),
            'user_email' => $user_data['user_email'],
            'user_url' => $user_data['user_url'] ?? '',
            'user_registered' => $user_data['user_registered'] ?? current_time('mysql'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => $user_data['display_name'] ?? $user_data['user_login']
        ];
        
        if ($existing_user_id) {
            // ACTUALIZAR usuario existente directamente en BD
            $result = $wpdb->update(
                $wpdb->users,
                $user_table_data,
                ['ID' => $existing_user_id],
                [
                    '%s', // user_login
                    '%s', // user_pass
                    '%s', // user_nicename
                    '%s', // user_email
                    '%s', // user_url
                    '%s', // user_registered
                    '%s', // user_activation_key
                    '%d', // user_status
                    '%s'  // display_name
                ],
                ['%d'] // ID
            );
            
            if ($result === false) {
                OctoUserCopy::log('error', 'Error al actualizar usuario en BD', [
                    'user_login' => $user_data['user_login'],
                    'error' => $wpdb->last_error
                ]);
                return 'errors';
            }
            
            $user_id = $existing_user_id;
            $action = 'updated';
            
        } else {
            // CREAR nuevo usuario directamente en BD
            $result = $wpdb->insert(
                $wpdb->users,
                $user_table_data,
                [
                    '%s', // user_login
                    '%s', // user_pass
                    '%s', // user_nicename
                    '%s', // user_email
                    '%s', // user_url
                    '%s', // user_registered
                    '%s', // user_activation_key
                    '%d', // user_status
                    '%s'  // display_name
                ]
            );
            
            if ($result === false) {
                OctoUserCopy::log('error', 'Error al insertar usuario en BD', [
                    'user_login' => $user_data['user_login'],
                    'error' => $wpdb->last_error
                ]);
                return 'errors';
            }
            
            $user_id = $wpdb->insert_id;
            $action = 'created';
        }
        
        // Actualizar meta-datos básicos
        update_user_meta($user_id, 'first_name', $user_data['first_name'] ?? '');
        update_user_meta($user_id, 'last_name', $user_data['last_name'] ?? '');
        update_user_meta($user_id, 'description', $user_data['description'] ?? '');
        update_user_meta($user_id, 'nickname', $user_data['display_name'] ?? $user_data['user_login']);
        
        // Procesar roles y capacidades
        $this->sync_user_roles_and_caps($user_id, $user_data['roles'] ?? [], $user_data['capabilities'] ?? []);
        
        // Sincronizar meta-datos adicionales
        if (!empty($user_data['meta'])) {
            foreach ($user_data['meta'] as $meta_key => $meta_value) {
                // Saltar meta sensible o que no debe sincronizarse
                if (in_array($meta_key, ['session_tokens', 'wp_user-settings', 'wp_user-settings-time'])) {
                    continue;
                }
                
                // Para capacidades y nivel de usuario, procesar especialmente
                if ($meta_key === 'wp_capabilities' || strpos($meta_key, '_capabilities') !== false) {
                    // Ya procesado en sync_user_roles_and_caps
                    continue;
                }
                
                update_user_meta($user_id, $meta_key, $meta_value);
            }
        }
        
        // Marcar última sincronización
        update_user_meta($user_id, 'octo_uc_last_sync', current_time('mysql'));
        update_user_meta($user_id, 'octo_uc_source_id', $user_data['ID']);
        
        // Limpiar caché de usuario
        clean_user_cache($user_id);
        
        OctoUserCopy::log('info', sprintf('Usuario %s: %s (ID: %d)', $action, $user_data['user_login'], $user_id));
        
        return $action;
    }
    
    /**
     * Sincroniza roles y capacidades del usuario
     */
    private function sync_user_roles_and_caps($user_id, $roles, $capabilities) {
        global $wpdb;
        
        // Obtener el prefijo de la tabla para las capacidades
        $cap_key = $wpdb->prefix . 'capabilities';
        $level_key = $wpdb->prefix . 'user_level';
        
        // Preparar array de capacidades basado en roles
        $caps = [];
        
        // Agregar capacidades por roles
        if (!empty($roles)) {
            foreach ($roles as $role) {
                $caps[$role] = true;
            }
        }
        
        // Agregar capacidades individuales si existen
        if (!empty($capabilities)) {
            foreach ($capabilities as $cap => $granted) {
                if ($granted) {
                    $caps[$cap] = true;
                }
            }
        }
        
        // Si no hay capacidades, asignar subscriber por defecto
        if (empty($caps)) {
            $caps['subscriber'] = true;
        }
        
        // Actualizar capacidades en meta
        update_user_meta($user_id, $cap_key, $caps);
        
        // Calcular y actualizar nivel de usuario
        $user_level = 0;
        if (isset($caps['administrator'])) {
            $user_level = 10;
        } elseif (isset($caps['editor'])) {
            $user_level = 7;
        } elseif (isset($caps['author'])) {
            $user_level = 2;
        } elseif (isset($caps['contributor'])) {
            $user_level = 1;
        }
        
        update_user_meta($user_id, $level_key, $user_level);
    }
}