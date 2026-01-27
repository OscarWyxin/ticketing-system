# 🐳 GUÍA DE DEPLOY - VPS con Docker (Hostinger)

## REQUISITOS EN EL VPS

- Docker instalado
- Docker Compose instalado
- Git (opcional, para clonar)

---

## PASO 1: CONECTAR AL VPS

```bash
ssh usuario@tu-ip-del-vps
```

---

## PASO 2: CREAR DIRECTORIO Y SUBIR ARCHIVOS

### Opción A: Usando Git (recomendado)

```bash
cd /opt
git clone https://tu-repo.git ticketing
cd ticketing
```

### Opción B: Usando SCP (desde tu PC Windows)

En PowerShell de tu PC:
```powershell
# Comprimir el proyecto
Compress-Archive -Path "C:\Users\Skar\Desktop\Ticketing System\*" -DestinationPath "C:\Users\Skar\Desktop\ticketing.zip"

# Subir al VPS
scp "C:\Users\Skar\Desktop\ticketing.zip" usuario@tu-ip:/opt/

# En el VPS, descomprimir
ssh usuario@tu-ip
cd /opt
unzip ticketing.zip -d ticketing
cd ticketing
```

### Opción C: Usando SFTP (FileZilla)

1. Conecta FileZilla a tu VPS (usa SFTP, puerto 22)
2. Sube toda la carpeta a `/opt/ticketing/`

---

## PASO 3: CONFIGURAR VARIABLES DE ENTORNO

```bash
cd /opt/ticketing

# Copiar el archivo de ejemplo
cp .env.example .env

# Editar con contraseñas seguras
nano .env
```

Contenido del `.env`:
```
DB_PASSWORD=TuContraseñaSegura123!
MYSQL_ROOT_PASSWORD=OtraContraseñaSegura456!
APP_PORT=8080
```

**⚠️ IMPORTANTE:** Usa contraseñas seguras y únicas.

---

## PASO 4: INICIAR CON DOCKER COMPOSE

```bash
cd /opt/ticketing

# Construir y levantar los contenedores
docker-compose up -d --build

# Ver los logs (para verificar que todo inicie bien)
docker-compose logs -f
```

Espera a ver mensajes como:
```
ticketing-db    | ready for connections
ticketing-app   | Apache/2.4.x (Debian) PHP/8.3.x configured
```

Presiona `Ctrl+C` para salir de los logs.

---

## PASO 5: VERIFICAR QUE FUNCIONA

```bash
# Ver contenedores corriendo
docker ps

# Deberías ver:
# ticketing-app   (puerto 8080:80)
# ticketing-db    (puerto 3306:3306)
```

Prueba en el navegador:
```
http://tu-ip-del-vps:8080/
```

---

## PASO 6: CONFIGURAR DOMINIO (Nginx Reverse Proxy)

Si quieres usar un dominio (ej: `tickets.tudominio.com`):

### Instalar Nginx (si no está)
```bash
apt update && apt install nginx -y
```

### Crear configuración del sitio
```bash
nano /etc/nginx/sites-available/ticketing
```

Contenido:
```nginx
server {
    listen 80;
    server_name tickets.tudominio.com;  # ← Tu dominio

    location / {
        proxy_pass http://localhost:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_cache_bypass $http_upgrade;
    }
}
```

### Activar el sitio
```bash
ln -s /etc/nginx/sites-available/ticketing /etc/nginx/sites-enabled/
nginx -t  # Verificar sintaxis
systemctl reload nginx
```

---

## PASO 7: CERTIFICADO SSL (HTTPS)

```bash
# Instalar Certbot
apt install certbot python3-certbot-nginx -y

# Obtener certificado
certbot --nginx -d tickets.tudominio.com

# Renovación automática (ya configurada)
```

Ahora puedes acceder por:
```
https://tickets.tudominio.com/
```

---

## COMANDOS ÚTILES

### Ver logs de la aplicación
```bash
docker-compose logs -f app
```

### Ver logs de la base de datos
```bash
docker-compose logs -f db
```

### Reiniciar servicios
```bash
docker-compose restart
```

### Parar todo
```bash
docker-compose down
```

### Parar y eliminar volúmenes (⚠️ BORRA LA BD)
```bash
docker-compose down -v
```

### Reconstruir después de cambios
```bash
docker-compose up -d --build
```

### Entrar al contenedor de la app
```bash
docker exec -it ticketing-app bash
```

### Entrar a MySQL
```bash
docker exec -it ticketing-db mysql -u ticketing_user -p ticketing_system
```

---

## ESTRUCTURA DE ARCHIVOS EN EL VPS

```
/opt/ticketing/
├── docker-compose.yml
├── Dockerfile
├── .env                 ← Tus contraseñas (NO subir a Git)
├── api/
├── assets/
├── config/
├── forms/
├── logs/               ← Persistido en volumen
├── hostinger-deploy/
│   ├── 01_structure.sql
│   └── 02_data.sql
├── index.html
└── ticket-tracking.php
```

---

## BACKUP DE LA BASE DE DATOS

### Crear backup
```bash
docker exec ticketing-db mysqldump -u root -p ticketing_system > backup_$(date +%Y%m%d).sql
```

### Restaurar backup
```bash
cat backup_20260127.sql | docker exec -i ticketing-db mysql -u root -p ticketing_system
```

---

## ACTUALIZAR LA APLICACIÓN

```bash
cd /opt/ticketing

# Si usas Git
git pull

# Reconstruir
docker-compose up -d --build
```

---

## TROUBLESHOOTING

### "Connection refused" al conectar
```bash
# Verificar que Docker esté corriendo
docker ps

# Ver logs de error
docker-compose logs
```

### "Access denied" en la base de datos
```bash
# Verificar variables de entorno
cat .env

# Reiniciar contenedores
docker-compose down
docker-compose up -d
```

### La BD no se inicializa con los datos
```bash
# Borrar volumen y recrear
docker-compose down -v
docker-compose up -d
```

### Cambiar el puerto
Edita `.env`:
```
APP_PORT=3000
```
Y en `docker-compose.yml`:
```yaml
ports:
  - "${APP_PORT:-8080}:80"
```

---

## ✅ CHECKLIST FINAL

- [ ] Archivos subidos a `/opt/ticketing/`
- [ ] `.env` configurado con contraseñas seguras
- [ ] `docker-compose up -d --build` ejecutado
- [ ] Contenedores corriendo (`docker ps`)
- [ ] Aplicación accesible en `http://ip:8080`
- [ ] (Opcional) Nginx configurado con dominio
- [ ] (Opcional) SSL con Certbot

---

¡Tu sistema de ticketing está corriendo en Docker! 🐳🎉
