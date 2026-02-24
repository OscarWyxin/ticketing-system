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
const PROJECTS_API = `${API_BASE}/projects.php`;
const NOTIFICATIONS_API = `${API_BASE}/notifications.php`;

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
        status: 'active',  // Por defecto mostrar solo activos
        priority: '',
        category: '',
        search: '',
        assigned: ''
    },
    ticketStatusTab: 'active',  // Tab activo: active, resolved, all
    agentStatusTab: 'active',   // Tab activo para dashboard de agente
    currentView: 'dashboard',
    timer: {
        running: false,
        seconds: 0,
        interval: null,
        ticketId: null  // ID del ticket al que pertenece el timer
    },
    // Sistema de notificaciones
    notifications: [],
    unreadNotifications: 0,
    currentUserId: null,
    currentUserName: 'Admin',
    notificationInterval: null,
    allUsers: [], // Lista de todos los usuarios para @menciones
    // Sistema de autenticación
    auth: {
        token: null,
        user: null,
        isAuthenticated: false
    }
};

const AUTH_API = `${API_BASE}/auth.php`;

// =====================================================
// Inicialización
// =====================================================

document.addEventListener('DOMContentLoaded', () => {
    init();
});

async function init() {
    // Limpiar localStorage viejo (migración a nuevo sistema de auth)
    cleanOldLocalStorage();
    
    // PRIMERO: Verificar autenticación
    const isAuth = await checkAuthentication();
    if (!isAuth) {
        // Redirigir a login
        window.location.href = 'login.html';
        return;
    }
    
    setupEventListeners();
    setupIframeListener();
    
    // Aplicar permisos según rol
    applyRolePermissions();
    
    // El usuario ya está cargado desde auth
    updateUserDisplay();
    
    // Verificar si hay vista guardada que requiere carga especial
    const savedView = localStorage.getItem('ticketing_currentView');
    const savedTicketId = localStorage.getItem('ticketing_currentTicketId');
    const savedAgentEmail = localStorage.getItem('ticketing_agentEmail');
    
    // Cargar data inicial (necesaria para todos los casos)
    await loadInitialData();
    
    // Cargar lista de usuarios para el dropdown
    await loadUsersList();
    
    // Iniciar sistema de notificaciones
    await loadNotifications();
    startNotificationPolling();
    
    // Si hay un ticket específico guardado, cargarlo directamente
    if (savedView === 'ticket-detail' && savedTicketId) {
        await loadStats();
        updateBadges();
        await loadTicketDetail(savedTicketId);
        return; // No cargar más
    }
    
    // Si hay un agente guardado, cargar su dashboard
    if (savedAgentEmail) {
        await loadStats();
        updateBadges();
        loadAgentDashboardByEmail(savedAgentEmail);
        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.toggle('active', item.dataset.agentEmail === savedAgentEmail);
        });
        return;
    }
    
    // Flujo normal: cargar stats y tickets
    await loadStats();
    updateBadges();
    await loadTickets();
    
    // Para agentes: ir directo a su dashboard de tickets
    if (state.auth.user?.role === 'agent') {
        const agentEmail = state.auth.user.email;
        loadAgentDashboardByEmail(agentEmail);
        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.toggle('active', item.dataset.agentEmail === agentEmail);
        });
        return;
    }
    
    // Restaurar vista guardada o mostrar dashboard
    if (savedView && savedView !== 'dashboard' && savedView !== 'ticket-detail') {
        showView(savedView);
    } else {
        // Asegurar que dashboard esté visible (empieza hidden para evitar flickering)
        showView('dashboard');
    }
}

