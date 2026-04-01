/**
 * VOID - Core Application Logic
 * Backend: PHP + SQLite (api/)
 * Features: Multi-conversation history, sidebar collapse, Gemini + OpenAI support.
 */

// ── API base path — relative so it works on any domain/server ────────────────
const API_BASE = window.location.origin;

const API = {
  auth:      API_BASE + '/api/auth.php',
  user:      API_BASE + '/api/user.php',
  convs:     API_BASE + '/api/conversations.php',
  proxy:     API_BASE + '/api/proxy.php',
  whitelist: API_BASE + '/api/whitelist.php',
};


// ─── Available models per provider ───────────────────────────────────────────
const MODELS = {
  gemini: [
    { id: 'gemini-2.5-flash',      label: 'Gemini 2.5 Flash',      tag: 'recomendado' },
    { id: 'gemini-2.5-pro',        label: 'Gemini 2.5 Pro',        tag: 'potente' },
    { id: 'gemini-2.0-flash',      label: 'Gemini 2.0 Flash',      tag: '' },
    { id: 'gemini-2.0-flash-lite', label: 'Gemini 2.0 Flash Lite', tag: 'ligero' },
  ],
  openai: [
    { id: 'gpt-4o',       label: 'GPT-4o',       tag: 'flagship' },
    { id: 'gpt-4o-mini',  label: 'GPT-4o Mini',  tag: 'rápido' },
    { id: 'gpt-4-turbo',  label: 'GPT-4 Turbo',  tag: 'potente' },
    { id: 'gpt-3.5-turbo',label: 'GPT-3.5 Turbo',tag: 'económico' },
    { id: 'o1-mini',      label: 'o1 Mini',       tag: 'razonamiento' },
  ],
  anthropic: [
    { id: 'claude-opus-4-6',           label: 'Claude Opus 4.6',    tag: 'potente' },
    { id: 'claude-sonnet-4-6',         label: 'Claude Sonnet 4.6',  tag: 'equilibrado' },
    { id: 'claude-haiku-4-5-20251001', label: 'Claude Haiku 4.5',   tag: 'rápido' },
  ],
};

function defaultModel(provider) {
  return MODELS[provider]?.[0]?.id ?? '';
}

async function apiFetch(url, options = {}) {
  const res = await fetch(url, {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    ...options,
  });
  const json = await res.json().catch(() => ({ ok: false, error: 'Respuesta inválida del servidor' }));
  return json; // { ok, data } | { ok: false, error }
}

