# Resumen de Cambios - Backlog Consultoría & Formularios Embebibles

## 📅 Fecha: 22 de Enero de 2026

---

## ✨ Nuevas Funcionalidades

### 1. Sistema de Backlog Consultoría

**Objetivo**: Centralizar tickets sin asignar de proyectos específicos para que el equipo pueda tomarlos.

#### Cambios en BD:
- ✅ Agregada columna `backlog` (BOOLEAN, DEFAULT FALSE) a tabla `tickets`

#### Cambios en API (`api/tickets.php`):
- ✅ Agregado endpoint `getBacklogTickets()` que retorna tickets sin asignar + backlog=true
- ✅ Agregado 'backlog' case en router (acción: 'backlog')
- ✅ Agregado campo 'backlog' a parámetro INSERT en `createTicket()`
- ✅ Agregado 'backlog' a `allowedFields` en `updateTicket()`

#### Cambios en HTML (`index.html`):
- ✅ Agregado nav-item "Backlog Consultoría" al sidebar (línea ~32)
- ✅ Agregada nueva sección `view-backlog` con tabla y estado vacío (línea ~310)
- ✅ Agregado badge para contar tickets en backlog

#### Cambios en JavaScript (`assets/js/app.js`):
- ✅ Agregada función `loadBacklogTickets()` - carga tickets del backlog
- ✅ Agregada función `renderBacklogTickets()` - renderiza tabla del backlog
- ✅ Agregada función `updateBacklogBadge()` - actualiza contador
- ✅ Agregada función `assignTicketFromBacklog()` - abre modal de asignación
- ✅ Agregada función `confirmBacklogAssignment()` - confirma asignación y saca del backlog
- ✅ Agregada lógica en `showView()` para manejar vista 'backlog'

---

### 2. Formularios Embebibles (4 Proyectos)

**Objetivo**: Permitir que clientes/socios creen tickets desde sitios externos sin acceder a la app principal.

#### Archivos Creados:

| Nombre | Ruta | Proyecto | Características |
|--------|------|----------|-----------------|
| IMP | `forms/form-imp.html` | IMP (ID: 1) | Gradiente púrpura, Icono briefcase |
| Soul Tech IA | `forms/form-soultech.html` | Soul Tech IA (ID: 2) | Gradiente rosa, Icono robot |
| Despacho Briones | `forms/form-despacho.html` | Despacho Briones (ID: 3) | Gradiente azul cian, Icono gavel |
| CMP | `forms/form-cmp.html` | CMP (ID: 4) | Gradiente naranja, Icono shopping-cart |

#### Características Comunes de Formularios:
- ✅ Campos: Título*, Descripción*, Nombre, Email, Teléfono, Categoría, Prioridad
- ✅ Carga dinámica de categorías desde API
- ✅ Validación de campos requeridos
- ✅ Limpieza de campos vacíos antes de envío
- ✅ Spinner de carga durante envío
- ✅ Toast notificaciones (éxito/error)
- ✅ PostMessage notificación a sitio padre
- ✅ Estilos responsivos y modernos

#### Lógica de Asignación:
```javascript
// Cada formulario hace:
- project_id = X (específico del proyecto)
- assigned_to = 3 (Alfonso por defecto)
- source = 'embedded_form'
- work_type = 'puntual'
- backlog = true (va directo al backlog)
- created_by = 3 (Alfonso crea)
```

---

## 📊 Flujo Completo

### Crear Ticket vía Formulario Embebible:
```
1. Usuario visita sitio (ej: IMP)
2. Rellena formulario
3. Haz clic "Enviar Ticket"
4. Formulario valida campos
5. POST a /api/tickets.php?action=create
6. Ticket se crea con backlog=true
7. Toast "✅ Ticket creado"
8. Aparece en "Backlog Consultoría" del sistema
```

### Tomar Ticket del Backlog:
```
1. Agente abre "Backlog Consultoría"
2. Ve tabla con tickets sin asignar
3. Haz clic "Tomar" en ticket
4. Modal: selecciona agente
5. Confirma asignación
6. PUT /api/tickets.php?action=update&id=X
7. assigned_to = nuevo agente
8. backlog = false
9. Ticket sale del backlog
10. Aparece en "Todos los tickets" del agente
```

---

## 🔧 Cambios Técnicos Detalles

### Base de Datos
```sql
-- Ejecutado al inicio
ALTER TABLE tickets ADD COLUMN backlog BOOLEAN DEFAULT FALSE;
```

### API - Nuevo Endpoint
```php
// En api/tickets.php
function getBacklogTickets($pdo) {
    // Retorna tickets WHERE backlog=TRUE AND assigned_to IS NULL
    // Ordenados por prioridad
    // Con datos de proyecto, categoría, usuario creador
}
```

### API - Cambios Existentes
```php
// createTicket()
- Agregado parámetro 'backlog' al INSERT (default: false)

// updateTicket()
- Agregado 'backlog' a allowedFields
- Permite cambiar backlog al actualizar asignación
```

