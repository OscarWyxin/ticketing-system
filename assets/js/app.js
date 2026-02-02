/**
 * Sistema de Ticketing - JavaScript Principal
 * Aplicación embebible para GHL
 */

// =====================================================
// Configuración
// =====================================================

const API_BASE = './api';
const TICKETS_API = `${API_BASE}/tickets.php`;
const HELPERS_API = `${API_BASE}/helpers.php`;
const GHL_API = `${API_BASE}/ghl.php`;

// Estado de la aplicación
let state = {
    tickets: [],
    categories: [],
    agents: [],
    tags: [],
    locations: [],
    clients: [],
    projects: [],
    stats: {},
    currentTicket: null,
    pagination: { page: 1, limit: 20, total: 0 },
    filters: {
        status: '',
        priority: '',
        category: '',
        search: '',
        assigned: ''
    },
    currentView: 'dashboard',
    timer: {
        running: false,
        seconds: 0,
        interval: null
    }
};

// =====================================================
// Inicialización
// =====================================================

document.addEventListener('DOMContentLoaded', () => {
    init();
});

async function init() {
    setupEventListeners();
    setupIframeListener();
    await loadInitialData();
    loadStats();
    loadTickets();
}

// Listen for messages from embedded forms
function setupIframeListener() {
    window.addEventListener('message', (event) => {
        if (event.data && event.data.type === 'ticket-created') {
            closeModal('modal-new-ticket');
            showToast('🎉 Ticket creado: ' + event.data.ticket.ticket_number, 'success');
            loadTickets();
            loadStats();
            // Reset iframe
            const iframe = document.getElementById('iframe-new-ticket');
            if (iframe) {
                iframe.src = iframe.src;
            }
        }
    });
}

function setupEventListeners() {
    // Navegación sidebar
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            
            // Manejar pestañas de agentes por email
            const agentEmail = item.dataset.agentEmail;
            if (agentEmail) {
                loadAgentDashboardByEmail(agentEmail);
                // Marcar como activo
                document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
                item.classList.add('active');
                return;
            }
            
            const view = item.dataset.view;
            showView(view);
        });
    });

    // Búsqueda
    const searchInput = document.getElementById('search-input');
    let searchTimeout;
    searchInput?.addEventListener('input', (e) => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            state.filters.search = e.target.value;
            loadTickets();
        }, 300);
    });

    // Filtros
    document.getElementById('filter-status')?.addEventListener('change', (e) => {
        state.filters.status = e.target.value;
        loadTickets();
    });

    document.getElementById('filter-priority')?.addEventListener('change', (e) => {
        state.filters.priority = e.target.value;
        loadTickets();
    });

    document.getElementById('filter-category')?.addEventListener('change', (e) => {
        state.filters.category = e.target.value;
        loadTickets();
    });

    document.getElementById('filter-assigned')?.addEventListener('change', (e) => {
        state.filters.assigned = e.target.value;
        loadTickets();
    });

    // Toggle sidebar móvil
    document.getElementById('toggle-sidebar')?.addEventListener('click', () => {
        document.querySelector('.sidebar').classList.toggle('open');
    });

    // Cerrar modales con Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                modal.classList.remove('active');
            });
        }
    });

    // Cerrar modal al hacer clic fuera
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.classList.remove('active');
            }
        });
    });
}

// =====================================================
// API Calls
// =====================================================

async function apiCall(url, options = {}) {
    try {
        const response = await fetch(url, {
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            },
            ...options
        });
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);
        showToast('Error de conexión', 'error');
        throw error;
    }
}

async function loadInitialData() {
    try {
        const [categoriesRes, agentsRes, tagsRes, clientsRes, projectsRes] = await Promise.all([
            apiCall(`${HELPERS_API}?action=categories`),
            apiCall(`${HELPERS_API}?action=agents`),
            apiCall(`${HELPERS_API}?action=tags`),
            apiCall(`${HELPERS_API}?action=clients`),
            apiCall(`${HELPERS_API}?action=projects`)
        ]);

        state.categories = categoriesRes.data || [];
        state.agents = agentsRes.data || [];
        state.tags = tagsRes.data || [];
        state.clients = clientsRes.data || [];
        state.projects = projectsRes.data || [];

        // Also load GHL locations if synced
        try {
            const locationsRes = await apiCall(`${GHL_API}?action=get-locations`);
            if (locationsRes.success) {
                state.locations = locationsRes.data || [];
            }
        } catch (e) {
            // GHL locations optional
        }

        populateSelects();
    } catch (error) {
        console.error('Error loading initial data:', error);
    }
}

// =====================================================
// GHL Integration
// =====================================================

async function testGHLConnection() {
    try {
        showToast('Verificando conexión con GHL...', 'info');
        const response = await apiCall(`${GHL_API}?action=test-connection`);
        
        if (response.success) {
            showToast('✅ Conexión exitosa con GHL', 'success');
            return true;
        } else {
            showToast('❌ Error: ' + (response.error || 'No se pudo conectar'), 'error');
            return false;
        }
    } catch (error) {
        showToast('❌ Error de conexión con GHL', 'error');
        return false;
    }
}

