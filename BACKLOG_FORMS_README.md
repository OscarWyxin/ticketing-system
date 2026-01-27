# Backlog Consultoría & Formularios Embebibles

## ✅ Implementado

### 1. Backlog Consultoría (Sistema de Gestión)

#### Nuevas Características:
- **Campo en BD**: Se agregó columna `backlog` (BOOLEAN, default FALSE) a la tabla `tickets`
- **Vista Backlog**: Nueva sección "Backlog Consultoría" en el sidebar de la app
- **Filtro Automático**: Muestra solo tickets sin asignar (`assigned_to IS NULL`) marcados como backlog (`backlog = TRUE`)
- **Botón "Tomar"**: Permite asignar un ticket del backlog a un agente
- **Salida Automática**: Cuando se asigna un ticket, sale del backlog (`backlog = FALSE`)

#### Endpoint API:
- **GET** `api/tickets.php?action=backlog`
- Retorna: Array de tickets del backlog + total count
- Formato: Sin asignar + marcados como backlog, ordenados por prioridad

#### UI/UX:
- Icono: Inbox (`fas fa-inbox`)
- Badge: Muestra cantidad de tickets en backlog
- Tabla: Moestra Ticket#, Proyecto, Título, Prioridad, Categoría, Fecha, Acciones
- Modal de Asignación: Al hacer clic "Tomar", aparece selector de agentes
- Estado Vacío: Mensaje amigable cuando no hay tickets

---

### 2. Formularios Embebibles (4 Proyectos)

Cada formulario es **independiente** y puede incrustarse en sitios externos.

#### Formularios Creados:

| Proyecto | Archivo | URL | Gradient |
|----------|---------|-----|----------|
| **IMP** | `forms/form-imp.html` | `/forms/form-imp.html` | Púrpura/Violeta |
| **Soul Tech IA** | `forms/form-soultech.html` | `/forms/form-soultech.html` | Rosa/Rojo |
| **Despacho Briones** | `forms/form-despacho.html` | `/forms/form-despacho.html` | Azul Cian |
| **CMP** | `forms/form-cmp.html` | `/forms/form-cmp.html` | Rosa/Amarillo |

#### Características Comunes:

✅ **Campos**:
- Título (requerido)
- Descripción (requerido)
- Nombre contacto (opcional)
- Email contacto (opcional)
- Teléfono contacto (opcional)
- Categoría (dropdown, cargado dinámicamente)
- Prioridad (predefinida: Media/Alta/Urgente/Baja)

✅ **Comportamiento**:
- Carga categorías desde API automáticamente
- Limpia campos vacíos antes de enviar
- Valida campos requeridos
- Muestra spinner durante envío
- Notifica al sistema padre (si está embebido)
- Se recarga tras crear ticket exitoso

✅ **Asignación Automática**:
```javascript
data.assigned_to = 3;        // Alfonso Bello (inicial)
data.source = 'embedded_form'; 
data.work_type = 'puntual';  // Por defecto
data.backlog = true;         // Entra al backlog
data.project_id = X;         // ID específico del proyecto
```

✅ **Notificación PostMessage**:
```javascript
window.parent.postMessage({
    type: 'ticket-created',
    ticket: result.data
}, '*');
```

---

## 📋 Cómo Usar

### En el Sistema Principal

1. **Ver Backlog**: Haz clic en "Backlog Consultoría" en el sidebar
2. **Tomar Ticket**: Haz clic en "Tomar" en la fila del ticket
3. **Asignar**: Selecciona un agente del dropdown y confirma
4. **Resultado**: El ticket sale del backlog y aparece en "Todos los tickets"

### Embeber Formularios en Sitios Externos

```html
<!-- Ejemplo: IMP -->
<iframe 
    src="http://localhost/Ticketing%20System/forms/form-imp.html"
    width="500"
    height="600"
    frameborder="0"
    style="border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
</iframe>
```

**Notas**:
- Ajusta `src` según tu dominio/IP
- El formulario se adapta automáticamente (responsive)
- Los tickets aparecen automáticamente en el Backlog
- Cada formulario llena el `project_id` automáticamente

---

## 🔧 Configuración

### Cambiar IDs de Proyecto

Si los IDs de proyecto son diferentes, actualiza en cada formulario:

```javascript
// Edita en cada form-XXX.html
const PROJECT_ID = X;  // Reemplaza X con ID real de la BD
```

Para obtener IDs reales:
```sql
SELECT id, name FROM projects WHERE active = 1;
```

### Cambiar Usuarios de Auto-Asignación