function restoreLastView() {
    const savedView = localStorage.getItem('ticketing_currentView');
    const savedAgentEmail = localStorage.getItem('ticketing_agentEmail');
    const savedTicketId = localStorage.getItem('ticketing_currentTicketId');
    
    if (savedAgentEmail) {
        // Restaurar vista de agente
        loadAgentDashboardByEmail(savedAgentEmail);
        // Marcar nav item activo
        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.toggle('active', item.dataset.agentEmail === savedAgentEmail);
        });
    } else if (savedView === 'ticket-detail' && savedTicketId) {
        // Restaurar vista de ticket específico
        loadTicketDetail(savedTicketId);
    } else if (savedView === 'project-detail') {
        // Para project-detail, volver a proyectos (no persistimos el ID)
        showView('projects');
    } else if (savedView && savedView !== 'dashboard') {
        showView(savedView);
    }
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
            if (view) {
                showView(view);
            }
        });
    });

    // Búsqueda
    const searchInput = document.getElementById('search-input');
    let searchTimeout;
    searchInput?.addEventListener('input', (e) => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            state.filters.search = e.target.value;
            if (e.target.value) {
                // Al buscar, mostrar todos los tickets (sin filtro de estado)
                state.filters.status = 'all';
                state.ticketStatusTab = 'all';
                if (state.currentView !== 'tickets') {
                    showView('tickets');
                    return; // showView ya llama loadTickets
                }
                // Actualizar tab visual
                document.querySelectorAll('.status-tab').forEach(t => {
                    t.classList.toggle('active', t.dataset.status === 'all');
                });
            }
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

// Variables globales para las gráficas
let chartByAgent = null;
let chartByProject = null;

async function loadStats(filters = {}) {
    try {
        // Construir URL con filtros
        const params = new URLSearchParams({ action: 'stats' });
        if (filters.period) params.append('period', filters.period);
        if (filters.date_from) params.append('date_from', filters.date_from);
        if (filters.date_to) params.append('date_to', filters.date_to);
        
        const response = await apiCall(`${TICKETS_API}?${params}`);
        if (response.success) {
            state.stats = response.data;
            renderStats();
        }
    } catch (error) {
        console.error('Error loading stats:', error);
    }
}

// Función para filtrar el dashboard por período
function filterDashboard() {
    const periodSelect = document.getElementById('dashboard-period-filter');
    const customRange = document.getElementById('custom-date-range');
    const filterBadge = document.getElementById('active-filter-badge');
    const filterText = document.getElementById('active-filter-text');
    const period = periodSelect.value;
    
    // Mostrar/ocultar fechas personalizadas
    if (period === 'custom') {
        customRange.style.display = 'flex';
        return; // Esperar a que el usuario haga clic en "Aplicar"
    } else {
        customRange.style.display = 'none';
    }
    
    const filters = {};
    let filterLabel = '';
    
    if (period === 'year') {
        filters.period = period;
        filterLabel = 'Este año';
    } else if (period === 'quarter') {
        filters.period = period;
        filterLabel = 'Este trimestre';
    } else if (period === 'month') {
        filters.period = period;
        filterLabel = 'Este mes';
    }
    
    // Mostrar/ocultar badge de filtro activo
    if (filterLabel && filterBadge) {
        filterText.textContent = filterLabel;
        filterBadge.style.display = 'flex';
    } else if (filterBadge) {
        filterBadge.style.display = 'none';
    }
    
    loadStats(filters);
}

// Función para aplicar filtro de fecha personalizada
function applyCustomDateFilter() {
    const dateFrom = document.getElementById('date-from').value;
    const dateTo = document.getElementById('date-to').value;
    const filterBadge = document.getElementById('active-filter-badge');
    const filterText = document.getElementById('active-filter-text');
    
    if (!dateFrom || !dateTo) {
        showNotification('Selecciona ambas fechas', 'warning');
        return;
    }
    
    if (new Date(dateFrom) > new Date(dateTo)) {
        showNotification('La fecha inicial debe ser anterior a la final', 'warning');
        return;
    }
    
    const filters = {
        date_from: dateFrom,
        date_to: dateTo
    };
    
    // Mostrar badge con rango de fechas
    if (filterBadge) {
        filterText.textContent = `${dateFrom} - ${dateTo}`;
        filterBadge.style.display = 'flex';
    }
    
    loadStats(filters);
}

// Función para limpiar filtros del dashboard
function clearDashboardFilter() {
    const periodSelect = document.getElementById('dashboard-period-filter');
    const customRange = document.getElementById('custom-date-range');
    const filterBadge = document.getElementById('active-filter-badge');
    const dateFrom = document.getElementById('date-from');
    const dateTo = document.getElementById('date-to');
    
    // Resetear todo
    if (periodSelect) periodSelect.value = '';
    if (customRange) customRange.style.display = 'none';
    if (filterBadge) filterBadge.style.display = 'none';
    if (dateFrom) dateFrom.value = '';
    if (dateTo) dateTo.value = '';
    
    // Cargar stats sin filtros
    loadStats({});
}

// Exponer funciones al window
window.filterDashboard = filterDashboard;
window.applyCustomDateFilter = applyCustomDateFilter;
window.clearDashboardFilter = clearDashboardFilter;

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
        // Si hay timer corriendo para OTRO ticket, preguntar antes de cambiar
        if (state.timer.running && state.timer.ticketId && state.timer.ticketId !== id) {
            const confirm = window.confirm(
                `Tienes un timer activo (${formatTimerDisplay(state.timer.seconds)}) para otro ticket.\n\n¿Quieres guardar el tiempo antes de cambiar?`
            );
            if (confirm) {
                await saveTimerForTicket(state.timer.ticketId, state.timer.seconds);
            }
            stopTimerClean();
        }
        
        const response = await apiCall(`${TICKETS_API}?action=get&id=${id}`);
        if (response.success) {
            state.currentTicket = response.data;
            // Guardar ticket ID para persistencia
            localStorage.setItem('ticketing_currentTicketId', id);
            renderTicketDetail();
            showTimerForPuntual();
            
            // Inicializar @menciones en textarea de comentarios
            setTimeout(() => {
                const commentTextarea = document.getElementById('new-comment');
                if (commentTextarea) {
                    initMentionAutocomplete(commentTextarea);
                }
            }, 100);
            
            // Restaurar timer si existe para este ticket, si no resetear
            restoreOrResetTimer(id);
            
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
    
    // KPIs de rendimiento
    document.getElementById('stat-overdue').textContent = stats.overdue || 0;
    document.getElementById('stat-avg-resolution').textContent = (stats.avg_resolution_hours || 0) + 'h';
    document.getElementById('stat-sla').textContent = (stats.sla_compliance || 0) + '%';
    document.getElementById('stat-avg-delay').textContent = (stats.avg_delay_days || 0) + 'd';
    
    // Actualizar badges de navegación inmediatamente
    updateBadges();
    
    // Actualizar tabs de tickets
    updateTicketTabCounts();
    
    // Actualizar mini stats
    updateTicketMiniStats();

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
    
    // Render NEW bar charts
    renderAgentBarChart();
    renderProjectBarChart();
    
    // Render agent stats (tabla resumen)
    renderAgentStats();
    
    // Render requester stats (interno y externo)
    renderInternalRequesterStats();
    renderExternalRequesterStats();

    // Render recent tickets
    renderRecentTickets();
}

// ========== NUEVA GRÁFICA: Tickets por Responsable ==========
function renderAgentBarChart() {
    const canvas = document.getElementById('chart-by-agent');
    console.log('renderAgentBarChart - canvas:', canvas);
    console.log('renderAgentBarChart - by_agent:', state.stats.by_agent);
    console.log('renderAgentBarChart - Chart available:', typeof Chart);
    if (!canvas) {
        console.log('Canvas not found!');
        return;
    }
    
    const agents = state.stats.by_agent || [];
    console.log('renderAgentBarChart - agents count:', agents.length);
    
    if (agents.length === 0) {
        canvas.parentElement.innerHTML = `
            <div class="empty-state" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                <i class="fas fa-user-check" style="font-size: 3rem; opacity: 0.3; margin-bottom: 10px;"></i>
                <p style="color: var(--gray-400);">Sin datos de agentes</p>
            </div>
        `;
        return;
    }
    
    try {
    
    // Destruir gráfica anterior si existe
    if (chartByAgent) {
        chartByAgent.destroy();
    }
    
    const ctx = canvas.getContext('2d');
    chartByAgent = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: agents.map(a => a.agent_name),
            datasets: [
                {
                    label: 'Abiertos',
                    data: agents.map(a => parseInt(a.open_count || 0) + parseInt(a.in_progress || 0)),
                    backgroundColor: '#3b82f6',
                    borderRadius: 4,
                },
                {
                    label: 'En Espera',
                    data: agents.map(a => parseInt(a.waiting || 0)),
                    backgroundColor: '#f59e0b',
                    borderRadius: 4,
                },
                {
                    label: 'Resueltos',
                    data: agents.map(a => parseInt(a.resolved || 0)),
                    backgroundColor: '#22c55e',
                    borderRadius: 4,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15
                    }
                },
                tooltip: {
                    callbacks: {
                        afterBody: function(context) {
                            const idx = context[0].dataIndex;
                            const agent = agents[idx];
                            return `Total: ${agent.total}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: { stepSize: 1 }
                }
            }
        }
    });
    console.log('Chart by agent created successfully');
    } catch (err) {
        console.error('Error creating agent chart:', err);
    }
}

// ========== NUEVA GRÁFICA: Tickets por Proyecto ==========
function renderProjectBarChart() {
    const canvas = document.getElementById('chart-by-project');
    console.log('renderProjectBarChart - canvas:', canvas);
    console.log('renderProjectBarChart - by_project:', state.stats.by_project);
    if (!canvas) return;
    
    const projects = state.stats.by_project || [];
    console.log('renderProjectBarChart - projects count:', projects.length);
    
    if (projects.length === 0) {
        canvas.parentElement.innerHTML = `
            <div class="empty-state" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                <i class="fas fa-project-diagram" style="font-size: 3rem; opacity: 0.3; margin-bottom: 10px;"></i>
                <p style="color: var(--gray-400);">Sin datos de proyectos</p>
            </div>
        `;
        return;
    }
    
    // Destruir gráfica anterior si existe
    if (chartByProject) {
        chartByProject.destroy();
    }
    
    const ctx = canvas.getContext('2d');
    chartByProject = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: projects.map(p => p.project_name.length > 20 ? p.project_name.substring(0, 20) + '...' : p.project_name),
            datasets: [
                {
                    label: 'Abiertos',
                    data: projects.map(p => parseInt(p.open_count || 0) + parseInt(p.in_progress || 0)),
                    backgroundColor: '#3b82f6',
                    borderRadius: 4,
                },
                {
                    label: 'En Espera',
                    data: projects.map(p => parseInt(p.waiting || 0)),
                    backgroundColor: '#f59e0b',
                    borderRadius: 4,
                },
                {
                    label: 'Resueltos',
                    data: projects.map(p => parseInt(p.resolved || 0)),
                    backgroundColor: '#22c55e',
                    borderRadius: 4,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15
                    }
                },
                tooltip: {
                    callbacks: {
                        title: function(context) {
                            const idx = context[0].dataIndex;
                            return projects[idx].project_name;
                        },
                        afterBody: function(context) {
                            const idx = context[0].dataIndex;
                            const project = projects[idx];
                            return `Total: ${project.total}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: { stepSize: 1 }
                }
            }
        }
    });
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
    
    // CORREGIDO: Usar datos del backend en lugar de state.tickets
    const agents = state.stats.by_agent || [];
    
    if (agents.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="padding: 20px;">
                <i class="fas fa-user-check" style="font-size: 2rem; opacity: 0.3;"></i>
                <p style="margin-top: 10px; color: var(--gray-400);">Sin tickets asignados</p>
            </div>
        `;
        return;
    }
    
    const maxTickets = Math.max(...agents.map(a => parseInt(a.total)), 1);
    
    container.innerHTML = agents.slice(0, 6).map(agent => {
        const open = parseInt(agent.open_count || 0) + parseInt(agent.in_progress || 0);
        const waiting = parseInt(agent.waiting || 0);
        const resolved = parseInt(agent.resolved || 0);
        const total = parseInt(agent.total || 0);
        
        return `
        <div class="agent-bar">
            <div class="agent-bar-header">
                <div class="agent-bar-info">
                    <img class="agent-bar-avatar" 
                         src="https://ui-avatars.com/api/?name=${encodeURIComponent(agent.agent_name)}&background=6366f1&color=fff&size=28" 
                         alt="${agent.agent_name}">
                    <span class="agent-bar-name">${agent.agent_name}</span>
                </div>
                <div class="agent-bar-counts" style="display: flex; gap: 8px; font-size: 0.8rem;">
                    <span style="color: #3b82f6;" title="Abiertos">${open}</span>
                    <span style="color: #f59e0b;" title="En espera">${waiting}</span>
                    <span style="color: #22c55e;" title="Resueltos">${resolved}</span>
                    <span style="font-weight: 600;">(${total})</span>
                </div>
            </div>
            <div class="agent-bar-progress" style="display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: var(--gray-100);">
                <div style="width: ${(open / total) * 100}%; background: #3b82f6;"></div>
                <div style="width: ${(waiting / total) * 100}%; background: #f59e0b;"></div>
                <div style="width: ${(resolved / total) * 100}%; background: #22c55e;"></div>
            </div>
        </div>
    `}).join('');
}

function renderInternalRequesterStats() {
    const container = document.getElementById('internal-requester-stats');
    if (!container) return;
    
    const requesters = state.stats.by_internal_requester || [];
    
    if (requesters.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="padding: 20px;">
                <i class="fas fa-building" style="font-size: 2rem; opacity: 0.3;"></i>
                <p style="margin-top: 10px; color: var(--gray-400);">Sin tickets de solicitantes internos</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = requesters.slice(0, 6).map(req => {
        const openCount = parseInt(req.open_count || 0) + parseInt(req.in_progress || 0);
        const total = parseInt(req.total || 0);
        return `
        <div class="client-row">
            <div class="client-info">
                <div class="client-icon">
                    <i class="fas fa-building"></i>
                </div>
                <span class="client-name">${req.requester_name}</span>
            </div>
            <div class="client-count">
                ${openCount > 0 ? `<span class="count-badge has-open">${openCount} abiertos</span>` : ''}
                <span class="count-badge">${total} total</span>
            </div>
        </div>
    `}).join('');
}

function renderExternalRequesterStats() {
    const container = document.getElementById('external-requester-stats');
    if (!container) return;
    
    const requesters = state.stats.by_external_requester || [];
    
    if (requesters.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="padding: 20px;">
                <i class="fas fa-user-tie" style="font-size: 2rem; opacity: 0.3;"></i>
                <p style="margin-top: 10px; color: var(--gray-400);">Sin tickets de solicitantes externos</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = requesters.slice(0, 6).map(req => {
        const openCount = parseInt(req.open_count || 0) + parseInt(req.in_progress || 0);
        const total = parseInt(req.total || 0);
        return `
        <div class="client-row">
            <div class="client-info">
                <div class="client-icon">
                    <i class="fas fa-user-tie"></i>
                </div>
                <span class="client-name">${req.requester_name}</span>
            </div>
            <div class="client-count">
                ${openCount > 0 ? `<span class="count-badge has-open">${openCount} abiertos</span>` : ''}
                <span class="count-badge">${total} total</span>
            </div>
        </div>
    `}).join('');
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
                <td><span class="status-badge status-${ticket.status}">${getStatusLabel(ticket.status)}</span>${getOverdueBadge(ticket)}</td>
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
                    ${ticket.due_date ? 
                        `<span style="color: ${isPastDue(ticket.due_date) ? 'var(--danger)' : 'var(--gray-500)'}">${formatDate(ticket.due_date)}</span>` : 
                        '<span style="color: var(--gray-400)">—</span>'}
                </td>
                <td>${getDeliveryTime(ticket)}</td>
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
                <td>
                    ${ticket.due_date ? 
                        `<span style="color: ${isPastDue(ticket.due_date) ? 'var(--danger)' : 'var(--gray-500)'}">${formatDate(ticket.due_date)}</span>` : 
                        '<span style="color: var(--gray-400)">—</span>'}
                </td>
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
                    <textarea class="form-control" id="new-comment" placeholder="Escribe un comentario... Usa @nombre para mencionar" rows="3"></textarea>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; gap: 15px;">
                            <label style="display: flex; align-items: center; gap: 6px; color: var(--gray-500); font-size: 0.85rem; cursor: pointer;">
                                <input type="checkbox" id="comment-internal"> 
                                <i class="fas fa-lock" style="font-size: 0.75rem;"></i> Nota interna
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px; color: var(--gray-500); font-size: 0.85rem; cursor: pointer;" title="Enviar notificación por email al responsable del ticket">
                                <input type="checkbox" id="notify-assigned"> 
                                <i class="fas fa-bell" style="font-size: 0.75rem; color: var(--warning);"></i> Notificar responsable
                            </label>
                        </div>
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
                    <select class="form-select" style="width: auto; padding: 6px 10px; font-size: 0.85rem;"
                            onchange="updateTicketField(${ticket.id}, 'category_id', this.value)">
                        <option value="">Sin categoría</option>
                        ${state.categories.map(c => `
                            <option value="${c.id}" ${ticket.category_id == c.id ? 'selected' : ''}>${c.name}</option>
                        `).join('')}
                    </select>
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
                    <input type="text" 
                           id="hours-dedicated-input"
                           value="${formatTimerDisplay(Math.round(parseFloat(ticket.hours_dedicated || 0) * 3600))}"
                           onchange="saveManualHours(this)"
                           onkeydown="if(event.key==='Enter'){this.blur();}"
                           style="font-weight: 700; color: var(--primary); background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: 6px; padding: 4px 8px; width: 100px; font-size: 0.95rem; text-align: center; transition: all 0.2s;"
                           onfocus="this.style.borderColor='var(--primary)'; this.style.background='white';"
                           onblur="this.style.borderColor='var(--gray-200)'; this.style.background='var(--gray-50)';"
                           title="Editar tiempo manualmente (HH:MM:SS)" />
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

    return comments.map(comment => {
        // Detectar si es respuesta del cliente (no tiene user_id, solo author_name)
        const isClientResponse = !comment.user_id && comment.author_name;
        
        // Renderizar attachments si existen
        let attachmentsHtml = '';
        if (comment.attachments && comment.attachments.length > 0) {
            attachmentsHtml = '<div class="comment-attachments" style="margin-top: 12px; display: flex; flex-wrap: wrap; gap: 8px;">' +
                comment.attachments.map(att => {
                    const isImage = att.file_type && att.file_type.startsWith('image/');
                    const fileUrl = '/' + att.filename;
                    if (isImage) {
                        return `<a href="${fileUrl}" target="_blank" style="display: block; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                            <img src="${fileUrl}" alt="${escapeHtml(att.original_name)}" style="max-width: 200px; max-height: 150px; display: block;">
                        </a>`;
                    } else {
                        return `<a href="${fileUrl}" target="_blank" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); border-radius: 8px; text-decoration: none; color: var(--gray-700); font-size: 0.85rem; border: 1px solid var(--gray-200); transition: all 0.2s;" onmouseover="this.style.background='linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%)'" onmouseout="this.style.background='linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%)'">
                            <i class="fas fa-file-alt" style="color: var(--primary);"></i>
                            <span>${escapeHtml(att.original_name)}</span>
                            <i class="fas fa-download" style="font-size: 0.75rem; color: var(--gray-400);"></i>
                        </a>`;
                    }
                }).join('') +
            '</div>';
        }
        
        // Estilo especial para respuestas del cliente
        if (isClientResponse) {
            return `
            <div class="comment-item client-response" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1px solid #93c5fd; border-radius: 12px; padding: 16px; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                    <div style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white; padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fas fa-reply"></i>
                        Respuesta del Cliente
                    </div>
                    <span style="color: var(--gray-500); font-size: 0.8rem;">${timeAgo(comment.created_at)}</span>
                </div>
                <div style="display: flex; gap: 12px;">
                    <img class="comment-avatar" src="https://ui-avatars.com/api/?name=${encodeURIComponent(comment.author_name || 'C')}&background=3b82f6&color=fff" alt="" style="width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;">
                    <div style="flex: 1;">
                        <div style="font-weight: 600; color: #1e40af; margin-bottom: 6px;">${escapeHtml(comment.author_name || 'Cliente')}</div>
                        <div style="color: #1e3a5f; line-height: 1.6; white-space: pre-wrap;">${escapeHtml(comment.content)}</div>
                        ${attachmentsHtml}
                    </div>
                </div>
            </div>
        `}
        
        // Comentario normal (interno o de agente)
        return `
        <div class="comment-item ${comment.is_internal ? 'internal' : ''}">
            <img class="comment-avatar" src="https://ui-avatars.com/api/?name=${encodeURIComponent(comment.user_name || comment.author_name || 'U')}&background=6366f1&color=fff" alt="">
            <div class="comment-content">
                <div class="comment-header">
                    <span class="comment-author">${escapeHtml(comment.user_name || comment.author_name || 'Usuario')}</span>
                    <span class="comment-time">${timeAgo(comment.created_at)}</span>
                    ${comment.is_internal ? '<span class="status-badge status-waiting" style="font-size: 0.7rem">Interno</span>' : ''}
                </div>
                <div class="comment-text">${escapeHtml(comment.content)}</div>
                ${attachmentsHtml}
            </div>
        </div>
    `}).join('');
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
    // Si hay timer activo y estamos saliendo del ticket-detail, advertir
    if (state.currentView === 'ticket-detail' && view !== 'ticket-detail' && state.timer.running && state.timer.seconds > 0) {
        const confirm = window.confirm(
            `Tienes un timer activo (${formatTimerDisplay(state.timer.seconds)}).\\n\\n¿Quieres guardar el tiempo antes de salir?`
        );
        if (confirm) {
            saveTimer();
        } else {
            // Preguntar si quiere descartar
            const discard = window.confirm('¿Descartar el tiempo sin guardar?');
            if (!discard) {
                return; // No cambiar de vista
            }
            stopTimerClean();
        }
    }
    
    state.currentView = view;
    
    // Guardar vista en localStorage
    localStorage.setItem('ticketing_currentView', view);
    localStorage.removeItem('ticketing_agentEmail'); // Limpiar agente si cambia de vista
    
    // Limpiar ticket ID si no estamos en ticket-detail
    if (view !== 'ticket-detail') {
        localStorage.removeItem('ticketing_currentTicketId');
    }

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
        // Si hay búsqueda activa, mantenerla y mostrar tab 'all'
        const hasSearch = state.filters.search && state.filters.search.trim() !== '';
        const currentTab = hasSearch ? 'all' : (state.ticketStatusTab || 'active');
        state.filters = { ...state.filters, status: currentTab, priority: '', category: '' };
        // No resetear search si hay búsqueda activa
        if (!hasSearch) state.filters.search = '';
        
        // Actualizar UI del tab
        document.querySelectorAll('.status-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.status === currentTab);
        });
        
        // Sincronizar input de búsqueda del header
        const searchInput = document.getElementById('search-input');
        if (searchInput) searchInput.value = state.filters.search || '';
        
        loadTickets();
    } else if (view === 'backlog-consultoria') {
        loadBacklogTickets('consultoria');
    } else if (view === 'backlog-aib') {
        loadBacklogTickets('aib');
    } else if (view === 'closures-history') {
        populateClosureAgentFilter();
        loadClosuresHistory();
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
    
    if (state.currentTicket && state.currentTicket.work_type === 'puntual') {
        if (timerDisplay) {
            timerDisplay.style.display = 'block';
        }
        updateTimerDisplay();
    } else {
        if (timerDisplay) {
            timerDisplay.style.display = 'none';
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
        // Guardar estado en localStorage
        saveTimerToStorage();
    } else {
        // Iniciar/reanudar timer
        state.timer.running = true;
        state.timer.ticketId = state.currentTicket?.id;
        btn.innerHTML = '<i class="fas fa-pause"></i> Pausar';
        
        state.timer.interval = setInterval(() => {
            state.timer.seconds++;
            updateTimerDisplay();
            // Guardar cada 10 segundos para no perder mucho si cierra
            if (state.timer.seconds % 10 === 0) {
                saveTimerToStorage();
            }
        }, 1000);
    }
}

function resetTimer() {
    stopTimerClean();
    const btn = document.getElementById('btn-timer');
    if (btn) btn.innerHTML = '<i class="fas fa-play"></i> Iniciar';
    updateTimerDisplay();
}

function stopTimerClean() {
    state.timer.running = false;
    state.timer.seconds = 0;
    state.timer.ticketId = null;
    clearInterval(state.timer.interval);
    clearTimerFromStorage();
}

function updateTimerDisplay() {
    const timerElement = document.getElementById('timer-time');
    if (timerElement) {
        timerElement.textContent = formatTimerDisplay(state.timer.seconds);
    }
}

function formatTimerDisplay(totalSeconds) {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

// ========== Timer Storage ==========
function saveTimerToStorage() {
    if (state.timer.ticketId) {
        localStorage.setItem('timer_state', JSON.stringify({
            ticketId: state.timer.ticketId,
            seconds: state.timer.seconds,
            running: state.timer.running,
            savedAt: Date.now()
        }));
    }
}

function clearTimerFromStorage() {
    localStorage.removeItem('timer_state');
}

function restoreOrResetTimer(ticketId) {
    const savedTimer = localStorage.getItem('timer_state');
    
    if (savedTimer) {
        const timerData = JSON.parse(savedTimer);
        
        // Si el timer guardado es para este ticket
        if (timerData.ticketId === ticketId) {
            // Calcular tiempo transcurrido si estaba corriendo
            let restoredSeconds = timerData.seconds;
            if (timerData.running && timerData.savedAt) {
                const elapsedSinceLastSave = Math.floor((Date.now() - timerData.savedAt) / 1000);
                restoredSeconds += elapsedSinceLastSave;
            }
            
            state.timer.seconds = restoredSeconds;
            state.timer.ticketId = ticketId;
            state.timer.running = false; // Siempre empieza pausado al restaurar
            
            const btn = document.getElementById('btn-timer');
            if (btn) btn.innerHTML = '<i class="fas fa-play"></i> Reanudar';
            
            updateTimerDisplay();
            showToast(`⏱️ Timer restaurado: ${formatTimerDisplay(restoredSeconds)}`, 'info');
            return;
        }
    }
    
    // No hay timer guardado para este ticket, resetear
    state.timer.seconds = 0;
    state.timer.ticketId = ticketId;
    state.timer.running = false;
    const btn = document.getElementById('btn-timer');
    if (btn) btn.innerHTML = '<i class="fas fa-play"></i> Iniciar';
    updateTimerDisplay();
}

function formatSecondsToTime(totalSeconds) {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = Math.round(totalSeconds % 60);
    
    return `${hours}h ${minutes}m ${seconds}s`;
}

// Parsear tiempo en varios formatos a horas decimales
function parseTimeToDecimalHours(value) {
    if (!value || value.trim() === '') return 0;
    
    const trimmed = value.trim();
    
    // Si ya es un número decimal (ej: "1.5")
    if (/^\d+(\.\d+)?$/.test(trimmed)) {
        return parseFloat(trimmed);
    }
    
    // Formato HH:MM:SS o HH:MM
    if (trimmed.includes(':')) {
        const parts = trimmed.split(':').map(p => parseInt(p) || 0);
        if (parts.length === 3) {
            // HH:MM:SS
            return parts[0] + (parts[1] / 60) + (parts[2] / 3600);
        } else if (parts.length === 2) {
            // HH:MM
            return parts[0] + (parts[1] / 60);
        }
    }
    
    // Formato "1h 30m 45s" o variaciones
    const hMatch = trimmed.match(/(\d+)\s*h/i);
    const mMatch = trimmed.match(/(\d+)\s*m/i);
    const sMatch = trimmed.match(/(\d+)\s*s/i);
    
    if (hMatch || mMatch || sMatch) {
        const hours = hMatch ? parseInt(hMatch[1]) : 0;
        const minutes = mMatch ? parseInt(mMatch[1]) : 0;
        const seconds = sMatch ? parseInt(sMatch[1]) : 0;
        return hours + (minutes / 60) + (seconds / 3600);
    }
    
    return null; // Formato no reconocido
}

// Guardar horas editadas manualmente
async function saveManualHours(inputElement) {
    if (!state.currentTicket) return;
    
    const value = inputElement.value;
    const decimalHours = parseTimeToDecimalHours(value);
    
    if (decimalHours === null) {
        showToast('Formato inválido. Usa HH:MM:SS, HH:MM o "1h 30m"', 'error');
        // Restaurar valor anterior
        const currentHours = parseFloat(state.currentTicket.hours_dedicated || 0);
        inputElement.value = formatTimerDisplay(Math.round(currentHours * 3600));
        return;
    }
    
    try {
        const response = await apiCall(`${TICKETS_API}?action=update&id=${state.currentTicket.id}`, {
            method: 'POST',
            body: JSON.stringify({ hours_dedicated: decimalHours, user_id: 1 })
        });
        
        if (response.success) {
            const timeFormat = formatSecondsToTime(decimalHours * 3600);
            showToast(`⏱️ Tiempo actualizado: ${timeFormat}`, 'success');
            state.currentTicket.hours_dedicated = decimalHours;
            // Actualizar el input con formato normalizado
            inputElement.value = formatTimerDisplay(Math.round(decimalHours * 3600));
        } else {
            showToast(response.error || 'Error al actualizar', 'error');
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
    }
}

function saveTimer() {
    if (!state.currentTicket) return;
    if (state.timer.seconds === 0) {
        showToast('No hay tiempo para guardar', 'warning');
        return;
    }
    
    // Convertir segundos a horas decimales
    const newHoursDecimal = state.timer.seconds / 3600;
    
    // SUMAR al tiempo existente (no reemplazar)
    const currentHours = parseFloat(state.currentTicket.hours_dedicated || 0);
    const totalHours = currentHours + newHoursDecimal;
    
    // Formato para mostrar
    const timeFormat = formatSecondsToTime(state.timer.seconds);
    const totalFormat = formatSecondsToTime(totalHours * 3600);
    
    updateTicketField(state.currentTicket.id, 'hours_dedicated', totalHours);
    showToast(`⏱️ +${timeFormat} guardadas (Total: ${totalFormat})`, 'success');
    
    // Actualizar ticket en estado local
    state.currentTicket.hours_dedicated = totalHours;
    renderTicketDetail();
    
    resetTimer();
}

// Función auxiliar para guardar tiempo en un ticket específico (usado al cambiar de ticket)
async function saveTimerForTicket(ticketId, seconds) {
    if (!ticketId || seconds === 0) return;
    
    try {
        // Obtener horas actuales del ticket
        const response = await apiCall(`${TICKETS_API}?action=get&id=${ticketId}`);
        if (response.success) {
            const currentHours = parseFloat(response.data.hours_dedicated || 0);
            const newHoursDecimal = seconds / 3600;
            const totalHours = currentHours + newHoursDecimal;
            
            await updateTicketField(ticketId, 'hours_dedicated', totalHours);
            showToast(`⏱️ +${formatSecondsToTime(seconds)} guardadas en ticket anterior`, 'success');
        }
    } catch (error) {
        console.error('Error saving timer for ticket:', error);
    }
}

// Advertencia antes de cerrar si hay timer activo
window.addEventListener('beforeunload', (e) => {
    if (state.timer.running && state.timer.seconds > 0) {
        saveTimerToStorage(); // Guardar estado antes de cerrar
        e.preventDefault();
        e.returnValue = 'Tienes un timer activo. ¿Seguro que quieres salir?';
        return e.returnValue;
    }
});

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
    const notifyAssigned = document.getElementById('notify-assigned')?.checked || false;
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
                notify_assigned: notifyAssigned,
                user_id: state.currentUserId || 1,
                author_name: state.currentUserName || 'Admin'
            })
        });

        if (response.success) {
            let message = 'Comentario agregado';
            if (response.notified > 0) {
                message += ` (${response.notified} notificado${response.notified > 1 ? 's' : ''})`;
            }
            showToast(message, 'success');
            textarea.value = '';
            // Reset checkboxes
            document.getElementById('comment-internal').checked = false;
            document.getElementById('notify-assigned').checked = false;
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
    
    // Actualizar badges de backlog en sidebar
    updateBacklogBadge(stats.backlog_consultoria || 0, 'consultoria');
    updateBacklogBadge(stats.backlog_aib || 0, 'aib');
}

function updateTicketTabCounts() {
    const stats = state.stats;
    const byStatus = stats.by_status || {};
    
    // Calcular counts
    const activeCount = (parseInt(byStatus.open) || 0) + 
                        (parseInt(byStatus.in_progress) || 0) + 
                        (parseInt(byStatus.waiting) || 0);
    const resolvedCount = (parseInt(byStatus.resolved) || 0) + 
                          (parseInt(byStatus.closed) || 0);
    const overdueCount = stats.overdue || 0;
    const allCount = stats.total || 0;
    
    // Actualizar tabs
    const tabActive = document.getElementById('tab-count-active');
    const tabOverdue = document.getElementById('tab-count-overdue');
    const tabResolved = document.getElementById('tab-count-resolved');
    const tabAll = document.getElementById('tab-count-all');
    
    if (tabActive) tabActive.textContent = activeCount;
    if (tabOverdue) tabOverdue.textContent = overdueCount;
    if (tabResolved) tabResolved.textContent = resolvedCount;
    if (tabAll) tabAll.textContent = allCount;
}

function updateTicketMiniStats() {
    const stats = state.stats;
    const byStatus = stats.by_status || {};
    
    const miniOpen = document.getElementById('mini-stat-open');
    const miniProgress = document.getElementById('mini-stat-progress');
    const miniWaiting = document.getElementById('mini-stat-waiting');
    
    if (miniOpen) miniOpen.textContent = byStatus.open || 0;
    if (miniProgress) miniProgress.textContent = byStatus.in_progress || 0;
    if (miniWaiting) miniWaiting.textContent = byStatus.waiting || 0;
}

function setTicketStatusTab(tab) {
    state.ticketStatusTab = tab;
    state.filters.status = tab;
    state.pagination.page = 1;
    
    // Actualizar UI de tabs
    document.querySelectorAll('.status-tab').forEach(t => {
        t.classList.toggle('active', t.dataset.status === tab);
    });
    
    // Actualizar input hidden
    const filterStatus = document.getElementById('filter-status');
    if (filterStatus) filterStatus.value = tab;
    
    // Mostrar/ocultar mini stats según tab
    const miniStats = document.getElementById('tickets-mini-stats');
    if (miniStats) {
        miniStats.style.display = tab === 'active' ? 'flex' : 'none';
    }
    
    // Recargar tickets
    loadTickets();
}

// Exponer función globalmente
window.setTicketStatusTab = setTicketStatusTab;

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
        status_changed: 'fas fa-exchange-alt',
        changed_hours_dedicated: 'fas fa-clock'
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
        case 'changed_hours_dedicated':
            const oldTime = formatSecondsToTime(parseFloat(old_value || 0) * 3600);
            const newTime = formatSecondsToTime(parseFloat(new_value || 0) * 3600);
            return `modificó el tiempo dedicado de <span class="badge">${oldTime}</span> a <span class="badge">${newTime}</span>`;
        case 'comment_added':
            return 'añadió un comentario';
        default:
            return getActivityLabel(action);
    }
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    // Agregar T12:00:00 para evitar problemas de timezone con fechas sin hora
    const dateToUse = dateStr.includes('T') ? dateStr : dateStr.split(' ')[0] + 'T12:00:00';
    const date = new Date(dateToUse);
    return date.toLocaleDateString('es-ES', { 
        day: '2-digit', 
        month: 'short', 
        year: 'numeric' 
    });
}

// Helper para parsear fecha sin problemas de timezone
function parseDate(dateStr) {
    if (!dateStr) return null;
    const dateOnly = dateStr.split(' ')[0].split('T')[0];
    return new Date(dateOnly + 'T12:00:00');
}

// Verifica si una fecha ya pasó (para due_date)
function isPastDue(dateStr) {
    if (!dateStr) return false;
    const dueDate = parseDate(dateStr);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return dueDate < today;
}

// Calcula tiempo de entrega: resolved_at - due_date
function getDeliveryTime(ticket) {
    // Solo mostrar si el ticket está resuelto y tiene fecha máxima
    if (!ticket.resolved_at || !ticket.due_date) {
        return '<span style="color: var(--gray-400)">—</span>';
    }
    
    const resolvedDate = parseDate(ticket.resolved_at);
    const dueDate = parseDate(ticket.due_date);
    const diffTime = resolvedDate - dueDate;
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    if (diffDays < 0) {
        // Entregado antes
        return `<span style="color: var(--success)">${diffDays}d</span>`;
    } else if (diffDays === 0) {
        // Entregado el mismo día
        return `<span style="color: var(--success)">0d</span>`;
    } else {
        // Entregado tarde
        return `<span style="color: var(--danger)">+${diffDays}d</span>`;
    }
}

// Verifica si un ticket está en retraso
function isOverdue(ticket) {
    if (!ticket.due_date) return false;
    if (['resolved', 'closed'].includes(ticket.status)) return false;
    return isPastDue(ticket.due_date);
}

// Genera badge de retraso si aplica
function getOverdueBadge(ticket) {
    if (isOverdue(ticket)) {
        return ' <span style="background: var(--danger); color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-left: 4px;">EN RETRASO</span>';
    }
    return '';
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

function showToast(message, type = 'info', duration = 3000) {
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
    }, duration);
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
    
    // Guardar en localStorage para persistencia
    localStorage.setItem('ticketing_agentEmail', email);
    localStorage.setItem('ticketing_currentView', 'agent');
    
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

    // Obtener filtro de estado actual
    const statusFilter = state.agentStatusTab || 'active';

    try {
        // Cargar estadísticas usando el ID del agente
        const statsResponse = await fetch(`${HELPERS_API}?action=agent-stats&agent_id=${agent.id}`);
        const statsData = await statsResponse.json();

        // Cargar tickets CON filtro de estado
        const ticketsResponse = await fetch(`${HELPERS_API}?action=agent-tickets&agent_id=${agent.id}&status=${statusFilter}`);
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

    const escapedAgentName = (agent.name || '').replace(/'/g, "\\'");
    
    let html = `
        <div class="agent-header-actions" style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
            <button class="btn btn-primary" onclick="openDailyClosureModal(${agent.id}, '${escapedAgentName}')">
                <i class="fas fa-clipboard-check"></i> Cierre del día
            </button>
        </div>
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

        <!-- Tabs principales: Tickets | Mis Cierres -->
        <div class="agent-main-tabs" style="display: flex; gap: 0; margin-bottom: 0; border-bottom: 2px solid var(--gray-200);">
            <button class="agent-main-tab active" data-tab="tickets" onclick="switchAgentMainTab('tickets', ${agent.id})">
                <i class="fas fa-tasks"></i> Tickets Asignados
            </button>
            <button class="agent-main-tab" data-tab="closures" onclick="switchAgentMainTab('closures', ${agent.id})">
                <i class="fas fa-clipboard-list"></i> Mis Cierres
            </button>
        </div>

        <!-- Contenido Tab Tickets -->
        <div class="agent-tab-content" id="agent-tab-tickets-${agent.id}">
            <div class="card" style="border-top-left-radius: 0; border-top-right-radius: 0;">
                <div class="card-body">
                    <!-- Tabs de estado para agente -->
                    <div class="agent-status-tabs">
                        <button class="agent-tab ${state.agentStatusTab === 'active' ? 'active' : ''}" onclick="filterAgentTickets('active', '${agent.email}')">
                            <span>Activos</span>
                            <span class="tab-badge">${stats.open || 0}</span>
                        </button>
                        <button class="agent-tab ${state.agentStatusTab === 'resolved' ? 'active' : ''}" onclick="filterAgentTickets('resolved', '${agent.email}')">
                            <span>Resueltos</span>
                            <span class="tab-badge">${stats.resolved || 0}</span>
                        </button>
                        <button class="agent-tab ${state.agentStatusTab === 'all' ? 'active' : ''}" onclick="filterAgentTickets('all', '${agent.email}')">
                            <span>Todos</span>
                            <span class="tab-badge">${stats.total || 0}</span>
                        </button>
                    </div>
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
                                    <th>F. Máxima</th>
                                    <th>T. Entrega</th>
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
                                            </span>${getOverdueBadge(t)}
                                        </td>
                                        <td>${getPriorityIcon(t.priority)} ${t.priority}</td>
                                        <td>${t.category_name || '-'}</td>
                                        <td>${formatDate(t.created_at)}</td>
                                        <td>${t.due_date ? `<span style="color: ${isPastDue(t.due_date) ? 'var(--danger)' : 'inherit'}">${formatDate(t.due_date)}</span>` : '—'}</td>
                                        <td>${getDeliveryTime(t)}</td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="loadTicketDetail(${t.id}); showView('ticket-detail')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                `).join('') : '<tr><td colspan="9" style="text-align: center; color: #999;">No hay tickets asignados</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contenido Tab Cierres -->
        <div class="agent-tab-content hidden" id="agent-tab-closures-${agent.id}">
            <div class="card" style="border-top-left-radius: 0; border-top-right-radius: 0;">
                <div class="card-body">
                    <div id="agent-closures-list-${agent.id}">
                        <div style="text-align: center; padding: 20px; color: var(--gray-500);">
                            <i class="fas fa-spinner fa-spin"></i> Cargando cierres...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

    return html;
}