async function syncWithGHL() {
    const btn = document.getElementById('btn-sync-ghl');
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sincronizando...';
    
    try {
        // Test connection first
        showToast('Conectando con GHL...', 'info');
        
        // Sync users (team members) - locations requiere scope oauth.readonly
        const usersRes = await apiCall(`${GHL_API}?action=sync-users`, {method: 'POST'});
        
        if (usersRes.success) {
            showToast(`✅ ${usersRes.synced || 0} usuarios sincronizados`, 'success');
        } else {
            throw new Error(usersRes.error || 'Error sincronizando usuarios');
        }
        
        // Reload data
        await loadInitialData();
        await loadStats();
        
        showToast('🎉 Sincronización completada', 'success');
        
    } catch (error) {
        console.error('GHL Sync Error:', error);
        showToast('❌ Error: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// Attach to window for HTML onclick
window.syncWithGHL = syncWithGHL;
window.testGHLConnection = testGHLConnection;

async function loadStats() {
    try {
        const response = await apiCall(`${TICKETS_API}?action=stats`);
        if (response.success) {
            state.stats = response.data;
            renderStats();
        }
    } catch (error) {
        console.error('Error loading stats:', error);
    }
}

async function loadTickets() {
    try {
        const params = new URLSearchParams({
            action: 'list',
            page: state.pagination.page,
            limit: state.pagination.limit,
            ...state.filters
        });

        // Filtrar parámetros vacíos
        for (const [key, value] of params.entries()) {
            if (!value) params.delete(key);
        }

        const response = await apiCall(`${TICKETS_API}?${params}`);
        
        if (response.success) {
            state.tickets = response.data;
            state.pagination = { ...state.pagination, ...response.pagination };
            renderTickets();
            updateBadges();
        }
    } catch (error) {
        console.error('Error loading tickets:', error);
    }
}

async function loadTicketDetail(id) {
    try {
        const response = await apiCall(`${TICKETS_API}?action=get&id=${id}`);
        if (response.success) {
            state.currentTicket = response.data;
            renderTicketDetail();
            showTimerForPuntual();
            resetTimer();
            showView('ticket-detail');
        }
    } catch (error) {
        console.error('Error loading ticket:', error);
    }
}

async function loadBacklogTickets(type = 'consultoria') {
    try {
        const response = await apiCall(`${TICKETS_API}?action=backlog&type=${type}`);
        
        if (response.success) {
            // Guardar tickets en estado
            backlogState[type].tickets = response.data;
            backlogState[type].currentTab = 'pending';
            
            renderBacklogTickets(response.data, type);
            updateBacklogBadge(response.total, type);
            
            // Cargar estadísticas del backlog
            loadBacklogStats(type);
        }
    } catch (error) {
        console.error('Error loading backlog tickets:', error);
    }
}

async function loadBacklogStats(type = 'consultoria') {
    try {
        const response = await apiCall(`${HELPERS_API}?action=backlog-stats&type=${type}`);
        
        if (response.success) {
            const stats = response.data;
            const prefix = type === 'consultoria' ? 'cons' : 'aib';
            
            // Actualizar métricas
            document.getElementById(`stat-${prefix}-pending`).textContent = stats.pending;
            document.getElementById(`stat-${prefix}-assigned`).textContent = stats.assigned;
            document.getElementById(`stat-${prefix}-resolved`).textContent = stats.resolved;
            document.getElementById(`stat-${prefix}-avg-time`).textContent = stats.avg_time + 'h';
            
            // Actualizar badges de tabs
            document.getElementById(`tab-badge-${prefix}-pending`).textContent = stats.pending;
            document.getElementById(`tab-badge-${prefix}-review`).textContent = stats.pending_review || 0;
            document.getElementById(`tab-badge-${prefix}-history`).textContent = stats.history_total;
        }
    } catch (error) {
        console.error('Error loading backlog stats:', error);
    }
}

async function loadBacklogHistory(type = 'consultoria') {
    try {
        const response = await apiCall(`${HELPERS_API}?action=backlog-history&type=${type}`);
        
        if (response.success) {
            backlogState[type].historyTickets = response.data;
            renderBacklogTickets(response.data, type, true);
        }
    } catch (error) {
        console.error('Error loading backlog history:', error);
    }
}

async function loadBacklogPendingReview(type = 'consultoria') {
    try {
        const response = await apiCall(`${HELPERS_API}?action=backlog-review&type=${type}`);
        
        if (response.success) {
            backlogState[type].reviewTickets = response.data;
            renderBacklogTickets(response.data, type, false, true); // isReview = true
        }
    } catch (error) {
        console.error('Error loading backlog pending review:', error);
    }
}

function switchBacklogTab(type, tab) {
    const prefix = type === 'consultoria' ? 'cons' : 'aib';
    
    // Actualizar tabs activos
    document.getElementById(`tab-${prefix}-pending`).classList.toggle('active', tab === 'pending');
    document.getElementById(`tab-${prefix}-review`).classList.toggle('active', tab === 'review');
    document.getElementById(`tab-${prefix}-history`).classList.toggle('active', tab === 'history');
    
    backlogState[type].currentTab = tab;
    
    if (tab === 'pending') {
        // Mostrar tickets pendientes (sin asignar)
        renderBacklogTickets(backlogState[type].tickets || [], type, false, false);
    } else if (tab === 'review') {
        // Cargar y mostrar pendientes de revisión
        loadBacklogPendingReview(type);
    } else {
        // Cargar y mostrar histórico
        loadBacklogHistory(type);
    }
}

// =====================================================
// Renders
// =====================================================

function renderStats() {
    const stats = state.stats;
    
    document.getElementById('stat-total').textContent = stats.total || 0;
    document.getElementById('stat-open').textContent = stats.by_status?.open || 0;
    document.getElementById('stat-progress').textContent = stats.by_status?.in_progress || 0;
    document.getElementById('stat-resolved').textContent = stats.by_status?.resolved || 0;

    // Render priority stats
    const priorityStats = document.getElementById('priority-stats');
    if (priorityStats) {
        const priorities = [
            { key: 'urgent', name: 'Urgente', color: '#ef4444' },
            { key: 'high', name: 'Alta', color: '#f59e0b' },
            { key: 'medium', name: 'Media', color: '#3b82f6' },
            { key: 'low', name: 'Baja', color: '#22c55e' }
        ];

        priorityStats.innerHTML = priorities.map(p => `
            <div class="priority-row">
                <div class="priority-dot" style="background: ${p.color}"></div>
                <span class="priority-name">${p.name}</span>
                <span class="priority-count">${stats.by_priority?.[p.key] || 0}</span>
            </div>
        `).join('');
    }

    // Render category donut chart
    renderCategoryChart();
    
    // Render agent stats
    renderAgentStats();
    
    // Render client stats
    renderClientStats();

    // Render recent tickets
    renderRecentTickets();
}

function renderCategoryChart() {
    const container = document.getElementById('chart-categories');
    if (!container) return;
    
    const categories = state.stats.by_category || [];
    const total = categories.reduce((sum, c) => sum + parseInt(c.count || 0), 0);
    
    if (total === 0) {
        container.innerHTML = `
            <div class="empty-state" style="padding: 20px;">
                <i class="fas fa-chart-pie" style="font-size: 2rem; opacity: 0.3;"></i>
                <p style="margin-top: 10px; color: var(--gray-400);">Sin datos</p>
            </div>
        `;
        return;
    }
    
    const colors = ['#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'];
    let cumulativePercent = 0;
    
    const segments = categories.map((cat, i) => {
        const percent = (parseInt(cat.count) / total) * 100;
        const startAngle = cumulativePercent * 3.6;
        cumulativePercent += percent;
        const endAngle = cumulativePercent * 3.6;
        return { ...cat, percent, color: colors[i % colors.length], startAngle, endAngle };
    });
    
    // Create SVG donut
    const size = 140;
    const strokeWidth = 25;
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    
    let offset = 0;
    const paths = segments.map(seg => {
        const strokeDasharray = (seg.percent / 100) * circumference;
        const strokeDashoffset = -offset;
        offset += strokeDasharray;
        return `<circle cx="${size/2}" cy="${size/2}" r="${radius}" 
                fill="none" stroke="${seg.color}" stroke-width="${strokeWidth}"
                stroke-dasharray="${strokeDasharray} ${circumference}"
                stroke-dashoffset="${strokeDashoffset}" />`;
    }).join('');
    
    container.innerHTML = `
        <div class="donut-chart">
            <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
                ${paths}
            </svg>
            <div class="donut-center">
                <span class="value">${total}</span>
                <span class="label">tickets</span>
            </div>
        </div>
        <div class="chart-legend">
            ${segments.map(seg => `
                <div class="legend-item">
                    <span class="legend-dot" style="background: ${seg.color}"></span>
                    <span>${seg.name || 'Sin categoría'} (${seg.count})</span>
                </div>
            `).join('')}
        </div>
    `;
}

function renderAgentStats() {
    const container = document.getElementById('agent-stats');
    if (!container) return;
    
    // Get agents with ticket counts
    const agentTickets = {};
    state.tickets.forEach(ticket => {
        const agentName = ticket.assigned_to_name || 'Sin asignar';
        if (!agentTickets[agentName]) {
            agentTickets[agentName] = { name: agentName, total: 0, open: 0 };
        }
        agentTickets[agentName].total++;
        if (['open', 'in_progress', 'waiting'].includes(ticket.status)) {
            agentTickets[agentName].open++;
        }
    });
    
    const agents = Object.values(agentTickets).sort((a, b) => b.total - a.total);
    const maxTickets = Math.max(...agents.map(a => a.total), 1);
    
    if (agents.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="padding: 20px;">
                <i class="fas fa-user-check" style="font-size: 2rem; opacity: 0.3;"></i>
                <p style="margin-top: 10px; color: var(--gray-400);">Sin tickets asignados</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = agents.slice(0, 5).map(agent => `
        <div class="agent-bar">
            <div class="agent-bar-header">
                <div class="agent-bar-info">
                    <img class="agent-bar-avatar" 
                         src="https://ui-avatars.com/api/?name=${encodeURIComponent(agent.name)}&background=6366f1&color=fff&size=28" 
                         alt="${agent.name}">
                    <span class="agent-bar-name">${agent.name}</span>
                </div>
                <span class="agent-bar-count">${agent.total} <small style="color: var(--warning); font-size: 0.75rem;">(${agent.open} abiertos)</small></span>
            </div>
            <div class="agent-bar-progress">
                <div class="agent-bar-fill" style="width: ${(agent.total / maxTickets) * 100}%"></div>
            </div>
        </div>
    `).join('');
}

function renderClientStats() {
    const container = document.getElementById('client-stats');
    if (!container) return;
    
    // Group tickets by contact/account
    const clientTickets = {};
    state.tickets.forEach(ticket => {
        const clientName = ticket.contact_name || ticket.account_name || 'Interno';
        if (!clientTickets[clientName]) {
            clientTickets[clientName] = { name: clientName, total: 0, open: 0 };
        }
        clientTickets[clientName].total++;
        if (['open', 'in_progress'].includes(ticket.status)) {
            clientTickets[clientName].open++;
        }
    });
    
    const clients = Object.values(clientTickets).sort((a, b) => b.total - a.total);
    
    if (clients.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="padding: 20px;">
                <i class="fas fa-building" style="font-size: 2rem; opacity: 0.3;"></i>
                <p style="margin-top: 10px; color: var(--gray-400);">Sin tickets de clientes</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = clients.slice(0, 6).map(client => `
        <div class="client-row">
            <div class="client-info">
                <div class="client-icon">
                    <i class="fas fa-${client.name === 'Interno' ? 'building' : 'user'}"></i>
                </div>
                <span class="client-name">${client.name}</span>
            </div>
            <div class="client-count">
                ${client.open > 0 ? `<span class="count-badge has-open">${client.open} abiertos</span>` : ''}
                <span class="count-badge">${client.total} total</span>
            </div>
        </div>
    `).join('');
}

function renderRecentTickets() {
    const container = document.getElementById('recent-tickets');
    if (!container) return;

    const recentTickets = state.tickets.slice(0, 5);

    if (recentTickets.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No hay tickets</h3>
                <p>Crea tu primer ticket para comenzar</p>
            </div>
        `;
        return;
    }

    container.innerHTML = recentTickets.map(ticket => `
        <div class="ticket-item" onclick="loadTicketDetail(${ticket.id})">
            <div class="ticket-priority priority-${ticket.priority}"></div>
            <div class="ticket-info">
                <div class="ticket-title">${escapeHtml(ticket.title)}</div>
                <div class="ticket-meta">
                    <span>${ticket.ticket_number}</span> • 
                    <span>${timeAgo(ticket.created_at)}</span>
                </div>
            </div>
            <div class="ticket-status">
                <span class="status-badge status-${ticket.status}">
                    ${getStatusLabel(ticket.status)}
                </span>
            </div>
        </div>
    `).join('');
}

function renderTickets() {
    const tbody = document.getElementById('tickets-tbody');
    const grid = document.getElementById('tickets-grid');
    
    if (!tbody && !grid) return;

    if (state.tickets.length === 0) {
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>No se encontraron tickets</h3>
                        <p>Intenta cambiar los filtros o crea un nuevo ticket</p>
                    </td>
                </tr>
            `;
        }
        if (grid) {
            grid.innerHTML = `
                <div style="grid-column: 1/-1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; text-align: center; color: var(--gray-500);">
                    <i class="fas fa-search" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                    <h3 style="color: var(--gray-800); margin-bottom: 8px;">No se encontraron tickets</h3>
                    <p>Intenta cambiar los filtros o crea un nuevo ticket</p>
                </div>
            `;
        }
        return;
    }

    // Render List View
    if (tbody) {
        tbody.innerHTML = state.tickets.map(ticket => `
            <tr onclick="loadTicketDetail(${ticket.id})">
                <td><span class="ticket-number">${ticket.ticket_number}</span></td>
                <td>
                    <strong>${escapeHtml(ticket.title)}</strong>
                    ${ticket.contact_name ? `<br><small style="color: var(--gray-500)">${escapeHtml(ticket.contact_name)}</small>` : ''}
                </td>
                <td><span class="status-badge status-${ticket.status}">${getStatusLabel(ticket.status)}</span></td>
                <td><span class="priority-badge ${ticket.priority}">${getPriorityLabel(ticket.priority)}</span></td>
                <td>
                    ${ticket.category_name ? 
                        `<span class="category-badge" style="background: ${ticket.category_color}20; color: ${ticket.category_color}">${ticket.category_name}</span>` : 
                        '<span style="color: var(--gray-400)">—</span>'}
                </td>
                <td>
                    ${ticket.assigned_to_name ? 
                        `<span>${ticket.assigned_to_name}</span>` : 
                        '<span style="color: var(--gray-400)">Sin asignar</span>'}
                </td>
                <td><span style="color: var(--gray-500)">${formatDate(ticket.created_at)}</span></td>
                <td>
                    <button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); loadTicketDetail(${ticket.id})">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    }

    // Render Grid View
    if (grid) {
        grid.innerHTML = state.tickets.map(ticket => `
            <div class="ticket-card" onclick="loadTicketDetail(${ticket.id})">
                <div class="ticket-card-header">
                    <span class="ticket-number">${ticket.ticket_number}</span>
                    <span class="status-badge status-${ticket.status}" style="font-size: 11px;">${getStatusLabel(ticket.status)}</span>
                </div>
                <div class="ticket-card-body">
                    <h4>${escapeHtml(ticket.title)}</h4>
                    ${ticket.contact_name ? `<div class="ticket-contact">${escapeHtml(ticket.contact_name)}</div>` : ''}
                    ${ticket.description ? `<div class="ticket-description">${escapeHtml(ticket.description)}</div>` : ''}
                </div>
                <div class="ticket-card-footer">
                    <div class="ticket-meta">
                        ${ticket.priority ? `<span class="priority-badge ${ticket.priority}">${getPriorityLabel(ticket.priority)}</span>` : ''}
                        ${ticket.category_name ? `<span class="category-badge" style="background: ${ticket.category_color}20; color: ${ticket.category_color}">${ticket.category_name}</span>` : ''}
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span class="ticket-assignee">${ticket.assigned_to_name ? ticket.assigned_to_name : 'Sin asignar'}</span>
                        <span class="ticket-date">${formatDate(ticket.created_at)}</span>
                    </div>
                </div>
            </div>
        `).join('');
    }

    renderPagination();
}

// Estado para backlog
let backlogState = {
    consultoria: { tickets: [], historyTickets: [], view: 'list', currentTab: 'pending', filters: { project: '', category: '', priority: '', date: '' } },
    aib: { tickets: [], historyTickets: [], view: 'list', currentTab: 'pending', filters: { project: '', category: '', priority: '', date: '' } }
};

function renderBacklogTickets(tickets, type = 'consultoria', isHistory = false, isReview = false) {
    const bodyId = type === 'aib' ? 'backlog-aib-tbody' : 'backlog-consultoria-tbody';
    const gridId = type === 'aib' ? 'backlog-aib-grid' : 'backlog-consultoria-grid';
    const emptyId = type === 'aib' ? 'backlog-aib-empty' : 'backlog-consultoria-empty';
    const listViewId = type === 'aib' ? 'backlog-aib-list-view' : 'backlog-consultoria-list-view';
    const gridViewId = type === 'aib' ? 'backlog-aib-grid-view' : 'backlog-consultoria-grid-view';
    
    const tbody = document.getElementById(bodyId);
    const grid = document.getElementById(gridId);
    const emptyState = document.getElementById(emptyId);
    const listView = document.getElementById(listViewId);
    const gridView = document.getElementById(gridViewId);
    
    // Guardar tickets en estado según tipo
    if (isHistory) {
        backlogState[type].historyTickets = tickets || [];
    } else if (isReview) {
        backlogState[type].reviewTickets = tickets || [];
    } else {
        backlogState[type].tickets = tickets || [];
    }
    
    // Aplicar filtros
    const filteredTickets = applyBacklogFilters(tickets, type);

    // Determinar mensaje vacío
    let emptyMessage = 'No hay tickets en el backlog';
    let emptyIcon = 'inbox';
    if (isHistory) {
        emptyMessage = 'No hay tickets en el histórico';
        emptyIcon = 'history';
    } else if (isReview) {
        emptyMessage = 'No hay tickets pendientes de revisión';
        emptyIcon = 'search';
    }

    if (!filteredTickets || filteredTickets.length === 0) {
        if (listView) listView.classList.add('hidden');
        if (gridView) gridView.classList.add('hidden');
        if (emptyState) {
            emptyState.classList.remove('hidden');
            emptyState.innerHTML = `
                <i class="fas fa-${emptyIcon}" style="font-size: 3rem; color: #ccc; margin-bottom: 1rem;"></i>
                <p>${emptyMessage}</p>
            `;
        }
        return;
    }

    emptyState?.classList.add('hidden');
    
    // Mostrar vista según preferencia
    if (backlogState[type].view === 'list') {
        listView?.classList.remove('hidden');
        gridView?.classList.add('hidden');
    } else {
        listView?.classList.add('hidden');
        gridView?.classList.remove('hidden');
    }

    // Render Lista
    if (tbody) {
        tbody.innerHTML = filteredTickets.map(ticket => {
            // Formatear asignados
            const assignedUsers = ticket.assignments && ticket.assignments.length > 0
                ? ticket.assignments.map(a => `<span class="user-badge ${a.role}">${a.name}</span>`).join(' ')
                : (ticket.assigned_to_name ? `<span class="user-badge primary">${ticket.assigned_to_name}</span>` : '<span style="color: var(--gray-400)">Sin asignar</span>');
            
            // Columna de acciones diferente para histórico y revisión
            let actionsColumn;
            if (isHistory) {
                actionsColumn = `
                    <td>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <span class="status-badge status-${ticket.status}" style="font-size: 11px;">${getStatusLabel(ticket.status)}</span>
                            <button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); loadTicketDetail(${ticket.id})">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </td>
                `;
            } else if (isReview) {
                actionsColumn = `
                    <td>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <span class="status-badge status-warning" style="font-size: 11px;">En Revisión</span>
                            <button class="btn btn-sm btn-success" onclick="event.stopPropagation(); approveTicket(${ticket.id})" title="Aprobar">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="event.stopPropagation(); rejectTicket(${ticket.id})" title="Rechazar">
                                <i class="fas fa-times"></i>
                            </button>
                            <button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); loadTicketDetail(${ticket.id})">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </td>
                `;
            } else {
                actionsColumn = `
                    <td>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); assignTicketFromBacklog(${ticket.id}, '${type}')">
                                <i class="fas fa-user-check"></i> Tomar
                            </button>
                            <button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); loadTicketDetail(${ticket.id})">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </td>
                `;
            }
            
            return `
            <tr onclick="loadTicketDetail(${ticket.id})" style="cursor: pointer;">
                <td><span class="ticket-number">${ticket.ticket_number}</span></td>
                <td>
                    <span class="project-badge">${ticket.project_name || 'Sin proyecto'}</span>
                </td>
                <td>
                    <strong>${escapeHtml(ticket.title)}</strong>
                    ${ticket.contact_name ? `<br><small style="color: var(--gray-500)">${escapeHtml(ticket.contact_name)}</small>` : ''}
                </td>
                <td>${assignedUsers}</td>
                <td><span class="priority-badge ${ticket.priority}">${getPriorityLabel(ticket.priority)}</span></td>
                <td>
                    ${ticket.category_name ? 
                        `<span class="category-badge" style="background: ${ticket.category_color}20; color: ${ticket.category_color}">${ticket.category_name}</span>` : 
                        '<span style="color: var(--gray-400)">—</span>'}
                </td>
                <td><span style="color: var(--gray-500)">${formatDate(ticket.created_at)}</span></td>
                ${actionsColumn}
            </tr>
        `}).join('');
    }

    // Render Grid
    if (grid) {
        grid.innerHTML = filteredTickets.map(ticket => {
            // Formatear asignados para el grid
            const assignedUsers = ticket.assignments && ticket.assignments.length > 0
                ? ticket.assignments.map(a => `<span class="user-badge ${a.role}" style="font-size: 10px;">${a.name}</span>`).join(' ')
                : (ticket.assigned_to_name ? `<span class="user-badge primary" style="font-size: 10px;">${ticket.assigned_to_name}</span>` : '<span style="color: var(--gray-400); font-size: 11px;">Sin asignar</span>');
            
            // Footer diferente para histórico y revisión
            let cardFooter;
            if (isHistory) {
                cardFooter = `
                    <div class="ticket-card-footer">
                        <div class="ticket-meta">
                            <span class="status-badge status-${ticket.status}" style="font-size: 10px;">${getStatusLabel(ticket.status)}</span>
                            ${ticket.priority ? `<span class="priority-badge ${ticket.priority}">${getPriorityLabel(ticket.priority)}</span>` : ''}
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                            <span class="ticket-date">${formatDate(ticket.created_at)}</span>
                        </div>
                    </div>
                `;
            } else if (isReview) {
                cardFooter = `
                    <div class="ticket-card-footer">
                        <div class="ticket-meta">
                            <span class="status-badge status-warning" style="font-size: 10px;">En Revisión</span>
                            ${ticket.priority ? `<span class="priority-badge ${ticket.priority}">${getPriorityLabel(ticket.priority)}</span>` : ''}
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px; gap: 8px;">
                            <span class="ticket-date">${formatDate(ticket.created_at)}</span>
                            <div style="display: flex; gap: 5px;">
                                <button class="btn btn-sm btn-success" onclick="event.stopPropagation(); approveTicket(${ticket.id})" title="Aprobar">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="event.stopPropagation(); rejectTicket(${ticket.id})" title="Rechazar">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                cardFooter = `
                    <div class="ticket-card-footer">
                        <div class="ticket-meta">
                            ${ticket.priority ? `<span class="priority-badge ${ticket.priority}">${getPriorityLabel(ticket.priority)}</span>` : ''}
                            ${ticket.category_name ? `<span class="category-badge" style="background: ${ticket.category_color}20; color: ${ticket.category_color}">${ticket.category_name}</span>` : ''}
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                            <span class="ticket-date">${formatDate(ticket.created_at)}</span>
                            <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); assignTicketFromBacklog(${ticket.id}, '${type}')">
                                <i class="fas fa-user-check"></i> Tomar
                            </button>
                        </div>
                    </div>
                `;
            }
            
            return `
            <div class="ticket-card" onclick="loadTicketDetail(${ticket.id})">
                <div class="ticket-card-header">
                    <span class="ticket-number">${ticket.ticket_number}</span>
                    <span class="project-badge" style="font-size: 10px;">${ticket.project_name || 'Sin proyecto'}</span>
                </div>
                <div class="ticket-card-body">
                    <h4>${escapeHtml(ticket.title)}</h4>
                    ${ticket.contact_name ? `<div class="ticket-contact">${escapeHtml(ticket.contact_name)}</div>` : ''}
                    ${ticket.description ? `<div class="ticket-description">${escapeHtml(ticket.description)}</div>` : ''}
                    <div class="ticket-assignees" style="margin-top: 8px;">${assignedUsers}</div>
                </div>
                ${cardFooter}
            </div>
        `}).join('');
    }
}

