<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seguimiento de Ticket</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .header p {
            color: #666;
            font-size: 14px;
        }
        
        .error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .success {
            background: #efe;
            border: 1px solid #cfc;
            color: #3c3;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .ticket-info {
            background: #f9f9f9;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 6px;
        }
        
        .ticket-info h2 {
            font-size: 20px;
            color: #333;
            margin-bottom: 15px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
            font-size: 13px;
        }
        
        .info-value {
            color: #333;
            font-size: 14px;
            text-align: right;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
        }
        
        .status-open {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .status-waiting {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .status-in_progress {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        .status-resolved {
            background: #e8f5e9;
            color: #388e3c;
        }
        
        .status-closed {
            background: #f5f5f5;
            color: #616161;
        }
        
        .timeline {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #e0e0e0;
        }
        
        .timeline h3 {
            color: #333;
            margin-bottom: 20px;
            font-size: 16px;
        }
        
        .timeline-item {
            display: flex;
            margin-bottom: 20px;
            gap: 15px;
        }
        
        .timeline-dot {
            width: 12px;
            height: 12px;
            background: #667eea;
            border-radius: 50%;
            margin-top: 4px;
            flex-shrink: 0;
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-date {
            font-size: 12px;
            color: #999;
            margin-bottom: 2px;
        }
        
        .timeline-text {
            font-size: 14px;
            color: #333;
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            font-size: 12px;
            color: #999;
        }
        
        .pending-info {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-top: 20px;
            border-radius: 6px;
        }
        
        .pending-info h4 {
            color: #856404;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .pending-info p {
            color: #856404;
            font-size: 13px;
            line-height: 1.5;
        }
        
        /* Response Form */
        .response-form {
            background: #f0f7ff;
            border: 2px solid #667eea;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .response-form h4 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .response-form textarea {
            width: 100%;
            min-height: 120px;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-family: inherit;
            font-size: 14px;
            resize: vertical;
            margin-bottom: 15px;
        }
        
        .response-form textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .file-upload {
            margin-bottom: 15px;
        }
        
        .file-upload label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            background: white;
            border: 2px dashed #ccc;
            border-radius: 6px;
            cursor: pointer;
            color: #666;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .file-upload label:hover {
            border-color: #667eea;
            color: #667eea;
        }
        
        .file-upload input[type="file"] {
            display: none;
        }
        
        .file-preview {
            margin-top: 10px;
            padding: 10px;
            background: white;
            border-radius: 4px;
            font-size: 13px;
            color: #333;
            display: none;
        }
        
        .file-preview.visible {
            display: block;
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            width: 100%;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .submit-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .submit-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Seguimiento de Ticket</h1>
            <p>Ver el estado de tu solicitud</p>
        </div>
        
        <div id="content"></div>
    </div>
    
    <script>
        // Obtener parámetros
        const params = new URLSearchParams(window.location.search);
        const ticketId = params.get('id');
        const token = params.get('token');
        
        const contentDiv = document.getElementById('content');
        
        async function loadTicket() {
            if (!ticketId || !token) {
                contentDiv.innerHTML = `
                    <div class="error">
                        <strong>Error:</strong> Enlace de seguimiento inválido. Por favor, verifica el enlace.
                    </div>
                `;
                return;
            }
            
            try {
                contentDiv.innerHTML = '<div style="text-align: center; color: #999;">Cargando...</div>';
                
                const response = await fetch(`./api/tickets.php?action=tracking&id=${encodeURIComponent(ticketId)}&token=${encodeURIComponent(token)}`);
                const data = await response.json();
                
                if (data.error) {
                    contentDiv.innerHTML = `
                        <div class="error">
                            <strong>Error:</strong> ${data.error}
                        </div>
                    `;
                    return;
                }
                
                const ticket = data.ticket;
                
                let html = `
                    <div class="success">
                        <strong>✓</strong> Ticket encontrado. Aquí está el estado de tu solicitud.
                    </div>
                    
                    <div class="ticket-info">
                        <h2>${ticket.ticket_number}</h2>
                        <div class="info-row">
                            <span class="info-label">Título</span>
                            <span class="info-value">${ticket.title}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Estado</span>
                            <span class="info-value">
                                <span class="status-badge status-${ticket.status}">
                                    ${getStatusLabel(ticket.status)}
                                </span>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Prioridad</span>
                            <span class="info-value">${getPriorityLabel(ticket.priority)}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Creado</span>
                            <span class="info-value">${formatDate(ticket.created_at)}</span>
                        </div>
                `;
                
                if (ticket.assigned_to_name) {
                    html += `
                        <div class="info-row">
                            <span class="info-label">Responsable</span>
                            <span class="info-value">${ticket.assigned_to_name}</span>
                        </div>
                    `;
                }
                
                if (ticket.due_date) {
                    html += `
                        <div class="info-row">
                            <span class="info-label">Fecha Límite</span>
                            <span class="info-value">${formatDate(ticket.due_date)}</span>
                        </div>
                    `;
                }
                
                html += '</div>';
                
                // Información pendiente
                if (ticket.status === 'waiting' && ticket.pending_info_details) {
                    html += `
                        <div class="pending-info">
                            <h4>⚠️ Información Pendiente</h4>
                            <p>${escapeHtml(ticket.pending_info_details)}</p>
                        </div>
                    `;
                }
                
                // Formulario de respuesta si está en waiting
                if (ticket.status === 'waiting') {
                    html += `
                        <div class="response-form" id="response-form">
                            <h4>📝 Enviar la información solicitada</h4>
                            <textarea id="response-text" placeholder="Escribe tu respuesta aquí..." required></textarea>
                            <div class="file-upload">
                                <label>
                                    <span>📎</span>
                                    <span>Adjuntar archivo (opcional)</span>
                                    <input type="file" id="response-file" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt" onchange="showFilePreview(this)">
                                </label>
                                <div class="file-preview" id="file-preview"></div>
                            </div>
                            <button class="submit-btn" onclick="submitResponse()">
                                Enviar Respuesta
                            </button>
                        </div>
                    `;
                }
                
                // Timeline
                if (data.activities && data.activities.length > 0) {
                    html += '<div class="timeline"><h3>📋 Historial de Cambios</h3>';
                    
                    data.activities.forEach(activity => {
                        html += `
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div class="timeline-content">
                                    <div class="timeline-date">${formatDate(activity.created_at)}</div>
                                    <div class="timeline-text">${formatActivityDescription(activity)}</div>
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                }
                
                html += `
                    <div class="footer">
                        <p>Este enlace es privado y te permite ver el estado de tu ticket en todo momento.</p>
                        <p style="margin-top: 10px;">Si tienes preguntas, contáctanos directamente.</p>
                    </div>
                `;
                
                contentDiv.innerHTML = html;
            } catch (error) {
                contentDiv.innerHTML = `
                    <div class="error">
                        <strong>Error:</strong> No se pudo cargar la información del ticket.
                    </div>
                `;
                console.error('Error:', error);
            }
        }
        
        function getStatusLabel(status) {
            const labels = {
                'open': 'Abierto',
                'waiting': 'Esperando Información',
                'in_progress': 'En Desarrollo',
                'resolved': 'Resuelto',
                'closed': 'Cerrado'
            };
            return labels[status] || status;
        }
        
        function getPriorityLabel(priority) {
            const labels = {
                'low': '🟢 Baja',
                'medium': '🟡 Media',
                'high': '🔴 Alta',
                'urgent': '🔴 Urgente'
            };
            return labels[priority] || priority;
        }
        
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('es-ES', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function formatActivityDescription(activity) {
            const actionLabels = {
                'created': '🆕 Ticket creado',
                'status_changed': '📊 Estado cambiado',
                'priority_changed': '⚡ Prioridad cambiada',
                'assigned': '👤 Asignado',
                'comment_added': '💬 Comentario añadido',
                'updated': '✏️ Actualizado',
                'resolved': '✅ Resuelto',
                'closed': '🔒 Cerrado',
                'reopened': '🔓 Reabierto',
                'pending_info_received': '📨 Información recibida del cliente'
            };
            
            let text = actionLabels[activity.action] || activity.action || 'Actualización';
            
            if (activity.old_value && activity.new_value) {
                text += `: ${activity.old_value} → ${activity.new_value}`;
            } else if (activity.new_value) {
                text += `: ${activity.new_value}`;
            }
            
            return text;
        }
        
        function showFilePreview(input) {
            const preview = document.getElementById('file-preview');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                preview.textContent = `📄 ${file.name} (${formatFileSize(file.size)})`;
                preview.classList.add('visible');
            } else {
                preview.classList.remove('visible');
            }
        }
        
        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }
        
        async function submitResponse() {
            const responseText = document.getElementById('response-text').value.trim();
            const fileInput = document.getElementById('response-file');
            const submitBtn = document.querySelector('.submit-btn');
            
            if (!responseText) {
                alert('Por favor, escribe tu respuesta antes de enviar.');
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Enviando...';
            
            try {
                const formData = new FormData();
                formData.append('response', responseText);
                formData.append('ticket_number', ticketId);
                formData.append('token', token);
                
                if (fileInput.files && fileInput.files[0]) {
                    formData.append('attachment', fileInput.files[0]);
                }
                
                const response = await fetch('./api/tickets.php?action=submit-pending-info', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    const formDiv = document.getElementById('response-form');
                    formDiv.innerHTML = `
                        <div class="submit-success">
                            <h4>✅ ¡Respuesta enviada correctamente!</h4>
                            <p>Tu información ha sido recibida. El equipo de soporte revisará tu respuesta y continuará con la gestión de tu ticket.</p>
                            <p style="margin-top: 10px; font-size: 12px; color: #666;">La página se actualizará en 3 segundos...</p>
                        </div>
                    `;
                    setTimeout(() => location.reload(), 3000);
                } else {
                    alert(data.error || 'Error al enviar la respuesta. Por favor, intenta de nuevo.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Enviar Respuesta';
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error de conexión. Por favor, intenta de nuevo.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Enviar Respuesta';
            }
        }
        
        // Cargar ticket al iniciar
        loadTicket();
    </script>
</body>
</html>