function switchAgentMainTab(tab, agentId) {
    console.log('switchAgentMainTab called:', tab, agentId);
    
    // Actualizar tabs activos (dentro del contexto del agente actual)
    document.querySelectorAll('.agent-main-tab').forEach(t => {
        t.classList.toggle('active', t.dataset.tab === tab);
    });
    
    // Mostrar/ocultar contenido usando IDs únicos por agente
    const ticketsTab = document.getElementById(`agent-tab-tickets-${agentId}`);
    const closuresTab = document.getElementById(`agent-tab-closures-${agentId}`);
    
    if (ticketsTab) ticketsTab.classList.toggle('hidden', tab !== 'tickets');
    if (closuresTab) closuresTab.classList.toggle('hidden', tab !== 'closures');
    
    // Cargar cierres si es la primera vez
    if (tab === 'closures' && agentId) {
        loadAgentClosures(agentId);
    }
}

window.switchAgentMainTab = switchAgentMainTab;

window.loadAgentDashboardByEmail = loadAgentDashboardByEmail;
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
window.refreshCurrentView = function() {
    if (state.currentView === 'dashboard') { loadStats(); loadTickets(); }
    else if (state.currentView === 'tickets') { loadTickets(); }
    else if (state.currentView === 'backlog-consultoria') { loadBacklogTickets('consultoria'); }
    else if (state.currentView === 'backlog-aib') { loadBacklogTickets('aib'); }
    else if (state.currentView === 'projects') { loadProjects(); }
    else if (state.currentView === 'closures-history') { loadClosuresHistory(); }
    else { loadTickets(); }
};
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
// CIERRE DEL DÍA
// =====================================================