// Toggle vista backlog
function toggleBacklogView(type, viewType) {
    backlogState[type].view = viewType;
    
    // Actualizar botones
    const listBtn = document.getElementById(`btn-view-backlog-${type === 'consultoria' ? 'cons' : 'aib'}-list`);
    const gridBtn = document.getElementById(`btn-view-backlog-${type === 'consultoria' ? 'cons' : 'aib'}-grid`);
    
    listBtn?.classList.toggle('active', viewType === 'list');
    gridBtn?.classList.toggle('active', viewType === 'grid');
    
    // Re-renderizar
    renderBacklogTickets(backlogState[type].tickets, type);
}

// Aplicar filtros a backlog
function applyBacklogFilters(tickets, type) {
    if (!tickets) return [];
    
    const filters = backlogState[type].filters;
    
    return tickets.filter(ticket => {
        // Filtro proyecto
        if (filters.project && ticket.project_id != filters.project) return false;
        
        // Filtro categoría
        if (filters.category && ticket.category_id != filters.category) return false;
        
        // Filtro prioridad
        if (filters.priority && ticket.priority !== filters.priority) return false;
        
        // Filtro fecha
        if (filters.date) {
            const ticketDate = new Date(ticket.created_at).toISOString().split('T')[0];
            if (ticketDate !== filters.date) return false;
        }
        
        return true;
    });
}

// Filtrar backlog
function filterBacklog(type) {
    const prefix = type === 'consultoria' ? 'cons' : 'aib';
    
    backlogState[type].filters = {
        project: document.getElementById(`filter-backlog-${prefix}-project`)?.value || '',
        category: document.getElementById(`filter-backlog-${prefix}-category`)?.value || '',
        priority: document.getElementById(`filter-backlog-${prefix}-priority`)?.value || '',
        date: document.getElementById(`filter-backlog-${prefix}-date`)?.value || ''
    };
    
    renderBacklogTickets(backlogState[type].tickets, type);
}

// Limpiar filtros backlog
function clearBacklogFilters(type) {
    const prefix = type === 'consultoria' ? 'cons' : 'aib';
    
    document.getElementById(`filter-backlog-${prefix}-project`).value = '';
    document.getElementById(`filter-backlog-${prefix}-category`).value = '';
    document.getElementById(`filter-backlog-${prefix}-priority`).value = '';
    document.getElementById(`filter-backlog-${prefix}-date`).value = '';
    
    backlogState[type].filters = { project: '', category: '', priority: '', date: '' };
    
    renderBacklogTickets(backlogState[type].tickets, type);
}

// Poblar selects de filtros de backlog
function populateBacklogFilters() {
    // Proyectos
    const projectOptions = state.projects.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
    ['filter-backlog-cons-project', 'filter-backlog-aib-project'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = `<option value="">Todos los proyectos</option>${projectOptions}`;
    });
    
    // Categorías
    const categoryOptions = state.categories.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
    ['filter-backlog-cons-category', 'filter-backlog-aib-category'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = `<option value="">Todas las categorías</option>${categoryOptions}`;
    });
}

