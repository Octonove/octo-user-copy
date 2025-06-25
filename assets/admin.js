/**
 * OCTO USER COPY - Admin JavaScript
 */

jQuery(document).ready(function($) {
    // Mostrar/ocultar campos según el modo
    $('#octo_uc_mode').on('change', function() {
        if ($(this).val() === 'receiver') {
            $('.octo-uc-receiver-only').show();
            // Hacer el campo de API key editable en receptor
            $('#octo_uc_api_key').prop('readonly', false);
        } else {
            $('.octo-uc-receiver-only').hide();
            // Hacer el campo de API key solo lectura en emisor
            $('#octo_uc_api_key').prop('readonly', true);
        }
    });
    
    // Forzar sincronización
    $('#force-sync').on('click', function() {
        var $button = $(this);
        var $spinner = $button.next('.spinner');
        var $message = $('#sync-message');
        
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $message.html('<span style="color: #0073aa;">' + octoUC.strings.syncing + '</span>');
        
        $.ajax({
            url: octoUC.ajaxurl,
            type: 'POST',
            data: {
                action: 'octo_uc_force_sync',
                nonce: octoUC.nonce
            },
            dataType: 'json',
            timeout: 60000, // 60 segundos de timeout
            success: function(response) {
                console.log('Respuesta recibida:', response);
                
                if (response.success) {
                    var message = response.data.message || octoUC.strings.success;
                    $message.html('<span style="color: #46b450;">' + message + '</span>');
                    
                    // Mostrar estadísticas si están disponibles
                    if (response.data.stats) {
                        var stats = response.data.stats;
                        var statsMsg = '<br><small>';
                        if (stats.created) statsMsg += 'Creados: ' + stats.created + ' ';
                        if (stats.updated) statsMsg += 'Actualizados: ' + stats.updated + ' ';
                        if (stats.skipped) statsMsg += 'Omitidos: ' + stats.skipped + ' ';
                        if (stats.errors) statsMsg += 'Errores: ' + stats.errors;
                        statsMsg += '</small>';
                        $message.append(statsMsg);
                    }
                    
                    // Recargar la página después de 3 segundos para mostrar los nuevos logs
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    var errorMsg = (response.data && response.data.message) ? response.data.message : 'Error desconocido';
                    $message.html('<span style="color: #dc3232;">' + octoUC.strings.error + ' ' + errorMsg + '</span>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error AJAX:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText,
                    responseJSON: xhr.responseJSON
                });
                
                var errorMessage = 'Error de conexión';
                
                // Intentar obtener más información del error
                if (xhr.status === 400) {
                    errorMessage = 'Solicitud incorrecta (400)';
                } else if (xhr.status === 500) {
                    errorMessage = 'Error del servidor (500)';
                } else if (status === 'timeout') {
                    errorMessage = 'Tiempo de espera agotado';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                } else if (xhr.responseText) {
                    // Intentar parsear la respuesta si no es JSON
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.data && response.data.message) {
                            errorMessage = response.data.message;
                        }
                    } catch(e) {
                        // Si no es JSON válido, mostrar los primeros 100 caracteres
                        errorMessage = 'Error: ' + xhr.responseText.substring(0, 100) + '...';
                    }
                }
                
                $message.html('<span style="color: #dc3232;">' + octoUC.strings.error + ' ' + errorMessage + '</span>');
                
                // Si la sincronización funcionó pero hay error en la respuesta, recargar igualmente
                if (xhr.responseText && xhr.responseText.includes('success')) {
                    $message.append('<br><small>Nota: La sincronización puede haberse completado. Recargando...</small>');
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
    
    // Probar conexión
    $('#test-connection').on('click', function() {
        var $button = $(this);
        var $message = $('<span style="margin-left: 10px;"></span>');
        
        $button.prop('disabled', true);
        $button.after($message);
        $message.html('<span style="color: #0073aa;">' + octoUC.strings.testing + '</span>');
        
        $.post(octoUC.ajaxurl, {
            action: 'octo_uc_test_connection',
            nonce: octoUC.nonce
        })
        .done(function(response) {
              console.log(response);
            if (response.success) {
                $message.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
            } else {
                $message.html('<span style="color: #dc3232;">✗ ' + response.data.message + '</span>');
            }
        })
        .fail(function(xhr) {
            console.error('Test connection error:', xhr);
            $message.html('<span style="color: #dc3232;">✗ Error de conexión</span>');
        })
        .always(function() {
            $button.prop('disabled', false);
            
            // Remover mensaje después de 5 segundos
            setTimeout(function() {
                $message.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        });
    });
    
    // Generar nueva API key (solo para emisor)
    $('#generate-api-key').on('click', function() {
        var $button = $(this);
        var $input = $('#octo_uc_api_key');
        
        $button.prop('disabled', true);
        $button.text(octoUC.strings.generating);
        
        $.post(octoUC.ajaxurl, {
            action: 'octo_uc_generate_api_key',
            nonce: octoUC.nonce
        })
        .done(function(response) {
            if (response.success) {
                $input.val(response.data.key);
                $button.remove(); // Remover el botón después de generar
            }
        })
        .fail(function() {
            alert('Error al generar la clave');
        })
        .always(function() {
            $button.prop('disabled', false);
            $button.text('Generar clave');
        });
    });
    
    // Mejorar la visualización de detalles en los logs
    $('details').on('toggle', function() {
        if ($(this).prop('open')) {
            $(this).find('pre').css('max-height', '300px');
        }
    });
    
    // Copiar al portapapeles con feedback visual
    $('.button').filter(function() {
        return $(this).text().trim() === octoUC.strings.copy || $(this).text().trim() === 'Copiar';
    }).on('click', function() {
        var $button = $(this);
        var originalText = $button.text();
        
        $button.text('✓ Copiado');
        setTimeout(function() {
            $button.text(originalText);
        }, 2000);
    });
});