const CLOSURES_API = `${API_BASE}/closures.php`;

function openDailyClosureModal(agentId, agentName) {
    // Crear modal overlay dinámicamente si no existe
    let modalOverlay = document.getElementById('modal-overlay-daily-closure');
    if (!modalOverlay) {
        modalOverlay = document.createElement('div');
        modalOverlay.id = 'modal-overlay-daily-closure';
        modalOverlay.className = 'modal-overlay';
        modalOverlay.innerHTML = `
            <div class="modal" style="max-width: 500px;">
                <div class="modal-header">
                    <h2><i class="fas fa-clipboard-check"></i> Cierre del día</h2>
                    <button class="btn-close" onclick="closeDailyClosureModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom: 15px; color: var(--gray-500);">
                        <strong id="closure-agent-name"></strong> - ${new Date().toLocaleDateString('es-ES', {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'})}
                    </p>
                    <div class="form-group">
                        <label>Resumen del día</label>
                        <textarea id="closure-summary" class="form-control" rows="6" 
                            placeholder="Describe las tareas completadas, tickets resueltos, pendientes para mañana, etc."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeDailyClosureModal()">Cancelar</button>
                    <button class="btn btn-primary" id="btn-submit-closure">
                        <i class="fas fa-paper-plane"></i> Enviar Cierre
                    </button>
                </div>
            </div>
        `;
        // Cerrar al hacer clic fuera
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) closeDailyClosureModal();
        });
        document.body.appendChild(modalOverlay);
    }
    
    // Configurar datos
    document.getElementById('closure-agent-name').textContent = agentName;
    document.getElementById('closure-summary').value = '';
    document.getElementById('btn-submit-closure').onclick = () => submitDailyClosure(agentId);
    
    // Verificar si ya existe cierre de hoy
    checkTodayClosure(agentId);
    
    // Mostrar modal
    modalOverlay.classList.add('active');
}