function renderTicketDetail() {
    const container = document.getElementById('ticket-detail');
    const ticket = state.currentTicket;
    if (!container || !ticket) return;

    container.innerHTML = `
        <div class="ticket-main">
            <div class="ticket-header-detail">
                <span class="ticket-number">${ticket.ticket_number}</span>
                <h1>${escapeHtml(ticket.title)}</h1>
                <div class="ticket-meta">
                    <span><i class="fas fa-clock"></i> ${formatDate(ticket.created_at)}</span>
                    ${ticket.contact_name ? `<span><i class="fas fa-user"></i> ${escapeHtml(ticket.contact_name)}</span>` : ''}
                    ${ticket.contact_email ? `<span><i class="fas fa-envelope"></i> ${escapeHtml(ticket.contact_email)}</span>` : ''}
                </div>
            </div>
            
            <div class="ticket-description">
                <h3>Descripción</h3>
                <div class="content" style="white-space: pre-wrap;">${escapeHtml(ticket.description)}</div>
            </div>
            
            ${ticket.briefing_url || ticket.video_url ? `
            <div class="ticket-links" style="margin-top: 20px; padding: 15px; background: var(--gray-50); border-radius: var(--radius); border-left: 4px solid var(--primary);">
                <h4 style="margin-bottom: 12px; color: var(--gray-700);"><i class="fas fa-link"></i> Enlaces</h4>
                ${ticket.briefing_url ? `
                <div style="margin-bottom: 8px;">
                    <span style="color: var(--gray-500); font-size: 0.85rem;">Briefing:</span>
                    <a href="${escapeHtml(ticket.briefing_url)}" target="_blank" style="color: var(--primary); margin-left: 8px;">
                        <i class="fas fa-external-link-alt"></i> ${escapeHtml(ticket.briefing_url.length > 50 ? ticket.briefing_url.substring(0, 50) + '...' : ticket.briefing_url)}
                    </a>
                </div>
                ` : ''}
                ${ticket.video_url ? `
                <div>
                    <span style="color: var(--gray-500); font-size: 0.85rem;">Video:</span>
                    <a href="${escapeHtml(ticket.video_url)}" target="_blank" style="color: var(--primary); margin-left: 8px;">
                        <i class="fas fa-video"></i> ${escapeHtml(ticket.video_url.length > 50 ? ticket.video_url.substring(0, 50) + '...' : ticket.video_url)}
                    </a>
                </div>
                ` : ''}
            </div>
            ` : ''}
            
            <div class="ticket-comments">
                <div class="comments-header">
                    <i class="fas fa-comments"></i> Comentarios (${ticket.comments?.length || 0})
                </div>
                <div class="comments-list" id="comments-list">
                    ${renderComments(ticket.comments || [])}
                </div>
                <div class="comment-form">
                    <textarea class="form-control" id="new-comment" placeholder="Escribe un comentario..." rows="3"></textarea>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <label style="display: flex; align-items: center; gap: 8px; color: var(--gray-500); font-size: 0.85rem;">
                            <input type="checkbox" id="comment-internal"> Nota interna
                        </label>
                        <button class="btn btn-primary btn-sm" onclick="addComment(${ticket.id})">
                            <i class="fas fa-paper-plane"></i> Enviar
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Sección Entregable -->
            <div class="ticket-deliverable">
                <div class="deliverable-header">
                    <i class="fas fa-box-open"></i> Entregable
                    ${ticket.revision_status == 1 ? '<span class="status-badge status-warning">En Revisión</span>' : ''}
                    ${ticket.revision_status == 2 ? '<span class="status-badge status-success">Aprobado</span>' : ''}
                </div>
                <div class="deliverable-content">
                    <textarea class="form-control" id="deliverable-input" rows="2" 
                              placeholder="URL o descripción del entregable..."
                              ${ticket.revision_status >= 1 ? 'disabled' : ''}>${escapeHtml(ticket.deliverable || '')}</textarea>
                    ${ticket.revision_status == 0 ? `
                    <div class="deliverable-actions">
                        <button class="btn btn-sm btn-secondary" onclick="saveDeliverable(${ticket.id})" id="btn-save-deliverable">
                            <i class="fas fa-save"></i> Guardar
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="requestReview(${ticket.id})" id="btn-request-review"
                                ${!ticket.deliverable ? 'disabled title="Primero guarda el entregable"' : ''}>
                            <i class="fas fa-search"></i> Pendiente de Revisión
                        </button>
                    </div>
                    ` : ''}
                    ${ticket.revision_status == 1 ? `
                    <div class="deliverable-actions review-actions">
                        <button class="btn btn-sm btn-success" onclick="approveTicket(${ticket.id})">
                            <i class="fas fa-check-circle"></i> Aprobar
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="rejectTicket(${ticket.id})">
                            <i class="fas fa-times-circle"></i> Rechazar
                        </button>
                    </div>
                    ` : ''}
                </div>
            </div>
        </div>
        
        <div class="ticket-sidebar">
            <div class="info-card">
                <h4>Detalles</h4>
                <div class="info-row">
                    <span class="label">Estado</span>
                    <select class="form-select" style="width: auto; padding: 6px 10px; font-size: 0.85rem;" 
                            onchange="updateTicketField(${ticket.id}, 'status', this.value)">
                        <option value="open" ${ticket.status === 'open' ? 'selected' : ''}>Abierto</option>
                        <option value="in_progress" ${ticket.status === 'in_progress' ? 'selected' : ''}>En Progreso</option>
                        <option value="waiting" ${ticket.status === 'waiting' ? 'selected' : ''}>En Espera</option>
                        <option value="resolved" ${ticket.status === 'resolved' ? 'selected' : ''}>Resuelto</option>
                        <option value="closed" ${ticket.status === 'closed' ? 'selected' : ''}>Cerrado</option>
                    </select>
                </div>
                <div class="info-row">
                    <span class="label">Prioridad</span>
                    <select class="form-select" style="width: auto; padding: 6px 10px; font-size: 0.85rem;"
                            onchange="updateTicketField(${ticket.id}, 'priority', this.value)">
                        <option value="low" ${ticket.priority === 'low' ? 'selected' : ''}>Baja</option>
                        <option value="medium" ${ticket.priority === 'medium' ? 'selected' : ''}>Media</option>
                        <option value="high" ${ticket.priority === 'high' ? 'selected' : ''}>Alta</option>
                        <option value="urgent" ${ticket.priority === 'urgent' ? 'selected' : ''}>Urgente</option>
                    </select>
                </div>
                <div class="info-row">
                    <span class="label">Categoría</span>
                    <span class="value">
                        ${ticket.category_name || '<span style="color: var(--gray-400)">Sin categoría</span>'}
                    </span>
                </div>
                <div class="info-row">
                    <span class="label">Asignado a</span>
                    <select class="form-select" style="width: auto; padding: 6px 10px; font-size: 0.85rem;"
                            onchange="updateTicketField(${ticket.id}, 'assigned_to', this.value)">
                        <option value="">Sin asignar</option>
                        ${state.agents.map(a => `
                            <option value="${a.id}" ${ticket.assigned_to == a.id ? 'selected' : ''}>${a.name}</option>
                        `).join('')}
                    </select>
                </div>
                <div class="info-row">
                    <span class="label">Origen</span>
                    <span class="value">${getSourceLabel(ticket.source)}</span>
                </div>
                <div class="info-row">
                    <span class="label">Creado</span>
                    <span class="value">${formatDateTime(ticket.created_at)}</span>
                </div>
                <div class="info-row">
                    <span class="label">Fecha Máx. Entrega</span>
                    <input type="date" class="form-control" style="width: auto; padding: 6px 10px; font-size: 0.85rem;"
                           value="${ticket.max_delivery_date || ''}"
                           onchange="updateTicketField(${ticket.id}, 'max_delivery_date', this.value)">
                </div>
                
                ${ticket.status === 'waiting' ? `
                <div class="info-card" style="background-color: var(--warning-bg); border-left: 4px solid var(--warning);">
                    <h4 style="color: var(--warning); margin-bottom: 10px;">
                        <i class="fas fa-exclamation-triangle"></i> Información Pendiente
                    </h4>
                    <textarea id="pending-info-text" class="form-control" rows="3" placeholder="¿Qué información falta?" style="margin-bottom: 10px;">${escapeHtml(ticket.pending_info_details || '')}</textarea>
                    <button class="btn btn-success btn-sm" onclick="markInfoComplete(${ticket.id})">
                        <i class="fas fa-check-circle"></i> Información Completa
                    </button>
                </div>
                ` : `
                <div class="info-card">
                    <h4>Información Pendiente</h4>
                    <textarea id="pending-info-text" class="form-control" rows="3" placeholder="¿Qué información falta?" style="margin-bottom: 10px;">${escapeHtml(ticket.pending_info_details || '')}</textarea>
                    <button class="btn btn-warning btn-sm" onclick="markInfoPending(${ticket.id})">
                        <i class="fas fa-clock"></i> Marcar como Pendiente
                    </button>
                </div>
                `}
                
                ${ticket.work_type === 'puntual' ? `
                <div class="info-row">
                    <span class="label">Horas Dedicadas</span>
                    <span class="value" style="font-weight: 700; color: var(--primary);">${formatSecondsToTime(parseFloat(ticket.hours_dedicated || 0) * 3600)}</span>
                </div>
                ` : ''}
            </div>
            
            ${ticket.activity && ticket.activity.length > 0 ? `
            <div class="info-card">
                <h4>Actividad Reciente</h4>
                <div class="activity-list">
                    ${ticket.activity.slice(0, 10).map(a => `
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="${getActivityIcon(a.action)}"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-text">
                                    <strong>${a.user_name || 'Sistema'}</strong> ${getDetailedActivityLabel(a)}
                                </div>
                                <div class="activity-time">${timeAgo(a.created_at)}</div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
            ` : ''}
        </div>
    `;
}

function renderComments(comments) {
    if (comments.length === 0) {
        return '<p style="color: var(--gray-400); text-align: center; padding: 20px;">No hay comentarios aún</p>';
    }

    return comments.map(comment => `
        <div class="comment-item ${comment.is_internal ? 'internal' : ''}">
            <img class="comment-avatar" src="https://ui-avatars.com/api/?name=${encodeURIComponent(comment.user_name || comment.author_name || 'U')}&background=6366f1&color=fff" alt="">
            <div class="comment-content">
                <div class="comment-header">
                    <span class="comment-author">${escapeHtml(comment.user_name || comment.author_name || 'Usuario')}</span>
                    <span class="comment-time">${timeAgo(comment.created_at)}</span>
                    ${comment.is_internal ? '<span class="status-badge status-waiting" style="font-size: 0.7rem">Interno</span>' : ''}
                </div>
                <div class="comment-text">${escapeHtml(comment.content)}</div>
            </div>
        </div>
    `).join('');
}

function renderPagination() {
    const container = document.getElementById('pagination');
    if (!container) return;

    const { page, pages, total } = state.pagination;
    if (pages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = `
        <button onclick="goToPage(${page - 1})" ${page === 1 ? 'disabled' : ''}>
            <i class="fas fa-chevron-left"></i>
        </button>
    `;

    for (let i = 1; i <= pages; i++) {
        if (i === 1 || i === pages || (i >= page - 2 && i <= page + 2)) {
            html += `<button onclick="goToPage(${i})" class="${i === page ? 'active' : ''}">${i}</button>`;
        } else if (i === page - 3 || i === page + 3) {
            html += '<button disabled>...</button>';
        }
    }

    html += `
        <button onclick="goToPage(${page + 1})" ${page === pages ? 'disabled' : ''}>
            <i class="fas fa-chevron-right"></i>
        </button>
    `;

    container.innerHTML = html;
}

// =====================================================
// Actions
// =====================================================

function showView(view) {
    state.currentView = view;

    // Actualizar navegación
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.toggle('active', item.dataset.view === view);
    });

    // Mostrar/ocultar secciones
    document.querySelectorAll('.content-section').forEach(section => {
        section.classList.add('hidden');
    });

    const viewSection = document.getElementById(`view-${view}`);
    if (viewSection) {
        viewSection.classList.remove('hidden');
    }

    // Cargar datos según vista
    if (view === 'dashboard') {
        loadStats();
        loadTickets();
    } else if (view === 'projects') {
        loadProjects();
    } else if (view === 'project-detail') {
        // Se carga desde loadProjectDetail
    } else if (view === 'tickets') {
        state.filters = { status: '', priority: '', category: '', search: '' };
        loadTickets();
    } else if (view === 'backlog-consultoria') {
        loadBacklogTickets('consultoria');
    } else if (view === 'backlog-aib') {
        loadBacklogTickets('aib');
    } else if (view === 'open') {
        state.filters = { ...state.filters, status: 'open' };
        document.getElementById('filter-status').value = 'open';
        loadTickets();
        showView('tickets');
    }
}

function toggleView(viewType) {
    // Actualizar botones activos
    document.getElementById('btn-view-list').classList.toggle('active', viewType === 'list');
    document.getElementById('btn-view-grid').classList.toggle('active', viewType === 'grid');
    
    // Mostrar/ocultar vistas
    document.getElementById('tickets-list-view').classList.toggle('hidden', viewType !== 'list');
    document.getElementById('tickets-grid-view').classList.toggle('hidden', viewType !== 'grid');
    

    // Guardar preferencia
    state.currentView = viewType;
    // Cerrar sidebar en móvil
    document.querySelector('.sidebar')?.classList.remove('open');
}

function openNewTicketModal() {
    openModal('modal-new-ticket');
}

function closeModal(modalId) {
    document.getElementById(modalId)?.classList.remove('active');
    // Reset form if it's the new ticket modal
    if (modalId === 'modal-new-ticket') {
        document.getElementById('form-new-ticket')?.reset();
    }
}

function openModal(modalId) {
    document.getElementById(modalId)?.classList.add('active');
    // Populate selects when opening new ticket modal
    if (modalId === 'modal-new-ticket') {
        populateNewTicketSelects();
    }
}

function populateNewTicketSelects() {
    // Categories
    const categorySelect = document.getElementById('new-ticket-category');
    if (categorySelect && state.categories.length) {
        categorySelect.innerHTML = '<option value="">Seleccionar...</option>' +
            state.categories.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
    }
    
    // Agents
    const assignedSelect = document.getElementById('new-ticket-assigned');
    if (assignedSelect && state.agents.length) {
        assignedSelect.innerHTML = '<option value="">Sin asignar</option>' +
            state.agents.map(a => `<option value="${a.id}">${a.name}</option>`).join('');
    }
    
    // Projects - Puntual
    const projectSelect = document.getElementById('new-ticket-project');
    if (projectSelect && state.projects.length) {
        projectSelect.innerHTML = '<option value="">Seleccionar...</option>' +
            state.projects.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
    }
    
    // Projects - Recurrente
    const projectSelectRecurrente = document.getElementById('new-ticket-project-recurrente');
    if (projectSelectRecurrente && state.projects.length) {
        projectSelectRecurrente.innerHTML = '<option value="">Seleccionar...</option>' +
            state.projects.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
    }
    
    // Projects - Soporte
    const projectSelectSoporte = document.getElementById('new-ticket-project-soporte');
    if (projectSelectSoporte && state.projects.length) {
        projectSelectSoporte.innerHTML = '<option value="">Seleccionar...</option>' +
            state.projects.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
    }
}


// =====================================================
// Funciones de Formularios
// =====================================================

/**
 * Cambiar entre tipos de trabajo en formulario
 */
function switchWorkType(type) {
    // Ocultar todas las secciones
    document.getElementById('fields-puntual').classList.add('hidden');
    document.getElementById('fields-recurrente').classList.add('hidden');
    document.getElementById('fields-soporte').classList.add('hidden');
    
    // Mostrar la seleccionada
    document.getElementById(`fields-${type}`).classList.remove('hidden');
}

// =====================================================
// Timer para Horas Dedicadas (Puntual tickets)
// =====================================================

function showTimerForPuntual() {
    const timerDisplay = document.getElementById('timer-display');
    console.log('showTimerForPuntual called');
    console.log('Current ticket:', state.currentTicket);
    console.log('Timer display element:', timerDisplay);
    
    if (state.currentTicket && state.currentTicket.work_type === 'puntual') {
        if (timerDisplay) {
            timerDisplay.style.display = 'block';
            console.log('Timer shown for Puntual ticket');
        }
        updateTimerDisplay();
    } else {
        if (timerDisplay) {
            timerDisplay.style.display = 'none';
            console.log('Timer hidden - not a Puntual ticket');
        }
    }
}

function toggleTimer() {
    const btn = document.getElementById('btn-timer');
    
    if (state.timer.running) {
        // Pausar timer
        state.timer.running = false;
        clearInterval(state.timer.interval);
        btn.innerHTML = '<i class="fas fa-play"></i> Reanudar';
    } else {
        // Iniciar/reanudar timer
        state.timer.running = true;
        btn.innerHTML = '<i class="fas fa-pause"></i> Pausar';
        
        state.timer.interval = setInterval(() => {
            state.timer.seconds++;
            updateTimerDisplay();
        }, 1000);
    }
}

function resetTimer() {
    state.timer.running = false;
    state.timer.seconds = 0;
    clearInterval(state.timer.interval);
    
    const btn = document.getElementById('btn-timer');
    btn.innerHTML = '<i class="fas fa-play"></i> Iniciar';
    updateTimerDisplay();
}

function updateTimerDisplay() {
    const hours = Math.floor(state.timer.seconds / 3600);
    const minutes = Math.floor((state.timer.seconds % 3600) / 60);
    const seconds = state.timer.seconds % 60;
    
    const display = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    document.getElementById('timer-time').textContent = display;
}

function formatSecondsToTime(totalSeconds) {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = Math.round(totalSeconds % 60);
    
    return `${hours}h ${minutes}m ${seconds}s`;
}

function saveTimer() {
    if (!state.currentTicket) return;
    
    // Convertir segundos a horas decimales
    const hoursDecimal = state.timer.seconds / 3600;
    
    // Formato: Xh Ym Zs
    const hours = Math.floor(state.timer.seconds / 3600);
    const minutes = Math.floor((state.timer.seconds % 3600) / 60);
    const seconds = state.timer.seconds % 60;
    const timeFormat = `${hours}h ${minutes}m ${seconds}s`;
    
    updateTicketField(state.currentTicket.id, 'hours_dedicated', hoursDecimal);
    showToast(`⏱️ ${timeFormat} guardadas`, 'success');
    
    // Actualizar ticket en estado local
    state.currentTicket.hours_dedicated = hoursDecimal;
    renderTicketDetail();
    
    resetTimer();
}

async function submitNewTicket(e) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    
    // Obtener el tipo de trabajo seleccionado
    const workType = document.querySelector('input[name="work_type"]:checked').value;
    data.work_type = workType;
    
    // Validar campos requeridos según el tipo
    if (!data.title || !data.description) {
        showToast('Título y descripción son requeridos', 'error');
        return;
    }
    
    // Añadir datos por defecto
    data.created_by = 1; // TODO: Usuario actual
    data.source = 'dashboard'; // Marca que viene del dashboard, no de formulario
    
    // NO enviar a backlog - va directo al usuario asignado
    // data.backlog = false; // No necesitamos enviarlo, por defecto es false
    
    // Limpiar todos los campos vacíos
    Object.keys(data).forEach(key => {
        if (!data[key] || data[key] === '') {
            delete data[key];
        }
    });
    
    // Procesar campos según el tipo de trabajo
    if (workType === 'puntual') {
        // Puntual usa project_id directamente
        delete data.monthly_hours;
        delete data.score;
        delete data.hours_dedicated;
        delete data.project_id_recurrente;
        delete data.project_id_soporte;
    } else if (workType === 'recurrente') {
        if (!data.monthly_hours) {
            showToast('Las horas mensuales son requeridas para trabajos recurrentes', 'error');
            return;
        }
        // Usar project_id_recurrente como project_id
        if (data.project_id_recurrente) {
            data.project_id = data.project_id_recurrente;
        }
        delete data.project_id_recurrente;
        delete data.project_id_soporte;
        // Limpiar campos de otros tipos
        delete data.hours_dedicated;
        delete data.max_delivery_date;
        delete data.briefing_url;
        delete data.video_url;
        delete data.info_pending_status;
        delete data.revision_status;
        delete data.score;
    } else if (workType === 'soporte') {
        // Usar project_id_soporte como project_id
        if (data.project_id_soporte) {
            data.project_id = data.project_id_soporte;
        }
        delete data.project_id_recurrente;
        delete data.project_id_soporte;
        // Soporte no requiere campos específicos obligatorios
        delete data.hours_dedicated;
        delete data.max_delivery_date;
        delete data.briefing_url;
        delete data.video_url;
        delete data.info_pending_status;
        delete data.revision_status;
        delete data.monthly_hours;
    }

    try {
        const response = await apiCall(`${TICKETS_API}?action=create`, {
            method: 'POST',
            body: JSON.stringify(data)
        });

        if (response.success) {
            showToast(`🎉 Ticket ${response.data.ticket_number} creado`, 'success');
            closeModal('modal-new-ticket');
            form.reset();
            switchWorkType('puntual'); // Reset a tipo por defecto
            loadTickets();
            loadStats();
        } else {
            showToast(response.error || 'Error al crear ticket', 'error');
            console.error('API Error:', response);
        }
    } catch (error) {
        showToast('Error de conexión: ' + error.message, 'error');
        console.error('Connection error:', error);
        console.log('Data sent:', data);
    }
}
async function updateTicketField(ticketId, field, value) {
    try {
        const response = await apiCall(`${TICKETS_API}?action=update&id=${ticketId}`, {
            method: 'POST',
            body: JSON.stringify({ [field]: value, user_id: 1 })
        });

        if (response.success) {
            showToast('Ticket actualizado', 'success');
            // Actualizar estado local
            if (state.currentTicket) {
                state.currentTicket[field] = value;
            }
        } else {
            showToast(response.error || 'Error al actualizar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function toggleInfoPending(ticketId) {
    try {
        const ticket = state.currentTicket;
        const newStatus = ticket.info_pending_status ? 0 : 1;
        
        const response = await apiCall(`${TICKETS_API}?action=update&id=${ticketId}`, {
            method: 'POST',
            body: JSON.stringify({ info_pending_status: newStatus, user_id: 1 })
        });

        if (response.success) {
            showToast(`Estado de información ${newStatus ? 'marcado como pendiente' : 'completado'}`, 'success');
            // Actualizar estado local
            if (state.currentTicket) {
                state.currentTicket.info_pending_status = newStatus;
                renderTicketDetail(); // Re-renderizar para actualizar el botón
            }
        } else {
            showToast(response.error || 'Error al actualizar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function markInfoPending(ticketId) {
    const textarea = document.getElementById('pending-info-text');
    const pendingInfo = textarea.value.trim();

    if (!pendingInfo) {
        showToast('Describe qué información falta', 'warning');
        return;
    }

    try {
        const response = await apiCall(`${TICKETS_API}?action=update&id=${ticketId}`, {
            method: 'POST',
            body: JSON.stringify({
                status: 'waiting',
                pending_info_details: pendingInfo,
                user_id: 1
            })
        });

        if (response.success) {
            showToast('Estado cambiado a Información Pendiente', 'success');
            if (state.currentTicket) {
                state.currentTicket.status = 'waiting';
                state.currentTicket.pending_info_details = pendingInfo;
                renderTicketDetail();
            }
        } else {
            showToast(response.error || 'Error al actualizar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function markInfoComplete(ticketId) {
    try {
        const response = await apiCall(`${TICKETS_API}?action=update&id=${ticketId}`, {
            method: 'POST',
            body: JSON.stringify({
                status: 'in_progress',
                pending_info_details: null,
                user_id: 1
            })
        });

        if (response.success) {
            showToast('Estado cambiado a En Curso - Información completa', 'success');
            if (state.currentTicket) {
                state.currentTicket.status = 'in_progress';
                state.currentTicket.pending_info_details = null;
                renderTicketDetail();
            }
        } else {
            showToast(response.error || 'Error al actualizar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function addComment(ticketId) {
    const textarea = document.getElementById('new-comment');
    const isInternal = document.getElementById('comment-internal')?.checked || false;
    const content = textarea.value.trim();

    if (!content) {
        showToast('Escribe un comentario', 'warning');
        return;
    }

    try {
        const response = await apiCall(`${TICKETS_API}?action=comments&ticket_id=${ticketId}`, {
            method: 'POST',
            body: JSON.stringify({
                content,
                is_internal: isInternal,
                user_id: 1, // TODO: Usuario actual
                author_name: 'Admin'
            })
        });

        if (response.success) {
            showToast('Comentario agregado', 'success');
            textarea.value = '';
            loadTicketDetail(ticketId);
        } else {
            showToast(response.error || 'Error al agregar comentario', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

// =====================================================
// Flujo de Revisión y Aprobación
// =====================================================

async function saveDeliverable(ticketId) {
    const deliverable = document.getElementById('deliverable-input')?.value.trim();
    
    if (!deliverable) {
        showToast('Ingresa el entregable', 'warning');
        return;
    }
    
    try {
        const response = await apiCall(`${TICKETS_API}?action=update&id=${ticketId}`, {
            method: 'POST',
            body: JSON.stringify({ deliverable })
        });
        
        if (response.success) {
            showToast('Entregable guardado', 'success');
            state.currentTicket.deliverable = deliverable;
            // Habilitar botón de revisión
            const btnReview = document.getElementById('btn-request-review');
            if (btnReview) {
                btnReview.disabled = false;
                btnReview.removeAttribute('title');
            }
        } else {
            showToast(response.error || 'Error al guardar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function requestReview(ticketId) {
    const deliverable = document.getElementById('deliverable-input')?.value.trim() || state.currentTicket?.deliverable;
    
    if (!deliverable) {
        showToast('Primero guarda el entregable', 'warning');
        return;
    }
    
    if (!confirm('¿Enviar a revisión? Se notificará a Alfonso y Alicia.')) {
        return;
    }
    
    try {
        const response = await apiCall(`${TICKETS_API}?action=request-review&id=${ticketId}`, {
            method: 'POST',
            body: JSON.stringify({ deliverable })
        });
        
        if (response.success) {
            showToast('Enviado a revisión. Alfonso y Alicia han sido notificados.', 'success');
            loadTicketDetail(ticketId);
        } else {
            showToast(response.error || 'Error al enviar a revisión', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function approveTicket(ticketId) {
    if (!confirm('¿Aprobar este ticket? Se enviará WhatsApp al cliente con el entregable.')) {
        return;
    }
    
    try {
        const response = await apiCall(`${TICKETS_API}?action=approve&id=${ticketId}`, {
            method: 'POST'
        });
        
        if (response.success) {
            showToast('Ticket aprobado. Cliente notificado por WhatsApp.', 'success');
            loadTicketDetail(ticketId);
        } else {
            showToast(response.error || 'Error al aprobar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function rejectTicket(ticketId) {
    const reason = prompt('Motivo del rechazo (se notificará al agente):');
    
    if (reason === null) return; // Canceló
    
    if (!reason.trim()) {
        showToast('Debes indicar un motivo', 'warning');
        return;
    }
    
    try {
        const response = await apiCall(`${TICKETS_API}?action=reject&id=${ticketId}`, {
            method: 'POST',
            body: JSON.stringify({ reason: reason.trim() })
        });
        
        if (response.success) {
            showToast('Ticket rechazado. Agente notificado.', 'success');
            loadTicketDetail(ticketId);
        } else {
            showToast(response.error || 'Error al rechazar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function deleteTicket() {
    if (!state.currentTicket) {
        showToast('No hay ticket seleccionado', 'error');
        return;
    }
    
    const ticketNumber = state.currentTicket.ticket_number;
    const confirmed = confirm(`¿Estás seguro de que quieres eliminar el ticket ${ticketNumber}?\n\nEsta acción no se puede deshacer.`);
    
    if (!confirmed) return;
    
    try {
        const response = await apiCall(`${TICKETS_API}?action=delete&id=${state.currentTicket.id}`, {
            method: 'DELETE'
        });
        
        if (response.success) {
            showToast(`Ticket ${ticketNumber} eliminado`, 'success');
            state.currentTicket = null;
            showView('tickets');
            loadTickets();
            loadStats();
        } else {
            showToast(response.error || 'Error al eliminar ticket', 'error');
        }
    } catch (error) {
        console.error('Error deleting ticket:', error);
        showToast('Error de conexión', 'error');
    }
}

function goToPage(page) {
    if (page < 1 || page > state.pagination.pages) return;
    state.pagination.page = page;
    loadTickets();
    window.scrollTo(0, 0);
}

// =====================================================
// Helpers
// =====================================================

function populateSelects() {
    // Categorías - incluir el nuevo modal
    const categorySelects = document.querySelectorAll('#ticket-category, #filter-category, #new-ticket-category');
    categorySelects.forEach(select => {
        const options = state.categories.map(c => 
            `<option value="${c.id}">${c.name}</option>`
        ).join('');
        
        if (select.id === 'filter-category') {
            select.innerHTML = '<option value="">Todas las categorías</option>' + options;
        } else {
            select.innerHTML = '<option value="">Seleccionar...</option>' + options;
        }
    });

    // Agentes - incluir el nuevo modal
    const agentSelects = document.querySelectorAll('#ticket-assigned, #new-ticket-assigned');
    agentSelects.forEach(select => {
        select.innerHTML = '<option value="">Sin asignar</option>' + 
            state.agents.map(a => `<option value="${a.id}">${a.name}</option>`).join('');
    });

    // Filtro de asignados
    const filterAssigned = document.getElementById('filter-assigned');
    if (filterAssigned) {
        filterAssigned.innerHTML = '<option value="">Todos los usuarios</option>' + 
            state.agents.map(a => `<option value="${a.id}">${a.name}</option>`).join('');
    }

    // Clientes/Cuentas
    const clientSelect = document.getElementById('new-ticket-client');
    if (clientSelect) {
        clientSelect.innerHTML = '<option value="">Seleccionar...</option>' +
            state.clients.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
    }

    // Proyectos
    const projectSelect = document.getElementById('new-ticket-project');
    if (projectSelect) {
        projectSelect.innerHTML = '<option value="">Seleccionar...</option>' +
            state.projects.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
    }

    // Poblar filtros de backlog
    populateBacklogFilters();
}

function updateBadges() {
    const stats = state.stats;
    const badgeTotal = document.getElementById('badge-total');
    const badgeOpen = document.getElementById('badge-open');
    
    if (badgeTotal) badgeTotal.textContent = stats.total || 0;
    if (badgeOpen) badgeOpen.textContent = stats.open || 0;
}

function updateBacklogBadge(count, type = 'consultoria') {
    const badgeId = type === 'aib' ? 'badge-backlog-aib' : 'badge-backlog-consultoria';
    const badge = document.getElementById(badgeId);
    if (badge) badge.textContent = count || 0;
}

function getStatusLabel(status) {
    const labels = {
        open: 'Abierto',
        in_progress: 'En Progreso',
        waiting: 'En Espera',
        resolved: 'Resuelto',
        closed: 'Cerrado'
    };
    return labels[status] || status;
}

function getPriorityLabel(priority) {
    const labels = {
        low: 'Baja',
        medium: 'Media',
        high: 'Alta',
        urgent: 'Urgente'
    };
    return labels[priority] || priority;
}

function getSourceLabel(source) {
    const labels = {
        internal: 'Interno',
        external: 'Externo',
        form: 'Formulario',
        api: 'API'
    };
    return labels[source] || source;
}

function getActivityLabel(action) {
    const labels = {
        ticket_created: 'Ticket creado',
        changed_status: 'Estado cambiado',
        changed_priority: 'Prioridad cambiada',
        changed_assigned_to: 'Asignación cambiada',
        assigned: 'Ticket asignado',
        changed_backlog: 'Backlog cambiado',
        comment_added: 'Comentario añadido',
        status_changed: 'Estado cambiado'
    };
    return labels[action] || action.replace(/_/g, ' ');
}

function getActivityIcon(action) {
    const icons = {
        ticket_created: 'fas fa-plus-circle',
        changed_status: 'fas fa-exchange-alt',
        changed_priority: 'fas fa-exclamation-circle',
        changed_assigned_to: 'fas fa-user-check',
        assigned: 'fas fa-user-check',
        changed_backlog: 'fas fa-inbox',
        comment_added: 'fas fa-comment',
        status_changed: 'fas fa-exchange-alt'
    };
    return icons[action] || 'fas fa-history';
}

function getDetailedActivityLabel(activity) {
    const { action, old_value, new_value, user_name, assigned_to_name } = activity;
    
    const formatValue = (val) => {
        if (val === '1' || val === 1 || val === true) return 'En backlog';
        if (val === '0' || val === 0 || val === false || val === null || val === '') return 'Fuera del backlog';
        return val;
    };
    
    switch(action) {
        case 'ticket_created':
            return 'creó este ticket';
        case 'changed_status':
        case 'status_changed':
            return `cambió el estado de <span class="badge">${old_value || 'inicial'}</span> a <span class="badge">${new_value}</span>`;
        case 'changed_priority':
            return `cambió la prioridad de <span class="badge">${old_value || 'inicial'}</span> a <span class="badge">${new_value}</span>`;
        case 'changed_assigned_to':
        case 'assigned':
            const nameToShow = assigned_to_name || new_value;
            if (nameToShow === 'null' || nameToShow === null || nameToShow === '0') {
                return 'removió la asignación del ticket';
            }
            return `asignó el ticket a <strong>${nameToShow}</strong>`;
        case 'changed_backlog':
            return `cambió el estado de backlog de <span class="badge">${formatValue(old_value)}</span> a <span class="badge">${formatValue(new_value)}</span>`;
        case 'comment_added':
            return 'añadió un comentario';
        default:
            return getActivityLabel(action);
    }
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toLocaleDateString('es-ES', { 
        day: '2-digit', 
        month: 'short', 
        year: 'numeric' 
    });
}

function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toLocaleDateString('es-ES', { 
        day: '2-digit', 
        month: 'short', 
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function timeAgo(dateStr) {
    if (!dateStr) return '';
    
    const date = new Date(dateStr);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);

    const intervals = {
        año: 31536000,
        mes: 2592000,
        semana: 604800,
        día: 86400,
        hora: 3600,
        minuto: 60
    };

    for (const [unit, secondsInUnit] of Object.entries(intervals)) {
        const interval = Math.floor(seconds / secondsInUnit);
        if (interval >= 1) {
            return `hace ${interval} ${unit}${interval > 1 ? (unit === 'mes' ? 'es' : 's') : ''}`;
        }
    }

    return 'hace un momento';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        <span>${message}</span>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideIn 0.3s ease reverse';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// =====================================================
// GHL Integration
// =====================================================

// Escuchar mensajes de GHL
window.addEventListener('message', (event) => {
    // Verificar origen si es necesario
    // if (event.origin !== 'https://app.gohighlevel.com') return;

    const data = event.data;
    
    if (data.type === 'ghl_contact') {
        // Pre-llenar datos del contacto de GHL
        console.log('Contact data from GHL:', data);
    }
    
    if (data.type === 'ghl_form_submission') {
        // Crear ticket desde formulario GHL
        console.log('Form submission from GHL:', data);
    }
});

// Enviar mensaje a GHL
function sendToGHL(type, data) {
    if (window.parent !== window) {
        window.parent.postMessage({ type, ...data }, '*');
    }
}

async function assignTicketFromBacklog(ticketId, backlogType = 'consultoria') {
    // Mostrar modal para seleccionar agente o asignar a usuario actual
    const agents = state.agents || [];
    
    if (agents.length === 0) {
        showToast('No hay agentes disponibles', 'error');
        return;
    }

    // Crear modal dinámico
    const modal = document.createElement('div');
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h2>Asignar Ticket</h2>
                <button class="btn-icon" onclick="this.closest('.modal-overlay').remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Asignar a:</label>
                    <select id="assign-agent" class="form-control">
                        <option value="">Selecciona un agente...</option>
                        ${agents.map(agent => `<option value="${agent.id}">${agent.name}</option>`).join('')}
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancelar</button>
                <button class="btn btn-primary" onclick="confirmBacklogAssignment(${ticketId}, '${backlogType}')">Asignar</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    document.getElementById('assign-agent').focus();
}

async function confirmBacklogAssignment(ticketId, backlogType = 'consultoria') {
    const agentId = document.getElementById('assign-agent').value;
    
    if (!agentId) {
        showToast('Selecciona un agente', 'error');
        return;
    }

    try {
        const response = await fetch(`${TICKETS_API}?action=update&id=${ticketId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                assigned_to: parseInt(agentId),
                backlog: false  // Sacar del backlog al asignar
            })
        });

        const result = await response.json();

        if (result.success) {
            showToast('✅ Ticket asignado correctamente', 'success');
            document.querySelector('.modal-overlay.active')?.remove();
            loadBacklogTickets(backlogType);  // Recargar backlog con el tipo correcto
            loadTickets();                     // Recargar tabla de tickets
            loadStats();                       // Recargar estadísticas
            // Si está abierto el detalle, recargarlo también
            if (state.currentTicket && state.currentTicket.id === ticketId) {
                loadTicketDetail(ticketId);
            }
        } else {
            showToast(result.error || 'Error al asignar ticket', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Error al asignar ticket', 'error');
    }
}

