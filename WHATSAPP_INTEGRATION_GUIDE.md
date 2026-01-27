# WhatsApp GHL Integration - Implementación Completada

## ✅ Cambios Realizados

### 1. **api/ghl-notifications.php** - Nuevas funciones WhatsApp
Se agregaron 6 nuevas funciones para integración con WhatsApp y custom fields:

```php
updateContactCustomFields($contactId, $customFields)
- Actualiza custom fields en GHL (ticket_id, informacion_pendiente, link_seguimiento)
- Usa PUT /contacts/{id}

generateTrackingLink($ticketId, $ticketNumber, $pdo)
- Genera link único con token SHA256
- Guarda token en tabla ticket_tracking_tokens
- Retorna URL con formato: /ticket-tracking.php?id=TK-xxx&token=xxx

sendWhatsAppTemplate($pdo, $contactPhone, $templateName, $variables)
- Busca o crea contacto en GHL por teléfono
- Envía mensaje WhatsApp usando template
- Soporta: ticket_creado, copy_info_pendiente2, en_desarrollo

notifyTicketCreatedWA($pdo, $ticketData)
- Se llama cuando se crea un ticket
- Genera link de seguimiento
- Actualiza custom fields: ticket_id, link_seguimiento
- Envía WhatsApp template 'ticket_creado'

notifyInfoPendingWA($pdo, $ticketId, $contactPhone, $contactName, $pendingInfo)
- Se llama cuando status → 'waiting' (información pendiente)
- Actualiza custom fields: informacion_pendiente
- Envía WhatsApp template 'copy_info_pendiente2'

notifyDevelopmentStartedWA($pdo, $ticketId, $contactPhone)
- Se llama cuando status → 'in_progress'
- Envía WhatsApp template 'en_desarrollo'
```

### 2. **api/ghl.php** - Soporte PUT/PATCH
Ya actualizado en pasos anteriores con soporte para:
- curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT')
- curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data))

### 3. **api/tickets.php** - Integración de notificaciones
Agregadas llamadas a funciones WhatsApp en:

#### createTicket()
```php
// Después de INSERT exitoso
if (!empty($input['contact_phone'])) {
    $whatsappResult = notifyTicketCreatedWA($pdo, $fullTicketData);
}
```

#### updateTicket()
```php
// Cuando status → 'waiting' (info pendiente)
if ($input['status'] === 'waiting' && !empty($updatedTicket['contact_phone'])) {
    notifyInfoPendingWA($pdo, $id, $updatedTicket['contact_phone'], ...);
}

// Cuando status → 'in_progress' (desarrollo)
if ($input['status'] === 'in_progress' && !empty($updatedTicket['contact_phone'])) {
    notifyDevelopmentStartedWA($pdo, $id, $updatedTicket['contact_phone']);
}
```

#### Nuevo action 'tracking'
```php
case 'tracking':
    getTicketTracking($pdo, $_GET['id'] ?? '', $_GET['token'] ?? '');
```

### 4. **ticket-tracking.php** - Página pública de seguimiento
Nueva página HTML/JS para que clientes vean estado del ticket:
- Parámetros: ?id=TK-xxx&token=xxxxx
- Valida token contra tabla ticket_tracking_tokens
- Muestra:
  - Información del ticket (estado, prioridad, responsable, fechas)
  - Información pendiente (si status=waiting)
  - Timeline con historial de cambios
- Diseño responsive con estilos modernos

### 5. **create-tracking-tokens-table.php** - Migration script
Script para crear tabla:
```sql
CREATE TABLE ticket_tracking_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ticket_id INT NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP DEFAULT DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 90 DAY),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_ticket (ticket_id)
);
```

## 🔄 Flujo de Trabajo

### 1. Ticket Creado
1. Cliente llena formulario con teléfono
2. createTicket() → INSERT en BD
3. notifyTicketCreatedWA() ejecuta:
   - Genera tracking link
   - Busca/crea contacto en GHL
   - Actualiza custom fields: ticket_id, link_seguimiento
   - Envía WhatsApp con template 'ticket_creado'

### 2. Información Pendiente
1. Agente marca "Información Pendiente" en UI
2. updateTicket() → status = 'waiting'
3. notifyInfoPendingWA() ejecuta:
   - Lee texto de informacion_pendiente
   - Actualiza custom fields en GHL: informacion_pendiente
   - Envía WhatsApp con template 'copy_info_pendiente2'

