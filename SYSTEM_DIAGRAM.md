# 📊 DIAGRAMA DEL SISTEMA WHATSAPP INTEGRATION

## ARQUITECTURA GENERAL

```
┌─────────────────────────────────────────────────────────────────┐
│                         CLIENTE (USUARIO)                       │
│                                                                 │
│  1. Crea Ticket      2. Recibe WhatsApp    3. Abre Link       │
│     (con teléfono)       (automático)         Seguimiento      │
└──────────┬──────────────────────────┬─────────────────────────┘
           │                          │
           ↓                          ↓
┌─────────────────────────────────────────────────────────────────┐
│                    BACKEND - PHP APIS                           │
│                                                                 │
│  POST /api/tickets.php?action=create                            │
│  └─ createTicket() → Crea registro en BD                       │
│     ├─ Extrae datos: title, description, contact_phone, etc   │
│     ├─ INSERT en tabla tickets                                │
│     └─ LLAMA: notifyTicketCreatedWA($pdo, $ticketData)       │
│                                                                 │
│  PUT /api/tickets.php?action=update&id=X                       │
│  └─ updateTicket() → Actualiza ticket                         │
│     ├─ Si status='waiting' → LLAMA: notifyInfoPendingWA()    │
│     └─ Si status='in_progress' → LLAMA: notifyDevelopmentStartedWA() │
│                                                                 │
│  GET /api/tickets.php?action=tracking&id=X&token=Y            │
│  └─ getTicketTracking() → Valida y retorna datos             │
│     ├─ Verifica existe ticket_number=X                       │
│     ├─ Valida token en BD                                    │
│     ├─ Verifica token NO expirado                            │
│     └─ Retorna: ticket data + activities                     │
└──────────┬───────────────────┬──────────────────┬──────────────┘
           │                   │                  │
           ↓                   ↓                  ↓
┌──────────────────────────────────────────────────────────────┐
│          NOTIFICATION FUNCTIONS (ghl-notifications.php)       │
│                                                              │
│  ┌─────────────────────────────────────────────────────────┐│
│  │ notifyTicketCreatedWA($pdo, $ticketData)              ││
│  ├─ generateTrackingLink() → Genera token                ││
│  ├─ Busca/crea Contact en GHL por teléfono              ││
│  ├─ updateContactCustomFields(): ticket_id, link_seg... ││
│  └─ sendWhatsAppTemplate(): 'ticket_creado'             ││
│                                                          ││
│  ┌─────────────────────────────────────────────────────────┐│
│  │ notifyInfoPendingWA($pdo, ticketId, phone, info)      ││
│  ├─ generateTrackingLink()                               ││
│  ├─ updateContactCustomFields(): informacion_pendiente   ││
│  └─ sendWhatsAppTemplate(): 'copy_info_pendiente2'       ││
│                                                          ││
│  ┌─────────────────────────────────────────────────────────┐│
│  │ notifyDevelopmentStartedWA($pdo, ticketId, phone)     ││
│  ├─ generateTrackingLink()                               ││
│  └─ sendWhatsAppTemplate(): 'en_desarrollo'              ││
│                                                          ││
│  ┌─────────────────────────────────────────────────────────┐│
│  │ updateContactCustomFields($contactId, $fields)        ││
│  └─ PUT /contacts/{id} en GHL API                        ││
│                                                          ││
│  ┌─────────────────────────────────────────────────────────┐│
│  │ sendWhatsAppTemplate($pdo, $phone, $template, $vars) ││
│  ├─ GET /contacts/lookup?phone={phone} en GHL           ││
│  ├─ POST /contacts si no existe                         ││
│  └─ POST /conversations/messages con template           ││
│                                                          ││
│  ┌─────────────────────────────────────────────────────────┐│
│  │ generateTrackingLink($ticketId, $number, $pdo)        ││
│  ├─ hash_sha256(ticketId + number + timestamp)          ││
│  ├─ INSERT en ticket_tracking_tokens                    ││
│  └─ Retorna: /ticket-tracking.php?id=X&token=Y         ││
│                                                          ││
│  ┌─────────────────────────────────────────────────────────┐│
│  │ ghlApiCall($endpoint, $method, $data, $locationId)    ││
│  ├─ GET, POST, PUT, PATCH/DELETE                        ││
│  ├─ Auth: Authorization: Bearer {API_KEY}               ││
│  └─ Retorna: JSON response                              ││
│                                                          ││
└──────────┬────────────────────────────────────────────────┘
           │
           ↓
┌──────────────────────────────────────────────────────────────┐
│                   GOHOSTING LEVEL (GHL) API                  │
│                                                              │
│  PUT https://services.leadconnectorhq.com/contacts/{id}     │
│  └─ Actualiza custom fields del contact                     │
│     ├─ ticket_id: "TK-20260126-ABC123"                      │
│     ├─ informacion_pendiente: "Descripción..."              │
│     └─ link_seguimiento: "http://...?id=X&token=Y"          │
│                                                              │
│  POST https://services.leadconnectorhq.com/conversations/messages │
│  └─ Envía WhatsApp usando template                          │
│     ├─ type: "WhatsApp"                                     │
│     ├─ contactId: "ghl_contact_123"                         │
│     ├─ templateName: "ticket_creado"                        │
│     └─ templateData: { variables del custom field }         │
│                                                              │
│  GET https://services.leadconnectorhq.com/contacts/lookup  │
│  └─ Busca contact por teléfono                             │
│                                                              │
│  POST https://services.leadconnectorhq.com/contacts/       │
│  └─ Crea nuevo contact si no existe                        │
│                                                              │
└──────────┬──────────────────────────────────────────────────┘
           │
           ↓
┌──────────────────────────────────────────────────────────────┐
│              WHATSAPP MESSAGING (vía GHL)                    │
│                                                              │
│  Cliente recibe mensaje WhatsApp con:                       │
│  ✅ Número de ticket                                        │
│  ✅ Descripción del trabajo                                 │
│  ✅ Link de seguimiento único                               │
│  ✅ Información pendiente (si aplica)                       │
│  ✅ Status actual del ticket                                │
│                                                              │
└──────────┬──────────────────────────────────────────────────┘
           │
           ↓
┌──────────────────────────────────────────────────────────────┐
│          CLIENTE ABRE LINK DE SEGUIMIENTO                    │
│                                                              │
│  ticket-tracking.php?id=TK-20260126-ABC123&token=abc123... │
│                                                              │
│  ┌─ Envía GET a /api/tickets.php?action=tracking           │
│  ├─ Valida: token existe en BD                             │
│  ├─ Valida: token NO expirado (< 90 días)                  │
│  └─ Retorna: datos del ticket + historial                  │
│                                                              │
│  Muestra en página:                                         │
│  ✅ Título y descripción del ticket                        │
│  ✅ Estado actual (Abierto, Esperando Info, En Desarrollo) │
│  ✅ Prioridad y responsable                                │
│  ✅ Información pendiente (si aplica)                      │
│  ✅ Timeline con todos los cambios                         │
│  ✅ Fechas de creación y límite                            │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

## FLUJOS DETALLADOS

### FLUJO 1: CREAR TICKET CON WHATSAPP

```
CLIENTE
   │
   └─ Llena formulario + teléfono
      │
      ↓