// =====================================================
// Agent Dashboard Functions
// =====================================================

// Mapeo de email a nombre corto para los elementos HTML
const AGENT_EMAIL_TO_SHORT = {
    'oscar.calamita@wixyn.com': 'oscar',
    'faguerre@abelross.com': 'fiorella',
    'victoria.aparicio@conmenospersonal.io': 'victoria',
    'andrea@wixyn.com': 'andrea',
    'gabriela.carvajal@wixyn.com': 'gabriela'
};

// Buscar agente por email en state.agents (cargado del API)
function getAgentByEmail(email) {
    return state.agents.find(a => a.email === email);
}

async function loadAgentDashboardByEmail(email) {
    const agent = getAgentByEmail(email);
    if (!agent) {
        showToast('Agente no encontrado', 'error');
        return;
    }
    
    const agentShort = AGENT_EMAIL_TO_SHORT[email];
    if (!agentShort) return;
    
    // Mostrar la sección correcta
    document.querySelectorAll('.content-section').forEach(section => {
        section.classList.add('hidden');
    });
    const viewSection = document.getElementById(`view-agent-${agentShort}`);
    if (viewSection) {
        viewSection.classList.remove('hidden');
    }
    
    const dashboard = document.getElementById(`agent-${agentShort}-dashboard`);
    if (!dashboard) return;

    dashboard.innerHTML = '<div class="loading">Cargando datos...</div>';

    try {
        // Cargar estadísticas usando el ID del agente
        const statsResponse = await fetch(`${HELPERS_API}?action=agent-stats&agent_id=${agent.id}`);
        const statsData = await statsResponse.json();

        // Cargar tickets
        const ticketsResponse = await fetch(`${HELPERS_API}?action=agent-tickets&agent_id=${agent.id}`);
        const ticketsData = await ticketsResponse.json();

        if (statsData.success && ticketsData.success) {
            dashboard.innerHTML = renderAgentDashboard(agent, statsData.data, ticketsData.data);
        } else {
            dashboard.innerHTML = '<div class="error">Error al cargar datos del agente</div>';
        }
    } catch (error) {
        console.error('Error loading agent dashboard:', error);
        dashboard.innerHTML = '<div class="error">Error al cargar el dashboard</div>';
    }
}