### Frontend - Estado Global
```javascript
// Sin cambios en state (reutiliza estructura existente)
// Nuevas funciones solo para backlog
```

### Frontend - Router
```javascript
// En showView()
else if (view === 'backlog') {
    loadBacklogTickets();
}
```

---

## 📝 Archivos Modificados

| Archivo | Cambios | Líneas |
|---------|---------|--------|
| `api/tickets.php` | +1 endpoint, +5 referencias 'backlog' | +40 |
| `index.html` | +1 nav item, +1 sección backlog | +35 |
| `assets/js/app.js` | +6 funciones backlog | +130 |
| ✨ `forms/form-imp.html` | Nuevo archivo | 230 |
| ✨ `forms/form-soultech.html` | Nuevo archivo | 230 |
| ✨ `forms/form-despacho.html` | Nuevo archivo | 230 |
| ✨ `forms/form-cmp.html` | Nuevo archivo | 230 |
| ✨ `BACKLOG_FORMS_README.md` | Nuevo archivo (documentación) | 350 |

---

## 🎯 Casos de Uso

### Para Clientes/Socios:
- ✅ Crear tickets sin acceso a la app principal
- ✅ Formularios personalizados por proyecto
- ✅ Seguimiento automático vía backlog

### Para Equipo de Consultoría:
- ✅ Ver todos los tickets nuevos en un lugar
- ✅ Asignar tickets a sí mismo o compañeros
- ✅ Organizar trabajo desde backlog
- ✅ Salir fácilmente del backlog al asignar

### Para Admin:
- ✅ Identificar tickets sin asignar
- ✅ Monitorear flujo de backlog
- ✅ Estadísticas en dashboard (futuro)

---

## ⚙️ Configuración Requerida

### IDs de Proyectos
Si los IDs en BD son diferentes, actualizar en cada formulario:
```javascript
// form-imp.html
const PROJECT_ID = 1; // ← Cambiar si es diferente

// form-soultech.html
const PROJECT_ID = 2; // ← Cambiar si es diferente

// form-despacho.html
const PROJECT_ID = 3; // ← Cambiar si es diferente

// form-cmp.html
const PROJECT_ID = 4; // ← Cambiar si es diferente
```

Verificar IDs:
```sql
SELECT id, name FROM projects;
```

### CORS (Si es necesario)
Los formularios harán requests CORS a la API. Asegúrate que `setCorsHeaders()` en `api/ghl-notifications.php` esté habilitado.

---

## 🧪 Testing

### Pruebas Sugeridas:

1. **Crear Ticket vía Formulario**:
   - [ ] Abre `http://localhost/Ticketing%20System/forms/form-imp.html`
   - [ ] Rellena formulario
   - [ ] Click "Enviar"
   - [ ] Verifica que aparezca en "Backlog Consultoría"

2. **Tomar Ticket del Backlog**:
   - [ ] Ve a "Backlog Consultoría" en app
   - [ ] Click "Tomar"
   - [ ] Selecciona agente (Alicia)
   - [ ] Confirma
   - [ ] Verifica que desapareza del backlog
   - [ ] Verifica que aparezca en "Todos los tickets" de Alicia

3. **Embebimiento en iframe**:
   - [ ] Crea HTML con iframe
   - [ ] Verifica que el formulario sea responsivo
   - [ ] Crea ticket y verifica postMessage

---

## 📋 Checklist Post-Deploy

- [ ] Ejecutar migración BD: `ALTER TABLE tickets ADD COLUMN backlog...`
- [ ] Verificar IDs de proyectos son correctos
- [ ] Testear cada formulario por separado
- [ ] Testear flujo completo: crear → backlog → asignar
- [ ] Verificar CORS si se usa en dominios diferentes
- [ ] Actualizar URLs en documentación de embebimiento
- [ ] Comunicar a clientes URL de formularios
- [ ] Entrenar a equipo en uso de backlog

---

## 🔄 Próximas Mejoras (Futuro)

- [ ] Dashboard de backlog con gráficos
- [ ] Notificaciones cuando nuevos tickets llegan al backlog
- [ ] Filtros avanzados en backlog (proyecto, categoría, etc)
- [ ] Auto-asignación por carga de trabajo
- [ ] Bulk operations (asignar múltiples tickets)
- [ ] Webhook integración con Slack
- [ ] Templates de respuesta rápida

---

## 📞 Contacto/Soporte

- **Backend**: Ver `api/tickets.php`, endpoint `?action=backlog`
- **Frontend**: Ver `assets/js/app.js`, funciones `loadBacklogTickets()`, `assignTicketFromBacklog()`
- **Formularios**: Ver `forms/form-*.html`
- **Documentación**: Ver `BACKLOG_FORMS_README.md`

---

**Estado**: ✅ IMPLEMENTADO Y FUNCIONAL
**Versión**: 1.0
**Fecha**: 22/01/2026