API: POST /api/tickets.php?action=create
   │
   ├─ Valida datos (title, description requeridos)
   ├─ Genera ticket_number = "P-20260126-ABC123"
   ├─ INSERT en tabla tickets
   ├─ Obtiene lastInsertId → $ticketId = 1
   │
   └─ if (contact_phone) {
      │
      ├─ LLAMA: notifyTicketCreatedWA($pdo, $ticketData)
      │  │
      │  ├─ LLAMA: generateTrackingLink($ticketId, $number)
      │  │  │
      │  │  ├─ $token = hash('sha256', "1-P-20260126-ABC123-time")
      │  │  ├─ INSERT en ticket_tracking_tokens (1, "abc123...", NOW(), expires_90d)
      │  │  └─ RETORNA: "http://localhost/ticket-tracking.php?id=P-20260126-ABC123&token=abc123..."
      │  │
      │  ├─ LLAMA: ghlApiCall('/contacts/lookup?phone=+1234567890', 'GET')
      │  │  │
      │  │  └─ Si NO existe → POST /contacts para crear
      │  │     └─ Obtiene: $contactId = "ghl_123456"
      │  │
      │  ├─ LLAMA: updateContactCustomFields($contactId, [...fields...])
      │  │  │
      │  │  ├─ PUT /contacts/ghl_123456
      │  │  ├─ customField: {
      │  │  │    ticket_id: "P-20260126-ABC123",
      │  │  │    link_seguimiento: "http://localhost/ticket-tracking.php?..."
      │  │  │  }
      │  │  └─ RETORNA: {success: true}
      │  │
      │  └─ LLAMA: sendWhatsAppTemplate("+1234567890", "ticket_creado", $fields)
      │     │
      │     ├─ POST /conversations/messages
      │     ├─ type: "WhatsApp"
      │     ├─ templateName: "ticket_creado"
      │     ├─ templateData: {
      │     │    ticket_id: "P-20260126-ABC123",
      │     │    link_seguimiento: "http://..."
      │     │  }
      │     └─ GHL ENVÍA WhatsApp al cliente
      │
      └─ RETORNA: {success: true, data: {id: 1, ticket_number: "P-..."}}
      
CLIENTE RECIBE:
   └─ WhatsApp con:
      ├─ "Tu ticket P-20260126-ABC123 ha sido creado"
      ├─ Link de seguimiento: http://localhost/ticket-tracking.php?id=P-...&token=...
      └─ "Puedes ver el estado en cualquier momento"
```

### FLUJO 2: MARCAR INFORMACIÓN PENDIENTE

```
AGENTE
   │
   └─ En dashboard, hace click "Información Pendiente"
      │
      ├─ Escribe: "Necesitamos el archivo de diseño en PDF"
      └─ Click: "Marcar como Pendiente"
         │
         ↓
API: PUT /api/tickets.php?action=update&id=1
   │
   ├─ Obtiene ticket actual
   ├─ input = {status: 'waiting', pending_info_details: "Necesitamos..."}
   ├─ UPDATE tickets SET status='waiting', pending_info_details='...' WHERE id=1
   │
   └─ if (status === 'waiting' && contact_phone) {
      │
      ├─ Obtiene ticket actualizado
      │
      └─ LLAMA: notifyInfoPendingWA($pdo, 1, "+1234567890", "Cliente", "Necesitamos...")
         │
         ├─ LLAMA: generateTrackingLink(1, "P-20260126-ABC123")
         │  └─ $token = nueva entrada en BD
         │
         ├─ LLAMA: updateContactCustomFields($contactId, {
         │    ticket_id: "P-20260126-ABC123",
         │    informacion_pendiente: "Necesitamos el archivo de diseño en PDF",
         │    link_seguimiento: "http://..."
         │  })
         │  └─ PUT en GHL actualiza custom fields
         │
         └─ LLAMA: sendWhatsAppTemplate(..., "copy_info_pendiente2", {...})
            │
            └─ GHL ENVÍA WhatsApp:
               ├─ "Hola Cliente,"
               ├─ "Para tu ticket P-20260126-ABC123 necesitamos:"
               ├─ "Necesitamos el archivo de diseño en PDF"
               ├─ "Puedes verlo aquí: http://..."
               └─ "Gracias"

CLIENTE RECIBE:
   └─ WhatsApp con:
      ├─ Qué información falta
      └─ Link para ver estado actualizado
      
CLIENTE ABRE LINK:
   └─ ticket-tracking.php muestra:
      ├─ Status: "Esperando Información ⚠️"
      ├─ En rojo: "Necesitamos el archivo de diseño en PDF"
      └─ Timeline: "Estado cambió a 'Esperando Información' hace 5 min"
```

### FLUJO 3: VER ESTADO EN LINK DE SEGUIMIENTO

```
CLIENTE
   │
   └─ Abre link: http://localhost/ticket-tracking.php?id=P-20260126-ABC123&token=abc123...
      │
      ↓
FRONTEND (ticket-tracking.php)
   │
   ├─ Extrae parámetros: id, token
   │
   └─ LLAMA AJAX: /api/tickets.php?action=tracking&id=P-...&token=abc123...
      │
      ↓
BACKEND (getTicketTracking)
   │
   ├─ SELECT * FROM tickets WHERE ticket_number = "P-20260126-ABC123"
   ├─ Encuentra ticket (✓)
   │
   ├─ SELECT * FROM ticket_tracking_tokens 
   │  WHERE ticket_id = 1 AND token LIKE "abc123..." AND expires_at > NOW()
   ├─ Encuentra token (✓) - No expirado (✓)
   │
   ├─ SELECT * FROM activities WHERE ticket_id = 1 ORDER BY created_at DESC
   │ (Obtiene historial: creado, estado cambió, info pendiente, etc)
   │
   └─ RETORNA JSON:
      {
        "success": true,
        "ticket": {
          "id": 1,
          "ticket_number": "P-20260126-ABC123",
          "title": "Mi Proyecto",
          "status": "waiting",
          "priority": "high",
          "assigned_to_name": "Agente Luis",
          "pending_info_details": "Necesitamos el archivo de diseño en PDF",
          "created_at": "2025-01-26 10:30:00",
          "due_date": "2025-02-10"
        },
        "activities": [
          {
            "action": "changed_status",
            "description": "Status cambió a 'waiting'",
            "created_at": "2025-01-26 10:35:00"
          },
          {
            "action": "ticket_created",
            "description": "Ticket creado",
            "created_at": "2025-01-26 10:30:00"
          }
        ]
      }

FRONTEND (JavaScript)
   │
   └─ Renderiza HTML con:
      │
      ├─ HEADER:
      │  └─ "P-20260126-ABC123"
      │
      ├─ INFORMACIÓN:
      │  ├─ Título: "Mi Proyecto"
      │  ├─ Estado: [🟡 Esperando Información]
      │  ├─ Prioridad: 🔴 Alta
      │  ├─ Responsable: Agente Luis
      │  ├─ Fecha Límite: 10/02/2025
      │  └─ Creado: 26/01/2025 10:30
      │
      ├─ INFORMACIÓN PENDIENTE (amarillo):
      │  └─ "⚠️ Información Pendiente"
      │     "Necesitamos el archivo de diseño en PDF"
      │
      └─ TIMELINE:
         ├─ "26/01/2025 10:35"
         │  "Status cambió a 'Esperando Información'"
         │
         └─ "26/01/2025 10:30"
            "Ticket creado"

CLIENTE VE:
   └─ Página profesional con:
      ├─ Número de ticket
      ├─ Estado actual (Esperando Información)
      ├─ Qué información falta
      ├─ Responsable y fechas
      └─ Historial de cambios
```

## TABLAS DE BASE DE DATOS

### tickets (Existente + nuevo campo)
```sql
┌─────────────────────────────────────────────┐
│ tickets                                     │
├──────────┬──────────┬───────────────────────┤
│ id       │ INT      │ PRIMARY KEY          │
│ ticket_number │ VARCHAR(50) │ UNIQUE      │
│ title    │ VARCHAR  │                     │
│ description │ LONGTEXT │                 │
│ status   │ ENUM     │ (open, waiting, ...) │
│ priority │ ENUM     │ (low, medium, high) │
│ created_by │ INT   │ FK users.id         │
│ assigned_to │ INT  │ FK users.id         │
│ contact_phone │ VARCHAR │ [NUEVO]        │
│ contact_name │ VARCHAR │                 │
│ contact_email │ VARCHAR │                │
│ pending_info_details │ LONGTEXT │ [NUEVO]│
│ created_at │ TIMESTAMP │                  │
│ updated_at │ TIMESTAMP │                  │
└─────────────────────────────────────────────┘
```

### ticket_tracking_tokens [NUEVA TABLA]
```sql
┌──────────────────────────────────────────┐
│ ticket_tracking_tokens                   │
├─────────────┬──────────┬──────────────────┤
│ id          │ INT      │ PRIMARY KEY      │
│ ticket_id   │ INT      │ FK tickets.id    │
│ token       │ VARCHAR  │ UNIQUE           │
│ created_at  │ TIMESTAMP│ DEFAULT NOW()    │
│ expires_at  │ TIMESTAMP│ +90 DAYS         │
│ INDEX idx_token │      │ (token)          │
│ INDEX idx_ticket │     │ (ticket_id)      │
└──────────────────────────────────────────┘
```

### GHL Contact Custom Fields
```
┌──────────────────────────────────────────┐
│ Contact (en GHL)                         │
├──────────────────┬──────────────────────┤
│ id               │ ghl_contact_id       │
│ phone            │ +1234567890          │
│ customField:     │                      │
│   ticket_id      │ "P-20260126-ABC123" │
│   informacion_pendiente │ "Texto..."    │
│   link_seguimiento │ "http://..."       │
└──────────────────────────────────────────┘
```

---

**Este diagrama muestra la arquitectura completa del sistema WhatsApp Integration con GHL**