function renderAgentDashboard(agent, stats, tickets) {
    const getStatusColor = (status) => {
        const colors = {
            'open': '#ff6b6b',
            'in_progress': '#4dabf7',
            'waiting': '#ffd43b',
            'resolved': '#51cf66',
            'closed': '#868e96'
        };
        return colors[status] || '#868e96';
    };

    const getPriorityIcon = (priority) => {
        const icons = {
            'urgent': '🔴',
            'high': '🟠',
            'medium': '🔵',
            'low': '🟢'
        };
        return icons[priority] || '⚪';
    };

    let html = `
        <div class="agent-stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #6366f1;">
                    <i class="fas fa-list"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value">${stats.total}</span>
                    <span class="stat-label">Tickets Totales</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background-color: #f59e0b;">
                    <i class="fas fa-hourglass-start"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value">${stats.open}</span>
                    <span class="stat-label">Abiertos/En Progreso</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background-color: #10b981;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value">${stats.resolved}</span>
                    <span class="stat-label">Resueltos</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background-color: #8b5cf6;">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value">${stats.avg_resolution_hours}h</span>
                    <span class="stat-label">Tiempo Promedio</span>
                </div>
            </div>
        </div>

        <div class="agent-dashboard-grid">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> Por Estado</h3>
                </div>
                <div class="card-body">
                    <div class="status-bars">
                        ${stats.by_status.map(s => `
                            <div class="status-bar-item">
                                <div class="status-bar-label">
                                    <span>${s.status.replace('_', ' ')}</span>
                                    <span class="badge">${s.count}</span>
                                </div>
                                <div class="status-bar" style="background-color: ${getStatusColor(s.status)}; width: ${(s.count / stats.total * 100) || 0}%"></div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-exclamation-triangle"></i> Por Prioridad</h3>
                </div>
                <div class="card-body">
                    <div class="priority-list">
                        ${stats.by_priority.map(p => `
                            <div class="priority-item">
                                <span>${getPriorityIcon(p.priority)} ${p.priority}</span>
                                <span class="badge">${p.count}</span>
                            </div>
                        `).join('')}
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-tasks"></i> Tickets Asignados</h3>
            </div>
            <div class="card-body">
                <div class="agent-tickets-table">
                    <table class="tickets-table">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Asunto</th>
                                <th>Estado</th>
                                <th>Prioridad</th>
                                <th>Categoría</th>
                                <th>Creado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tickets.length > 0 ? tickets.map(t => `
                                <tr class="status-${t.status}">
                                    <td class="ticket-cell"><strong>${t.ticket_number}</strong></td>
                                    <td>${t.title}</td>
                                    <td>
                                        <span class="status-badge status-${t.status}">
                                            ${t.status.replace('_', ' ')}
                                        </span>
                                    </td>
                                    <td>${getPriorityIcon(t.priority)} ${t.priority}</td>
                                    <td>${t.category_name || '-'}</td>
                                    <td>${new Date(t.created_at).toLocaleDateString('es-ES')}</td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="loadTicketDetail(${t.id}); showView('ticket-detail')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            `).join('') : '<tr><td colspan="7" style="text-align: center; color: #999;">No hay tickets asignados</td></tr>'}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;

    return html;
}

