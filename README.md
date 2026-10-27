# WPCore

Reinstalador del núcleo de WordPress desde la terminal, pensado para limpiar sitios infectados.

Reemplaza `wp-admin/`, `wp-includes/` y los archivos raíz del núcleo por una copia limpia descargada de wordpress.org y verificada con los checksums oficiales. Conserva `wp-config.php`, `wp-content/` y `.htaccess`.

```
  ██╗    ██╗██████╗  ██████╗ ██████╗ ██████╗ ███████╗
  ██║    ██║██╔══██╗██╔════╝██╔═══██╗██╔══██╗██╔════╝
  ██║ █╗ ██║██████╔╝██║     ██║   ██║██████╔╝█████╗
  ██║███╗██║██╔═══╝ ██║     ██║   ██║██╔══██╗██╔══╝
  ╚███╔███╔╝██║     ╚██████╗╚██████╔╝██║  ██║███████╗
   ╚══╝╚══╝ ╚═╝      ╚═════╝ ╚═════╝ ╚═╝  ╚═╝╚══════╝
  Reinstalador del núcleo de WordPress  ·  v2.0.0
```

## Requisitos

- PHP 7.4 o superior (CLI)
- Extensión `curl` o `allow_url_fopen=On`
- Extensión `zip` o el comando `unzip`
- Acceso SSH al servidor

## Instalación

Desde la carpeta de la cuenta del cliente (por ejemplo `/home/cliente`) o desde la raíz de su WordPress:

```bash
curl -fsSLO https://raw.githubusercontent.com/willdotcom/wpcore/main/wpcore.php
php wpcore.php
```

Si lo ejecutas en `/home/cliente`, encuentra solo el WordPress en `public_html`, `www` o `htdocs`, y el script queda fuera de la carpeta pública.

El script solo funciona desde la terminal: si alguien lo abre desde el navegador responde 403. Aun así, bórralo del servidor cuando termines.

## Uso

Sin argumentos se abre el asistente interactivo. Muestra los datos del sitio y un menú que se maneja con las flechas, espacio para marcar y enter para confirmar. Si la terminal no admite flechas, las opciones se eligen por número.

¿Elegiste mal? Pulsa **←** o **Esc** (o `0` en el modo por números) para volver al paso anterior. La confirmación final también ofrece **Volver y cambiar opciones**, y nada se modifica hasta que eliges **Iniciar la reinstalación**. Si la cuenta tiene varios WordPress, el menú principal incluye **Cambiar de sitio**.

```bash
php wpcore.php
```

### Comandos

| Comando | Qué hace |
| --- | --- |
| *(ninguno)* | Abre el asistente interactivo |
| `reinstall` | Reinstala el núcleo de WordPress |
| `verify` | Compara cada archivo del núcleo con los checksums oficiales, sin cambiar nada |
| `scan` | Busca código malicioso en `wp-content/` y en la raíz (heurístico) |
| `salts` | Regenera las claves SALT de `wp-config.php` |
| `help` | Muestra la ayuda |

### Opciones

| Opción | Descripción |
| --- | --- |
| `--path=<dir>` | Ruta de WordPress. Por defecto busca en el directorio actual, luego hacia arriba y después en sus carpetas `public_html`, `www` o `htdocs` |
| `--version=<v>` | Versión a instalar: `current`, `latest` o un número como `6.4.3` |
| `-y`, `--yes` | Modo desatendido: acepta los valores por defecto |
| `--no-backup` | No crear el respaldo `.zip` del núcleo actual |
| `--no-salts` | No regenerar las claves SALT |
| `--keep-unknown` | No eliminar archivos extraños de la raíz |
| `--fix-perms` | Corregir permisos (carpetas 755, archivos 644) |
| `--report=<archivo>` | Guardar el reporte de `verify` o `scan` en un archivo |
| `--no-color` | Desactivar los colores |

### Ejemplos

```bash
# Asistente interactivo
php wpcore.php

# Reinstalar la misma versión sin preguntas
php wpcore.php reinstall --version=current -y

# Diagnóstico de otro sitio, guardando el reporte
php wpcore.php verify --path=/home/cliente/public_html --report=/home/cliente/reporte.txt
```

## Qué hace `reinstall`

1. **Versión.** Consulta wordpress.org y ofrece la versión instalada o la última estable. Descarga los checksums oficiales de esa versión.
2. **Análisis y opciones.** Lista los archivos del núcleo modificados, los inyectados en `wp-admin`/`wp-includes`, los PHP dentro de `uploads` y los archivos extraños de la raíz. Eliges cuáles de estos últimos borrar.
3. **Confirmación.** Muestra un resumen de qué se reemplaza, qué se elimina y qué se conserva.
4. **Reinstalación.**
   - Crea un respaldo `.zip` del núcleo actual, fuera de la carpeta pública cuando es posible.
   - Descarga el paquete `no-content`, lo extrae y lo verifica con checksums. Si algo no coincide, se detiene sin tocar el sitio.
   - Elimina el núcleo viejo y coloca el nuevo. El sitio queda caído solo unos segundos, y durante este paso Ctrl+C espera a que termine.
   - Si se ejecuta como root, devuelve los archivos al dueño de `wp-content/`.
   - Opcional: regenera las claves SALT y corrige permisos.
   - Verificación final con checksums.

`wp-content/` no se modifica. Los PHP sospechosos que haya en `uploads` se informan para que los revises a mano.

## Cómo decide qué está infectado

| Categoría | Criterio |
| --- | --- |
| **Modificados** | El MD5 del archivo no coincide con el checksum oficial de wordpress.org para esa versión. |
| **Faltantes** | El checksum oficial lista el archivo, pero no existe. |
| **Inyectados** | Archivo con extensión ejecutable (`.php`, `.phtml`, `.js`, `.html`, `.htaccess`…) dentro de `wp-admin/` o `wp-includes/` que no está en la lista oficial. |
| **Otros no oficiales** | Archivos no ejecutables que tampoco están en la lista oficial. Se eliminan al reinstalar, pero no se consideran una amenaza. Se ignoran `error_log`, `.DS_Store` y `Thumbs.db`. |
| **PHP en uploads** | Se lee el contenido. Se descartan los que no tienen código: `index.php` vacíos o con solo comentarios, y los archivos `._` de macOS. Los que coinciden con firmas de malware se marcan como **malware**; el resto, como **para revisar**. |
| **`scan`** | Busca en el contenido patrones típicos de malware: `eval(base64_decode(`, ejecución de `$_POST`/`$_GET`, `include` de archivos `.ico` o `.png`, web shells conocidas, ofuscación hexadecimal o base64. Es heurístico: revisa cada archivo antes de borrarlo. |

## Después de limpiar

1. Cambia las contraseñas de los administradores, FTP/SFTP y base de datos.
2. Revisa plugins y temas con `php wpcore.php scan`.
3. Revisa `.htaccess` y los usuarios administradores.
4. Elimina plugins o temas abandonados o "nulled" y actualiza el resto.
5. Borra el respaldo `wpcore-backup-*.zip` cuando ya no lo necesites: contiene el núcleo infectado.
