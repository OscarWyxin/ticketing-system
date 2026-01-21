# 🎫 Sistema de Ticketing GHL

Un moderno sistema de gestión de tickets completamente embebible en GoHighLevel (GHL), con interfaz profesional, filtros avanzados y vistas múltiples.

## ✨ Características

- **Dashboard Analítico** - Estadísticas en tiempo real, gráficos por categoría, agente y cliente
- **Gestión Completa de Tickets** - CRUD con estados, prioridades y categorías
- **Filtros Avanzados** - Por estado, tipo, prioridad, categoría, usuario asignado y fecha
- **Vistas Múltiples** - Lista (tabla) y Grid (cards) intercambiables
- **Integración GHL** - Sincronización con GoHighLevel, webhooks y notificaciones
- **Formularios Públicos** - Para clientes sin login (embebible en iframe)
- **Sistema de Comentarios** - Comunicación interna y con clientes
- **Historial de Actividad** - Audit log completo
- **Responsive Design** - Funciona en desktop, tablet y mobile

## 🚀 Stack Tecnológico

- **Frontend**: HTML5, CSS3, JavaScript vanilla (ES6+)
- **Backend**: PHP 7.4+ con PDO
- **Database**: MySQL 5.7+
- **External**: GoHighLevel API
- **No Dependencies**: Cero frameworks, código puro y directo

## 📋 Requisitos

- PHP 7.4 o superior
- MySQL 5.7 o superior
- Servidor Web (Apache/Nginx)
- Navegador moderno (Chrome, Firefox, Safari, Edge)

## 🔧 Instalación Rápida

### Con XAMPP (Windows)

```bash
# 1. Instalar XAMPP
winget install ApacheFriends.Xampp.8.2

# 2. Clonar proyecto
git clone https://github.com/tu-usuario/ticketing-system.git
cd ticketing-system

# 3. Copiar a htdocs
Copy-Item . C:\xampp\htdocs\ticketing -Recurse

# 4. Iniciar Apache y MySQL en XAMPP Control Panel

# 5. Abrir navegador
# http://localhost/ticketing/setup.php
```

### Con PHP Built-in

```bash
# Clonar
git clone https://github.com/tu-usuario/ticketing-system.git
cd ticketing-system

# Crear BD
mysql -u root -p < database/schema.sql

# Servir
php -S localhost:8000

# Acceder
# http://localhost:8000/index.html
```

## 📱 URLs Principales

| URL | Descripción |
|-----|-------------|
| `/index.html` | Dashboard principal de agentes |
| `/form.html` | Formulario público para clientes |
| `/form-agencia.html` | Formulario interno para staff |
| `/test.html` | Panel de pruebas y diagnóstico |

## 🔌 API Endpoints

### Tickets

```
GET    /api/tickets.php?action=list
GET    /api/tickets.php?action=get&id=X
POST   /api/tickets.php?action=create
PUT    /api/tickets.php?action=update&id=X
DELETE /api/tickets.php?action=delete&id=X
GET    /api/tickets.php?action=stats
```

### Filtros Disponibles

```
?status=open|in_progress|waiting|resolved|closed
?priority=urgent|high|medium|low
?category=ID
?type=internal|external|form|api
?assigned=USER_ID
?date=YYYY-MM-DD
?search=texto
?page=1&limit=20
```

### Helpers

```
GET /api/helpers.php?action=categories
GET /api/helpers.php?action=users
GET /api/helpers.php?action=agents
GET /api/helpers.php?action=tags
```

## 📊 Base de Datos

### Tablas Principales

| Tabla | Descripción |
|-------|-------------|
| `users` | Agentes, admins, clientes |
| `accounts` | Sub-cuentas/locations GHL |
| `categories` | Categorías de tickets |
| `tickets` | Ticket principal |
| `comments` | Comentarios/respuestas |
| `attachments` | Archivos adjuntos |
| `activity_log` | Historial de cambios |
| `tags` | Etiquetas |

