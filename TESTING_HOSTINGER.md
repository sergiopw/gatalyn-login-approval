# Guía de Testing - WP Login Approval en Hostinger

## Instalación Rápida

1. **Descargar el ZIP**: `dist/gatalyn-login-approval.zip`
2. **En cPanel de Hostinger**:
   - File Manager → `/public_html/wp-content/plugins/`
   - Elimina `gatalyn-login-approval` si existe
   - Sube el ZIP nuevo
   - Extrae aquí
3. **Activa el plugin** en WordPress admin

## Testing Flujo "Before" Mode

### Escenario 1: PC (Desktop)

```
1. Abre Firefox/Chrome en la PC
2. Accede a wp-login.php
3. Ingresa usuario (ej: admin)
4. Se envía email
5. Página muestra "Waiting for approval..."
   - Abre Firefox Developer Tools (F12)
   - Ve Console para logs de polling
```

### Escenario 2: Móvil (Aprobación)

```
1. Abre el correo en tu teléfono
2. Haz clic en "Approve Login"
3. Verás redirección a login form
```

### Resultado Esperado

```
- PC debería redirigirse automáticamente
- Browser console (PC) mostrará:
  [WLA] Initialized polling for request_id: (id aquí)
  [WLA] Poll #1 checking: https://tudominio.com/wp-login.php?action=wla_check_approval&request_id=...
  ...
  [WLA] Status: approved
  [WLA] Approved! Redirecting to: https://tudominio.com/wp-login.php?wla_approved=1&...
```

## Debugging - Browser Console Logs

### Abre la consola del navegador:

- **Chrome/Firefox**: F12 → Console
- **Safari**: Cmd+Option+I → Console

### Busca logs [WLA]:

```
[WLA] Initialized polling for request_id: 12a4b5c6d7e8f9...
[WLA] Poll #1 checking: https://...
[WLA] Response received, status: 200
[WLA] JSON parsed: {success: true, data: {status: "pending"}}
[WLA] Status: pending
[WLA] Scheduling next poll in 3s
...
[WLA] Status: approved
[WLA] Approved! Redirecting to: https://...
```

### Si hay errores:

```
[WLA] Fetch error: Network error or timeout
[WLA] JSON parse error: Unexpected token < in JSON at position 0
[WLA] Unexpected payload: {success: false, ...}
```

## Troubleshooting

### Problema: Polling no comienza

**Sol**: El `request_id` no se está pasando a `show_pending_page()`

- Verifica en Console que dice `Initialized polling...`
- Si no aparece, el ID está vacío

### Problema: "JSON parse error: Unexpected token <"

**Sol**: El endpoint está devolviendo HTML en lugar de JSON

- Puede ser un error PHP (500, blank page)
- Verifica logs de Hostinger en cPanel

### Problema: Poll detiene después de N intentos

**Sol**: Timeout o límite de 600 polls (30 minutos)

- Recarga la página
- Vuelve a hacer clic en el enlace de aprobación

### Problema: Redirect URL no es correcto

**Sol**: El `$status['user_login']` o `session_token` no se guardó

- Verifica que `update_request_status()` almacena correctamente ambos

## Verificar Almacenamiento en Base de Datos

En phpMyAdmin (Hostinger → cPanel):

```sql
-- Verificar que el estado se guardó como opción
SELECT option_name, option_value
FROM wp_options
WHERE option_name LIKE 'gatalyn_request_%'
LIMIT 10;

-- Ver recientes:
SELECT * FROM wp_options
WHERE option_id > 100
ORDER BY option_id DESC
LIMIT 20;
```

## URL de Prueba Manual

Puedes probar el endpoint de revisión manualmente:

```
https://tu-dominio.com/wp-login.php?action=wla_check_approval&request_id=12a4b5c6d7e8f9...

Debería devolver JSON:
{"success":true,"data":{"status":"pending"}}

O cuando está aprobado:
{"success":true,"data":{"status":"approved","redirect_url":"https://..."}}
```

## Tips para Hostinger

1. **Caché**: Asegúrate que el caché esté deshabilitado para `/wp-login.php`
2. **AJAX**: Hostinger puede tener restricciones en AJAX - hemos agregado fallback a `wp_options`
3. **Logs PHP**: En cPanel → Metrics → Error Log
4. **wp-config.php**: Agrega para debugging:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```

## Próximos Pasos

1. Descarga e instala el ZIP actualizado
2. Prueba el flujo "before" con 2 dispositivos
3. Abre la consola del navegador (F12) para ver los logs [WLA]
4. Reporta cualquier error que veas en la consola

¡El polling ahora tiene logging completo para facilitar el debugging!
