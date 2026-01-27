# 🎯 PASOS FINALES DE ACTIVACIÓN

## 1️⃣ CREAR LA TABLA DE TRACKING TOKENS

Abre en tu navegador:
```
http://localhost/create-tracking-tokens-table.php
```

Deberías ver:
```
✅ Tabla ticket_tracking_tokens creada exitosamente
```

Si no ves eso, revisa la consola del navegador para errores.

## 2️⃣ VERIFICAR CUSTOM FIELDS EN GHL

Accede a tu Location en GHL:
1. Settings → Custom Fields
2. Verifica que existan estos campos:
   - ✅ `ticket_id` (texto)
   - ✅ `informacion_pendiente` (texto largo)
   - ✅ `link_seguimiento` (URL)

Si alguno no existe, créalo con el nombre exacto.

## 3️⃣ VERIFICAR TEMPLATES WHATSAPP

En GHL:
1. Messaging → WhatsApp Templates
2. Verifica que existan:
   - ✅ `ticket_creado`
   - ✅ `copy_info_pendiente2`
   - ✅ `en_desarrollo`

Los templates deben incluir las variables:
```
{{ contact.ticket_id }}
{{ contact.informacion_pendiente }}
{{ contact.link_seguimiento }}
```

## 4️⃣ PROBAR CREACIÓN DE TICKET

1. En la aplicación, crear nuevo ticket con:
   - Título: "Test WhatsApp"
   - Descripción: "Testing WhatsApp integration"
   - Teléfono: tu número (formato: +XXXXXXXXXXXX)
   - Nombre: Tu nombre

2. Si tienes los logs activados, revisar:
   ```
   /logs/notifications.log
   ```

3. Debería recibir WhatsApp con el template 'ticket_creado'

## 5️⃣ PROBAR INFORMACIÓN PENDIENTE

1. En detalles del ticket, hacer click "Información Pendiente"
2. Escribir texto descriptivo
3. Hacer click "Marcar como Pendiente"

Debería recibir WhatsApp con el template 'copy_info_pendiente2'

## 6️⃣ PROBAR LINK DE SEGUIMIENTO

Cada ticket ahora genera un link único como:
```
http://tudominio.com/ticket-tracking.php?id=TK-20260126-ABC123&token=abc123...
```

Abre este link para ver:
- Estado del ticket
- Información pendiente (si aplica)
- Historial de cambios

## 🔍 DEBUGGING

Si algo no funciona:

### No se envía WhatsApp:
- Revisar logs: `/logs/notifications.log`
- Verificar teléfono en formato: +XXXXXXXXXXXX
- Verificar custom fields existen en GHL
- Verificar templates creados en GHL

### Link de seguimiento no funciona:
- Verificar tabla existe: SELECT * FROM ticket_tracking_tokens;
- Verificar token en tabla: SELECT COUNT(*) FROM ticket_tracking_tokens;
- Revisar token no expirado: WHERE expires_at > NOW()

### Error en updateTicket:
- Verificar pending_info_details column existe
- Revisar logs para error específico

## 📞 SOPORTE

Si tienes problemas:

1. Revisar archivo de logs: `/logs/notifications.log`
2. Revisar Error Log de PHP: `php_error_log`
3. Verificar BD:
   ```sql
   SELECT * FROM ticket_tracking_tokens LIMIT 1;
   SELECT pending_info_details FROM tickets LIMIT 1;
   SELECT * FROM activities ORDER BY created_at DESC LIMIT 5;
   ```

## ✅ CHECKLIST FINAL

- [ ] Tabla ticket_tracking_tokens creada
- [ ] Custom fields verificados en GHL
- [ ] Templates WhatsApp verificados
- [ ] Teléfono en formato correcto
- [ ] Ticket creado con teléfono
- [ ] WhatsApp recibido
- [ ] Link de seguimiento funciona
- [ ] Información pendiente funciona

---

**Una vez completes todos estos pasos, ¡el sistema estará 100% operacional!**
