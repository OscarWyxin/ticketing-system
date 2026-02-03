# Templates de WhatsApp para GHL

## Configuración de Webhooks

Los webhooks envían datos al endpoint de GHL. En el flujo de GHL, usa estas variables:

### Variables Disponibles (del payload del webhook)

```
{{ticket.id}}
{{ticket.ticket_number}}
{{ticket.title}}
{{ticket.description}}
{{ticket.status}}
{{ticket.priority}}
{{ticket.deliverable}}
{{ticket.tracking_link}}
{{ticket.due_date}}
{{contact.name}}
{{contact.email}}
{{contact.phone}}
{{assigned_to.name}}
{{notification_type}}
```

---

## Template: Ticket Creado
**notification_type:** `ticket_created`

```
Ticket Creado

Hola {{contact.name}}, recibimos tu solicitud:

#{{ticket.ticket_number}}
{{ticket.title}}

Tu ticket ha sido registrado y será atendido a la brevedad.

Seguimiento: {{ticket.tracking_link}}

Sistema de Tickets - Mensaje automático
```

---

## Template: Información Pendiente
**notification_type:** `pending_info`

```
Información Requerida

Hola {{contact.name}},

Para continuar con tu ticket #{{ticket.ticket_number}} necesitamos información adicional:

{{ticket.informacion_pendiente}}

Por favor responde este mensaje con la información solicitada.

Seguimiento: {{ticket.tracking_link}}

Sistema de Tickets - Mensaje automático
```

---

## Template: En Progreso
**notification_type:** `in_progress`

```
Ticket en Progreso

Hola {{contact.name}},

Tu ticket #{{ticket.ticket_number}} está siendo trabajado:

{{ticket.title}}
Asignado a: {{assigned_to.name}}

Te notificaremos cuando esté listo.

Seguimiento: {{ticket.tracking_link}}

Sistema de Tickets - Mensaje automático
```

---

## Template: Ticket Completado (CON ENTREGABLE)
**notification_type:** `ticket_approved`

```
Ticket Completado

Hola {{contact.name}},

Tu ticket ha sido finalizado:

#{{ticket.ticket_number}}
{{ticket.title}}

━━━━━━━━━━━━━━━━━━━━━━
ENTREGABLE:
{{ticket.deliverable}}
━━━━━━━━━━━━━━━━━━━━━━

Ver detalles: {{ticket.tracking_link}}

Gracias por confiar en nosotros.

Sistema de Tickets - Mensaje automático
```

---

## Template Alternativo: Completado (Versión Corta)
**notification_type:** `ticket_approved`

```
Ticket #{{ticket.ticket_number}} Completado

{{contact.name}}, tu solicitud fue procesada:

Entregable:
{{ticket.deliverable}}

{{ticket.tracking_link}}
```

---

## Template: Recordatorio
**notification_type:** `reminder` (si lo implementas)

```
Recordatorio

Hola {{contact.name}},

Tu ticket #{{ticket.ticket_number}} aún requiere tu atención.

{{ticket.title}}
Fecha límite: {{ticket.due_date}}

{{ticket.tracking_link}}

Sistema de Tickets - Mensaje automático
```

---

## Configuración en GHL

### Paso 1: Crear Workflow
1. Ve a **Automation** > **Workflows**
2. Crea un nuevo workflow
3. Trigger: **Webhook** (usa la URL del webhook)

### Paso 2: Configurar Condiciones
Usa **If/Else** para diferenciar por `notification_type`:

```
IF {{notification_type}} = "ticket_created"
   → Enviar mensaje de ticket creado

ELSE IF {{notification_type}} = "ticket_approved"
   → Enviar mensaje de ticket completado

ELSE IF {{notification_type}} = "pending_info"
   → Enviar mensaje de info pendiente

ELSE IF {{notification_type}} = "in_progress"
   → Enviar mensaje de en progreso
```

### Paso 3: Acción WhatsApp
1. Añade acción **Send WhatsApp**
2. Usa el template correspondiente
3. Las variables se reemplazan automáticamente desde el payload

---

## Notas Importantes

1. **Formato WhatsApp:**
   - `*texto*` = **negrita**
   - `_texto_` = _cursiva_
   - `~texto~` = ~~tachado~~
   - ``` ```texto``` ``` = `monoespaciado`

2. **Límites:**
   - Mensaje máximo: 4096 caracteres
   - Si el entregable es muy largo, considera truncarlo

3. **Links:**
   - WhatsApp convierte URLs en links automáticamente
   - No uses formato markdown para links

4. **Emojis:**
   - Mejoran la legibilidad
   - Úsalos con moderación

---

## Ejemplo de Payload Recibido en GHL

```json
{
  "notification_type": "ticket_approved",
  "timestamp": "2026-02-03T15:30:00-03:00",
  "ticket": {
    "id": 125,
    "ticket_number": "TKT-000125",
    "title": "Diseño de logo corporativo",
    "description": "Necesito un logo para mi empresa...",
    "status": "resolved",
    "priority": "high",
    "deliverable": "https://drive.google.com/file/d/xxx/view - Logo en formato PNG, SVG y PDF",
    "tracking_link": "https://tickets.srv764777.hstgr.cloud/seguimiento.html?ticket=TKT-000125"
  },
  "contact": {
    "contact_id": "abc123",
    "name": "Juan Pérez",
    "email": "juan@empresa.com",
    "phone": "+56912345678"
  },
  "assigned_to": {
    "user_id": 150,
    "name": "Gabriela Carvajal",
    "email": "gabriela.carvajal@wixyn.com"
  }
}
```