function closeDailyClosureModal() {
    const modalOverlay = document.getElementById('modal-overlay-daily-closure');
    if (modalOverlay) modalOverlay.classList.remove('active');
}

async function checkTodayClosure(agentId) {
    try {
        const response = await fetch(`${CLOSURES_API}?action=get-today&agent_id=${agentId}`);
        const data = await response.json();
        
        if (data.success && data.has_closure) {
            document.getElementById('closure-summary').value = data.data.summary;
            showToast('Ya existe un cierre para hoy. Puedes actualizarlo.', 'info');
        }
    } catch (error) {
        console.error('Error checking today closure:', error);
    }
}

async function submitDailyClosure(agentId) {
    const summary = document.getElementById('closure-summary').value.trim();
    
    if (!summary) {
        showToast('Debes escribir un resumen del día', 'error');
        return;
    }
    
    const btn = document.getElementById('btn-submit-closure');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
    
    try {
        // Enviar fecha local del cliente para evitar desfase por timezone del servidor
        const today = new Date();
        const closureDate = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        
        const response = await fetch(`${CLOSURES_API}?action=create`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                agent_id: agentId,
                summary: summary,
                closure_date: closureDate
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('✅ Cierre del día enviado correctamente', 'success');
            closeModal('modal-daily-closure');
        } else {
            showToast(data.error || 'Error al enviar el cierre', 'error');
        }
    } catch (error) {
        console.error('Error submitting closure:', error);
        showToast('Error al enviar el cierre', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Cierre';
    }
}

// =====================================================
// HISTORIAL DE CIERRES
// =====================================================

async function loadClosuresHistory() {
    const container = document.getElementById('closures-list');
    const emptyState = document.getElementById('closures-empty');
    
    if (!container) return;
    
    container.innerHTML = '<div class="loading" style="text-align: center; padding: 40px; color: var(--gray-500);"><i class="fas fa-spinner fa-spin"></i> Cargando cierres...</div>';
    if (emptyState) emptyState.classList.add('hidden');
    
    // Obtener filtros
    const agentId = document.getElementById('filter-closure-agent')?.value || '';
    const dateFrom = document.getElementById('filter-closure-from')?.value || '';
    const dateTo = document.getElementById('filter-closure-to')?.value || '';
    
    // Construir URL con filtros
    let url = `${CLOSURES_API}?action=list`;
    if (agentId) url += `&agent_id=${agentId}`;
    if (dateFrom) url += `&date_from=${dateFrom}`;
    if (dateTo) url += `&date_to=${dateTo}`;
    
    try {
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success && data.data && data.data.length > 0) {
            container.innerHTML = data.data.map(closure => renderClosureCard(closure)).join('');
        } else {
            container.innerHTML = '';
            if (emptyState) emptyState.classList.remove('hidden');
        }
    } catch (error) {
        console.error('Error loading closures:', error);
        container.innerHTML = '<div class="error-state" style="text-align: center; padding: 40px; color: var(--danger);">Error al cargar los cierres</div>';
    }
}

function renderClosureCard(closure) {
    // Agregar T00:00:00 para que se interprete como hora local, no UTC
    const date = new Date(closure.closure_date + 'T00:00:00');
    const dateFormatted = date.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const createdAt = new Date(closure.created_at);
    const timeFormatted = createdAt.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
    
    const avatarUrl = closure.agent_avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(closure.agent_name)}&background=6366f1&color=fff`;
    
    return `
        <div class="closure-card">
            <div class="closure-header">
                <div class="closure-agent">
                    <img src="${avatarUrl}" alt="${closure.agent_name}">
                    <div class="closure-agent-info">
                        <h4>${closure.agent_name}</h4>
                        <span>${closure.agent_email}</span>
                    </div>
                </div>
                <div class="closure-date">
                    <span class="date"><i class="fas fa-calendar-day"></i> ${dateFormatted}</span>
                    <span class="time"><i class="fas fa-clock"></i> Enviado a las ${timeFormatted}</span>
                </div>
            </div>
            <div class="closure-summary">${escapeHtml(closure.summary)}</div>
        </div>
    `;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function loadAgentClosures(agentId) {
    const container = document.getElementById(`agent-closures-list-${agentId}`);
    if (!container) {
        console.log('Container not found for agent:', agentId);
        return;
    }
    
    try {
        const response = await fetch(`${CLOSURES_API}?action=list&agent_id=${agentId}&limit=10`);
        const data = await response.json();
        
        if (data.success && data.data && data.data.length > 0) {
            container.innerHTML = data.data.map(closure => {
                // Agregar T00:00:00 para que se interprete como hora local, no UTC
                const date = new Date(closure.closure_date + 'T00:00:00');
                const dateFormatted = date.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                const createdAt = new Date(closure.created_at);
                const timeFormatted = createdAt.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
                const escapedSummary = closure.summary.replace(/'/g, "\\'").replace(/\n/g, "\\n");
                
                return `
                    <div class="closure-card" style="margin-bottom: 16px;">
                        <div class="closure-header" style="margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <div class="closure-date" style="display: flex; gap: 15px; align-items: center;">
                                <span class="date" style="font-weight: 600;"><i class="fas fa-calendar-day"></i> ${dateFormatted}</span>
                                <span class="time" style="color: var(--gray-500); font-size: 0.85rem;"><i class="fas fa-clock"></i> ${timeFormatted}</span>
                            </div>
                            <button class="btn btn-sm btn-ghost" onclick="editClosure(${closure.id}, ${agentId}, '${escapedSummary}')" title="Editar cierre">
                                <i class="fas fa-edit"></i>
                            </button>
                        </div>
                        <div class="closure-summary">${escapeHtml(closure.summary)}</div>
                    </div>
                `;
            }).join('');
        } else {
            container.innerHTML = `
                <div style="text-align: center; padding: 30px; color: var(--gray-400);">
                    <i class="fas fa-clipboard-list" style="font-size: 2rem; margin-bottom: 10px;"></i>
                    <p>No hay cierres registrados</p>
                    <p style="font-size: 0.85rem; margin-top: 5px;">Usa el botón "Cierre del día" para crear uno</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading agent closures:', error);
        container.innerHTML = '<div style="text-align: center; color: var(--danger);">Error al cargar cierres</div>';
    }
}

function editClosure(closureId, agentId, summary) {
    // Abrir modal con datos precargados
    let modalOverlay = document.getElementById('modal-overlay-daily-closure');
    if (!modalOverlay) {
        // Crear el modal si no existe
        openDailyClosureModal(agentId, 'Agente');
        modalOverlay = document.getElementById('modal-overlay-daily-closure');
    }
    
    // Precargar datos
    document.getElementById('closure-summary').value = summary.replace(/\\n/g, "\n");
    
    // Cambiar el botón para actualizar
    const btn = document.getElementById('btn-submit-closure');
    btn.onclick = () => updateClosure(closureId, agentId);
    btn.innerHTML = '<i class="fas fa-save"></i> Actualizar Cierre';
    
    // Mostrar modal
    modalOverlay.classList.add('active');
}

