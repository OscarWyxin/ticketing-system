# 🚀 Backlog AIB - Quick Reference Card

## System URLs

| Component | URL |
|-----------|-----|
| **Main System** | http://localhost/Ticketing%20System/ |
| **Backlog Consultoría** | (Click nav item in main system) |
| **Backlog AIB** | (Click nav item in main system) |

## Form URLs

### Consultoría Forms
```
form-imp.html              → IMP (Project 2)
form-soultech.html         → Soul Tech IA (Project 3)
form-despacho.html         → Despacho Briones (Project 4)
form-cmp.html              → CMP (Project 5)
```

### AIB Forms
```
form-aib-central.html           → AIB Central (Project 6)
form-sava-valencia.html         → Sava Valencia (Project 7)
form-clinica-madrid.html        → Clínica Madrid (Project 8)
form-clinica-bilbao.html        → Clínica Bilbao (Project 9)
form-clinica-barcelona.html     → Clínica Barcelona (Project 10)
form-clinica-valencia.html      → Clínica Valencia (Project 11)
form-ownman.html                → OwnMan (Project 12)
form-bravo-room.html            → Bravo Room (Project 13)
```

## Key Database Values

| Type | Value |
|------|-------|
| **Consultoría backlog_type** | 'consultoria' |
| **AIB backlog_type** | 'aib' |
| **Backlog Flag** | TRUE |
| **Initial Assignee** | Alfonso (ID: 3) |
| **Project IDs (Consultoría)** | 2, 3, 4, 5 |
| **Project IDs (AIB)** | 6, 7, 8, 9, 10, 11, 12, 13 |

## Common Commands

### Test Database
```sql
-- Check backlog_type column
SELECT DISTINCT backlog_type FROM tickets WHERE backlog = 1;

-- Count tickets by backlog type
SELECT backlog_type, COUNT(*) FROM tickets WHERE backlog = 1 GROUP BY backlog_type;

-- View AIB projects
SELECT id, name FROM projects WHERE id BETWEEN 6 AND 13;
```

### Test API
```bash
# Get Consultoría backlog
curl "http://localhost/api/tickets.php?action=backlog&type=consultoria"

# Get AIB backlog
curl "http://localhost/api/tickets.php?action=backlog&type=aib"

# Create AIB ticket
curl -X POST "http://localhost/api/tickets.php?action=create" \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","description":"Test","project_id":6,"backlog":true,"backlog_type":"aib","assigned_to":3,"created_by":3}'
```

## File Locations

### Source (Development)
```
c:\Users\Skar\Desktop\Ticketing System\
├── index.html (modified)
├── api/tickets.php (modified)
├── assets/js/app.js (modified)
└── forms/
    ├── form-aib-central.html (new)
    ├── form-sava-valencia.html (new)
    ├── form-clinica-madrid.html (new)
    ├── form-clinica-bilbao.html (new)
    ├── form-clinica-barcelona.html (new)
    ├── form-clinica-valencia.html (new)
    ├── form-ownman.html (new)
    └── form-bravo-room.html (new)
```

### Production (Laragon)
```
C:\laragon\www\Ticketing System\
├── index.html
├── api/tickets.php
├── assets/js/app.js
└── forms/ (all files)
```

## Workflow Diagram

