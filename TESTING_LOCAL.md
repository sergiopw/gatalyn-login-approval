# Probar el plugin en local con Docker

El plugin se monta directamente desde este repo, así que cualquier cambio en
`includes/`, `assets/` o `gatalyn-login-approval.php` se ve al recargar, sin
reconstruir nada.

## Arrancar

Con Docker Desktop abierto, desde la raíz del repo:

```powershell
.\local-test.ps1
```

(En Git Bash, Linux o macOS: `./local-test.sh`.)

La primera vez tarda un par de minutos en bajar las imágenes. Cuando acaba:

| Qué | Dónde |
| --- | --- |
| WordPress | http://localhost:8080/wp-login.php |
| Correos (Mailpit) | http://localhost:8025 |
| Administrador | `admin` / `admin` |
| Usuario de prueba | `tester` / `tester123` |

El plugin queda activado en modo "Before credentials".

## Entrar

1. En http://localhost:8080/wp-login.php escribe `admin` y envía.
2. Abre http://localhost:8025, abre el correo de aprobación y pulsa el enlace
   de aprobar.
3. La pestaña del login avanza sola: escribe la contraseña `admin`.

## Qué comprobar (1.1.0)

En **Ajustes > Login Approval**:

1. Hay una cuarta pestaña, **Security**, con Limit Login Attempts, Allowed
   Failed Attempts, Lockout Duration y REST API Access.
2. Cambia los valores (por ejemplo: activar el límite, 7 intentos, 30
   minutos, "Hide the users endpoint") y pulsa **Save Changes**.
3. Ve a la pestaña General, vuelve a guardar y regresa a Security: los
   valores deben seguir ahí. Antes de 1.1.0, cada guardado los reseteaba.
4. Con "Hide the users endpoint", en una ventana privada
   http://localhost:8080/?rest_route=/wp/v2/users debe dar 404, y
   http://localhost:8080/?rest_route=/wp/v2/posts debe seguir funcionando.
5. Con el límite activo, en una ventana privada usa el modo "After
   credentials" (pestaña General) y falla la contraseña de `tester` más veces
   de las permitidas: el siguiente intento debe quedar bloqueado y no debe
   llegar ningún correo a Mailpit.

## Parar o empezar de cero

```powershell
docker compose down          # para los contenedores y conserva los datos
.\local-test.ps1 -Reset      # borra la base de datos y vuelve a instalar
```