### 3. Desarrollo Iniciado
1. Agente cambia estado a "En Desarrollo"
2. updateTicket() → status = 'in_progress'
3. notifyDevelopmentStartedWA() ejecuta:
   - Envía WhatsApp con template 'en_desarrollo'

### 4. Cliente Verifica Estado
1. Cliente abre link de WhatsApp: /ticket-tracking.php?id=TK-xxx&token=xxx
2. Frontend valida token
3. API getTicketTracking() verifica:
   - Ticket existe
   - Token es válido
   - Token no ha expirado (90 días)
4. Muestra estado actual + historial

## 📋 Prerequisitos

1. **Crear tabla en BD** - Ejecutar create-tracking-tokens-table.php mediante:
   - Web browser: http://localhost/create-tracking-tokens-table.php
   - O CLI: php create-tracking-tokens-table.php

2. **Verificar custom fields en GHL**:
   - ticket_id (para guardar número del ticket)
   - informacion_pendiente (para info pendiente)
   - link_seguimiento (para tracking link)

3. **WhatsApp templates en GHL** (ya creados):
   - ticket_creado
   - copy_info_pendiente2
   - en_desarrollo

## 🧪 Testing

### Test 1: Crear ticket con teléfono
```bash
POST /api/tickets.php?action=create
{
  "title": "Test",
  "description": "Test description",
  "contact_phone": "+1234567890",
  "contact_name": "Cliente Test"
}
```
Esperado: WhatsApp enviado con tracking link

### Test 2: Marcar información pendiente
```bash
PUT /api/tickets.php?action=update&id=1
{
  "status": "waiting",
  "pending_info_details": "Se necesita más información..."
}
```
Esperado: WhatsApp enviado con detalles de info pendiente

### Test 3: Acceder a tracking link
```
GET /ticket-tracking.php?id=TK-20260126-ABC123&token=xxxxx
```
Esperado: Muestra estado del ticket + historial

## ⚙️ Configuración

Todas las configuraciones usan **variables de entorno**:
- **GHL_API_KEY** - Token de integración privada de GHL
- **GHL_COMPANY_ID** - ID de la compañía en GHL
- **GHL_LOCATION_ID** - ID de la ubicación/sub-cuenta
- **GHL_API_BASE** - URL base de la API (default: https://services.leadconnectorhq.com)

Configura estas variables en:
- **Local**: archivo `.env` o en Laragon
- **Docker**: en `docker-compose.yml` bajo `environment:`

## 📝 Notas Importantes

1. Los tokens de seguimiento expiran en 90 días (modificable en create-tracking-tokens-table.php)
2. El teléfono debe estar en formato E.164 (+1234567890) para GHL
3. Los custom fields deben existir en GHL con exactamente esos nombres
4. Las funciones usan error_log() para debugging - revisar logs del servidor
5. Si GHL API falla, la función retorna success=false pero no rompe el flujo principal
6. Los templates de WhatsApp deben tener las variables {{ contact.xxx }} exactamente como se definen

## 🔐 Seguridad

- Los tokens de seguimiento son SHA256 hash de (ticket_id + ticket_number + timestamp)
- Los tokens se validan contra la BD - validación de doble factor
- La página de tracking es pública pero requiere token válido y no expirado
- Los tokens se crean por ticket, no reutilizables

## 📱 GHL Custom Field Variables

En los templates de WhatsApp, usar:
- `{{ contact.ticket_id }}` - Número del ticket (TK-20260126-ABC123)
- `{{ contact.informacion_pendiente }}` - Detalles de información pendiente
- `{{ contact.link_seguimiento }}` - Link para seguimiento del cliente

## 🚀 Próximas mejoras (opcional)

1. Agregar más templates (ticket resuelto, entregado)
2. Sistema de notificaciones por email como fallback
3. Webhooks para actualizaciones en tiempo real
4. Dashboard de estadísticas de WhatsApp enviados
5. Reintento automático si GHL API falla

---

**Estado**: ✅ Implementación completa y lista para usar
**Fecha**: 2025-01-26
**Versión**: 2.0 (Con WhatsApp GHL Integration)