```
┌─ CREATE TICKET ─────────────────────────────────────────┐
│                                                          │
│  AIB Form (form-aib-*.html)                             │
│     ↓                                                   │
│  Sets: backlog=true, backlog_type='aib'               │
│     ↓                                                   │
│  Creates Ticket via API                                │
│     ↓                                                   │
│  GHL Notifies Alfonso                                  │
│                                                        │
└────────────────────────────────────────────────────────┘

┌─ ASSIGN TICKET ─────────────────────────────────────────┐
│                                                          │
│  Alfonso Opens "Backlog AIB"                           │
│     ↓                                                   │
│  showView('backlog-aib')                               │
│     ↓                                                   │
│  loadBacklogTickets('aib')                             │
│     ↓                                                   │
│  API filters: WHERE backlog=1 AND backlog_type='aib'  │
│     ↓                                                   │
│  Displays unassigned tickets                           │
│     ↓                                                   │
│  Alfonso clicks "Tomar"                                │
│     ↓                                                   │
│  assignTicketFromBacklog(id, 'aib')                   │
│     ↓                                                   │
│  Modal opens to select assignee                        │
│     ↓                                                   │
│  confirmBacklogAssignment(id, 'aib')                  │
│     ↓                                                   │
│  Updates ticket: assigned_to=XX, backlog=false        │
│     ↓                                                   │
│  loadBacklogTickets('aib') reloads                    │
│     ↓                                                   │
│  Ticket disappears from AIB backlog                    │
│     ↓                                                   │
│  GHL Notifies final assignee                          │
│                                                        │
└────────────────────────────────────────────────────────┘
```

## Frontend JavaScript Functions

### Load Backlog
```javascript
loadBacklogTickets('aib') // Load AIB backlog
loadBacklogTickets('consultoria') // Load Consultoría backlog
```

### Show View
```javascript
showView('backlog-aib') // Show AIB backlog view
showView('backlog-consultoria') // Show Consultoría view
```

### Assign Ticket
```javascript
assignTicketFromBacklog(ticketId, 'aib')
confirmBacklogAssignment(ticketId, 'aib')
```

### Update Badge
```javascript
updateBacklogBadge(count, 'aib')
```

## HTML Element IDs

### Navigation
```html
<a href="#" data-view="backlog-consultoria">Backlog Consultoría</a>
<a href="#" data-view="backlog-aib">Backlog AIB</a>
```

### Views
```html
<div id="view-backlog-consultoria">...</div>
<div id="view-backlog-aib">...</div>
```

### Tables
```html
<tbody id="backlog-consultoria-tbody">...</tbody>
<tbody id="backlog-aib-tbody">...</tbody>
```

### Badges
```html
<span id="badge-backlog-consultoria">0</span>
<span id="badge-backlog-aib">0</span>
```

### Empty States
```html
<div id="backlog-consultoria-empty">...</div>
<div id="backlog-aib-empty">...</div>
```

## Form Configuration

Each AIB form has these constants:
```javascript
const API_BASE = '../api';
const TICKETS_API = `${API_BASE}/tickets.php`;
const HELPERS_API = `${API_BASE}/helpers.php`;
const PROJECT_ID = 6; // Changes per form (6-13)
const BACKLOG_TYPE = 'aib'; // Always 'aib'
const ASSIGNED_USERS = [3, 14]; // Alfonso & Alicia
```

## Troubleshooting Quick Links

| Issue | Check |
|-------|-------|
| Forms not loading | Verify ../api/ path in form script |
| Tickets not appearing | Check backlog_type column in database |
| Wrong backlog showing | Verify type parameter in loadBacklogTickets() |
| Notifications failing | Check GHL API credentials |
| Assignment not working | Verify allowedFields in updateTicket() |

## Performance Notes

- Badge counters update independently
- Backlog filtering done server-side (efficient)
- Separate tbody elements prevent DOM conflicts
- Type parameter passed through entire chain
- GHL notifications non-blocking

## Browser Support

✅ Chrome 90+
✅ Firefox 88+
✅ Safari 14+
✅ Edge 90+

## Mobile Support

✅ iOS Safari (full support)
✅ Android Chrome (full support)
✅ Responsive iframe sizing required

## Accessibility

- ✅ ARIA labels on buttons
- ✅ Keyboard navigation
- ✅ Screen reader compatible
- ✅ Form validation feedback

## Security Notes

- ✅ SQL prepared statements
- ✅ Type parameter validated
- ✅ XSS protection via escapeHtml()
- ✅ CSRF tokens where applicable

---

**Documentation Version**: 1.0
**Last Updated**: 2024
**Status**: ✅ COMPLETE