## 🔐 Credenciales por Defecto

Luego de ejecutar `setup.php`:

```
Email: admin@ticketing.local
Rol: Administrador de Agencia
Contraseña: (configurar en primer login)
```

## 🔗 Integración GoHighLevel

### Configuración

1. Editar `api/ghl.php`:

```php
define('GHL_API_KEY', 'pit-XXXXX');
define('GHL_LOCATION_ID', 'XXXXX');
define('GHL_COMPANY_ID', 'XXXXX');
```

2. Desde dashboard, hacer clic en "Sincronizar GHL"

### Webhooks

Los tickets públicos se sincronizan automáticamente a GHL.

## 🎨 Personalización

### Cambiar Tema de Color

Editar `assets/css/styles.css`:

```css
:root {
    --primary: #6366f1;        /* Azul indigo por defecto */
    --primary-light: #818cf8;
    --primary-dark: #4f46e5;
    /* Cambiar estos valores */
}
```

### Vistas Disponibles

- **Lista**: Tabla tradicional con todas las columnas
- **Grid**: Cards modernas, mejor para mobile

Cambiar con botones de toggle en la esquina superior derecha.

## 📦 Estructura

```
ticketing-system/
├── index.html              # Dashboard principal
├── form.html               # Formulario público
├── form-agencia.html       # Formulario agengia
├── form-cliente.html       # Formulario cliente
├── setup.php               # Setup inicial
├── test.html               # Panel de diagnóstico
├── api/
│   ├── tickets.php
│   ├── helpers.php
│   ├── ghl.php
│   ├── ghl-notifications.php
│   └── test.php
├── assets/
│   ├── css/styles.css
│   └── js/app.js
├── config/
│   └── database.php
├── database/
│   └── schema.sql
└── logs/
```

## 🔨 Desarrollo

### Agregar un Nuevo Filtro

1. **HTML** (`index.html`):
```html
<div class="filter-group">
    <label>Mi Filtro</label>
    <select id="filter-mio">
        <option value="">Todos</option>
    </select>
</div>
```

2. **JavaScript** (`assets/js/app.js`):
```javascript
document.getElementById('filter-mio')?.addEventListener('change', (e) => {
    state.filters.mio = e.target.value;
    state.pagination.page = 1;
    loadTickets();
});
```

3. **PHP** (`api/tickets.php`):
```php
if ($mio = $_GET['mio'] ?? '') {
    $where[] = "t.mi_campo = ?";
    $params[] = $mio;
}
```

## 🐛 Troubleshooting

| Error | Solución |
|-------|----------|
| "No se puede conectar a BD" | Verificar MySQL está corriendo, revisar config/database.php |
| "JSON inválido" | Limpiar caché (Ctrl+Shift+Del), revisar logs/php-errors.log |
| "Filtros no funcionan" | Abrir Developer Tools (F12), revisar Console |
| "Tabla vacía" | Visitar /test.html para diagnóstico |

## 📊 Performance

- **Paginación**: 20 items por página por defecto
- **Indexes**: Todos los campos filtrados tienen indexes
- **Caché**: localStorage para preferencias usuario
- **Lazy Load**: Comentarios cargan bajo demanda

## 🔒 Seguridad

- PDO Prepared Statements (SQL Injection protection)
- CORS headers configurados
- Password hashing ready (PHP password_hash)
- Activity logging para auditoría

## 📞 Soporte

Para issues o preguntas:
- Abrir un [GitHub Issue](https://github.com/tu-usuario/ticketing-system/issues)
- Email: soporte@tu-dominio.com

## 📄 Licencia

MIT License - libre para uso comercial y personal

## 🤝 Contribuir

Las pull requests son bienvenidas. Para cambios mayores, abrir un issue primero.

## 👨‍💻 Autor

Desarrollado con ❤️ para GoHighLevel

---

**Versión**: 1.0.0  
**Última actualización**: Enero 2026
