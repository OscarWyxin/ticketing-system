# 🚀 GUÍA DE DEPLOY A HOSTINGER - Sistema de Ticketing

## RESUMEN DE PASOS

1. Crear base de datos en Hostinger
2. Importar estructura y datos
3. Subir archivos PHP
4. Configurar database.php
5. Crear carpeta logs
6. Probar

---

## PASO 1: CREAR BASE DE DATOS EN HOSTINGER

1. Entra a tu **Panel de Hostinger** → https://hpanel.hostinger.com
2. Ve a **Hosting** → Selecciona tu dominio
3. Busca **Bases de datos** → **MySQL Databases**
4. Crea una nueva base de datos:
   - **Nombre de BD:** `ticketing` (Hostinger añadirá prefijo, ej: `u123456789_ticketing`)
   - **Usuario:** `ticketing_user` (igual, quedará `u123456789_ticketing_user`)
   - **Contraseña:** Genera una contraseña segura y GUÁRDALA

5. **ANOTA ESTOS DATOS:**
   ```
   Host: localhost
   Base de datos: u123456789_ticketing  (el nombre completo)
   Usuario: u123456789_ticketing_user   (el nombre completo)
   Contraseña: [la que creaste]
   ```

---

## PASO 2: IMPORTAR LA BASE DE DATOS

1. En el panel de Hostinger, ve a **phpMyAdmin**
2. Selecciona tu base de datos en el menú izquierdo
3. Click en pestaña **Importar** (arriba)
4. **Primera importación - Estructura:**
   - Click "Seleccionar archivo"
   - Sube: `hostinger-deploy/01_structure.sql`
   - Click "Importar"
   - Espera mensaje de éxito ✅

5. **Segunda importación - Datos:**
   - Click "Seleccionar archivo" de nuevo
   - Sube: `hostinger-deploy/02_data.sql`
   - Click "Importar"
   - Espera mensaje de éxito ✅

---

## PASO 3: SUBIR ARCHIVOS AL SERVIDOR

### Opción A: Usando File Manager de Hostinger (MÁS FÁCIL)

1. En panel Hostinger → **File Manager**
2. Navega a `public_html` (o la carpeta de tu dominio)
3. Crea una carpeta llamada `ticketing` (o usa la raíz si es subdominio dedicado)
4. Sube TODOS estos archivos y carpetas:

```
📁 Carpetas a subir:
   /api/          (toda la carpeta)
   /assets/       (toda la carpeta)
   /config/       (toda la carpeta)
   /forms/        (toda la carpeta)

📄 Archivos a subir:
   index.html
   ticket-tracking.php
   form.html
   form-cliente.html
   form-agencia.html
```

5. **CREA la carpeta `logs/`** en el servidor (vacía, para los logs)

### Opción B: Usando FTP (FileZilla)

1. En Hostinger → **Cuentas FTP** → Crea una cuenta o usa la principal
2. Conecta FileZilla:
   - Host: tu-dominio.com
   - Usuario: (el FTP de Hostinger)
   - Puerto: 21
3. Sube los mismos archivos a `public_html/ticketing/`

---

## PASO 4: CONFIGURAR database.php

1. En File Manager, navega a `/ticketing/config/`
2. Abre `database.php` para editar
3. Cambia los valores:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456789_ticketing');      // ← TU nombre de BD real
define('DB_USER', 'u123456789_ticketing_user'); // ← TU usuario real
define('DB_PASS', 'TuContraseñaReal');          // ← TU contraseña real
```

4. **GUARDA** el archivo

---

## PASO 5: CONFIGURAR RUTA DE LOGS

1. Edita `/ticketing/api/ghl-notifications.php`
2. Busca esta línea (cerca de la línea 28):
   ```php
   define('NOTIFICATION_LOG', 'C:\\laragon\\www\\ticketing\\logs\\notifications.log');
   ```
3. **Cámbiala por:**
   ```php
   define('NOTIFICATION_LOG', __DIR__ . '/../logs/notifications.log');
   ```
4. Guarda el archivo

---

## PASO 6: PROBAR LA INSTALACIÓN

1. Abre en tu navegador:
   ```
   https://tu-dominio.com/ticketing/
   ```
   Deberías ver el dashboard del backlog

2. Prueba la API:
   ```
   https://tu-dominio.com/ticketing/api/projects.php?action=list
   ```
   Deberías ver la lista de proyectos en JSON

3. Prueba crear un ticket desde un formulario embebido

---

## PASO 7: ACTUALIZAR URLs EN GHL

Ahora que tienes la URL de producción, actualiza en GHL:

1. **Custom Menu Links** → Cambia las URLs de los formularios:
   ```
   Antes: https://xxxx.loca.lt/forms/form-xxx.html
   Ahora: https://tu-dominio.com/ticketing/forms/form-xxx.html
   ```

2. **Backlog iframe**:
   ```
   https://tu-dominio.com/ticketing/index.html
   ```

---

## ⚠️ ARCHIVOS QUE NO DEBES SUBIR

No subas estos archivos a producción:
- `/backups/` - Backups locales
- `*.md` - Archivos de documentación
- `/database/` - Scripts de setup local
- `.git/` - Control de versiones
- Archivos `*.bak` - Backups
- `setup-test.php`, `install.php`, `validate-integration.php` - Solo para desarrollo

---

## 🔒 SEGURIDAD POST-DEPLOY

1. **Verifica permisos de carpeta logs:**
   - La carpeta `logs/` debe ser escribible (755 o 775)

2. **Protege la carpeta logs con .htaccess:**
   Crea `/ticketing/logs/.htaccess` con:
   ```
   Deny from all
   ```

3. **Verifica que /config/ no sea accesible:**
   Crea `/ticketing/config/.htaccess` con:
   ```
   Deny from all
   ```

---

## 🆘 PROBLEMAS COMUNES

### "Error de conexión a base de datos"
- Verifica los datos en `database.php`
- El host casi siempre es `localhost` en Hostinger

### "Error 500"
- Revisa el error_log de Hostinger (Panel → Logs)
- Verifica que no haya rutas absolutas de Windows

### "CORS error" en los formularios embebidos
- Ya está configurado en el código, debería funcionar
- Si persiste, verifica que las cabeceras lleguen correctamente

### "No se crean logs"
- Verifica que la carpeta `logs/` exista
- Verifica permisos de escritura (755)

---

## ✅ CHECKLIST FINAL

- [ ] Base de datos creada en Hostinger
- [ ] Estructura importada (01_structure.sql)
- [ ] Datos importados (02_data.sql)
- [ ] Archivos subidos a public_html/ticketing/
- [ ] database.php configurado con credenciales reales
- [ ] Ruta de logs actualizada en ghl-notifications.php
- [ ] Carpeta logs/ creada con permisos
- [ ] .htaccess de seguridad en logs/ y config/
- [ ] Probado que carga el dashboard
- [ ] Probado que la API responde
- [ ] URLs actualizadas en GHL

---

¡Listo! Tu sistema de ticketing está en producción 🎉
