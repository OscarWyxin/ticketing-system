# Backlog AIB Forms - Embedding Guide

## Quick Reference

### Consultoría Forms (4 projects)
```html
<!-- IMP Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-imp.html" width="100%" height="800"></iframe>

<!-- Soul Tech IA Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-soultech.html" width="100%" height="800"></iframe>

<!-- Despacho Briones Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-despacho.html" width="100%" height="800"></iframe>

<!-- CMP Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-cmp.html" width="100%" height="800"></iframe>
```

### AIB Forms (8 projects)
```html
<!-- AIB Central Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-aib-central.html" width="100%" height="800"></iframe>

<!-- Sava Valencia Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-sava-valencia.html" width="100%" height="800"></iframe>

<!-- Clínica Madrid Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-clinica-madrid.html" width="100%" height="800"></iframe>

<!-- Clínica Bilbao Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-clinica-bilbao.html" width="100%" height="800"></iframe>

<!-- Clínica Barcelona Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-clinica-barcelona.html" width="100%" height="800"></iframe>

<!-- Clínica Valencia Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-clinica-valencia.html" width="100%" height="800"></iframe>

<!-- OwnMan Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-ownman.html" width="100%" height="800"></iframe>

<!-- Bravo Room Project -->
<iframe src="http://your-domain/ticketing-system/forms/form-bravo-room.html" width="100%" height="800"></iframe>
```

## Form Configuration Details

### Project Mapping
| Form File | Project Name | Project ID | Backlog Type |
|-----------|-------------|-----------|--------------|
| form-imp.html | IMP | 2 | consultoria |
| form-soultech.html | Soul Tech IA | 3 | consultoria |
| form-despacho.html | Despacho Briones | 4 | consultoria |
| form-cmp.html | CMP | 5 | consultoria |
| form-aib-central.html | AIB Central | 6 | aib |
| form-sava-valencia.html | Sava Valencia | 7 | aib |
| form-clinica-madrid.html | Clínica Madrid | 8 | aib |
| form-clinica-bilbao.html | Clínica Bilbao | 9 | aib |
| form-clinica-barcelona.html | Clínica Barcelona | 10 | aib |
| form-clinica-valencia.html | Clínica Valencia | 11 | aib |
| form-ownman.html | OwnMan | 12 | aib |
| form-bravo-room.html | Bravo Room | 13 | aib |

## Form Features

### All Forms Include:
- ✅ Required fields: Title, Description
- ✅ Optional fields: Contact name, Email, Phone
- ✅ Category selector (dynamically loaded)
- ✅ Priority selector (Medium, High, Urgent, Low)
- ✅ Auto-assignment to Alfonso (ID: 3) as initial reviewer
- ✅ GHL notifications on creation and assignment
- ✅ Input validation
- ✅ Success/Error toasts
- ✅ Loading indicator
- ✅ Responsive design

### Specific to AIB Forms:
- **backlog_type**: Set to 'aib' (vs 'consultoria' for Consultoría forms)
- **backlog**: Always true (marks as backlog ticket)
- **work_type**: Default 'puntual'
- **source**: Set to 'form'
- **created_by**: Alfonso (ID: 3)

## Embedding Examples

### Example 1: Single Form in a Page
```html
<!DOCTYPE html>
<html>
<head>
    <title>Soporte AIB Central</title>
</head>
<body>
    <h1>Centro de Soporte - AIB Central</h1>
    <p>Por favor, complete el formulario a continuación para reportar un problema o requerimiento.</p>
    
    <iframe 
        src="https://ticketing.yourcompany.com/forms/form-aib-central.html" 
        width="100%" 
        height="900"
        style="border: none; border-radius: 8px;"
    ></iframe>
</body>
</html>
```

### Example 2: Multiple Forms in Tabs
```html
<!DOCTYPE html>
<html>
<head>
    <title>Soporte AIB</title>
    <style>
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .tab-button { padding: 10px 20px; cursor: pointer; }
        .tab-button.active { background: #667eea; color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body>
    <div class="tabs">
        <button class="tab-button active" onclick="showTab('central')">AIB Central</button>
        <button class="tab-button" onclick="showTab('sava')">Sava Valencia</button>
        <button class="tab-button" onclick="showTab('madrid')">Clínica Madrid</button>
    </div>
    
    <div id="central" class="tab-content active">
        <iframe src="https://ticketing.yourcompany.com/forms/form-aib-central.html" width="100%" height="800" style="border: none;"></iframe>
    </div>
    
    <div id="sava" class="tab-content">
        <iframe src="https://ticketing.yourcompany.com/forms/form-sava-valencia.html" width="100%" height="800" style="border: none;"></iframe>
    </div>
    
    <div id="madrid" class="tab-content">
        <iframe src="https://ticketing.yourcompany.com/forms/form-clinica-madrid.html" width="100%" height="800" style="border: none;"></iframe>
    </div>
    
    <script>
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-button').forEach(el => el.classList.remove('active'));
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
```

## Troubleshooting

### Forms Not Loading
- Check base URL in form `../api/` references
- Ensure API is accessible from iframe origin
- Check browser console for CORS errors

### Tickets Not Creating
- Verify database connection
- Check api/tickets.php logs
- Confirm PROJECT_ID constant in form matches database

### Notifications Not Sending
- Verify GHL API credentials in config
- Check ghl-notifications.php error logs
- Confirm Alfonso (ID: 3) has valid GHL contact ID

### Wrong Backlog Type
- Confirm backlog_type='aib' in form script
- Check SQL query filtering by type
- Verify database contains backlog_type column

## Local Testing

### Laragon Path
```
C:\laragon\www\Ticketing System\forms\
```

### Local URL
```
http://localhost/Ticketing%20System/forms/form-aib-central.html
```

### Test Form Submission
1. Open form in browser
2. Fill in Title and Description
3. Click "Enviar Ticket"
4. Verify success toast appears
5. Check "Backlog AIB" tab in main system
6. Confirm ticket appears in correct backlog

## Form Customization

### To Change Colors
Edit the `.form-header` style in the HTML:
```css
.form-header {
    background: linear-gradient(135deg, #YOUR_COLOR 0%, #YOUR_COLOR2 100%);
}
```

### To Change Header Icon
Edit the `<i>` tag in `.form-header`:
```html
<i class="fas fa-YOUR_ICON" style="font-size: 32px; margin-bottom: 12px;"></i>
```

### To Add Required Fields
Add a new form-group in the form:
```html
<div class="form-group">
    <label>Your Field <span class="required">*</span></label>
    <input type="text" name="your_field_name" required>
</div>
```

## Support

For issues or questions about form integration:
1. Check the main ticketing system logs
2. Review database tickets table for entries
3. Verify backlog_type column exists
4. Check GHL API configuration