Por defecto, ambos formularios asignan a Alfonso (ID 3):
```javascript
const ASSIGNED_USERS = [3, 14];  // Alfonso y Alicia
data.assigned_to = ASSIGNED_USERS[0];  // Alfonso
```

Para cambiar:
```javascript
data.assigned_to = 14;  // Asignar a Alicia en su lugar
```

---

## 📡 Flujo de Datos

### 1. Crear Ticket vía Formulario
```
Usuario relleña formulario 
    → Valida campos
    → Limpia vacíos
    → Agrega: project_id, assigned_to, backlog=true
    → POST a /api/tickets.php?action=create
    → Recibe ID del ticket
    → Toast "Ticket creado"
    → Recarga página padre (si embebido)
```

### 2. Backlog → Ticket Normal
```
Usuario ve Backlog Consultoría
    → Hace click "Tomar"
    → Modal: selecciona agente
    → Confirma
    → PUT /api/tickets.php?action=update&id=X
    → assigned_to = nuevo agente
    → backlog = false
    → Ticket sale del backlog
    → Aparece en "Todos los tickets" del agente
```

---

## 🗄️ Base de Datos

### Cambios en Schema:

```sql
-- Nueva columna
ALTER TABLE tickets ADD COLUMN backlog BOOLEAN DEFAULT FALSE;

-- Índice para queries rápidas (opcional)
ALTER TABLE tickets ADD INDEX idx_backlog (backlog, assigned_to);
```

### Campos Utilizados:
- `backlog` (BOOLEAN): Marca si está en backlog
- `assigned_to` (INT): ID del agente asignado
- `project_id` (INT): ID del proyecto
- `source` (VARCHAR): 'embedded_form' para tickets de formularios
- `work_type` (VARCHAR): Siempre 'puntual' para embebibles

---

## 🔗 API Endpoints

### GET Backlog Tickets
```
GET /api/tickets.php?action=backlog

Response:
{
    "success": true,
    "data": [
        {
            "id": 1,
            "ticket_number": "P-20250122-ABCD",
            "title": "...",
            "project_name": "IMP",
            "priority": "medium",
            ...
        }
    ],
    "total": 5
}
```

### Create Ticket (con backlog)
```
POST /api/tickets.php?action=create

Body:
{
    "title": "...",
    "description": "...",
    "project_id": 1,
    "assigned_to": 3,
    "backlog": true,
    "work_type": "puntual",
    ...
}

Response: { "success": true, "data": { "id": 123, ... } }
```

### Update Ticket (asignar desde backlog)
```
POST /api/tickets.php?action=update&id=123

Body:
{
    "assigned_to": 14,
    "backlog": false
}

Response: { "success": true, "data": { "id": 123, ... } }
```

---

## 🎨 Customización

### Colores de Headers
Cada formulario tiene su propio gradient:

```css
/* IMP */
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);

/* Soul Tech */
background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);

/* Despacho */
background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);

/* CMP */
background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
```

### Iconos
Cada formulario usa Font Awesome:
- IMP: `fas fa-briefcase`
- Soul Tech: `fas fa-robot`
- Despacho: `fas fa-gavel`
- CMP: `fas fa-shopping-cart`

---

## ⚠️ Consideraciones Importantes

1. **CORS**: Los formularios embebibles harán requests a tu API. Asegúrate de que CORS esté configurado en `api/ghl-notifications.php`

2. **URLs Base**: Cada formulario usa rutas relativas. Si cambia la estructura de carpetas, actualiza:
   ```javascript
   const API_BASE = '../api';  // Ruta relativa a /forms/
   ```

3. **Validación**: Los formularios validan lado cliente. El backend también valida requeridos.

4. **Asignación**: Al crear ticket, se asigna a Alfonso. Para cambiar a ambos, usa:
   ```javascript
   data.assigned_to = JSON.stringify(ASSIGNED_USERS); // NO RECOMENDADO
   ```
   Mejor crear tickets sin asignar y que aparezcan en backlog para que el equipo los tome.

---

## 📝 Próximos Pasos

- [ ] Actualizar Project IDs en formularios con valores reales de BD
- [ ] Testear embebimiento en sitios reales
- [ ] Configurar CORS si es necesario
- [ ] Personalizar colores/iconos si se requiere
- [ ] Crear dashboard de backlog con estadísticas

---

## 📞 Soporte

Para dudas sobre:
- **Backlog**: Ver `showView('backlog')` en `assets/js/app.js`
- **Formularios**: Revisar `forms/form-xxx.html`
- **API**: Ver `api/tickets.php` endpoints `backlog` y `create`