async function updateClosure(closureId, agentId) {
    const summary = document.getElementById('closure-summary').value.trim();
    
    if (!summary) {
        showToast('Debes escribir un resumen del día', 'error');
        return;
    }
    
    const btn = document.getElementById('btn-submit-closure');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Actualizando...';
    
    try {
        const response = await fetch(`${CLOSURES_API}?action=update&id=${closureId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ summary })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('✅ Cierre actualizado correctamente', 'success');
            closeDailyClosureModal();
            loadAgentClosures(agentId);
        } else {
            showToast(data.error || 'Error al actualizar', 'error');
        }
    } catch (error) {
        console.error('Error updating closure:', error);
        showToast('Error al actualizar el cierre', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Cierre';
        btn.onclick = () => submitDailyClosure(agentId);
    }
}

window.editClosure = editClosure;
window.updateClosure = updateClosure;

function clearClosureFilters() {
    document.getElementById('filter-closure-agent').value = '';
    document.getElementById('filter-closure-from').value = '';
    document.getElementById('filter-closure-to').value = '';
    loadClosuresHistory();
}

function populateClosureAgentFilter() {
    const select = document.getElementById('filter-closure-agent');
    if (!select || !state.agents) return;
    
    select.innerHTML = '<option value="">Todos los agentes</option>';
    state.agents.forEach(agent => {
        select.innerHTML += `<option value="${agent.id}">${agent.name}</option>`;
    });
}

window.openDailyClosureModal = openDailyClosureModal;
window.closeDailyClosureModal = closeDailyClosureModal;
window.submitDailyClosure = submitDailyClosure;
window.loadClosuresHistory = loadClosuresHistory;
window.loadAgentClosures = loadAgentClosures;
window.clearClosureFilters = clearClosureFilters;

// =====================================================
// GESTIÓN DE PROYECTOS
// =====================================================

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
    const progress = project.stats?.progress || 0;
    const projectStatus = getProjectTimeStatus(project);
    const overview = document.getElementById('project-overview');
    overview.innerHTML = `
        <div class="project-header-section">
            <div class="project-title-row">
                <h2>${escapeHtml(project.name)}</h2>
                <span class="project-status-badge ${projectStatus.class}">${projectStatus.label}</span>
            </div>
            ${project.description ? `<p class="project-description">${escapeHtml(project.description)}</p>` : ''}
        </div>
        
        <div class="project-content-grid">
            <div class="project-info-card">
                <h4><i class="fas fa-info-circle"></i> Información</h4>
                <div class="info-grid">
                    <div class="info-item">
                        <i class="fas fa-user-check" style="color: #10b981;"></i>
                        <div>
                            <span class="info-label">Responsable</span>
                            <span class="info-value">${project.responsible_name || 'Sin asignar'}</span>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-building" style="color: #6366f1;"></i>
                        <div>
                            <span class="info-label">Cliente</span>
                            <span class="info-value">${project.client_name || 'Sin cliente'}</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="project-info-card">
                <h4><i class="fas fa-calendar-alt"></i> Fechas</h4>
                <div class="info-grid">
                    <div class="info-item">
                        <i class="fas fa-play-circle" style="color: #10b981;"></i>
                        <div>
                            <span class="info-label">Inicio</span>
                            <span class="info-value">${project.start_date ? formatDate(project.start_date) : 'Sin definir'}</span>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-flag-checkered" style="color: #ef4444;"></i>
                        <div>
                            <span class="info-label">Fin</span>
                            <span class="info-value">${project.end_date ? formatDate(project.end_date) : 'Sin definir'}</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="project-info-card project-progress-card">
                <h4><i class="fas fa-chart-line"></i> Progreso</h4>
                <div class="progress-visual">
                    <div class="progress-circle" style="--progress: ${progress}">
                        <span class="progress-number">${progress}%</span>
                    </div>
                    <div class="progress-stats">
                        <div class="stat-row">
                            <span class="stat-label">Fases</span>
                            <span class="stat-value">${project.stats?.total_phases || 0}</span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Actividades</span>
                            <span class="stat-value">${project.stats?.completed_activities || 0}/${project.stats?.total_activities || 0}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="project-actions-bar">
            <a href="forms/form-${project.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}.html" 
               target="_blank" class="btn btn-secondary btn-sm">
                <i class="fas fa-external-link-alt"></i> Abrir formulario
            </a>
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
    
    container.innerHTML = phases.map((phase, index) => {
        const totalActivities = phase.activities?.length || 0;
        const completedActivities = phase.activities?.filter(a => a.status === 'completed' || a.status === 'converted').length || 0;
        const phaseProgress = totalActivities > 0 ? Math.round((completedActivities / totalActivities) * 100) : 0;
        const connectorClass = phase.status === 'completed' ? 'completed' : (phase.status === 'in_progress' ? 'in-progress' : '');
        
        return `
        <div class="roadmap-phase" data-phase-id="${phase.id}">
            <div class="phase-header" onclick="togglePhase(${phase.id})">
                <div class="phase-title">
                    <i class="fas fa-chevron-down" id="phase-chevron-${phase.id}"></i>
                    <span class="phase-number">Fase ${index + 1}</span>
                    <i class="fas fa-layer-group"></i>
                    <span>${escapeHtml(phase.name)}</span>
                </div>
                <div style="display: flex; align-items: center; gap: 15px;">
                    ${phase.start_date || phase.end_date ? `
                    <span class="phase-dates">
                        <i class="fas fa-calendar-alt"></i>
                        ${phase.start_date ? formatDate(phase.start_date) : '?'} - ${phase.end_date ? formatDate(phase.end_date) : '?'}
                    </span>` : ''}
                    <div class="phase-progress-mini">
                        <div class="phase-progress-bar">
                            <div class="phase-progress-fill" style="width: ${phaseProgress}%"></div>
                        </div>
                        <span class="phase-progress-text">${completedActivities}/${totalActivities}</span>
                    </div>
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
        ${index < phases.length - 1 ? `<div class="roadmap-connector ${connectorClass}"></div>` : ''}
    `}).join('');
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
                    ${activity.assigned_name ? `<span class="meta-responsible"><i class="fas fa-user-check"></i> ${escapeHtml(activity.assigned_name)}</span>` : ''}
                    ${activity.contact_name ? `<span class="meta-client"><i class="fas fa-user"></i> ${escapeHtml(activity.contact_name)}</span>` : ''}
                    ${activity.start_date || activity.end_date ? `<span class="meta-dates"><i class="fas fa-calendar-alt"></i> ${activity.start_date ? formatDate(activity.start_date) : ''} ${activity.start_date && activity.end_date ? '-' : ''} ${activity.end_date ? formatDate(activity.end_date) : ''}</span>` : ''}
                    ${activity.video_url ? `<span class="meta-video"><i class="fas fa-video"></i> Con vídeo</span>` : ''}
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

function getProjectTimeStatus(project) {
    const progress = project.stats?.progress || 0;
    
    // Si está completado
    if (progress === 100) {
        return { label: 'Completado', class: 'status-completed' };
    }
    
    // Si no tiene fecha de fin, solo mostrar progreso
    if (!project.end_date) {
        if (progress === 0) return { label: 'Sin iniciar', class: 'status-pending' };
        return { label: 'En progreso', class: 'status-in-progress' };
    }
    
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const endDate = new Date(project.end_date);
    endDate.setHours(0, 0, 0, 0);
    const daysRemaining = Math.ceil((endDate - today) / (1000 * 60 * 60 * 24));
    
    // Si ya pasó la fecha de fin
    if (daysRemaining < 0) {
        return { label: `Atrasado (${Math.abs(daysRemaining)} días)`, class: 'status-overdue' };
    }
    
    // Si le quedan menos de 7 días
    if (daysRemaining <= 7) {
        return { label: `Próximo (${daysRemaining} días)`, class: 'status-warning' };
    }
    
    // En tiempo normal
    return { label: 'En tiempo', class: 'status-on-track' };
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

async function openNewProjectModal() {
    document.getElementById('modal-project-title').innerHTML = '<i class="fas fa-folder-plus"></i> Nuevo Proyecto';
    document.getElementById('form-project').reset();
    document.getElementById('project-id').value = '';
    
    // Cargar opciones de responsables y clientes
    await populateProjectSelects();
    
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

async function editCurrentProject() {
    if (!currentProject) return;
    
    document.getElementById('modal-project-title').innerHTML = '<i class="fas fa-edit"></i> Editar Proyecto';
    document.getElementById('project-id').value = currentProject.id;
    document.getElementById('project-name').value = currentProject.name || '';
    document.getElementById('project-description').value = currentProject.description || '';
    document.getElementById('project-start-date').value = currentProject.start_date || '';
    document.getElementById('project-end-date').value = currentProject.end_date || '';
    
    await populateProjectSelects();
    
    // Ahora sí asignar los valores seleccionados
    document.getElementById('project-responsible').value = currentProject.responsible_id || '';
    document.getElementById('project-client').value = currentProject.client_id || '';
    
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
            // Si se generó formulario, mostrar toast con link
            if (response.form_generated && response.form_generated.url) {
                const formUrl = window.location.origin + window.location.pathname.replace('index.html', '') + response.form_generated.url;
                showToast(`Proyecto creado - <a href="${formUrl}" target="_blank" style="color: inherit; text-decoration: underline;">Ver formulario</a>`, 'success', 8000);
            } else {
                showToast(id ? 'Proyecto actualizado' : 'Proyecto creado', 'success');
            }
            closeModal('modal-project');
            
            // Recargar state.projects para que aparezca en el dropdown de nuevo ticket
            await loadInitialData();
            
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
    const phase = currentProject?.phases?.find(p => Number(p.id) === Number(phaseId));
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

async function openNewActivityModal(phaseId) {
    document.getElementById('modal-activity-title').innerHTML = '<i class="fas fa-tasks"></i> Nueva Actividad';
    document.getElementById('form-activity').reset();
    document.getElementById('activity-id').value = '';
    document.getElementById('activity-phase-id').value = phaseId;
    
    await populateActivitySelects();
    
    openModal('modal-activity');
}

async function populateActivitySelects() {
    // Contactos - cargar dinámicamente desde la API
    const contactSelect = document.getElementById('activity-contact');
    if (contactSelect) {
        try {
            const response = await apiCall(`${HELPERS_API}?action=users`);
            if (response.success && response.data) {
                contactSelect.innerHTML = '<option value="">Seleccionar solicitante...</option>' +
                    response.data.map(u => `<option value="${u.id}">${escapeHtml(u.name)}</option>`).join('');
            }
        } catch (error) {
            console.error('Error cargando contactos:', error);
            contactSelect.innerHTML = '<option value="">Seleccionar solicitante...</option>';
        }
    }
    
    // Asignados (agentes)
    const assignedSelect = document.getElementById('activity-assigned');
    if (assignedSelect && state.agents && state.agents.length > 0) {
        assignedSelect.innerHTML = '<option value="">Seleccionar agente...</option>' +
            state.agents.map(a => `<option value="${a.id}">${escapeHtml(a.name)}</option>`).join('');
    }
}

async function editActivity(activityId) {
    let activity = null;
    for (const phase of (currentProject?.phases || [])) {
        activity = phase.activities?.find(a => Number(a.id) === Number(activityId));
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
    document.getElementById('activity-start-date').value = activity.start_date || '';
    document.getElementById('activity-end-date').value = activity.end_date || '';
    
    // Esperar a que se carguen los selects antes de asignar valores
    await populateActivitySelects();
    
    // Ahora sí asignar los valores seleccionados
    document.getElementById('activity-contact').value = activity.contact_user_id || '';
    document.getElementById('activity-assigned').value = activity.assigned_to || '';
    
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

// =====================================================
// Filtro de Tickets por Estado para Agentes
// =====================================================

async function filterAgentTickets(tab, agentEmail) {
    state.agentStatusTab = tab;
    
    // Actualizar estado visual de tabs
    document.querySelectorAll('.agent-tab').forEach(t => t.classList.remove('active'));
    const clickedTab = document.querySelector(`.agent-tab[onclick*="'${tab}'"]`);
    if (clickedTab) clickedTab.classList.add('active');
    
    // Recargar el dashboard del agente con el nuevo filtro
    await loadAgentDashboardByEmail(agentEmail);
}

window.filterAgentTickets = filterAgentTickets;

// =====================================================
// Sistema de Notificaciones Internas
// =====================================================

/**
 * Cargar usuario actual desde localStorage o usar default
 */
function loadCurrentUser() {
    const savedUserId = localStorage.getItem('ticketing_currentUserId');
    const savedUserName = localStorage.getItem('ticketing_currentUserName');
    
    if (savedUserId) {
        state.currentUserId = parseInt(savedUserId);
        state.currentUserName = savedUserName || 'Usuario';
        updateUserDisplay();
    } else {
        // Default: Admin (ID 1) o primer usuario disponible
        state.currentUserId = 3; // Alfonso Bello por defecto
        state.currentUserName = 'Alfonso Bello';
    }
}

/**
 * Actualizar display del usuario en el header
 */
function updateUserDisplay() {
    const nameEl = document.getElementById('current-user-name');
    const avatarEl = document.getElementById('current-user-avatar');
    const dropdownNameEl = document.getElementById('dropdown-user-name');
    const dropdownRoleEl = document.getElementById('dropdown-user-role');
    
    if (nameEl) nameEl.textContent = state.currentUserName;
    if (avatarEl) {
        avatarEl.src = `https://ui-avatars.com/api/?name=${encodeURIComponent(state.currentUserName)}&background=6366f1&color=fff`;
    }
    
    // Actualizar dropdown con info del usuario
    if (dropdownNameEl) dropdownNameEl.textContent = state.currentUserName;
    if (dropdownRoleEl && state.auth.user) {
        const roleNames = {
            'super_admin': 'Super Admin',
            'admin': 'Administrador',
            'agent': 'Agente'
        };
        dropdownRoleEl.textContent = roleNames[state.auth.user.role] || state.auth.user.role;
    }
}

/**
 * Cargar lista de usuarios para el dropdown
 */
async function loadUsersList() {
    try {
        const response = await apiCall(`${NOTIFICATIONS_API}?action=users`);
        if (response.success && response.data) {
            state.allUsers = response.data; // Guardar para autocompletado de @menciones
            // Ya no renderizamos dropdown de usuarios, solo guardamos para menciones
        }
    } catch (error) {
        console.error('Error cargando usuarios:', error);
    }
}

/**
 * Renderizar dropdown de usuarios
 */
function renderUserDropdown(users) {
    const listEl = document.getElementById('user-dropdown-list');
    if (!listEl) return;
    
    listEl.innerHTML = users.map(user => `
        <div class="user-dropdown-item ${user.id == state.currentUserId ? 'active' : ''}" 
             onclick="selectUser(${user.id}, '${escapeHtml(user.name)}')">
            <img src="${user.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(user.name)}&background=6366f1&color=fff`}" alt="${escapeHtml(user.name)}">
            <div class="user-info">
                <div class="user-name">${escapeHtml(user.name)}</div>
                <div class="user-email">${escapeHtml(user.email)}</div>
            </div>
            ${user.id == state.currentUserId ? '<i class="fas fa-check" style="color: var(--success);"></i>' : ''}
        </div>
    `).join('');
}

/**
 * Seleccionar usuario actual
 */
function selectUser(userId, userName) {
    state.currentUserId = userId;
    state.currentUserName = userName;
    
    // Guardar en localStorage
    localStorage.setItem('ticketing_currentUserId', userId);
    localStorage.setItem('ticketing_currentUserName', userName);
    
    // Actualizar UI
    updateUserDisplay();
    toggleUserMenu();
    
    // Recargar notificaciones
    loadNotifications();
    
    showToast(`Sesión iniciada como ${userName}`, 'success');
}

/**
 * Toggle del menú de usuario
 */
function toggleUserMenu() {
    const dropdown = document.getElementById('user-dropdown');
    const notifDropdown = document.getElementById('notification-dropdown');
    
    // Cerrar notificaciones si está abierto
    if (notifDropdown) notifDropdown.classList.remove('show');
    
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
}

/**
 * Toggle del dropdown de notificaciones
 */
function toggleNotifications() {
    const dropdown = document.getElementById('notification-dropdown');
    const userDropdown = document.getElementById('user-dropdown');
    
    // Cerrar user menu si está abierto
    if (userDropdown) userDropdown.classList.remove('show');
    
    if (dropdown) {
        dropdown.classList.toggle('show');
        
        // Si se abre, recargar notificaciones
        if (dropdown.classList.contains('show')) {
            loadNotifications();
        }
    }
}

/**
 * Cargar notificaciones del usuario actual
 */
async function loadNotifications() {
    if (!state.currentUserId) return;
    
    try {
        const response = await apiCall(`${NOTIFICATIONS_API}?action=list&user_id=${state.currentUserId}&limit=20`);
        
        if (response.success && response.data) {
            state.notifications = response.data;
            state.unreadNotifications = response.data.filter(n => !n.is_read).length;
            renderNotifications();
            updateNotificationBadge();
        }
    } catch (error) {
        console.error('Error cargando notificaciones:', error);
    }
}

/**
 * Renderizar lista de notificaciones
 */
function renderNotifications() {
    const listEl = document.getElementById('notification-list');
    if (!listEl) return;
    
    if (state.notifications.length === 0) {
        listEl.innerHTML = `
            <div class="notification-empty">
                <i class="fas fa-check-circle"></i>
                <p>No tienes notificaciones</p>
            </div>
        `;
        return;
    }
    
    listEl.innerHTML = state.notifications.map(notif => {
        const iconMap = {
            'mention': 'at',
            'comment': 'comment',
            'assignment': 'user-check',
            'status_change': 'exchange-alt',
            'overdue': 'exclamation-triangle',
            'review': 'search',
            'info_complete': 'check-circle'
        };
        
        const icon = iconMap[notif.type] || 'bell';
        const unreadClass = notif.is_read ? '' : 'unread';
        
        return `
            <div class="notification-item ${unreadClass}" onclick="handleNotificationClick(${notif.id}, ${notif.ticket_id})">
                <div class="notification-icon ${notif.type}">
                    <i class="fas fa-${icon}"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title">${escapeHtml(notif.message)}</div>
                    <div class="notification-meta">
                        <span class="notification-ticket">#${notif.ticket_number || 'N/A'}</span>
                        <span>•</span>
                        <span>${timeAgo(notif.created_at)}</span>
                        ${notif.triggered_by_name ? `<span>• por ${escapeHtml(notif.triggered_by_name)}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

/**
 * Actualizar badge de notificaciones
 */
function updateNotificationBadge() {
    const badge = document.getElementById('notification-badge');
    if (!badge) return;
    
    badge.textContent = state.unreadNotifications;
    badge.style.display = state.unreadNotifications > 0 ? 'flex' : 'none';
}

/**
 * Manejar click en una notificación
 */
async function handleNotificationClick(notificationId, ticketId) {
    // Marcar como leída
    try {
        await apiCall(`${NOTIFICATIONS_API}?action=mark-read`, {
            method: 'POST',
            body: JSON.stringify({ notification_id: notificationId })
        });
        
        // Actualizar estado local
        const notif = state.notifications.find(n => n.id === notificationId);
        if (notif && !notif.is_read) {
            notif.is_read = true;
            state.unreadNotifications--;
            updateNotificationBadge();
            renderNotifications();
        }
    } catch (error) {
        console.error('Error marcando notificación:', error);
    }
    
    // Cerrar dropdown
    toggleNotifications();
    
    // Ir al ticket
    if (ticketId) {
        loadTicketDetail(ticketId);
    }
}

/**
 * Marcar todas las notificaciones como leídas
 */
async function markAllNotificationsRead() {
    if (!state.currentUserId || state.unreadNotifications === 0) return;
    
    try {
        const response = await apiCall(`${NOTIFICATIONS_API}?action=mark-all-read`, {
            method: 'POST',
            body: JSON.stringify({ user_id: state.currentUserId })
        });
        
        if (response.success) {
            state.notifications.forEach(n => n.is_read = true);
            state.unreadNotifications = 0;
            updateNotificationBadge();
            renderNotifications();
            showToast('Todas las notificaciones marcadas como leídas', 'success');
        }
    } catch (error) {
        showToast('Error al marcar notificaciones', 'error');
    }
}

// =====================================================
// Sistema de @Menciones con Autocomplete
// =====================================================

let mentionState = {
    isActive: false,
    startPosition: 0,
    searchText: '',
    selectedIndex: 0
};

/**
 * Inicializar sistema de @menciones en un textarea
 */
function initMentionAutocomplete(textarea) {
    if (!textarea) return;
    
    // Crear contenedor de sugerencias si no existe
    let suggestionsContainer = textarea.parentElement.querySelector('.mention-suggestions');
    if (!suggestionsContainer) {
        suggestionsContainer = document.createElement('div');
        suggestionsContainer.className = 'mention-suggestions';
        suggestionsContainer.id = 'mention-suggestions';
        textarea.parentElement.style.position = 'relative';
        textarea.parentElement.appendChild(suggestionsContainer);
    }
    
    // Eventos del textarea
    textarea.addEventListener('input', handleMentionInput);
    textarea.addEventListener('keydown', handleMentionKeydown);
    textarea.addEventListener('blur', () => {
        // Pequeño delay para permitir clicks en sugerencias
        setTimeout(() => hideMentionSuggestions(), 150);
    });
}

/**
 * Manejar input en textarea para detectar @
 */
function handleMentionInput(e) {
    const textarea = e.target;
    const value = textarea.value;
    const cursorPos = textarea.selectionStart;
    
    // Buscar @ antes del cursor
    const textBeforeCursor = value.substring(0, cursorPos);
    const lastAtIndex = textBeforeCursor.lastIndexOf('@');
    
    if (lastAtIndex >= 0) {
        // Verificar que @ es inicio de palabra (después de espacio, inicio, o salto de línea)
        const charBefore = lastAtIndex > 0 ? value.charAt(lastAtIndex - 1) : ' ';
        if (charBefore === ' ' || charBefore === '\n' || lastAtIndex === 0) {
            const searchText = textBeforeCursor.substring(lastAtIndex + 1);
            
            // Solo buscar si no hay espacios en el texto de búsqueda
            if (!searchText.includes(' ') && searchText.length <= 30) {
                mentionState.isActive = true;
                mentionState.startPosition = lastAtIndex;
                mentionState.searchText = searchText.toLowerCase();
                mentionState.selectedIndex = 0;
                
                showMentionSuggestions(textarea, searchText);
                return;
            }
        }
    }
    
    hideMentionSuggestions();
}

/**
 * Manejar teclas especiales en autocomplete
 */
function handleMentionKeydown(e) {
    if (!mentionState.isActive) return;
    
    const suggestionsContainer = document.getElementById('mention-suggestions');
    if (!suggestionsContainer || !suggestionsContainer.classList.contains('show')) return;
    
    const items = suggestionsContainer.querySelectorAll('.mention-item');
    if (items.length === 0) return;
    
    switch (e.key) {
        case 'ArrowDown':
            e.preventDefault();
            mentionState.selectedIndex = Math.min(mentionState.selectedIndex + 1, items.length - 1);
            updateMentionSelection(items);
            break;
            
        case 'ArrowUp':
            e.preventDefault();
            mentionState.selectedIndex = Math.max(mentionState.selectedIndex - 1, 0);
            updateMentionSelection(items);
            break;
            
        case 'Enter':
        case 'Tab':
            e.preventDefault();
            const selectedItem = items[mentionState.selectedIndex];
            if (selectedItem) {
                selectMention(e.target, selectedItem.dataset.userId, selectedItem.dataset.userName);
            }
            break;
            
        case 'Escape':
            hideMentionSuggestions();
            break;
    }
}

/**
 * Actualizar selección visual
 */
function updateMentionSelection(items) {
    items.forEach((item, index) => {
        item.classList.toggle('selected', index === mentionState.selectedIndex);
    });
    
    // Scroll al elemento seleccionado
    const selected = items[mentionState.selectedIndex];
    if (selected) {
        selected.scrollIntoView({ block: 'nearest' });
    }
}

/**
 * Mostrar sugerencias de usuarios
 */
function showMentionSuggestions(textarea, searchText) {
    const suggestionsContainer = document.getElementById('mention-suggestions');
    if (!suggestionsContainer) return;
    
    // Filtrar usuarios
    const filteredUsers = state.allUsers.filter(user => {
        const name = user.name.toLowerCase();
        const email = user.email?.toLowerCase() || '';
        const search = searchText.toLowerCase();
        return name.includes(search) || email.includes(search);
    }).slice(0, 8); // Máximo 8 sugerencias
    
    if (filteredUsers.length === 0) {
        hideMentionSuggestions();
        return;
    }
    
    // Renderizar sugerencias
    suggestionsContainer.innerHTML = filteredUsers.map((user, index) => `
        <div class="mention-item ${index === 0 ? 'selected' : ''}" 
             data-user-id="${user.id}" 
             data-user-name="${escapeHtml(user.name)}"
             onclick="selectMentionFromClick(${user.id}, '${escapeHtml(user.name).replace(/'/g, "\\'")}')">
            <img src="${user.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(user.name)}&background=6366f1&color=fff&size=32`}" 
                 alt="${escapeHtml(user.name)}">
            <div class="mention-user-info">
                <div class="mention-user-name">${escapeHtml(user.name)}</div>
                <div class="mention-user-email">${escapeHtml(user.email || '')}</div>
            </div>
        </div>
    `).join('');
    
    // Posicionar y mostrar
    suggestionsContainer.classList.add('show');
}

/**
 * Ocultar sugerencias
 */
function hideMentionSuggestions() {
    const suggestionsContainer = document.getElementById('mention-suggestions');
    if (suggestionsContainer) {
        suggestionsContainer.classList.remove('show');
    }
    mentionState.isActive = false;
}

/**
 * Seleccionar mención (por click)
 */
function selectMentionFromClick(userId, userName) {
    const textarea = document.getElementById('new-comment');
    if (textarea) {
        selectMention(textarea, userId, userName);
    }
}

/**
 * Insertar mención seleccionada
 */
function selectMention(textarea, userId, userName) {
    const value = textarea.value;
    const beforeMention = value.substring(0, mentionState.startPosition);
    const afterCursor = value.substring(textarea.selectionStart);
    
    // Crear nombre para mención (primera palabra o nombre completo sin espacios)
    const mentionName = userName.split(' ')[0].toLowerCase();
    
    // Insertar mención
    const newValue = beforeMention + '@' + mentionName + ' ' + afterCursor;
    textarea.value = newValue;
    
    // Posicionar cursor después de la mención
    const newCursorPos = mentionState.startPosition + mentionName.length + 2; // +2 para @ y espacio
    textarea.setSelectionRange(newCursorPos, newCursorPos);
    textarea.focus();
    
    hideMentionSuggestions();
}

// Exponer función global
window.selectMentionFromClick = selectMentionFromClick;

/**
 * Iniciar polling de notificaciones
 */
function startNotificationPolling() {
    // Polling cada 60 segundos
    state.notificationInterval = setInterval(() => {
        if (state.currentUserId) {
            loadNotifications();
        }
    }, 60000);
}

/**
 * Cerrar dropdowns al hacer click fuera
 */
document.addEventListener('click', (e) => {
    const notifCenter = document.getElementById('notification-center');
    const userMenu = document.querySelector('.user-menu');
    const notifDropdown = document.getElementById('notification-dropdown');
    const userDropdown = document.getElementById('user-dropdown');
    
    // Cerrar notificaciones si click fuera
    if (notifCenter && !notifCenter.contains(e.target) && notifDropdown) {
        notifDropdown.classList.remove('show');
    }
    
    // Cerrar user menu si click fuera
    if (userMenu && !userMenu.contains(e.target) && userDropdown && !userDropdown.contains(e.target)) {
        userDropdown.classList.remove('show');
    }
});

// =====================================================
// Sistema de Autenticación
// =====================================================

/**
 * Limpiar localStorage viejo del sistema anterior (sin auth)
 * Esto fuerza a todos los usuarios a hacer login con el nuevo sistema
 */
function cleanOldLocalStorage() {
    // Si existe el viejo sistema (sin auth_token) pero con usuario guardado, limpiar todo
    const hasOldSystem = localStorage.getItem('ticketing_currentUserId') && !localStorage.getItem('auth_token');
    
    if (hasOldSystem) {
        console.log('Migrando a nuevo sistema de autenticación...');
        // Limpiar todo el localStorage viejo
        localStorage.removeItem('ticketing_currentUserId');
        localStorage.removeItem('ticketing_currentUserName');
        localStorage.removeItem('ticketing_currentView');
        localStorage.removeItem('ticketing_currentTicketId');
        localStorage.removeItem('ticketing_agentEmail');
    }
}

/**
 * Verificar si el usuario está autenticado
 */
async function checkAuthentication() {
    const token = localStorage.getItem('auth_token');
    const savedUser = localStorage.getItem('auth_user');
    
    if (!token) {
        return false;
    }
    
    try {
        const response = await fetch(`${AUTH_API}?action=me`, {
            headers: {
                'Authorization': `Bearer ${token}`
            }
        });
        
        const data = await response.json();
        
        if (data.success && data.user) {
            // Usuario autenticado
            state.auth.token = token;
            state.auth.user = data.user;
            state.auth.isAuthenticated = true;
            
            // Sincronizar con el estado existente
            state.currentUserId = data.user.id;
            state.currentUserName = data.user.name;
            
            return true;
        } else {
            // Token inválido, limpiar
            localStorage.removeItem('auth_token');
            localStorage.removeItem('auth_user');
            return false;
        }
    } catch (error) {
        console.error('Error verificando autenticación:', error);
        return false;
    }
}

/**
 * Cerrar sesión
 */
async function logout() {
    const token = localStorage.getItem('auth_token');
    
    try {
        if (token) {
            await fetch(`${AUTH_API}?action=logout`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
        }
    } catch (e) {
        // Ignorar errores de logout
    }
    
    // Limpiar storage
    localStorage.removeItem('auth_token');
    localStorage.removeItem('auth_user');
    localStorage.removeItem('ticketing_currentView');
    localStorage.removeItem('ticketing_currentTicketId');
    localStorage.removeItem('ticketing_agentEmail');
    
    // Redirigir a login
    window.location.href = 'login.html';
}

/**
 * Verificar si el usuario tiene un rol específico
 */
function hasRole(...roles) {
    if (!state.auth.user) return false;
    return roles.includes(state.auth.user.role);
}

/**
 * Verificar si el usuario puede realizar una acción
 */
function canDo(action) {
    const role = state.auth.user?.role;
    if (!role) return false;
    
    const permissions = {
        // Ver todos los tickets
        'view_all_tickets': ['super_admin', 'admin'],
        // Crear tickets
        'create_ticket': ['super_admin', 'admin'],
        // Editar tickets
        'edit_ticket': ['super_admin', 'admin'],
        // Comentar en tickets
        'comment_ticket': ['super_admin', 'admin', 'agent'],
        // Ver estadísticas globales
        'view_global_stats': ['super_admin', 'admin'],
        // Ver mis métricas
        'view_my_metrics': ['super_admin', 'admin', 'agent'],
        // Gestionar usuarios
        'manage_users': ['super_admin', 'admin'],
        // Cierres diarios
        'manage_closures': ['super_admin', 'admin'],
        // Configuración del sistema
        'system_config': ['super_admin'],
        // Ver backlog
        'view_backlog': ['super_admin', 'admin'],
        // Ver proyectos
        'view_projects': ['super_admin', 'admin']
    };
    
    return permissions[action]?.includes(role) || false;
}

/**
 * Aplicar permisos según rol del usuario
 */
function applyRolePermissions() {
    const role = state.auth.user?.role;
    if (!role) return;
    
    // === BOTÓN NUEVO TICKET ===
    const newTicketBtn = document.querySelector('.sidebar-footer .btn-primary');
    if (newTicketBtn) {
        if (!canDo('create_ticket')) {
            newTicketBtn.style.display = 'none';
        }
    }
    
    // === MENÚ LATERAL ===
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        const view = item.dataset.view;
        const agentEmail = item.dataset.agentEmail;
        
        // Para agentes: ocultar vistas que no pueden ver
        if (role === 'agent') {
            // Ocultar dashboard global (estadísticas)
            if (view === 'dashboard') {
                item.style.display = 'none';
            }
            // Ocultar proyectos
            if (view === 'projects') {
                item.style.display = 'none';
            }
            // Ocultar backlogs
            if (view === 'backlog-consultoria' || view === 'backlog-aib') {
                item.style.display = 'none';
            }
            // Ocultar cierres
            if (view === 'closures-history') {
                item.style.display = 'none';
            }
            // Ocultar otros agentes (solo ver el suyo)
            if (agentEmail && agentEmail !== state.auth.user.email) {
                item.style.display = 'none';
            }
        }
    });
    
    // === PARA AGENTES: Mostrar vista de mis métricas por defecto ===
    if (role === 'agent') {
        // El agente ve su propio dashboard
        const agentEmail = state.auth.user.email;
        const myAgentNav = document.querySelector(`[data-agent-email="${agentEmail}"]`);
        
        if (myAgentNav) {
            // Renombrar a "Mis Tickets"
            const spanEl = myAgentNav.querySelector('span');
            if (spanEl) spanEl.textContent = 'Mis Tickets';
        }
        
        // Agregar link a Mis Métricas si no existe
        addMyMetricsLink();
    }
    
    // === HEADER: Todos pueden usar el menú para cerrar sesión ===
    // El menú ya no tiene opción de cambiar usuario, solo cerrar sesión y cambiar contraseña
}


/**
 * Agregar link a Mis Métricas para agentes
 */
function addMyMetricsLink() {
    const navDivider = document.querySelector('.nav-divider');
    if (!navDivider) return;
    
    // Verificar si ya existe
    if (document.querySelector('[data-view="my-metrics"]')) return;
    
    // Crear link
    const link = document.createElement('a');
    link.href = '#';
    link.className = 'nav-item';
    link.dataset.view = 'my-metrics';
    link.innerHTML = '<i class="fas fa-chart-bar"></i><span>Mis Métricas</span>';
    link.onclick = (e) => {
        e.preventDefault();
        showMyMetrics();
    };
    
    // Insertar antes del divider de agentes
    navDivider.parentNode.insertBefore(link, navDivider);
}

/**
 * Mostrar métricas del agente actual
 */
async function showMyMetrics() {
    // Actualizar nav
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.toggle('active', item.dataset.view === 'my-metrics');
    });
    
    // Ocultar todas las secciones
    document.querySelectorAll('.content-section').forEach(section => {
        section.classList.add('hidden');
    });
    
    // Mostrar sección de métricas (crearla si no existe)
    let metricsSection = document.getElementById('view-my-metrics');
    if (!metricsSection) {
        metricsSection = createMyMetricsSection();
        document.querySelector('.main-content').appendChild(metricsSection);
    }
    metricsSection.classList.remove('hidden');
    
    // Cargar datos
    await loadMyMetrics();
}

/**
 * Crear sección de métricas
 */
function createMyMetricsSection() {
    const section = document.createElement('section');
    section.className = 'content-section';
    section.id = 'view-my-metrics';
    section.innerHTML = `
        <div class="section-header">
            <h1><i class="fas fa-chart-bar"></i> Mis Métricas</h1>
        </div>
        
        <div class="stats-grid" id="my-metrics-stats">
            <div class="stat-card">
                <div class="stat-icon bg-primary">
                    <i class="fas fa-ticket-alt"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value" id="my-total-assigned">0</span>
                    <span class="stat-label">Total Asignados</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value" id="my-pending">0</span>
                    <span class="stat-label">Pendientes</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value" id="my-completed-month">0</span>
                    <span class="stat-label">Completados este mes</span>
                </div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #ef4444;">
                <div class="stat-icon" style="background: #fef2f2; color: #ef4444;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value" id="my-overdue">0</span>
                    <span class="stat-label">Vencidos</span>
                </div>
            </div>
        </div>
        
        <div class="stats-grid" style="margin-top: 20px;">
            <div class="stat-card" style="border-left: 4px solid #3b82f6;">
                <div class="stat-icon" style="background: #eff6ff; color: #3b82f6;">
                    <i class="fas fa-stopwatch"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value" id="my-avg-resolution">0h</span>
                    <span class="stat-label">Tiempo medio resolución</span>
                </div>
            </div>
        </div>
        
        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Tickets completados por mes</h3>
            </div>
            <div class="card-body" style="height: 300px;">
                <canvas id="my-metrics-chart"></canvas>
            </div>
        </div>
    `;
    return section;
}

let myMetricsChart = null;

/**
 * Cargar métricas del agente
 */
async function loadMyMetrics() {
    try {
        const token = localStorage.getItem('auth_token');
        const response = await fetch(`${AUTH_API}?action=my-metrics`, {
            headers: {
                'Authorization': `Bearer ${token}`
            }
        });
        
        const data = await response.json();
        
        if (data.success && data.metrics) {
            const m = data.metrics;
            
            // Actualizar cards
            document.getElementById('my-total-assigned').textContent = m.total_assigned;
            document.getElementById('my-pending').textContent = m.pending;
            document.getElementById('my-completed-month').textContent = m.completed_this_month;
            document.getElementById('my-overdue').textContent = m.overdue;
            document.getElementById('my-avg-resolution').textContent = m.avg_resolution_hours + 'h';
            
            // Gráfica de completados por mes
            renderMyMetricsChart(m.monthly_completed);
        }
    } catch (error) {
        console.error('Error cargando métricas:', error);
        showToast('Error cargando métricas', 'error');
    }
}

/**
 * Renderizar gráfica de métricas
 */
function renderMyMetricsChart(monthlyData) {
    const canvas = document.getElementById('my-metrics-chart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    // Destruir gráfica anterior si existe
    if (myMetricsChart) {
        myMetricsChart.destroy();
    }
    
    const labels = monthlyData.map(d => {
        const [year, month] = d.month.split('-');
        const monthNames = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        return monthNames[parseInt(month) - 1] + ' ' + year;
    });
    const values = monthlyData.map(d => d.count);
    
    myMetricsChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Tickets completados',
                data: values,
                backgroundColor: 'rgba(99, 102, 241, 0.8)',
                borderColor: 'rgba(99, 102, 241, 1)',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
}

/**
 * Cambiar contraseña
 */
async function changePassword(currentPassword, newPassword) {
    const token = localStorage.getItem('auth_token');
    
    try {
        const response = await fetch(`${AUTH_API}?action=change-password`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
                current_password: currentPassword,
                new_password: newPassword
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Contraseña actualizada', 'success');
            return true;
        } else {
            showToast(data.error || 'Error al cambiar contraseña', 'error');
            return false;
        }
    } catch (error) {
        showToast('Error de conexión', 'error');
        return false;
    }
}

// Exponer funciones de auth globalmente
window.logout = logout;
window.hasRole = hasRole;
window.canDo = canDo;
window.changePassword = changePassword;
window.showMyMetrics = showMyMetrics;

// Exponer funciones globalmente
window.toggleNotifications = toggleNotifications;
window.toggleUserMenu = toggleUserMenu;
window.selectUser = selectUser;
window.handleNotificationClick = handleNotificationClick;
window.markAllNotificationsRead = markAllNotificationsRead;