window.loadAgentDashboard = loadAgentDashboard;
window.loadTicketDetail = loadTicketDetail;
window.updateTicketField = updateTicketField;
window.toggleInfoPending = toggleInfoPending;
window.markInfoPending = markInfoPending;
window.markInfoComplete = markInfoComplete;
window.addComment = addComment;
window.saveDeliverable = saveDeliverable;
window.requestReview = requestReview;
window.approveTicket = approveTicket;
window.rejectTicket = rejectTicket;
window.deleteTicket = deleteTicket;
window.goToPage = goToPage;
window.showView = showView;
window.toggleView = toggleView;
window.openNewTicketModal = openNewTicketModal;
window.submitNewTicket = submitNewTicket;
window.switchWorkType = switchWorkType;
window.toggleTimer = toggleTimer;
window.resetTimer = resetTimer;
window.saveTimer = saveTimer;
window.assignTicketFromBacklog = assignTicketFromBacklog;
window.confirmBacklogAssignment = confirmBacklogAssignment;
window.toggleBacklogView = toggleBacklogView;
window.filterBacklog = filterBacklog;
window.clearBacklogFilters = clearBacklogFilters;
window.switchBacklogTab = switchBacklogTab;
window.loadBacklogStats = loadBacklogStats;
window.loadBacklogHistory = loadBacklogHistory;
window.loadBacklogPendingReview = loadBacklogPendingReview;

// =====================================================
// GESTIÓN DE PROYECTOS
// =====================================================

const PROJECTS_API = 'api/projects.php';
let currentProject = null;

async function loadProjects() {
    const container = document.getElementById('projects-list');
    if (!container) return;
    
    container.innerHTML = '<div class="loading">Cargando proyectos...</div>';
    
    try {
        const response = await apiCall(`${PROJECTS_API}?action=list`);
        
        if (response.success && response.projects) {
            renderProjects(response.projects);
            populateResponsibleFilter(response.projects);
        } else {
            container.innerHTML = '<p>Error al cargar proyectos</p>';
        }
    } catch (error) {
        console.error('Error loading projects:', error);
        container.innerHTML = '<p>Error de conexión</p>';
    }
}