const app = {
  currentUser: null,
  chatHistory: [],       // Messages of the CURRENT conversation
  conversations: [],     // All saved conversations [{id, title, messages, createdAt}]
  activeConvId: null,    // ID of the currently loaded conversation
  apiKey: '',            // deprecated — el servidor gestiona las API keys
  apiProvider: 'gemini', // 'gemini' | 'openai' | 'anthropic'
  apiModel: '',          // specific model, e.g. 'gpt-4o', 'gemini-2.0-flash'
  useProxy: true,        // true = API key stored server-side; false = direct from browser
  isTyping: false,
  streamAbort: null,     // AbortController for active stream
  sidebarCollapsed: false,
  userMemory: '',        // Persistent memory injected into every system prompt
  attachedFilesContext: [], // Archivos persistentes para toda la conversación

  async init() {
    this.initCursor();
    this.initCanvas();
    this.initScrollReveal();
    this.initMarquee();
    this.initLandingInteractive();
    await this.loadState();

    if (this.currentUser) {
      this.showPage('chat');
      this.renderChat();
    } else {
      this.showPage('landing');
    }
    // Signal to inline scripts that app is ready (used for oauth_error toast)
    window.dispatchEvent(new Event('void:ready'));
  },

  // ==========================================
  // STATE MANAGEMENT (server-backed)
  // ==========================================
  async loadState() {
    const res = await apiFetch(API.auth + '?action=me');

    // Cargar ajustes desde localStorage como respaldo (funciona sin BD)
    const lsProvider = localStorage.getItem('void_provider')  || 'gemini';
    const lsModel    = localStorage.getItem('void_model')     || '';
    this.apiProvider = lsProvider;
    // Si el modelo guardado ya no existe en la lista, resetear al por defecto
    const validModels = (MODELS[lsProvider] || []).map(m => m.id);
    this.apiModel = (lsModel && validModels.includes(lsModel)) ? lsModel : defaultModel(lsProvider);
    if (this.apiModel !== lsModel) localStorage.setItem('void_model', this.apiModel);
    this.useProxy    = true; // el servidor siempre tiene la key configurada

    if (!res.ok) return; // not authenticated via BD — continuar igual

    this.currentUser = res.data;
    this.apiProvider = res.data.api_provider || lsProvider || 'gemini';
    const dbModel = res.data.api_model || '';
    const validDbModels = (MODELS[this.apiProvider] || []).map(m => m.id);
    // Si el modelo de BD ya no es válido, resetear al por defecto y guardar en BD
    if (dbModel && !validDbModels.includes(dbModel)) {
      this.apiModel = defaultModel(this.apiProvider);
      // Guardar silenciosamente el modelo válido en BD
      apiFetch(API.user + '?action=settings', {
        method: 'PUT',
        body: JSON.stringify({ api_provider: this.apiProvider, api_model: this.apiModel }),
      }).catch(() => {});
      localStorage.setItem('void_model', this.apiModel);
    } else {
      this.apiModel = dbModel || this.apiModel || defaultModel(this.apiProvider);
    }
    this.useProxy = true; // el servidor gestiona la API key

    // Sidebar state stored locally (cosmetic preference)
    this.sidebarCollapsed = localStorage.getItem('void_sidebar_' + this.currentUser.email) === 'true';

    // Load conversations from server
    const convRes = await apiFetch(API.convs + '?action=list');
    if (convRes.ok) {
      this.conversations = convRes.data.map(c => ({
        id: c.id, title: c.title, messages: c.messages,
        createdAt: new Date(c.created_at).getTime()
      }));
    }

    // Load user memory
    const memRes = await apiFetch(API.user + '?action=memory');
    if (memRes.ok) this.userMemory = memRes.data.memory || '';

    // Restore active conversation (last used, stored locally)
    this.activeConvId = localStorage.getItem('void_active_' + this.currentUser.email) || null;
    if (this.activeConvId) {
      const conv = this.conversations.find(c => c.id === this.activeConvId);
      this.chatHistory = conv ? conv.messages : [];
    }

    this.updateSidebarUser();
    this.updateModelStatus();
    if (this.sidebarCollapsed) this.applySidebarCollapse(true);
  },

  async saveConversations() {
    if (!this.currentUser || !this.activeConvId) return;
    localStorage.setItem('void_active_' + this.currentUser.email, this.activeConvId || '');
    await this.syncCurrentConv();
  },

  async syncCurrentConv() {
    if (!this.activeConvId || this.chatHistory.length === 0) return;
    const idx = this.conversations.findIndex(c => c.id === this.activeConvId);
    if (idx === -1) return;
    this.conversations[idx].messages = this.chatHistory;
    // Mantener el contexto de archivos adjuntos en memoria
    this.conversations[idx].attachedFilesContext = this.attachedFilesContext;

    // Título provisional basado en el primer mensaje del usuario
    const firstUser = this.chatHistory.find(m => m.role === 'user');
    if (firstUser && !this.conversations[idx]._aiTitled) {
      const _raw = firstUser.content;
      const _plain = Array.isArray(_raw)
        ? (_raw.find(p => p.type === 'text')?.text || 'Archivos adjuntos')
        : (typeof _raw === 'string' ? _raw : 'Conversación');
      this.conversations[idx].title = _plain.slice(0, 40) + (_plain.length > 40 ? '…' : '');
    }

    if (this.currentUser) localStorage.setItem('void_active_' + this.currentUser.email, this.activeConvId);
    const conv = this.conversations[idx];
    apiFetch(API.convs + '?action=save', {
      method: 'POST',
      body: JSON.stringify({ id: conv.id, title: conv.title, messages: conv.messages }),
    }).then(res => {
      if (!res.ok) console.error('[VOID] Error guardando conversación:', res.error);
    }).catch(err => console.error('[VOID] Error guardando conversación:', err));

    // (El título con IA se genera desde sendMessage, tras recibir la respuesta completa)
  },

  async _generateAiTitle(convId) {
    const msgs = this.chatHistory.slice(0, 6).map(m => {
      if (Array.isArray(m.content)) {
        const txt = m.content.filter(p => p.type === 'text').map(p => p.text).join(' ');
        return { role: m.role, content: txt || '(adjunto)' };
      }
      return { role: m.role, content: typeof m.content === 'string' ? m.content : '(adjunto)' };
    });

    console.log('[VOID title] Generando título, msgs:', msgs.length, 'provider:', this.apiProvider);
    if (!msgs.length) { console.warn('[VOID title] Sin mensajes'); return; }

    try {
      const res = await apiFetch(API.proxy, {
        method: 'POST',
        body: JSON.stringify({
          action: 'title',
          messages: msgs,
          provider: this.apiProvider,
          model: this.apiModel || defaultModel(this.apiProvider),
        }),
      });

      console.log('[VOID title] Respuesta proxy:', JSON.stringify(res));

      const title = res?.data?.title || res?.title || '';
      if (!title) { console.warn('[VOID title] Título vacío en respuesta'); return; }

      const conv = this.conversations.find(c => c.id === convId);
      if (!conv) { console.warn('[VOID title] Conv no encontrada:', convId); return; }

      console.log('[VOID title] Título generado:', title);
      conv.title = title;
      this.updateSidebarHistory();

      apiFetch(API.convs + '?action=save', {
        method: 'POST',
        body: JSON.stringify({ id: conv.id, title: conv.title, messages: conv.messages }),
      }).catch(e => console.error('[VOID title] Error guardando título:', e));
    } catch (e) {
      console.error('[VOID title] Error:', e);
    }
  },

  // ==========================================
  // UI ROUTING & HELPERS
  // ==========================================
  showPage(pageId) {
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.getElementById('page-' + pageId).classList.add('active');
    window.scrollTo(0, 0);
  },

  showAuth(mode) {
    this.showPage('auth');
    this.switchAuthTab(mode);
  },

  switchAuthTab(mode) {
    // Solo existe el formulario de login; el registro pasa por la whitelist
    document.querySelectorAll('.form-panel').forEach(p => p.classList.remove('active'));
    const form = document.getElementById('form-' + mode) || document.getElementById('form-login');
    if (form) form.classList.add('active');
  },

  showToast(msg, type) {
    const t = document.getElementById('toast');
    // Determine icon and color based on type or legacy emoji prefix
    let icon = 'ico-info', color = 'var(--text2)';
    if (type === 'success' || msg.includes('✦') || msg.toLowerCase().includes('bienvenido') || msg.toLowerCase().includes('guardado')) {
      icon = 'ico-check'; color = 'var(--accent)';
    } else if (type === 'error' || msg.includes('⚠️') || msg.toLowerCase().includes('incorrecto') || msg.toLowerCase().includes('registrado') || msg.toLowerCase().includes('supera')) {
      icon = 'ico-warn'; color = '#ff6b6b';
    }
    // Strip legacy emojis and escape to prevent XSS (msg can originate from URL params)
    const cleanMsg = this.escapeHtml(msg.replace(/[⚠️✦]/g, '').trim());
    t.innerHTML = `<svg style="width:15px;height:15px;flex-shrink:0;color:${color}"><use href="#${icon}"/></svg><span>${cleanMsg}</span>`;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
  },

  // ==========================================
  // AUTHENTICATION
  // ==========================================
  async handleLogin(e) {
    e.preventDefault();
    const email = document.getElementById('login-email').value.trim();
    const pass = document.getElementById('login-password').value;
    const btn = e.target.querySelector('[type=submit]');
    if (btn) btn.disabled = true;
    const res = await apiFetch(API.auth + '?action=login', {
      method: 'POST',
      body: JSON.stringify({ email, password: pass }),
    });
    if (btn) btn.disabled = false;
    if (!res.ok) { this.showToast('⚠️ ' + res.error); return; }
    await this._afterLogin(res.data);
  },

  async _afterLogin(data) {
    this.currentUser = data.user;
    this.apiProvider = data.user.api_provider || 'gemini';
    this.apiModel = data.user.api_model || defaultModel(this.apiProvider);
    this.useProxy = true; // el servidor siempre tiene la key configurada
    await this.loadState();
    this.showPage('chat');
    this.renderChat();
    this.showToast('Bienvenido, ' + data.user.name);
  },

  async handleLogout() {
    await apiFetch(API.auth + '?action=logout', { method: 'POST' });
    this.currentUser = null;
    this.chatHistory = [];
    this.conversations = [];
    this.activeConvId = null;
    this.showPage('landing');
    this.showToast('Sesión cerrada');
  },

  loginWithGoogle() {
    window.location.href = `${API_BASE}/api/oauth_google.php?action=redirect`;
  },

  loginWithGithub() {
    window.location.href = `${API_BASE}/api/oauth_github.php?action=redirect`;
  },

  // ==========================================
  // SIDEBAR COLLAPSE
  // ==========================================
  toggleMobileNav() {
    const nav = document.getElementById('nav-mobile');
    const btn = document.getElementById('nav-hamburger');
    const open = nav.classList.toggle('open');
    btn.classList.toggle('open', open);
  },

  closeMobileNav() {
    document.getElementById('nav-mobile').classList.remove('open');
    document.getElementById('nav-hamburger').classList.remove('open');
  },

  openModal(id) {
    document.getElementById(id).classList.add('active');
  },

  closeModal(id) {
    document.getElementById(id).classList.remove('active');
  },

  // ==========================================
  // PROFILE SETTINGS
  // ==========================================
  openProfile() {
    if (!this.currentUser) return;
    // Fill fields with current data
    document.getElementById('profile-name').value = this.currentUser.name || '';
    document.getElementById('profile-email').value = this.currentUser.email || '';
    document.getElementById('profile-pass-current').value = '';
    document.getElementById('profile-pass-new').value = '';
    document.getElementById('profile-pass-confirm').value = '';
    // Reset pending avatar state
    this._pendingAvatar = null;
    // Render avatar preview
    this._renderProfileAvatar();
    document.getElementById('modal-profile').classList.add('active');
  },

  closeProfile() {
    document.getElementById('modal-profile').classList.remove('active');
  },

  _renderProfileAvatar() {
    const el = document.getElementById('profile-avatar-preview');
    if (!el) return;
    const avatar = this.currentUser.avatar;
    if (avatar) {
      el.innerHTML = '<img src="' + avatar + '" alt="avatar">';
    } else {
      el.innerHTML = (this.currentUser.name || 'V').slice(0, 1).toUpperCase();
    }
  },

  handleAvatarFile(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) { this.showToast('⚠️ La imagen no puede superar 10 MB'); input.value = ''; return; }
    const reader = new FileReader();
    reader.onload = (e) => {
      // Compress image to max 200x200 JPEG before storing (keeps localStorage footprint tiny)
      const img = new Image();
      img.onload = () => {
        const MAX = 200;
        const scale = Math.min(MAX / img.width, MAX / img.height, 1);
        const w = Math.round(img.width * scale);
        const h = Math.round(img.height * scale);
        const canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, w, h);
        const compressed = canvas.toDataURL('image/jpeg', 0.82);
        this._pendingAvatar = compressed;
        const el = document.getElementById('profile-avatar-preview');
        if (el) el.innerHTML = '<img src="' + compressed + '" alt="avatar">';
      };
      img.src = e.target.result;
    };
    reader.readAsDataURL(file);
    input.value = '';
  },

  removeAvatar() {
    this._pendingAvatar = '__remove__';
    const el = document.getElementById('profile-avatar-preview');
    if (el) el.innerHTML = (this.currentUser.name || 'V').slice(0, 1).toUpperCase();
  },

  togglePassVis(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const isPass = input.type === 'password';
    input.type = isPass ? 'text' : 'password';
    btn.innerHTML = isPass
      ? '<svg class="icon" style="width:16px;height:16px;"><use href="#ico-eye-off"/></svg>'
      : '<svg class="icon" style="width:16px;height:16px;"><use href="#ico-eye"/></svg>';
  },

  async saveProfile() {
    if (!this.currentUser) return;
    const newName = document.getElementById('profile-name').value.trim();
    const newEmail = document.getElementById('profile-email').value.trim().toLowerCase();
    const passCurrent = document.getElementById('profile-pass-current').value;
    const passNew = document.getElementById('profile-pass-new').value;
    const passConfirm = document.getElementById('profile-pass-confirm').value;

    if (!newName) { this.showToast('\u26a0\ufe0f El nombre no puede estar vac\xc3\xado'); return; }
    if (!newEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(newEmail)) { this.showToast('\u26a0\ufe0f Email inv\xc3\xa1lido'); return; }

    // Profile fields
    const profileRes = await apiFetch(API.user + '?action=profile', {
      method: 'PUT',
      body: JSON.stringify({
        name: newName, email: newEmail,
        password_current: passCurrent, password_new: passNew, password_confirm: passConfirm,
      }),
    });
    if (!profileRes.ok) { this.showToast('\u26a0\ufe0f ' + profileRes.error); return; }

    // Avatar (if changed)
    if (this._pendingAvatar !== null && this._pendingAvatar !== undefined) {
      const avatarRes = await apiFetch(API.user + '?action=avatar', {
        method: 'PUT',
        body: JSON.stringify({ avatar: this._pendingAvatar }),
      });
      if (avatarRes.ok) {
        this.currentUser.avatar = avatarRes.data.avatar;
      }
      this._pendingAvatar = null;
    }

    this.currentUser.name = newName;
    this.currentUser.email = newEmail;
    this.updateSidebarUser();
    const welcomeH2 = document.querySelector('#chat-welcome h2');
    if (welcomeH2) {
      const rawFirst = newName.split(' ')[0];
      const firstName = rawFirst ? rawFirst.charAt(0).toUpperCase() + rawFirst.slice(1).toLowerCase() : '';
      welcomeH2.innerHTML = 'Hola, ' + firstName + '<br>¿Por dónde empezamos?';
    }
    this.closeProfile();
    this.showToast('Perfil actualizado \u2756');
  },

  toggleSidebar() {
    const sidebar = document.getElementById('chat-sidebar');
    if (window.innerWidth < 900) {
      const isOpen = sidebar.classList.toggle('open');
      const backdrop = document.getElementById('sidebar-backdrop');
      if (backdrop) backdrop.classList.toggle('visible', isOpen);
    } else {
      this.sidebarCollapsed = !this.sidebarCollapsed;
      this.applySidebarCollapse(this.sidebarCollapsed);
      if (this.currentUser) {
        localStorage.setItem('void_sidebar_' + this.currentUser.email, this.sidebarCollapsed);
      }
    }
  },

  closeSidebar() {
    const sidebar = document.getElementById('chat-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    sidebar.classList.remove('open');
    if (backdrop) backdrop.classList.remove('visible');
  },

  applySidebarCollapse(collapsed) {
    const sidebar = document.getElementById('chat-sidebar');
    const main = document.querySelector('.chat-main');
    if (collapsed) {
      sidebar.classList.add('collapsed');
      if (main) main.classList.add('sidebar-collapsed');
    } else {
      sidebar.classList.remove('collapsed');
      if (main) main.classList.remove('sidebar-collapsed');
    }
  },

  // ==========================================
  // CONVERSATION HISTORY
  // ==========================================
  async newConversation() {
    if (this.chatHistory.length > 0) {
      await this.syncCurrentConv();
    }
    this.activeConvId = null;
    this.chatHistory = [];
    this.attachedFilesContext = [];
    this.renderChat();
    if (window.innerWidth < 900) this.closeSidebar();
  },

  async loadConversation(id) {
    if (this.chatHistory.length > 0) {
      await this.syncCurrentConv();
    }
    const conv = this.conversations.find(c => c.id === id);
    if (!conv) return;
    this.activeConvId = id;
    this.chatHistory = conv.messages;
    this.attachedFilesContext = conv.attachedFilesContext || [];
    if (this.currentUser) localStorage.setItem('void_active_' + this.currentUser.email, id);
    this.renderChat();
    this.updateSidebarHistory();
    if (window.innerWidth < 900) this.closeSidebar();
  },

  async deleteConversation(id, e) {
    e.stopPropagation();
    this.conversations = this.conversations.filter(c => c.id !== id);
    if (this.activeConvId === id) {
      this.activeConvId = null;
      this.chatHistory = [];
    }
    apiFetch(API.convs + '?action=delete&id=' + encodeURIComponent(id), { method: 'DELETE' });
    this.updateSidebarHistory();
    if (this.activeConvId === null) this.renderChat();
  },

  // ==========================================
  // CHAT RENDERING
  // ==========================================
  updateSidebarUser() {
    if (!this.currentUser) return;
    const avatarEl = document.getElementById('user-avatar-sb');
    if (avatarEl) {
      if (this.currentUser.avatar) {
        avatarEl.innerHTML = '<img src="' + this.currentUser.avatar + '" alt="avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
      } else {
        avatarEl.innerHTML = this.currentUser.name.slice(0, 1).toUpperCase();
      }
    }
    document.getElementById('user-name-sb').textContent = this.currentUser.name;
    const emailEl = document.getElementById('user-email-sb');
    if (emailEl) emailEl.textContent = this.currentUser.email;
  },

  renderChat() {
    const inner = document.getElementById('chat-messages-inner');
    inner.innerHTML = '';
    if (this.chatHistory.length === 0) {
      const rawFirst = this.currentUser ? this.currentUser.name.split(' ')[0] : '';
      const firstName = rawFirst ? rawFirst.charAt(0).toUpperCase() + rawFirst.slice(1).toLowerCase() : '';
      inner.innerHTML = `<div id="chat-welcome" class="chat-welcome"><div class="chat-welcome-icon"><span class="chat-welcome-logo">VOID</span></div><h2>Hola, ${firstName}<br>¿Por dónde empezamos?</h2><p>Cuéntame qué tienes en mente — un problema, una idea, una pregunta.<br>Estoy aquí para ayudarte a pensar con claridad.</p></div>`;
    } else {
      this.chatHistory.forEach((msg, idx) => this.appendMessageUI(msg.role, msg.content, idx));
      this.scrollToBottom();
    }
    this.updateSidebarHistory();
  },

  async clearChat() {
    if (this.activeConvId) {
      await apiFetch(API.convs + '?action=delete&id=' + encodeURIComponent(this.activeConvId), { method: 'DELETE' });
      this.conversations = this.conversations.filter(c => c.id !== this.activeConvId);
    }
    await this.newConversation();
  },

  updateSidebarHistory() {
    const container = document.getElementById('sidebar-convs');
    let html = '';

    if (this.chatHistory.length > 0 && !this.activeConvId) {
      const firstMsg = this.chatHistory.find(m => m.role === 'user');
      const title = firstMsg ? firstMsg.content.slice(0, 35) + '…' : 'Nueva conversación';
      html += '<div class="sidebar-conv active" style="cursor:default;"><span class="sidebar-conv-icon">⚫</span><div class="sidebar-conv-text"><div class="sidebar-conv-title">' + this.escapeHtml(title) + '</div><div class="sidebar-conv-time">Actual</div></div></div>';
    }

    var convsCopy = this.conversations.slice().reverse();
    for (var i = 0; i < convsCopy.length; i++) {
      var conv = convsCopy[i];
      var isActive = conv.id === this.activeConvId;
      var time = this.formatConvTime(conv.createdAt);
      html += '<div class="sidebar-conv ' + (isActive ? 'active' : '') + '" onclick="app.loadConversation(\'' + conv.id + '\')">' +
        '<span class="sidebar-conv-icon">⚫</span>' +
        '<div class="sidebar-conv-text">' +
        '<div class="sidebar-conv-title">' + this.escapeHtml(conv.title) + '</div>' +
        '<div class="sidebar-conv-time">' + time + '</div>' +
        '</div>' +
        '<button class="sidebar-conv-delete" onclick="app.deleteConversation(\'' + conv.id + '\', event)" title="Eliminar">' +
        '<svg style="width:12px;height:12px"><use href="#ico-trash"/></svg>' +
        '</button>' +
        '</div>';
    }

    container.innerHTML = html || '<p style="color:var(--muted);font-size:0.8rem;padding:10px 14px;">No hay historial</p>';
  },

  formatConvTime(ts) {
    if (!ts) return '';
    const d = new Date(ts);
    const diff = Date.now() - d;
    if (diff < 60000) return 'Ahora';
    if (diff < 3600000) return Math.floor(diff / 60000) + ' min';
    if (diff < 86400000) return Math.floor(diff / 3600000) + 'h';
    return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit' });
  },

  autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 150) + 'px';
  },

  handleInputKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.sendMessage(); }
  },

  stopGeneration() {
    if (this.streamAbort) {
      this.streamAbort.abort();
      this.streamAbort = null;
    }
  },

  async sendMessage() {
    if (this.isTyping) return;
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    const hasFiles = this.pendingFiles.length > 0;
    if (!text && !hasFiles) return;
    // Bloquear envío si algún documento todavía se está extrayendo
    if (this.pendingFiles.some(f => f.extracting)) {
      this.showToast('Espera — todavía extrayendo el documento…');
      return;
    }

    const attachedFiles = [...this.pendingFiles];
    const displayText = text || (attachedFiles.length === 1 ? attachedFiles[0].name : attachedFiles.length + ' archivos adjuntos');

    input.value = '';
    this.autoResize(input);
    this.clearAttachments();

    const welcome = document.getElementById('chat-welcome');
    if (welcome) welcome.remove();

    if (!this.activeConvId) {
      const id = 'conv_' + Date.now();
      this.activeConvId = id;
      this.conversations.push({ id, title: displayText.slice(0, 40) + (displayText.length > 40 ? '…' : ''), messages: [], createdAt: Date.now() });
    }

    // Persistir archivos nuevos al contexto acumulado de la conversación
    if (attachedFiles.length > 0) {
      attachedFiles.forEach(f => {
        // Guardar solo metadatos ligeros + contenido extraído (sin base64 de imágenes para no saturar BD)
        const stored = { name: f.name, isImage: f.isImage, isBinaryDoc: f.isBinaryDoc, content: f.content || null };
        if (f.isImage) { stored.base64 = f.base64; stored.mimeType = f.mimeType; }
        if (f.pageImages) { stored.pageImages = f.pageImages; stored.pageCount = f.pageCount; }
        // Evitar duplicados por nombre
        const existing = this.attachedFilesContext.findIndex(x => x.name === f.name);
        if (existing !== -1) this.attachedFilesContext[existing] = stored;
        else this.attachedFilesContext.push(stored);
      });
    }

    const fileContext = this.buildFileContextFrom(attachedFiles);
    const fullContent = text + fileContext;
    this.chatHistory.push({ role: 'user', content: fullContent });
    const userMsgIdx = this.chatHistory.length - 1;
    this.appendMessageWithFiles('user', text || '📎 Archivos adjuntos', attachedFiles, userMsgIdx);
    await this.syncCurrentConv();
    this.updateSidebarHistory();

    this.isTyping = true;
    this._setSendButtonStop(true);
    const typingId = this.addTypingIndicator();

    let responseText = '';
    if (this.useProxy) {
      // Always route through the server proxy to avoid CORB/CORS issues
      // when calling AI APIs directly from the browser
      responseText = await this.fetchViaProxyStream(text, attachedFiles, typingId);
    } else {
      responseText = await this.fetchMockAI(text);
      this.removeTypingIndicator(typingId);
    }

    // Push first so idx is correct when action buttons are added
    this.chatHistory.push({ role: 'assistant', content: responseText });
    const aiIdx = this.chatHistory.length - 1;

    if (!this._lastStreamBubble) {
      this.appendMessageUI('ai', responseText, aiIdx);
    } else {
      this._renderMarkdownInBubble(this._lastStreamBubble, responseText);
      this._addMsgActions(this._lastStreamBubble.closest('.msg-content'), true, aiIdx);
      this._lastStreamBubble = null;
    }

    // Generar título con IA tras el primer intercambio completo
    const convIdx = this.conversations.findIndex(c => c.id === this.activeConvId);
    const shouldGenerateTitle = convIdx !== -1 && !this.conversations[convIdx]._aiTitled && this.useProxy && responseText && !responseText.startsWith('⚠️');
    if (shouldGenerateTitle) {
      this.conversations[convIdx]._aiTitled = true;
    }

    await this.syncCurrentConv();

    if (shouldGenerateTitle) {
      // Esperar 4s antes de pedir el título — evita colisión de RPM con la petición del chat
      setTimeout(() => this._generateAiTitle(this.activeConvId), 4000);
    }

    this.isTyping = false;
    this.streamAbort = null;
    this._setSendButtonStop(false);
    input.focus();
  },

  _setSendButtonStop(isStreaming) {
    const btn = document.getElementById('chat-send');
    if (!btn) return;
    if (isStreaming) {
      btn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>';
      btn.title = 'Detener generación';
      btn.onclick = () => this.stopGeneration();
      btn.disabled = false;
    } else {
      btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>';
      btn.title = 'Enviar';
      btn.onclick = () => this.sendMessage();
      btn.disabled = false;
    }
  },

  // ── Streaming fetch via proxy ─────────────────────────────────────────────
  async fetchViaProxyStream(text, files = [], typingId) {
    let SYSTEM = 'Eres VOID, un asistente de IA serio, preciso y directo. Tu nombre está inspirado en un agujero negro — absorbes cualquier pregunta y devuelves respuestas claras y precisas. Si el usuario te pregunta quién eres o cómo te llamas, responde que eres VOID. Responde siempre en el idioma del usuario. Cuando el usuario adjunte archivos o imágenes, analízalos en detalle y responde sobre su contenido.';
    if (this.userMemory && this.userMemory.trim()) {
      SYSTEM += '\n\n[MEMORIA DEL USUARIO]\n' + this.userMemory.trim();
    }

    // ── Contexto ampliado: hasta 30 mensajes recientes ──────────────────────
    const history = this.chatHistory.slice(-30).map(m => ({
      role: m.role === 'assistant' ? 'assistant' : 'user',
      content: m.content,
    }));

    const lastMsg = history[history.length - 1];

    // ── Archivos del mensaje actual ──────────────────────────────────────────
    if (lastMsg && files && files.length) {
      const content = [];
      if (text) content.push({ type: 'text', text });
      files.forEach(f => {
        if (f.isImage && f.base64) {
          content.push({ type: 'image_url', image_url: { url: `data:${f.mimeType};base64,${f.base64}`, detail: 'auto' } });
        } else if (f.pageImages && f.pageImages.length) {
          const totalPages = f.pageCount || f.pageImages.length;
          const label = `[PDF: ${f.name} — ${totalPages} páginas${totalPages > f.pageImages.length ? `, mostrando ${f.pageImages.length} como imágenes` : ''}]`;
          if (f.content) content.push({ type: 'text', text: `\n${label}\nTexto extraído:\n${f.content.slice(0, 15000)}` });
          else content.push({ type: 'text', text: `\n${label}` });
          f.pageImages.forEach((b64, pi) => {
            content.push({ type: 'text', text: `[Página ${pi + 1}]` });
            content.push({ type: 'image_url', image_url: { url: `data:image/jpeg;base64,${b64}`, detail: 'high' } });
          });
        } else if (f.content) {
          const label = f.isBinaryDoc ? `[Documento: ${f.name}]` : `[Archivo: ${f.name}]`;
          content.push({ type: 'text', text: `\n${label}\n${f.content.slice(0, 28000)}` });
        } else if (f.extracting) {
          content.push({ type: 'text', text: `[${f.name}: todavía extrayendo texto, inténtalo en un momento]` });
        }
      });
      if (!content.length) content.push({ type: 'text', text: text || '(Analiza los archivos adjuntos)' });
      lastMsg.content = content;
    }

    // ── Archivos persistentes de mensajes anteriores ─────────────────────────
    // Si hay archivos en el contexto de la conversación que NO se han enviado
    // en este mensaje, los inyectamos como recordatorio en el system prompt
    const currentFileNames = new Set(files.map(f => f.name));
    const previousFiles = this.attachedFilesContext.filter(f => !currentFileNames.has(f.name));
    if (previousFiles.length > 0) {
      let fileReminder = '\n\n[ARCHIVOS ADJUNTOS EN ESTA CONVERSACIÓN — disponibles para consulta]\n';
      previousFiles.forEach(f => {
        if (f.isImage) {
          fileReminder += `• Imagen: ${f.name}\n`;
        } else if (f.content) {
          fileReminder += `• ${f.isBinaryDoc ? 'Documento' : 'Archivo'}: ${f.name}\n${f.content.slice(0, 8000)}\n`;
        }
      });
      SYSTEM += fileReminder;
    }

    const messages = [{ role: 'system', content: SYSTEM }, ...history];

    // Create the streaming AI bubble immediately
    this.removeTypingIndicator(typingId);
    const bubble = this._createStreamingBubble();
    this._lastStreamBubble = bubble;

    const controller = new AbortController();
    this.streamAbort = controller;

    let fullText = '';

    try {
      const res = await fetch(API.proxy, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages, provider: this.apiProvider, model: this.apiModel || defaultModel(this.apiProvider), stream: true, webSearch: true }),
        signal: controller.signal,
      });

      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        this._renderMarkdownInBubble(bubble, '⚠️ ' + (err.error || 'Error del servidor') + ' ✦');
        return '⚠️ ' + (err.error || 'Error del servidor') + ' ✦';
      }

      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buf = '';

      let streamDone = false;
      while (!streamDone) {
        const { done, value } = await reader.read();
        if (done) break;
        buf += decoder.decode(value, { stream: true });

        const lines = buf.split('\n');
        buf = lines.pop(); // keep incomplete line

        for (const line of lines) {
          const trimmed = line.trim();
          if (!trimmed.startsWith('data: ')) continue;
          const payload = trimmed.slice(6);
          if (payload === '[DONE]') { streamDone = true; break; }
          try {
            const obj = JSON.parse(payload);
            if (obj.error) { this._renderMarkdownInBubble(bubble, '⚠️ ' + obj.error); return '⚠️ ' + obj.error; }
            // Evento de estado: búsqueda web en curso
            if (obj.status === 'searching') {
              const bhWrap = bubble.querySelector('.streaming-bh-wrap');
              if (bhWrap) {
                const searchLabel = document.createElement('span');
                searchLabel.className = 'void-searching-label';
                searchLabel.textContent = '🔍 Buscando en la web…';
                bhWrap.appendChild(searchLabel);
              }
            }
            if (obj.chunk) {
              fullText += obj.chunk;
              // Remove black hole animation wrap on first chunk
              const bhWrap = bubble.querySelector('.streaming-bh-wrap');
              if (bhWrap) {
                if (bubble._bhRaf) { cancelAnimationFrame(bubble._bhRaf); bubble._bhRaf = null; }
                bhWrap.remove();
              }
              // Append new chunk as a fade-in span
              const span = document.createElement('span');
              span.className = 'stream-chunk-fade';
              span.textContent = obj.chunk;
              bubble.appendChild(span);
              this.scrollToBottom();
            }
          } catch (_) {}
        }
      }
    } catch (err) {
      if (err.name === 'AbortError') {
        fullText += '\n\n*[Generación detenida]*';
      } else if (!fullText) {
        // Solo mostrar error si no se recibió nada — si ya hay texto, el stream simplemente cerró
        fullText = '⚠️ Error de conexión con el servidor. ✦';
      }
    }

    return fullText;
  },

  _createStreamingBubble() {
    const inner = document.getElementById('chat-messages-inner');
    const msg = document.createElement('div');
    msg.className = 'msg ai';
    const avatar = document.createElement('div');
    avatar.className = 'msg-avatar ai';
    avatar.textContent = 'V';
    const content = document.createElement('div');
    content.className = 'msg-content';
    const name = document.createElement('div');
    name.className = 'msg-name';
    name.textContent = 'VOID';
    const bubble = document.createElement('div');
    bubble.className = 'msg-bubble ai streaming-bubble';

    // Mini black hole canvas instead of typing dots
    const bhWrap = document.createElement('div');
    bhWrap.className = 'streaming-bh-wrap';
    const canvasId = 'bh-stream-' + Date.now();
    const canvas = document.createElement('canvas');
    canvas.id = canvasId;
    canvas.className = 'streaming-bh-canvas';
    canvas.width = 44;
    canvas.height = 44;
    bhWrap.appendChild(canvas);
    bubble.appendChild(bhWrap);

    content.appendChild(name);
    content.appendChild(bubble);
    msg.appendChild(avatar);
    msg.appendChild(content);
    inner.appendChild(msg);
    this.scrollToBottom();

    // Animate mini black hole (same as addTypingIndicator)
    const ctx = canvas.getContext('2d');
    const W = 44, H = 44, cx = W / 2, cy = H / 2;
    let particles = [], lastTime = null;
    for (let i = 0; i < 32; i++) {
      const a = Math.random() * Math.PI * 2;
      const r = 8 + Math.random() * 14;
      particles.push({
        a, r, baseR: r,
        dir: Math.random() > 0.5 ? 1 : -1,
        speed: 0.0008 + Math.random() * 0.0012,
        size: Math.random() * 1.1 + 0.3,
        opacity: Math.random() * 0.8 + 0.2,
        isYellow: Math.random() < 0.45,
        hue: 55 + Math.random() * 20
      });
    }
    const draw = (ts) => {
      if (lastTime === null) lastTime = ts;
      const dt = Math.min(ts - lastTime, 50);
      lastTime = ts;
      ctx.clearRect(0, 0, W, H);
      // Outer glow
      for (let i = 2; i > 0; i--) {
        const g = ctx.createRadialGradient(cx, cy, 3 * i, cx, cy, 11 * i + 5);
        g.addColorStop(0, `rgba(232,255,71,${0.06 / i})`);
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = g; ctx.beginPath(); ctx.arc(cx, cy, 11 * i + 5, 0, Math.PI * 2); ctx.fill();
      }
      // Accretion disc warm glow
      const disc = ctx.createRadialGradient(cx, cy, 5, cx, cy, 19);
      disc.addColorStop(0, 'rgba(0,0,0,0)');
      disc.addColorStop(0.4, 'rgba(232,200,40,0.07)');
      disc.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = disc; ctx.beginPath(); ctx.arc(cx, cy, 19, 0, Math.PI * 2); ctx.fill();
      // Event horizon
      const bh = ctx.createRadialGradient(cx, cy, 0, cx, cy, 7);
      bh.addColorStop(0, 'rgba(0,0,0,1)'); bh.addColorStop(0.8, 'rgba(0,0,0,1)'); bh.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = bh; ctx.beginPath(); ctx.arc(cx, cy, 7, 0, Math.PI * 2); ctx.fill();
      // Particles
      particles.forEach(p => {
        p.a += p.dir * p.speed * dt;
        p.r = p.baseR + Math.sin(p.a * 3) * 1.5;
        const x = cx + Math.cos(p.a) * p.r;
        const y = cy + Math.sin(p.a) * p.r * 0.38;
        const alpha = p.opacity * Math.max(0, 1 - (10 / p.r));
        ctx.beginPath(); ctx.arc(x, y, p.size, 0, Math.PI * 2);
        ctx.fillStyle = p.isYellow ? `hsla(${p.hue},100%,68%,${alpha})` : `rgba(235,238,248,${alpha * 0.7})`;
        ctx.fill();
      });
      bubble._bhRaf = requestAnimationFrame(draw);
    };
    bubble._bhRaf = requestAnimationFrame(draw);

    return bubble;
  },

  // ── Markdown rendering ────────────────────────────────────────────────────
  _renderMarkdownInBubble(bubble, text) {
    if (typeof marked === 'undefined') {
      bubble.innerHTML = this.escapeHtml(text).replace(/\n/g, '<br>');
      return;
    }
    bubble.classList.remove('streaming-bubble');

    // Configure marked
    marked.setOptions({ breaks: true, gfm: true });

    const renderer = new marked.Renderer();
    // Code blocks with syntax highlight + copy button
    renderer.code = (code, lang) => {
      const language = (lang || '').split(/[^a-zA-Z0-9]/, 1)[0] || 'plaintext';
      let highlighted = code;
      if (typeof hljs !== 'undefined') {
        try {
          highlighted = hljs.highlight(code, { language, ignoreIllegals: true }).value;
        } catch (_) {
          highlighted = hljs.highlightAuto(code).value;
        }
      } else {
        highlighted = this.escapeHtml(code);
      }
      const id = 'cb-' + Math.random().toString(36).slice(2, 8);
      return `<div class="code-block"><div class="code-header"><span class="code-lang">${this.escapeHtml(language)}</span><button class="code-copy" onclick="app.copyCode('${id}')" title="Copiar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copiar</button></div><pre><code id="${id}" class="hljs language-${this.escapeHtml(language)}">${highlighted}</code></pre></div>`;
    };
    marked.use({ renderer });

    const parsed = marked.parse(text.trim());
    bubble.innerHTML = parsed.replace(/<p>\s*<\/p>\s*$/i, '');
    bubble.style.whiteSpace = 'normal';
    bubble.classList.add('markdown-fade-in');
    this.scrollToBottom();
  },

  copyCode(id) {
    const el = document.getElementById(id);
    if (!el) return;
    navigator.clipboard.writeText(el.textContent).then(() => {
      this.showToast('✓ Copiado');
    }).catch(() => {
      this.showToast('No se pudo copiar');
    });
  },

  appendMessageUI(role, text, idx) {
    const inner = document.getElementById('chat-messages-inner');
    const isAI = role === 'ai' || role === 'assistant';
    const uiRole = isAI ? 'ai' : 'user';
    const msg = document.createElement('div');
    msg.className = 'msg ' + uiRole;
    const avatarStr = isAI ? 'V' : (this.currentUser ? this.currentUser.name.slice(0, 1).toUpperCase() : 'U');
    const name = isAI ? 'VOID' : 'Tú';

    const bubble = document.createElement('div');
    bubble.className = 'msg-bubble ' + uiRole;

    if (isAI) {
      this._renderMarkdownInBubble(bubble, text);
    } else {
      bubble.innerHTML = this.escapeHtml(text).replace(/\n/g, '<br>');
    }

    msg.innerHTML = '<div class="msg-avatar ' + uiRole + '">' + avatarStr + '</div><div class="msg-content"><div class="msg-name">' + name + '</div></div>';
    const msgContent = msg.querySelector('.msg-content');
    msgContent.appendChild(bubble);
    if (idx !== undefined) this._addMsgActions(msgContent, isAI, idx);
    inner.appendChild(msg);
    this.scrollToBottom();
  },

  addTypingIndicator() {
    const inner = document.getElementById('chat-messages-inner');
    const id = 'typing-' + Date.now();
    const el = document.createElement('div');
    el.id = id;
    el.className = 'typing-indicator';

    const canvasId = 'bh-typing-' + Date.now();
    el.innerHTML = `<div class="typing-bh-wrap"><canvas id="${canvasId}" class="typing-bh-canvas" width="56" height="56"></canvas></div>`;
    inner.appendChild(el);
    this.scrollToBottom();

    // Animate mini black hole
    const canvas = document.getElementById(canvasId);
    const ctx = canvas.getContext('2d');
    const W = 56, H = 56, cx = W / 2, cy = H / 2;
    let particles = [], lastTime = null;
    for (let i = 0; i < 38; i++) {
      const a = Math.random() * Math.PI * 2;
      const r = 10 + Math.random() * 18;
      particles.push({
        a, r, baseR: r,
        dir: Math.random() > 0.5 ? 1 : -1,
        speed: 0.0008 + Math.random() * 0.0012,
        size: Math.random() * 1.2 + 0.3,
        opacity: Math.random() * 0.8 + 0.2,
        isYellow: Math.random() < 0.45,
        hue: 55 + Math.random() * 20
      });
    }
    let rafId;
    const draw = (ts) => {
      if (lastTime === null) lastTime = ts;
      const dt = Math.min(ts - lastTime, 50);
      lastTime = ts;
      ctx.clearRect(0, 0, W, H);

      // Outer glow
      for (let i = 2; i > 0; i--) {
        const g = ctx.createRadialGradient(cx, cy, 4 * i, cx, cy, 14 * i + 6);
        g.addColorStop(0, `rgba(232,255,71,${0.06 / i})`);
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = g; ctx.beginPath(); ctx.arc(cx, cy, 14 * i + 6, 0, Math.PI * 2); ctx.fill();
      }
      // Accretion disc warm glow
      const disc = ctx.createRadialGradient(cx, cy, 7, cx, cy, 24);
      disc.addColorStop(0, 'rgba(0,0,0,0)');
      disc.addColorStop(0.4, 'rgba(232,200,40,0.07)');
      disc.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = disc; ctx.beginPath(); ctx.arc(cx, cy, 24, 0, Math.PI * 2); ctx.fill();
      // Event horizon
      const bh = ctx.createRadialGradient(cx, cy, 0, cx, cy, 9);
      bh.addColorStop(0, 'rgba(0,0,0,1)'); bh.addColorStop(0.8, 'rgba(0,0,0,1)'); bh.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = bh; ctx.beginPath(); ctx.arc(cx, cy, 9, 0, Math.PI * 2); ctx.fill();
      // Particles
      particles.forEach(p => {
        p.a += p.dir * p.speed * dt;
        p.r = p.baseR + Math.sin(p.a * 3) * 2;
        const x = cx + Math.cos(p.a) * p.r;
        const y = cy + Math.sin(p.a) * p.r * 0.38;
        const alpha = p.opacity * Math.max(0, 1 - (12 / p.r));
        ctx.beginPath(); ctx.arc(x, y, p.size, 0, Math.PI * 2);
        ctx.fillStyle = p.isYellow ? `hsla(${p.hue},100%,68%,${alpha})` : `rgba(235,238,248,${alpha * 0.7})`;
        ctx.fill();
      });
      rafId = requestAnimationFrame(draw);
    };
    rafId = requestAnimationFrame(draw);

    // Store rafId on element to cancel when removed
    el._bhRaf = rafId;
    el._bhCanvas = canvasId;
    return id;
  },

  removeTypingIndicator(id) {
    const el = document.getElementById(id);
    if (el) {
      if (el._bhRaf) cancelAnimationFrame(el._bhRaf);
      el.remove();
    }
  },

  scrollToBottom() {
    const container = document.getElementById('chat-messages');
    container.scrollTop = container.scrollHeight;
  },

  // ==========================================
  // FILE ATTACHMENTS
  // ==========================================
  pendingFiles: [], // [{name, size, type, content, base64, mimeType, isImage}]

  getFileIcon(name, size = 14) {
    const ext = name.split('.').pop().toLowerCase();
    const s = `width:${size}px;height:${size}px;flex-shrink:0;vertical-align:middle;`;
    const svg = (id) => `<svg style="${s}" viewBox="0 0 24 24"><use href="#${id}"/></svg>`;
    if (ext === 'pdf')                                          return svg('ico-file-pdf');
    if (['doc','docx'].includes(ext))                          return svg('ico-file-doc');
    if (['xls','xlsx','csv'].includes(ext))                    return svg('ico-file-sheet');
    if (['js','ts','py','html','css','json','xml','yaml','yml'].includes(ext)) return svg('ico-file-code');
    if (['jpg','jpeg','png','gif','webp'].includes(ext))        return svg('ico-file-img');
    return svg('ico-file-text');
  },

  formatSize(bytes) {
    if (bytes < 1024) return bytes + 'B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + 'KB';
    return (bytes / (1024 * 1024)).toFixed(1) + 'MB';
  },

  async handleFiles(fileList) {
    const MAX = 5, MAX_MB = 30;
    const files = Array.from(fileList).slice(0, MAX - this.pendingFiles.length);
    for (const file of files) {
      if (file.size > MAX_MB * 1024 * 1024) { this.showToast('⚠️ ' + file.name + ' supera los 30MB'); continue; }
      const isImage = file.type.startsWith('image/');
      const ext     = file.name.split('.').pop().toLowerCase();
      const isPdf   = ext === 'pdf'  || file.type === 'application/pdf';
      const isDocx  = ext === 'docx' || file.type.includes('wordprocessingml');
      const isXlsx  = ['xlsx','xls'].includes(ext) || file.type.includes('spreadsheetml') || file.type.includes('ms-excel');
      const isBinaryDoc = isPdf || isDocx || isXlsx;

      if (isImage) {
        const data = await this._readAsBase64(file);
        this.pendingFiles.push({ name: file.name, size: file.size, type: file.type, mimeType: file.type, isImage, isBinaryDoc: false, ...data });
        this.renderAttachmentPreviews();
      } else if (isPdf || isDocx || isXlsx) {
        const idx = this.pendingFiles.length;
        this.pendingFiles.push({ name: file.name, size: file.size, type: file.type, mimeType: file.type, isImage: false, isBinaryDoc: true, base64: null, content: null, extracting: true });
        this.renderAttachmentPreviews();
        if (isPdf) {
          this._extractPdfText(file).then(result => {
            if (this.pendingFiles[idx] && this.pendingFiles[idx].extracting) {
              if (typeof result === 'string') {
                this.pendingFiles[idx].content = result;
              } else {
                this.pendingFiles[idx].content     = result.text;
                this.pendingFiles[idx].pageImages  = result.images || [];
                this.pendingFiles[idx].pageCount   = result.pageCount;
                this.pendingFiles[idx].isVisualPdf = result.isVisualPdf;
              }
              this.pendingFiles[idx].extracting = false;
              this.renderAttachmentPreviews();
            }
          });
        } else {
          this._extractOoxmlText(file, isXlsx).then(text => {
            if (this.pendingFiles[idx] && this.pendingFiles[idx].extracting) {
              this.pendingFiles[idx].content    = text;
              this.pendingFiles[idx].extracting = false;
              this.renderAttachmentPreviews();
            }
          });
        }
      } else {
        const data = await this._readAsText(file);
        this.pendingFiles.push({ name: file.name, size: file.size, type: file.type, mimeType: file.type, isImage: false, isBinaryDoc: false, ...data });
        this.renderAttachmentPreviews();
      }
    }
    document.getElementById('file-input').value = '';
  },

  _readAsBase64(file) {
    return new Promise(resolve => {
      const r = new FileReader();
      r.onload = e => resolve({ base64: e.target.result.split(',')[1], content: null, extracting: false });
      r.onerror = () => resolve({ base64: null, content: null, extracting: false });
      r.readAsDataURL(file);
    });
  },

  _readAsText(file) {
    return new Promise(resolve => {
      const r = new FileReader();
      r.onload = e => resolve({ base64: null, content: e.target.result, extracting: false });
      r.onerror = () => resolve({ base64: null, content: '[No se pudo leer el archivo]', extracting: false });
      r.readAsText(file, 'UTF-8');
    });
  },

  async _extractPdfText(file) {
    try {
      const arrayBuffer = await file.arrayBuffer();
      const pdfjsLib = await import('https://cdn.jsdelivr.net/npm/pdfjs-dist@4.4.168/build/pdf.min.mjs');
      pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.4.168/build/pdf.worker.min.mjs';
      const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;

      // Extraer texto de todas las páginas (hasta 100)
      let fullText = '';
      const maxPages = Math.min(pdf.numPages, 100);
      for (let i = 1; i <= maxPages; i++) {
        const page = await pdf.getPage(i);
        const tc = await page.getTextContent();
        const pageText = tc.items.map(item => item.str).join(' ').trim();
        if (pageText) fullText += `[Página ${i}]\n${pageText}\n\n`;
      }

      // Detectar si es PDF visual/escaneado (poco texto)
      const avgCharsPerPage = fullText.length / maxPages;
      const isVisualPdf = avgCharsPerPage < 80;

      // Renderizar páginas como imágenes (máx. 10 para no saturar el contexto)
      const renderPages = Math.min(pdf.numPages, 10);
      const pageImages = [];
      for (let i = 1; i <= renderPages; i++) {
        const page = await pdf.getPage(i);
        const scale = 1.5;
        const viewport = page.getViewport({ scale });
        const canvas = document.createElement('canvas');
        canvas.width  = viewport.width;
        canvas.height = viewport.height;
        const ctx = canvas.getContext('2d');
        await page.render({ canvasContext: ctx, viewport }).promise;
        pageImages.push(canvas.toDataURL('image/jpeg', 0.82).split(',')[1]);
        canvas.remove();
      }

      return {
        text: fullText.trim().slice(0, 30000) || null,
        images: pageImages,
        pageCount: pdf.numPages,
        isVisualPdf,
      };
    } catch (e) {
      return { text: '[Error al leer el PDF: ' + e.message + ']', images: [], pageCount: 0, isVisualPdf: false };
    }
  },

  async _extractOoxmlText(file, isXlsx) {
    try {
      if (typeof JSZip === 'undefined') return '[JSZip no disponible — recarga la página e inténtalo de nuevo]';
      const arrayBuffer = await file.arrayBuffer();
      const zip = await JSZip.loadAsync(arrayBuffer);
      let xmlContent = '';
      if (isXlsx) {
        const ss = zip.file('xl/sharedStrings.xml');
        if (ss) xmlContent += await ss.async('string');
        const sh = zip.file('xl/worksheets/sheet1.xml');
        if (sh) xmlContent += await sh.async('string');
      } else {
        const doc = zip.file('word/document.xml');
        if (doc) xmlContent = await doc.async('string');
      }
      if (!xmlContent) return '[No se pudo extraer contenido del documento]';
      const text = xmlContent
        .replace(/<\/w:p>/g, '\n').replace(/<\/w:r>/g, ' ').replace(/<[^>]+>/g, '')
        .replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"').replace(/&#39;/g,"'")
        .replace(/\n{3,}/g, '\n\n').trim();
      return text.length > 30000 ? text.slice(0, 30000) + '\n\n[... truncado]' : text;
    } catch (e) {
      return '[Error al leer el documento: ' + e.message + ']';
    }
  },

  renderAttachmentPreviews() {
    const container = document.getElementById('chat-attachments');
    container.innerHTML = '';
    this.pendingFiles.forEach((f, i) => {
      const chip = document.createElement('div');
      chip.className = 'attach-chip' + (f.isImage ? ' is-image' : '');
      if (f.isImage && f.base64) {
        chip.innerHTML = `<img class="attach-chip-thumb" src="data:${f.mimeType};base64,${f.base64}"><span class="attach-chip-name">${this.escapeHtml(f.name)}</span><span class="attach-chip-size">${this.formatSize(f.size)}</span><button class="attach-chip-remove" onclick="app.removeAttachment(${i})"><svg style="width:12px;height:12px"><use href="#ico-close"/></svg></button>`;
      } else if (f.isBinaryDoc) {
        const badge = f.extracting
          ? `<span class="attach-chip-badge extracting"><svg class="chip-spinner" style="width:11px;height:11px" viewBox="0 0 24 24"><use href="#ico-spinner"/></svg> Extrayendo…</span>`
          : `<span class="attach-chip-badge ready"><svg style="width:11px;height:11px" viewBox="0 0 24 24"><use href="#ico-check-sm"/></svg> Listo</span>`;
        chip.innerHTML = `<span class="attach-chip-icon">${this.getFileIcon(f.name)}</span><span class="attach-chip-name">${this.escapeHtml(f.name)}</span>${badge}<button class="attach-chip-remove" onclick="app.removeAttachment(${i})"><svg style="width:12px;height:12px"><use href="#ico-close"/></svg></button>`;
      } else {
        chip.innerHTML = `<span class="attach-chip-icon">${this.getFileIcon(f.name)}</span><span class="attach-chip-name">${this.escapeHtml(f.name)}</span><span class="attach-chip-size">${this.formatSize(f.size)}</span><button class="attach-chip-remove" onclick="app.removeAttachment(${i})"><svg style="width:12px;height:12px"><use href="#ico-close"/></svg></button>`;
      }
      container.appendChild(chip);
    });
  },

  removeAttachment(i) {
    this.pendingFiles.splice(i, 1);
    this.renderAttachmentPreviews();
  },

  clearAttachments() {
    this.pendingFiles = [];
    document.getElementById('chat-attachments').innerHTML = '';
  },

  appendMessageWithFiles(role, text, files, idx) {
    const inner = document.getElementById('chat-messages-inner');
    const isAI = role === 'ai' || role === 'assistant';
    const uiRole = isAI ? 'ai' : 'user';
    const msg = document.createElement('div');
    msg.className = 'msg ' + uiRole;
    const avatarStr = isAI ? 'V' : (this.currentUser ? this.currentUser.name.slice(0, 1).toUpperCase() : 'U');
    const name = isAI ? 'VOID' : 'Tú';

    let attachHtml = '';
    if (files && files.length) {
      attachHtml = '<div class="msg-attachments">' + files.map(f =>
        `<span class="msg-attach-badge">${this.getFileIcon(f.name)} ${this.escapeHtml(f.name)}</span>`
      ).join('') + '</div>';
    }

    const bubble = document.createElement('div');
    bubble.className = 'msg-bubble ' + uiRole;
    if (attachHtml) bubble.innerHTML = attachHtml;

    if (isAI) {
      this._renderMarkdownInBubble(bubble, text);
    } else {
      bubble.innerHTML += this.escapeHtml(text).replace(/\n/g, '<br>');
    }

    msg.innerHTML = `<div class="msg-avatar ${uiRole}">${avatarStr}</div><div class="msg-content"><div class="msg-name">${name}</div></div>`;
    const msgContent2 = msg.querySelector('.msg-content');
    msgContent2.appendChild(bubble);
    if (idx !== undefined) this._addMsgActions(msgContent2, isAI, idx);
    inner.appendChild(msg);
    this.scrollToBottom();
  },

  escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  },

  // ==========================================
  // AI ENGINES
  // ==========================================

  async fetchMockAI(text) {
    return new Promise(resolve => {
      setTimeout(() => {
        const lower = text.toLowerCase();
        let reply = "Recibido. Para obtener respuestas reales configura tu API Key en ajustes.";
        if (lower.includes('hola')) reply = "Hola. ¿En qué puedo ayudarte?";
        else if (lower.includes('agujero negro')) reply = "Un agujero negro es una región del espacio-tiempo donde la gravedad es tan intensa que ninguna partícula, ni siquiera la luz, puede escapar. Se forman principalmente tras el colapso gravitacional de estrellas masivas.";
        else if (lower.includes('cuerdas')) reply = "La teoría de cuerdas propone que las partículas fundamentales son cuerdas vibrantes unidimensionales. Ofrece un marco para unificar la relatividad general con la mecánica cuántica, aunque aún carece de verificación experimental directa.";
        else if (lower.includes('codigo') || lower.includes('código')) reply = "En modo de demostración las capacidades de análisis de código son limitadas. Conecta una API Key en ajustes para obtener respuestas completas.";
        resolve(reply);
      }, 1500);
    });
  },

  buildFileContextFrom(files) {
    if (!files || !files.length) return '';
    let ctx = '\n\n[ARCHIVOS ADJUNTOS — analiza su contenido]\n';
    files.forEach(f => {
      if (f.isImage) {
        ctx += `\n• Imagen adjunta: ${f.name}\n`;
      } else if (f.isBinaryDoc) {
        ctx += `\n• Documento adjunto: ${f.name}${f.extracting ? ' (extrayendo…)' : f.content ? ' (' + Math.round(f.content.length/1000) + 'k caracteres extraídos)' : ''}\n`;
      } else {
        const preview = f.content ? f.content.slice(0, 8000) : '';
        ctx += `\n• Archivo: ${f.name}\n\`\`\`\n${preview}${f.content && f.content.length > 8000 ? '\n...[truncado a 8000 chars]' : ''}\n\`\`\`\n`;
      }
    });
    return ctx;
  },


  // ==========================================
  // MESSAGE ACTIONS
  // ==========================================
  _addMsgActions(msgContent, isAI, idx) {
    if (!msgContent || msgContent.querySelector('.msg-actions')) return;
    const copyBtn = '<button class="msg-action-btn" title="Copiar" onclick="app.copyMessage(' + idx + ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>';
    const regenBtn = '<button class="msg-action-btn" title="Regenerar" onclick="app.regenerateMessage(' + idx + ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4-4.64 4.36A9 9 0 0 1 3.51 15"/></svg></button>';
    const editBtn  = '<button class="msg-action-btn" title="Editar" onclick="app.startEditMessage(' + idx + ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>';
    const html = '<div class="msg-actions">' + copyBtn + (isAI ? regenBtn : editBtn) + '</div>';
    msgContent.insertAdjacentHTML('beforeend', html);
  },

  copyMessage(idx) {
    const msg = this.chatHistory[idx];
    if (!msg) return;
    navigator.clipboard.writeText(msg.content).then(() => this.showToast('\u2713 Copiado')).catch(() => this.showToast('No se pudo copiar'));
  },

  startEditMessage(idx) {
    const msg = this.chatHistory[idx];
    if (!msg || msg.role !== 'user') return;
    const allMsgs = document.querySelectorAll('#chat-messages-inner .msg');
    const msgEl = allMsgs[idx];
    if (!msgEl) return;
    const bubble = msgEl.querySelector('.msg-bubble');
    if (!bubble) return;
    const originalText = msg.content;
    const textarea = document.createElement('textarea');
    textarea.className = 'msg-edit-textarea';
    textarea.value = originalText;
    bubble.innerHTML = '';
    bubble.appendChild(textarea);
    textarea.focus();
    textarea.style.height = textarea.scrollHeight + 'px';
    const actions = document.createElement('div');
    actions.className = 'msg-edit-actions';
    actions.innerHTML = '<button class="msg-edit-btn msg-edit-confirm" onclick="app.confirmEditMessage(' + idx + ')">Enviar edición</button><button class="msg-edit-btn msg-edit-cancel" onclick="app.cancelEditMessage(' + idx + ')">Cancelar</button>';
    bubble.appendChild(actions);
  },

  cancelEditMessage(idx) {
    const msg = this.chatHistory[idx];
    if (!msg) return;
    const allMsgs = document.querySelectorAll('#chat-messages-inner .msg');
    const msgEl = allMsgs[idx];
    if (!msgEl) return;
    const bubble = msgEl.querySelector('.msg-bubble');
    if (bubble) bubble.innerHTML = this.escapeHtml(msg.content).replace(/\n/g, '<br>');
  },

  async confirmEditMessage(idx) {
    if (this.isTyping) return;
    const allMsgs = document.querySelectorAll('#chat-messages-inner .msg');
    const msgEl = allMsgs[idx];
    if (!msgEl) return;
    const textarea = msgEl.querySelector('.msg-edit-textarea');
    if (!textarea) return;
    const newText = textarea.value.trim();
    if (!newText) return;

    this.chatHistory = this.chatHistory.slice(0, idx);
    this.chatHistory.push({ role: 'user', content: newText });

    const inner = document.getElementById('chat-messages-inner');
    Array.from(inner.querySelectorAll('.msg')).slice(idx).forEach(el => el.remove());

    const userIdx = this.chatHistory.length - 1;
    this.appendMessageUI('user', newText, userIdx);
    await this.syncCurrentConv();
    this.updateSidebarHistory();

    this.isTyping = true;
    this._setSendButtonStop(true);
    const typingId = this.addTypingIndicator();
    const responseText = await this.fetchViaProxyStream(newText, [], typingId);

    this.chatHistory.push({ role: 'assistant', content: responseText });
    const aiIdx = this.chatHistory.length - 1;
    if (!this._lastStreamBubble) {
      this.appendMessageUI('ai', responseText, aiIdx);
    } else {
      this._renderMarkdownInBubble(this._lastStreamBubble, responseText);
      this._addMsgActions(this._lastStreamBubble.closest('.msg-content'), true, aiIdx);
      this._lastStreamBubble = null;
    }
    await this.syncCurrentConv();
    this.isTyping = false;
    this.streamAbort = null;
    this._setSendButtonStop(false);
  },

  async regenerateMessage(idx) {
    if (this.isTyping) return;
    let userIdx = idx - 1;
    while (userIdx >= 0 && this.chatHistory[userIdx].role !== 'user') userIdx--;
    if (userIdx < 0) return;
    const userText = this.chatHistory[userIdx].content;

    this.chatHistory = this.chatHistory.slice(0, idx);
    const inner = document.getElementById('chat-messages-inner');
    Array.from(inner.querySelectorAll('.msg')).slice(idx).forEach(el => el.remove());
    await this.syncCurrentConv();

    this.isTyping = true;
    this._setSendButtonStop(true);
    const typingId = this.addTypingIndicator();
    const responseText = await this.fetchViaProxyStream(userText, [], typingId);

    this.chatHistory.push({ role: 'assistant', content: responseText });
    const aiIdx = this.chatHistory.length - 1;
    if (!this._lastStreamBubble) {
      this.appendMessageUI('ai', responseText, aiIdx);
    } else {
      this._renderMarkdownInBubble(this._lastStreamBubble, responseText);
      this._addMsgActions(this._lastStreamBubble.closest('.msg-content'), true, aiIdx);
      this._lastStreamBubble = null;
    }
    await this.syncCurrentConv();
    this.isTyping = false;
    this.streamAbort = null;
    this._setSendButtonStop(false);
  },

  // ==========================================
  // MEMORY MANAGEMENT
  // ==========================================
  openMemory() {
    const modal = document.getElementById('modal-memory');
    if (!modal) return;
    document.getElementById('memory-textarea').value = this.userMemory || '';
    this._updateMemoryCount();
    modal.classList.add('active');
  },

  closeMemory() {
    document.getElementById('modal-memory').classList.remove('active');
  },

  _updateMemoryCount() {
    const ta = document.getElementById('memory-textarea');
    const counter = document.getElementById('memory-char-count');
    if (!ta || !counter) return;
    counter.textContent = ta.value.length + ' / 4000';
  },

  async saveMemory() {
    const ta = document.getElementById('memory-textarea');
    if (!ta) return;
    const memory = ta.value.trim();
    if (memory.length > 4000) { this.showToast('\u26a0\ufe0f Máximo 4000 caracteres'); return; }
    const res = await apiFetch(API.user + '?action=memory', { method: 'PUT', body: JSON.stringify({ memory }) });
    if (!res.ok) { this.showToast('\u26a0\ufe0f ' + res.error); return; }
    this.userMemory = memory;
    this.closeMemory();
    this.showToast('Memoria guardada \u2756');
  },

  clearMemory() {
    const ta = document.getElementById('memory-textarea');
    if (ta) { ta.value = ''; this._updateMemoryCount(); }
  },

  // ==========================================
  // SETTINGS MODAL
  // ==========================================
  renderModelSelector(provider, selectedModel) {
    const container = document.getElementById('model-selector');
    if (!container) return;
    const models = MODELS[provider] || [];
    const active = selectedModel || defaultModel(provider);
    container.innerHTML = models.map(m => `
      <button class="model-btn${m.id === active ? ' active' : ''}"
        data-model="${m.id}"
        onclick="app.selectModel('${m.id}')">
        ${m.label}
        <span class="model-tag">${m.tag}</span>
      </button>
    `).join('');
  },

  selectModel(modelId) {
    this._tempModel = modelId;
    document.querySelectorAll('.model-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.model === modelId);
    });
  },

  selectProvider(provider) {
    // Only updates the UI + temp state, never commits to this.apiProvider
    this._tempProvider = provider;
    document.querySelectorAll('.provider-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.provider === provider);
    });
    // Reset temp model and re-render model selector for new provider
    this._tempModel = defaultModel(provider);
    this.renderModelSelector(provider, this._tempModel);
  },

  openSettings() {
    const provider = this.apiProvider || 'gemini';
    this._tempProvider = provider;
    this.apiProvider = provider; // asegurar que nunca sea undefined

    document.getElementById('modal-settings').classList.add('active');

    document.querySelectorAll('.provider-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.provider === provider);
    });
    this._tempModel = this.apiModel || defaultModel(provider);
    this.renderModelSelector(provider, this._tempModel);
    this.updateModelStatus();
  },

  closeSettings() {
    // Revert any unsaved provider change
    this._tempProvider = null;
    document.getElementById('modal-settings').classList.remove('active');
  },

  async saveSettings() {
    const provider = this._tempProvider || this.apiProvider;
    const model = this._tempModel || this.apiModel || defaultModel(provider);
    this._tempProvider = null;
    this._tempModel = null;

    // Intentar guardar en BD (puede fallar si no hay backend)
    try {
      const res = await apiFetch(API.user + '?action=settings', {
        method: 'PUT',
        body: JSON.stringify({ api_provider: provider, api_model: model }),
      });
      if (!res.ok && res.error && !res.error.includes('autenticad')) {
        this.showToast('⚠️ ' + res.error); return;
      }
    } catch(e) { /* sin BD — continuar igualmente */ }

    this.apiProvider = provider;
    this.apiModel = model;
    this.useProxy = true; // el servidor siempre tiene la key configurada

    // Guardar en localStorage como respaldo sin BD
    localStorage.setItem('void_provider', provider);
    localStorage.setItem('void_model', model);

    this.updateModelStatus();
    this.closeSettings();
    this.showToast('Ajustes guardados ✦');
  },

  updateModelStatus() {
    const dot = document.getElementById('model-status-dot');
    const text = document.getElementById('model-status-text');
    const badge = document.querySelector('.status-badge');
    const isConnected = this.useProxy;
    if (isConnected) {
      const modelList = MODELS[this.apiProvider] || [];
      const modelInfo = modelList.find(m => m.id === this.apiModel);
      const modelLabel = modelInfo ? modelInfo.label : (this.apiProvider === 'gemini' ? 'Gemini' : 'OpenAI');
      if (dot) dot.classList.add('active');
      if (text) text.textContent = 'VOID Singularidad (' + modelLabel + ')';
      if (badge) { badge.className = 'status-badge real'; badge.textContent = 'Conectado · ' + modelLabel; }
    } else {
      if (dot) dot.classList.remove('active');
      if (text) text.textContent = 'VOID Base (Mock)';
      if (badge) { badge.className = 'status-badge mock'; badge.textContent = 'Simulación (Mock)'; }
    }
  },

  // ==========================================
  // VISUALS & ANIMATIONS
  // ==========================================
  initCursor() {
    const cursor = document.getElementById('cursor');
    const ring   = document.getElementById('cursor-ring');
    if (!cursor || !ring) return;

    let mx = -100, my = -100; // raw mouse position
    let rx = -100, ry = -100; // ring interpolated position
    let rafId = null;

    // Update dot instantly on mousemove — zero lag
    document.addEventListener('mousemove', e => {
      mx = e.clientX; my = e.clientY;
      cursor.style.transform = `translate(calc(-50% + ${mx}px), calc(-50% + ${my}px))`;
    }, { passive: true });

    // Ring follows with smooth lerp via rAF — no CSS transition on position
    const LERP = 0.18; // 0 = instant, 1 = never moves; 0.18 = snappy trail
    function animateRing() {
      rx += (mx - rx) * LERP;
      ry += (my - ry) * LERP;
      ring.style.transform = `translate(calc(-50% + ${rx}px), calc(-50% + ${ry}px))`;
      rafId = requestAnimationFrame(animateRing);
    }
    animateRing();

    document.addEventListener('mousedown', () => {
      cursor.style.width = '6px'; cursor.style.height = '6px';
      ring.style.width = '44px'; ring.style.height = '44px';
    });
    document.addEventListener('mouseup', () => {
      cursor.style.width = '10px'; cursor.style.height = '10px';
      ring.style.width = '32px'; ring.style.height = '32px';
    });

    // Hide when leaving window
    document.addEventListener('mouseleave', () => {
      cursor.style.opacity = '0'; ring.style.opacity = '0';
    });
    document.addEventListener('mouseenter', () => {
      cursor.style.opacity = '1'; ring.style.opacity = '1';
    });
  },

  initCanvas() {
    const canvas = document.getElementById('bh-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let W, H, particles = [];
    // Use delta-time so speed is always frame-rate independent and never accelerates
    let lastTime = null;
    const ORBITAL_SPEED = 0.00045; // radians per ms — constant forever

    function getSidebarOffset() {
      if (window.innerWidth < 900) return 0;
      const sidebar = document.getElementById('chat-sidebar');
      if (!sidebar) return 0;
      const page = document.getElementById('page-chat');
      if (!page || !page.classList.contains('active')) return 0;
      return sidebar.getBoundingClientRect().right;
    }
    function resize() { W = canvas.width = window.innerWidth; H = canvas.height = window.innerHeight; }
    resize(); window.addEventListener('resize', resize);

    for (let i = 0; i < 160; i++) {
      const a = Math.random() * Math.PI * 2, r = 120 + Math.random() * 340;
      // ~40% of particles are yellow-tinted for the warm accretion disc look
      const isYellow = Math.random() < 0.40;
      particles.push({
        a, r, baseR: r,
        dir: Math.random() > 0.5 ? 1 : -1,
        speed: (0.00022 + Math.random() * 0.00055),
        size: Math.random() * 1.6 + 0.4,
        opacity: Math.random() * 0.75 + 0.15,
        isYellow,
        // yellow particles vary slightly in hue (55–75) for a richer glow
        hue: isYellow ? 55 + Math.random() * 20 : 0
      });
    }

    function draw(timestamp) {
      if (lastTime === null) lastTime = timestamp;
      const dt = Math.min(timestamp - lastTime, 50); // cap at 50ms to survive tab-blur jumps
      lastTime = timestamp;

      ctx.clearRect(0, 0, W, H);
      const sidebarOffset = getSidebarOffset();
      const cx = sidebarOffset + (W - sidebarOffset) / 2, cy = H / 2;

      // Outer yellow glow rings — more layers, more intensity
      for (let i = 4; i > 0; i--) {
        const grad = ctx.createRadialGradient(cx, cy, 50 * i, cx, cy, 72 * i + 120);
        grad.addColorStop(0, 'rgba(232,255,71,' + (0.025 / i) + ')');
        grad.addColorStop(0.5, 'rgba(255,210,30,' + (0.012 / i) + ')');
        grad.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = grad; ctx.beginPath(); ctx.arc(cx, cy, 72 * i + 120, 0, Math.PI * 2); ctx.fill();
      }

      // Inner accretion disc — warm yellow-orange band just outside the event horizon
      const disc = ctx.createRadialGradient(cx, cy, 95, cx, cy, 200);
      disc.addColorStop(0, 'rgba(0,0,0,0)');
      disc.addColorStop(0.3, 'rgba(232,200,40,0.06)');
      disc.addColorStop(0.6, 'rgba(232,255,71,0.04)');
      disc.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = disc; ctx.beginPath(); ctx.arc(cx, cy, 200, 0, Math.PI * 2); ctx.fill();

      // Event horizon — pure black sphere
      const bh = ctx.createRadialGradient(cx, cy, 0, cx, cy, 112);
      bh.addColorStop(0, 'rgba(0,0,0,1)');
      bh.addColorStop(0.78, 'rgba(0,0,0,1)');
      bh.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = bh; ctx.beginPath(); ctx.arc(cx, cy, 112, 0, Math.PI * 2); ctx.fill();

      // Move particles with constant delta-time speed — no acceleration ever
      particles.forEach(p => {
        p.a += p.dir * p.speed * dt;
        p.r = p.baseR + Math.sin(p.a * 3) * 8;
        const x = cx + Math.cos(p.a) * p.r;
        const y = cy + Math.sin(p.a) * p.r * 0.38;
        const alpha = p.opacity * Math.max(0, 1 - (155 / p.r));
        ctx.beginPath(); ctx.arc(x, y, p.size, 0, Math.PI * 2);
        if (p.isYellow) {
          ctx.fillStyle = 'hsla(' + p.hue + ',100%,68%,' + alpha + ')';
        } else {
          ctx.fillStyle = 'rgba(235,238,248,' + (alpha * 0.7) + ')';
        }
        ctx.fill();
      });

      requestAnimationFrame(draw);
    }
    requestAnimationFrame(draw);
  },

  initScrollReveal() {
    const observer = new IntersectionObserver(entries => {
      entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('visible'); });
    }, { threshold: 0.1 });
    document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
  },

  initLandingInteractive() {
    this.initFloatingChips();
    this.initCursorGlow();
    this.initDemoChat();
    this.initStatCounters();
    this.initFeatureCardGlow();
  },

  initFloatingChips() {
    const container = document.getElementById('hero-prompts');
    if (!container) return;
    const prompts = [
      'Explícame la física cuántica', '¿Qué es un agujero negro?',
      'Resume este artículo', 'Escribe código en Python',
      'Traduce al inglés', 'Dame ideas creativas',
      'Analiza este problema', '¿Cómo funciona el universo?',
      'Optimiza mi texto', 'Explora esta hipótesis',
      'Genera un poema', 'Debug este error'
    ];
    let i = 0;
    const spawn = () => {
      const chip = document.createElement('div');
      chip.className = 'hero-prompt-chip';
      chip.textContent = prompts[i % prompts.length]; i++;
      const dur = 8 + Math.random() * 8;
      chip.style.cssText = 'left:' + (5 + Math.random() * 85) + '%;top:' + (20 + Math.random() * 65) + '%;animation-duration:' + dur + 's;animation-delay:0s;';
      container.appendChild(chip);
      setTimeout(() => chip.remove(), dur * 1000);
    };
    spawn();
    setInterval(spawn, 1800);
  },

  initCursorGlow() {
    const glow = document.getElementById('hero-glow');
    if (!glow) return;
    document.addEventListener('mousemove', e => {
      glow.style.left = e.clientX + 'px';
      glow.style.top = e.clientY + 'px';
    });
  },

  initDemoChat() {
    const container = document.getElementById('demo-messages');
    const inputEl = document.getElementById('demo-input-text');
    if (!container) return;
    const exchanges = [
      { user: '¿En qué eres mejor que otros chatbots?', ai: 'En precisión y contexto. Mantengo el hilo de la conversación, evito el relleno y voy directo a lo que necesitas. Sin rodeos.' },
      { user: 'Dame un resumen de machine learning', ai: 'El machine learning es una rama de la IA donde los modelos aprenden patrones a partir de datos sin ser programados explícitamente. Cuanto más y mejores datos, mejores predicciones.' },
      { user: 'Explícame la teoría de cuerdas en 3 líneas', ai: 'Las partículas fundamentales son cuerdas vibrantes unidimensionales. Su frecuencia de vibración determina su masa y carga. Ofrece un marco para unificar relatividad y mecánica cuántica, pero aún carece de evidencia experimental.' },
    ];
    let ei = 0;
    const typeIntoInput = (text, cb) => {
      let ci = 0;
      inputEl.textContent = '';
      const t = setInterval(() => {
        inputEl.textContent += text[ci]; ci++;
        if (ci >= text.length) { clearInterval(t); setTimeout(cb, 400); }
      }, 52);
    };
    const addMsg = (role, text, cb) => {
      const wrap = document.createElement('div');
      wrap.className = 'l-demo-msg ' + role;
      if (role === 'user') {
        const av = document.createElement('div');
        av.className = 'l-demo-av user'; av.textContent = 'V';
        wrap.appendChild(av);
      }
      const bub = document.createElement('div');
      bub.className = 'l-demo-bubble ' + role;
      bub.textContent = text;
      wrap.appendChild(bub);
      container.appendChild(wrap);
      requestAnimationFrame(() => { requestAnimationFrame(() => { wrap.classList.add('visible'); container.scrollTop = container.scrollHeight; }); });
      setTimeout(cb, 600);
    };
    const showTyping = (cb) => {
      const el = document.createElement('div');
      el.className = 'l-demo-msg ai';
      const canvasId = 'demo-bh-' + Date.now();
      el.innerHTML = `<div class="typing-bh-wrap"><canvas id="${canvasId}" class="typing-bh-canvas" width="44" height="44"></canvas></div>`;
      container.appendChild(el);
      requestAnimationFrame(() => { requestAnimationFrame(() => { el.classList.add('visible'); container.scrollTop = container.scrollHeight; }); });

      // Mini black hole animation
      const canvas = document.getElementById(canvasId);
      const ctx = canvas.getContext('2d');
      const W = 44, H = 44, cx = W / 2, cy = H / 2;
      let pts = [], lastT = null;
      for (let i = 0; i < 30; i++) {
        const a = Math.random() * Math.PI * 2, r = 8 + Math.random() * 13;
        pts.push({ a, r, baseR: r, dir: Math.random() > .5 ? 1 : -1, speed: 0.0008 + Math.random() * 0.001, size: Math.random() * 1.1 + 0.3, opacity: Math.random() * 0.8 + 0.2, isY: Math.random() < .45, hue: 55 + Math.random() * 20 });
      }
      let rafId;
      const draw = (ts) => {
        if (!lastT) lastT = ts;
        const dt = Math.min(ts - lastT, 50); lastT = ts;
        ctx.clearRect(0, 0, W, H);
        for (let i = 2; i > 0; i--) { const g = ctx.createRadialGradient(cx, cy, 3 * i, cx, cy, 11 * i + 4); g.addColorStop(0, `rgba(232,255,71,${0.05 / i})`); g.addColorStop(1, 'rgba(0,0,0,0)'); ctx.fillStyle = g; ctx.beginPath(); ctx.arc(cx, cy, 11 * i + 4, 0, Math.PI * 2); ctx.fill(); }
        const bh = ctx.createRadialGradient(cx, cy, 0, cx, cy, 7); bh.addColorStop(0, 'rgba(0,0,0,1)'); bh.addColorStop(0.8, 'rgba(0,0,0,1)'); bh.addColorStop(1, 'rgba(0,0,0,0)'); ctx.fillStyle = bh; ctx.beginPath(); ctx.arc(cx, cy, 7, 0, Math.PI * 2); ctx.fill();
        pts.forEach(p => { p.a += p.dir * p.speed * dt; p.r = p.baseR + Math.sin(p.a * 3) * 1.5; const x = cx + Math.cos(p.a) * p.r, y = cy + Math.sin(p.a) * p.r * 0.38, alpha = p.opacity * Math.max(0, 1 - (9 / p.r)); ctx.beginPath(); ctx.arc(x, y, p.size, 0, Math.PI * 2); ctx.fillStyle = p.isY ? `hsla(${p.hue},100%,68%,${alpha})` : `rgba(235,238,248,${alpha * .7})`; ctx.fill(); });
        rafId = requestAnimationFrame(draw);
      };
      rafId = requestAnimationFrame(draw);

      setTimeout(() => { cancelAnimationFrame(rafId); el.remove(); cb(); }, 1800);
    };
    const runExchange = () => {
      const ex = exchanges[ei % exchanges.length]; ei++;
      inputEl.textContent = 'Escribe tu mensaje...';
      setTimeout(() => {
        typeIntoInput(ex.user, () => {
          inputEl.textContent = 'Escribe tu mensaje...';
          addMsg('user', ex.user, () => {
            showTyping(() => {
              addMsg('ai', ex.ai, () => {
                // trim to last 6 messages
                while (container.children.length > 6) container.removeChild(container.firstChild);
                setTimeout(runExchange, 2400);
              });
            });
          });
        });
      }, 800);
    };
    setTimeout(runExchange, 1000);
  },

  initStatCounters() {
    const cells = document.querySelectorAll('[data-target]');
    // Whitelist bar — cargar dato real desde la API
    const bar        = document.querySelector('.l-whitelist-bar-fill');
    const labelSpan  = document.querySelector('.l-whitelist-bar-labels span:first-child');
    const limitSpan  = document.querySelector('.l-whitelist-bar-limit');
    const LIMIT      = 50;

    if (bar) {
      // Obtener conteo real de aprobados
      fetch(API.whitelist + '?action=count')
        .then(r => r.json())
        .then(data => {
          const count = data.data?.count ?? 0;
          const pct   = Math.min(Math.round((count / LIMIT) * 100), 100);

          // Observe the bar-wrap (visible container) instead of bar-fill (starts at width:0)
          const barWrap = bar.closest('.l-whitelist-bar-wrap') || bar.parentElement;
          const obs = new IntersectionObserver(entries => {
            if (!entries[0].isIntersecting) return;
            obs.disconnect();
            // Animar número
            if (labelSpan) {
              let cur = 0;
              const step = Math.max(1, Math.ceil(count / 40));
              const t = setInterval(() => {
                cur = Math.min(cur + step, count);
                labelSpan.textContent = cur + ' plaza' + (cur !== 1 ? 's' : '') + ' ocupada' + (cur !== 1 ? 's' : '');
                if (cur >= count) clearInterval(t);
              }, 30);
            }
            // Animar barra
            bar.style.width = (pct || 1) + '%';
          }, { threshold: 0.3 });
          obs.observe(barWrap);
        })
        .catch(() => {
          // Fallback silencioso — dejar en 0
          const barWrap = bar.closest('.l-whitelist-bar-wrap') || bar.parentElement;
          const obs = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting) { bar.style.width = '1%'; obs.disconnect(); }
          }, { threshold: 0.3 });
          obs.observe(barWrap);
        });
    }
    if (!cells.length) return;
    const observer = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (!e.isIntersecting) return;
        const el = e.target;
        observer.unobserve(el);
        const target = parseInt(el.dataset.target);
        let current = 0;
        const step = Math.ceil(target / 60);
        const t = setInterval(() => {
          current = Math.min(current + step, target);
          el.textContent = current + (target >= 100 ? '%' : 'K');
          if (current >= target) clearInterval(t);
        }, 24);
      });
    }, { threshold: 0.5 });
    cells.forEach(c => observer.observe(c));
  },

  initFeatureCardGlow() {
    document.querySelectorAll('.feature-card').forEach(card => {
      card.addEventListener('mousemove', e => {
        const r = card.getBoundingClientRect();
        card.style.setProperty('--mx', ((e.clientX - r.left) / r.width * 100) + '%');
        card.style.setProperty('--my', ((e.clientY - r.top) / r.height * 100) + '%');
      });
    });
  },

  // ==========================================
  // WHITELIST MODAL (usuario)
  // ==========================================
  openWhitelistModal() {
    const modal = document.getElementById('modal-whitelist');
    if (!modal) return;
    // Reset state
    document.getElementById('wl-request-form').style.display = '';
    document.getElementById('wl-request-result').style.display = 'none';
    document.getElementById('wl-name-input').value = '';
    document.getElementById('wl-email-input').value = '';
    document.getElementById('wl-password-input').value = '';
    modal.classList.add('active');
  },

  closeWhitelistModal() {
    document.getElementById('modal-whitelist').classList.remove('active');
  },

  async requestWhitelistAccess() {
    const name  = document.getElementById('wl-name-input').value.trim();
    const email = document.getElementById('wl-email-input').value.trim();
    const pass  = document.getElementById('wl-password-input').value;
    if (!name)  { this.showToast('⚠️ Introduce tu nombre'); return; }
    if (!email) { this.showToast('⚠️ Introduce un email'); return; }
    if (!pass || pass.length < 6) { this.showToast('⚠️ La contraseña debe tener al menos 6 caracteres'); return; }

    const btn = document.querySelector('#modal-whitelist .btn-submit');
    if (btn) btn.disabled = true;

    const res = await apiFetch(API.whitelist + '?action=request', {
      method: 'POST',
      body: JSON.stringify({ name, email, password: pass }),
    });

    if (btn) btn.disabled = false;

    const resultEl = document.getElementById('wl-request-result');
    const formEl   = document.getElementById('wl-request-form');

    if (res.ok) {
      formEl.style.display = 'none';
      resultEl.style.display = '';
      resultEl.innerHTML = `
        <div class="wl-success">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="40" height="40">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/>
          </svg>
          <h3>Solicitud enviada</h3>
          <p>Hola <strong>${name}</strong>, tu solicitud para <strong>${email}</strong> está en lista de espera. El administrador la revisará pronto.</p>
          <button class="btn-submit" onclick="app.closeWhitelistModal()" style="margin-top:1.5rem">Entendido</button>
        </div>`;
    } else {
      this.showToast('⚠️ ' + res.error);
    }
  },

  initMarquee() {
    const track = document.getElementById('marquee-track');
    if (!track) return;
    const items = ['Singularidad Artificial', 'Horizonte de Eventos', 'Densidad de Información', 'Gravedad Cognitiva', 'VOID Intelligence', 'Masa de Conocimiento'];
    let html = '';
    for (let i = 0; i < 4; i++) {
      items.forEach(item => { html += '<div class="l-marquee-item"><span class="dot"><svg style="width:10px;height:10px;color:var(--accent)"><use href="#ico-spark"/></svg></span> ' + item + '</div>'; });
    }
    track.innerHTML = html;
  }
};

document.addEventListener('DOMContentLoaded', () => app.init().catch(console.error));
