# OCTO USER COPY

Plugin de WordPress para sincronizar usuarios entre múltiples sitios de forma segura y automatizada.

## 📋 Descripción

OCTO USER COPY permite sincronizar usuarios, roles y capacidades entre diferentes instalaciones de WordPress. El plugin funciona con una arquitectura emisor-receptor donde un sitio actúa como fuente de datos (emisor) y otros sitios consumen esos datos (receptores).

### Características principales:

- ✅ Sincronización completa de usuarios con contraseñas hasheadas
- ✅ Sincronización de roles y capacidades personalizadas
- ✅ Inserción directa en base de datos para máximo rendimiento
- ✅ API REST segura con autenticación por clave
- ✅ Sincronización automática vía WP Cron
- ✅ Panel de administración con logs detallados
- ✅ Sincronización manual con un clic
- ✅ Filtros para excluir roles específicos
- ✅ Opción para sincronizar solo usuarios activos

## 🚀 Requisitos

- WordPress 5.0 o superior
- PHP 7.4 o superior
- Módulo cURL habilitado
- Acceso a WP Cron (o cron del sistema)

## 📦 Instalación

1. Descarga el plugin y descomprímelo en `/wp-content/plugins/octo-user-copy/`
2. Activa el plugin desde el panel de WordPress
3. Ve a **Herramientas > OCTO USER COPY** para configurar

## ⚙️ Configuración

### Modo Emisor (Sitio fuente)

1. Selecciona **"Emisor (Exporta usuarios)"** en el modo del sitio
2. Se generará automáticamente una clave API
3. Comparte esta clave API con los sitios receptores
4. Opcionalmente configura:
   - Roles a excluir de la sincronización
   - Sincronizar solo usuarios activos (últimos 90 días)

### Modo Receptor (Sitio destino)

1. Selecciona **"Receptor (Importa usuarios)"** en el modo del sitio
2. Ingresa la URL del sitio emisor (sin barra final)
3. Pega la clave API proporcionada por el emisor
4. Configura la frecuencia de sincronización:
   - Cada hora
   - Dos veces al día
   - Diariamente (recomendado)
   - Semanalmente
5. Prueba la conexión con el botón "Probar conexión"
6. Guarda los cambios

## 🔄 Uso

### Sincronización automática

Una vez configurado, el plugin sincronizará automáticamente según la frecuencia establecida.

### Sincronización manual

En modo receptor, puedes forzar una sincronización inmediata:
1. Ve a **Herramientas > OCTO USER COPY**
2. Haz clic en **"Forzar sincronización ahora"**
3. Revisa los logs para ver el resultado

## 📊 Endpoints API (Modo Emisor)

El plugin expone los siguientes endpoints cuando está en modo emisor:

- **Usuarios**: `https://tu-sitio.com/wp-json/usercopy/v1/users?key=TU_CLAVE_API`
- **Roles**: `https://tu-sitio.com/wp-json/usercopy/v1/roles?key=TU_CLAVE_API`
- **Debug**: `https://tu-sitio.com/wp-json/usercopy/v1/debug?key=TU_CLAVE_API`

## 🔒 Seguridad

- Las contraseñas se transfieren ya hasheadas (no en texto plano)
- Autenticación por clave API en todos los endpoints
- Validación de permisos antes de cualquier operación
- Logs detallados de todas las operaciones
- No se sincronizan tokens de sesión ni datos sensibles

## 📝 Logs y monitoreo

El plugin mantiene un registro detallado de:
- Intentos de sincronización
- Usuarios creados/actualizados
- Errores y advertencias
- Accesos a la API

Los logs se pueden ver en **Herramientas > OCTO USER COPY** en la barra lateral.

## 🛠️ Solución de problemas

### "Error de conexión"
- Verifica que la URL del emisor sea correcta
- Asegúrate de que el sitio emisor esté accesible
- Comprueba que el plugin esté activo en modo emisor en el sitio fuente

### "Clave API incorrecta"
- Verifica que la clave API sea exactamente la misma (sin espacios)
- Asegúrate de que el sitio emisor esté en modo "Emisor"

### "No se sincronizan usuarios"
- Revisa los filtros de roles excluidos
- Verifica en los logs si hay errores específicos
- Comprueba que el cron de WordPress esté funcionando

## 🤝 Soporte

- **Autor**: Octonove
- **Sitio web**: [https://octonove.com](https://octonove.com)
- **Versión**: 1.0.0
- **Licencia**: GPL v2 o posterior

## 📄 Changelog

### 1.0.0 (2025-01-20)
- Lanzamiento inicial
- Sincronización bidireccional emisor-receptor
- API REST segura
- Panel de administración completo
- Logs detallados
- Sincronización automática y manual

## ⚡ Rendimiento

El plugin utiliza inserción directa en base de datos para máximo rendimiento:
- Procesa miles de usuarios sin timeout
- Actualización incremental (solo cambios)
- Bajo consumo de memoria
- Compatible con grandes instalaciones

## 🔧 Hooks y filtros

### Filtros disponibles:

```php
// Personalizar meta-datos exportables
add_filter('octo_uc_exportable_user_meta', function($keys) {
    $keys[] = 'mi_campo_personalizado';
    return $keys;
});

// Añadir prefijos de meta a exportar
add_filter('octo_uc_exportable_meta_prefixes', function($prefixes) {
    $prefixes[] = 'mi_prefijo_';
    return $prefixes;
});
```

## 🚨 Importante

- Realiza siempre copias de seguridad antes de sincronizar
- Prueba primero en un entorno de desarrollo
- Los usuarios sincronizados mantienen sus contraseñas originales
- Los roles personalizados se crean automáticamente si no existen