function renderProjects(projects) {
    const container = document.getElementById('projects-list');
    
    if (!projects || projects.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="grid-column: 1 / -1; text-align: center; padding: 60px;">
                <i class="fas fa-folder-open" style="font-size: 4rem; color: var(--gray-300); margin-bottom: 20px;"></i>
                <h3 style="color: var(--gray-500);">No hay proyectos</h3>
                <p style="color: var(--gray-400);">Crea tu primer proyecto para comenzar</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = projects.map(project => `
        <div class="project-card" onclick="loadProjectDetail(${project.id})">
            <div class="project-card-header">
                <div class="project-card-title">
                    <i class="fas fa-folder"></i>
                    ${escapeHtml(project.name)}
                </div>
                <span class="project-status ${project.status}">${project.status === 'active' ? 'Activo' : 'Inactivo'}</span>
            </div>
            <div class="project-card-meta">
                ${project.responsible_name ? `<span><i class="fas fa-user"></i> ${escapeHtml(project.responsible_name)}</span>` : ''}
                ${project.client_name ? `<span><i class="fas fa-building"></i> ${escapeHtml(project.client_name)}</span>` : ''}
                <span><i class="fas fa-layer-group"></i> ${project.phase_count || 0} fases</span>
                <span><i class="fas fa-tasks"></i> ${project.activity_count || 0} actividades</span>
            </div>
            <div class="project-progress">
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" style="width: ${project.progress}%"></div>
                </div>
                <div class="progress-text">
                    <span>${project.progress}% completado</span>
                    <span>${project.completed_activities || 0}/${project.activity_count || 0} actividades</span>
                </div>
            </div>
        </div>
    `).join('');
}

function populateResponsibleFilter(projects) {
    const filter = document.getElementById('projects-filter-responsible');
    if (!filter) return;
    
    const responsibles = [...new Set(projects.filter(p => p.responsible_name).map(p => JSON.stringify({id: p.responsible_id, name: p.responsible_name})))];
    
    filter.innerHTML = '<option value="">Todos los responsables</option>' +
        responsibles.map(r => {
            const resp = JSON.parse(r);
            return `<option value="${resp.id}">${escapeHtml(resp.name)}</option>`;
        }).join('');
}

function filterProjects() {
    const search = document.getElementById('projects-search')?.value.toLowerCase() || '';
    const responsible = document.getElementById('projects-filter-responsible')?.value || '';
    
    document.querySelectorAll('.project-card').forEach(card => {
        const title = card.querySelector('.project-card-title')?.textContent.toLowerCase() || '';
        const meta = card.querySelector('.project-card-meta')?.textContent.toLowerCase() || '';
        
        const matchesSearch = !search || title.includes(search) || meta.includes(search);
        const matchesResponsible = !responsible || card.dataset.responsible === responsible;
        
        card.style.display = matchesSearch && matchesResponsible ? '' : 'none';
    });
}

async function loadProjectDetail(projectId) {
    try {
        const response = await apiCall(`${PROJECTS_API}?action=get&id=${projectId}`);
        
        if (response.success && response.project) {
            currentProject = response.project;
            renderProjectDetail(response.project);
            showView('project-detail');
        } else {
            showToast(response.error || 'Error al cargar proyecto', 'error');
        }
    } catch (error) {
        console.error('Error loading project detail:', error);
        showToast('Error de conexión', 'error');
    }
}

function renderProjectDetail(project) {
    // Título
    document.getElementById('project-detail-title').innerHTML = 
        `<i class="fas fa-folder-open"></i> ${escapeHtml(project.name)}`;
    
    // Overview
    const overview = document.getElementById('project-overview');
    overview.innerHTML = `
        <div class="project-info">
            <h2>${escapeHtml(project.name)}</h2>
            ${project.description ? `<p>${escapeHtml(project.description)}</p>` : ''}
            <div class="project-details">
                <div class="project-detail-item">
                    <span class="label">Responsable</span>
                    <span class="value">${project.responsible_name || 'Sin asignar'}</span>
                </div>
                <div class="project-detail-item">
                    <span class="label">Cliente</span>
                    <span class="value">${project.client_name || 'Sin cliente'}</span>
                </div>
                ${project.start_date ? `
                <div class="project-detail-item">
                    <span class="label">Inicio</span>
                    <span class="value">${formatDate(project.start_date)}</span>
                </div>
                ` : ''}
                ${project.end_date ? `
                <div class="project-detail-item">
                    <span class="label">Fin</span>
                    <span class="value">${formatDate(project.end_date)}</span>
                </div>
                ` : ''}
            </div>
        </div>
        <div class="project-stats">
            <div class="project-stat">
                <div class="number">${project.stats?.total_phases || 0}</div>
                <div class="label">Fases</div>
            </div>
            <div class="project-stat">
                <div class="number">${project.stats?.total_activities || 0}</div>
                <div class="label">Actividades</div>
            </div>
            <div class="project-stat">
                <div class="number">${project.stats?.completed_activities || 0}</div>
                <div class="label">Completadas</div>
            </div>
            <div class="project-stat">
                <div class="number">${project.stats?.progress || 0}%</div>
                <div class="label">Progreso</div>
            </div>
        </div>
    `;
    
    // Roadmap
    renderRoadmap(project.phases || []);
}

function renderRoadmap(phases) {
    const container = document.getElementById('roadmap-container');
    
    if (!phases || phases.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-lg);">
                <i class="fas fa-road" style="font-size: 4rem; color: var(--gray-300); margin-bottom: 20px;"></i>
                <h3 style="color: var(--gray-500);">Sin roadmap</h3>
                <p style="color: var(--gray-400);">Crea la primera fase para comenzar el roadmap</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = phases.map((phase, index) => `
        <div class="roadmap-phase" data-phase-id="${phase.id}">
            <div class="phase-header" onclick="togglePhase(${phase.id})">
                <div class="phase-title">
                    <i class="fas fa-chevron-down" id="phase-chevron-${phase.id}"></i>
                    <i class="fas fa-layer-group"></i>
                    <span>${escapeHtml(phase.name)}</span>
                    <span style="color: var(--gray-400); font-weight: normal; font-size: 0.85rem;">
                        (${phase.activities?.length || 0} actividades)
                    </span>
                </div>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span class="phase-status ${phase.status}">${getPhaseStatusLabel(phase.status)}</span>
                    <div class="phase-actions" onclick="event.stopPropagation()">
                        <button class="btn btn-sm btn-ghost" onclick="editPhase(${phase.id})" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-ghost" onclick="deletePhase(${phase.id})" title="Eliminar">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="phase-content" id="phase-content-${phase.id}">
                <div class="activities-list">
                    ${renderActivities(phase.activities || [], phase.id)}
                    <button class="add-activity-btn" onclick="openNewActivityModal(${phase.id})">
                        <i class="fas fa-plus"></i> Agregar actividad
                    </button>
                </div>
            </div>
        </div>
        ${index < phases.length - 1 ? '<div class="roadmap-connector"></div>' : ''}
    `).join('');
}

function renderActivities(activities, phaseId) {
    if (!activities || activities.length === 0) {
        return `
            <div class="phase-empty">
                <i class="fas fa-inbox"></i>
                <p>Sin actividades en esta fase</p>
            </div>
        `;
    }
    
    return activities.map(activity => `
        <div class="activity-item" data-activity-id="${activity.id}">
            <div class="activity-checkbox ${activity.status}" 
                 onclick="toggleActivityStatus(${activity.id}, '${activity.status}')"
                 title="${activity.status === 'converted' ? 'Convertido a ticket' : 'Click para cambiar estado'}">
                ${activity.status === 'completed' || activity.status === 'converted' ? '<i class="fas fa-check"></i>' : ''}
            </div>
            <div class="activity-info">
                <div class="activity-title ${activity.status === 'completed' ? 'completed' : ''}">
                    ${escapeHtml(activity.title)}
                </div>
                <div class="activity-meta">
                    ${activity.assigned_name ? `<span><i class="fas fa-user"></i> ${escapeHtml(activity.assigned_name)}</span>` : ''}
                    ${activity.contact_name ? `<span><i class="fas fa-phone"></i> ${escapeHtml(activity.contact_name)}</span>` : ''}
                    ${activity.video_url ? `<span><i class="fas fa-video"></i> Con vídeo</span>` : ''}
                </div>
            </div>
            ${activity.ticket_id ? `
                <div class="activity-converted" onclick="loadTicketDetail(${activity.ticket_id}); showView('ticket-detail')">
                    <i class="fas fa-ticket-alt"></i> ${activity.ticket_number || 'Ticket'}
                </div>
            ` : `
                <div class="activity-actions">
                    <button class="btn btn-sm btn-warning" onclick="convertActivityToTicket(${activity.id})" title="Bajar a Ticket">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    <button class="btn btn-sm btn-ghost" onclick="editActivity(${activity.id})" title="Editar">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-ghost" onclick="deleteActivity(${activity.id})" title="Eliminar">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `}
        </div>
    `).join('');
}

function getPhaseStatusLabel(status) {
    const labels = {
        'pending': 'Pendiente',
        'in_progress': 'En Progreso',
        'completed': 'Completada'
    };
    return labels[status] || status;
}

function togglePhase(phaseId) {
    const content = document.getElementById(`phase-content-${phaseId}`);
    const chevron = document.getElementById(`phase-chevron-${phaseId}`);
    
    if (content) {
        content.classList.toggle('collapsed');
        if (chevron) {
            chevron.classList.toggle('fa-chevron-down');
            chevron.classList.toggle('fa-chevron-right');
        }
    }
}

// =====================================================
// MODALES DE PROYECTOS
// =====================================================

function openNewProjectModal() {
    document.getElementById('modal-project-title').innerHTML = '<i class="fas fa-folder-plus"></i> Nuevo Proyecto';
    document.getElementById('form-project').reset();
    document.getElementById('project-id').value = '';
    
    // Cargar opciones de responsables y clientes
    populateProjectSelects();
    
    openModal('modal-project');
}

async function populateProjectSelects() {
    // Cargar usuarios para responsable
    const respSelect = document.getElementById('project-responsible');
    if (respSelect && state.agents && state.agents.length > 0) {
        respSelect.innerHTML = '<option value="">Sin asignar</option>' +
            state.agents.map(a => `<option value="${a.id}">${escapeHtml(a.name)}</option>`).join('');
    }
    
    // Cargar clientes
    const clientSelect = document.getElementById('project-client');
    if (clientSelect && state.clients && state.clients.length > 0) {
        clientSelect.innerHTML = '<option value="">Sin cliente</option>' +
            state.clients.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
    }
}

function editCurrentProject() {
    if (!currentProject) return;
    
    document.getElementById('modal-project-title').innerHTML = '<i class="fas fa-edit"></i> Editar Proyecto';
    document.getElementById('project-id').value = currentProject.id;
    document.getElementById('project-name').value = currentProject.name || '';
    document.getElementById('project-description').value = currentProject.description || '';
    document.getElementById('project-start-date').value = currentProject.start_date || '';
    document.getElementById('project-end-date').value = currentProject.end_date || '';
    
    populateProjectSelects();
    
    setTimeout(() => {
        document.getElementById('project-responsible').value = currentProject.responsible_id || '';
        document.getElementById('project-client').value = currentProject.client_id || '';
    }, 100);
    
    openModal('modal-project');
}

async function saveProject(event) {
    event.preventDefault();
    
    const form = document.getElementById('form-project');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const id = data.id;
    delete data.id;
    
    try {
        const action = id ? `update&id=${id}` : 'create';
        const response = await apiCall(`${PROJECTS_API}?action=${action}`, {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify(data)
        });
        
        if (response.success) {
            showToast(id ? 'Proyecto actualizado' : 'Proyecto creado', 'success');
            closeModal('modal-project');
            
            if (id && currentProject) {
                loadProjectDetail(id);
            } else {
                loadProjects();
            }
        } else {
            showToast(response.error || 'Error al guardar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

// =====================================================
// MODALES DE FASES
// =====================================================

function openNewPhaseModal() {
    if (!currentProject) return;
    
    document.getElementById('modal-phase-title').innerHTML = '<i class="fas fa-layer-group"></i> Nueva Fase';
    document.getElementById('form-phase').reset();
    document.getElementById('phase-id').value = '';
    document.getElementById('phase-project-id').value = currentProject.id;
    
    openModal('modal-phase');
}

function editPhase(phaseId) {
    const phase = currentProject?.phases?.find(p => p.id === phaseId);
    if (!phase) return;
    
    document.getElementById('modal-phase-title').innerHTML = '<i class="fas fa-edit"></i> Editar Fase';
    document.getElementById('phase-id').value = phase.id;
    document.getElementById('phase-project-id').value = currentProject.id;
    document.getElementById('phase-name').value = phase.name || '';
    document.getElementById('phase-description').value = phase.description || '';
    document.getElementById('phase-start-date').value = phase.start_date || '';
    document.getElementById('phase-end-date').value = phase.end_date || '';
    
    openModal('modal-phase');
}

async function savePhase(event) {
    event.preventDefault();
    
    const form = document.getElementById('form-phase');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const id = data.id;
    delete data.id;
    
    try {
        const action = id ? `update-phase&id=${id}` : 'create-phase';
        const response = await apiCall(`${PROJECTS_API}?action=${action}`, {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify(data)
        });
        
        if (response.success) {
            showToast(id ? 'Fase actualizada' : 'Fase creada', 'success');
            closeModal('modal-phase');
            loadProjectDetail(currentProject.id);
        } else {
            showToast(response.error || 'Error al guardar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function deletePhase(phaseId) {
    if (!confirm('¿Eliminar esta fase y todas sus actividades?')) return;
    
    try {
        const response = await apiCall(`${PROJECTS_API}?action=delete-phase&id=${phaseId}`, {
            method: 'DELETE'
        });
        
        if (response.success) {
            showToast('Fase eliminada', 'success');
            loadProjectDetail(currentProject.id);
        } else {
            showToast(response.error || 'Error al eliminar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

// =====================================================
// MODALES DE ACTIVIDADES
// =====================================================

function openNewActivityModal(phaseId) {
    document.getElementById('modal-activity-title').innerHTML = '<i class="fas fa-tasks"></i> Nueva Actividad';
    document.getElementById('form-activity').reset();
    document.getElementById('activity-id').value = '';
    document.getElementById('activity-phase-id').value = phaseId;
    
    populateActivitySelects();
    
    openModal('modal-activity');
}

function populateActivitySelects() {
    // Contactos
    const contactSelect = document.getElementById('activity-contact');
    if (contactSelect) {
        contactSelect.innerHTML = `
            <option value="">Sin contacto</option>
            <option value="3">Alfonso Bello</option>
            <option value="6">Alicia Urdiales</option>
            <option value="150">Angel Aparicio</option>
        `;
    }
    
    // Asignados (agentes)
    const assignedSelect = document.getElementById('activity-assigned');
    if (assignedSelect && state.agents && state.agents.length > 0) {
        assignedSelect.innerHTML = '<option value="">Sin asignar</option>' +
            state.agents.map(a => `<option value="${a.id}">${escapeHtml(a.name)}</option>`).join('');
    }
}

function editActivity(activityId) {
    let activity = null;
    for (const phase of (currentProject?.phases || [])) {
        activity = phase.activities?.find(a => a.id === activityId);
        if (activity) break;
    }
    if (!activity) return;
    
    document.getElementById('modal-activity-title').innerHTML = '<i class="fas fa-edit"></i> Editar Actividad';
    document.getElementById('activity-id').value = activity.id;
    document.getElementById('activity-phase-id').value = activity.phase_id;
    document.getElementById('activity-title').value = activity.title || '';
    document.getElementById('activity-description').value = activity.description || '';
    document.getElementById('activity-notes').value = activity.notes || '';
    document.getElementById('activity-video').value = activity.video_url || '';
    
    populateActivitySelects();
    
    setTimeout(() => {
        document.getElementById('activity-contact').value = activity.contact_user_id || '';
        document.getElementById('activity-assigned').value = activity.assigned_to || '';
    }, 100);
    
    openModal('modal-activity');
}

async function saveActivity(event) {
    event.preventDefault();
    
    const form = document.getElementById('form-activity');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const id = data.id;
    delete data.id;
    
    try {
        const action = id ? `update-activity&id=${id}` : 'create-activity';
        const response = await apiCall(`${PROJECTS_API}?action=${action}`, {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify(data)
        });
        
        if (response.success) {
            showToast(id ? 'Actividad actualizada' : 'Actividad creada', 'success');
            closeModal('modal-activity');
            loadProjectDetail(currentProject.id);
        } else {
            showToast(response.error || 'Error al guardar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function deleteActivity(activityId) {
    if (!confirm('¿Eliminar esta actividad?')) return;
    
    try {
        const response = await apiCall(`${PROJECTS_API}?action=delete-activity&id=${activityId}`, {
            method: 'DELETE'
        });
        
        if (response.success) {
            showToast('Actividad eliminada', 'success');
            loadProjectDetail(currentProject.id);
        } else {
            showToast(response.error || 'Error al eliminar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function toggleActivityStatus(activityId, currentStatus) {
    if (currentStatus === 'converted') {
        showToast('Esta actividad ya fue convertida a ticket', 'warning');
        return;
    }
    
    const newStatus = currentStatus === 'completed' ? 'pending' : 'completed';
    
    try {
        const response = await apiCall(`${PROJECTS_API}?action=update-activity&id=${activityId}`, {
            method: 'PUT',
            body: JSON.stringify({ status: newStatus })
        });
        
        if (response.success) {
            loadProjectDetail(currentProject.id);
        } else {
            showToast(response.error || 'Error al actualizar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

async function convertActivityToTicket(activityId) {
    if (!confirm('¿Convertir esta actividad en un ticket?\n\nSe creará un ticket con todos los datos de la actividad.')) return;
    
    try {
        const response = await apiCall(`${PROJECTS_API}?action=convert-to-ticket&id=${activityId}`, {
            method: 'POST'
        });
        
        if (response.success) {
            showToast(`Ticket ${response.ticket_number} creado`, 'success');
            loadProjectDetail(currentProject.id);
        } else {
            showToast(response.error || 'Error al convertir', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

// Exponer funciones globalmente
window.loadProjects = loadProjects;
window.loadProjectDetail = loadProjectDetail;
window.filterProjects = filterProjects;
window.openNewProjectModal = openNewProjectModal;
window.editCurrentProject = editCurrentProject;
window.saveProject = saveProject;
window.openNewPhaseModal = openNewPhaseModal;
window.editPhase = editPhase;
window.savePhase = savePhase;
window.deletePhase = deletePhase;
window.togglePhase = togglePhase;
window.openNewActivityModal = openNewActivityModal;
window.editActivity = editActivity;
window.saveActivity = saveActivity;
window.deleteActivity = deleteActivity;
window.toggleActivityStatus = toggleActivityStatus;
window.convertActivityToTicket = convertActivityToTicket;
