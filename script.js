/**
 * VOID - Core Application Logic
 * Backend: PHP + SQLite (api/)
 * Features: Multi-conversation history, sidebar collapse, Gemini + OpenAI support.
 */

// ── API base path — adjust if your PHP files live in a subdirectory ──────────
const API_BASE = 'https://void-production-32d7.up.railway.app';

const API = {
  auth:      API_BASE + '/api/auth.php',
  user:      API_BASE + '/api/user.php',
  convs:     API_BASE + '/api/conversations.php',
  proxy:     API_BASE + '/api/proxy.php',
  whitelist: API_BASE + '/api/whitelist.php',
};

// Admin secret — must match VOID_ADMIN_SECRET env var on the server
// IMPORTANT: Change this to a strong secret and never commit it to a public repo
const ADMIN_SECRET = 'void-admin-2025-secret';

// ─── Available models per provider ───────────────────────────────────────────
const MODELS = {
  gemini: [
    { id: 'gemini-2.0-flash',         label: 'Gemini 2.0 Flash',       tag: 'rápido' },
    { id: 'gemini-2.0-flash-lite',    label: 'Gemini 2.0 Flash Lite',  tag: 'ligero' },
    { id: 'gemini-1.5-pro',           label: 'Gemini 1.5 Pro',         tag: 'potente' },
    { id: 'gemini-1.5-flash',         label: 'Gemini 1.5 Flash',       tag: 'equilibrado' },
  ],
  openai: [
    { id: 'gpt-4o',                   label: 'GPT-4o',                 tag: 'flagship' },
    { id: 'gpt-4o-mini',              label: 'GPT-4o Mini',            tag: 'rápido' },
    { id: 'gpt-4-turbo',              label: 'GPT-4 Turbo',            tag: 'potente' },
    { id: 'gpt-3.5-turbo',           label: 'GPT-3.5 Turbo',          tag: 'económico' },
    { id: 'o1-mini',                  label: 'o1 Mini',                tag: 'razonamiento' },
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
  apiKey: '',            // Only used client-side for direct-mode (proxy mode stores on server)
  apiProvider: 'gemini', // 'gemini' | 'openai'
  apiModel: '',          // specific model, e.g. 'gpt-4o', 'gemini-2.0-flash'
  useProxy: true,        // true = API key stored server-side; false = direct from browser
  isTyping: false,
  sidebarCollapsed: false,

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
    if (!res.ok) return; // not authenticated

    this.currentUser = res.data;
    this.apiProvider = res.data.api_provider || 'gemini';
    this.apiModel = res.data.api_model || defaultModel(this.apiProvider);
    // has_key: server has an API key stored for this user
    this.useProxy = !!res.data.api_key; // api_key comes back as '***' if set

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
  },

  async syncCurrentConv() {
    if (!this.activeConvId || this.chatHistory.length === 0) return;
    const idx = this.conversations.findIndex(c => c.id === this.activeConvId);
    if (idx === -1) return;
    this.conversations[idx].messages = this.chatHistory;
    const firstUser = this.chatHistory.find(m => m.role === 'user');
    if (firstUser) {
      this.conversations[idx].title = firstUser.content.slice(0, 40) + (firstUser.content.length > 40 ? '…' : '');
    }
    if (this.currentUser) localStorage.setItem('void_active_' + this.currentUser.email, this.activeConvId);
    const conv = this.conversations[idx];
    apiFetch(API.convs + '?action=save', {
      method: 'POST',
      body: JSON.stringify({ id: conv.id, title: conv.title, messages: conv.messages }),
    });
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
    document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.form-panel').forEach(p => p.classList.remove('active'));
    const tab = document.getElementById('tab-' + mode);
    if (tab) tab.classList.add('active');
    const form = document.getElementById('form-' + mode);
    if (form) form.classList.add('active');
    else {
      const fallback = document.getElementById('form-login');
      if (fallback) fallback.classList.add('active');
    }
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
    // Strip legacy emojis
    const cleanMsg = msg.replace(/[⚠️✦]/g, '').trim();
    t.innerHTML = `<svg style="width:15px;height:15px;flex-shrink:0;color:${color}"><use href="#${icon}"/></svg><span>${cleanMsg}</span>`;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
  },

  // ==========================================
  // AUTHENTICATION
  // ==========================================
  async handleRegister(e) {
    e.preventDefault();
    const name = document.getElementById('reg-name').value.trim();
    const email = document.getElementById('reg-email').value.trim();
    const pass = document.getElementById('reg-password').value;
    const btn = e.target.querySelector('[type=submit]');
    if (btn) btn.disabled = true;
    const res = await apiFetch(API.auth + '?action=register', {
      method: 'POST',
      body: JSON.stringify({ name, email, password: pass }),
    });
    if (btn) btn.disabled = false;
    if (!res.ok) { this.showToast('⚠️ ' + res.error); return; }
    await this._afterLogin(res.data);
  },

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
    this.useProxy = !!data.user.api_key; // '***' means key is set server-side
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
  window.location.href = 'https://void-production-32d7.up.railway.app/api/oauth_google.php?action=redirect';
},

  loginWithGithub() {
  window.location.href = 'https://void-production-32d7.up.railway.app/api/oauth_github.php?action=redirect';
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
    if (welcomeH2) welcomeH2.innerHTML = 'Hola, ' + newName.split(' ')[0] + '<br>\xc2\xbfPor d\xc3\xb3nde empezamos?';
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
      const firstName = this.currentUser ? this.currentUser.name.split(' ')[0] : '';
      inner.innerHTML = `<div id="chat-welcome" class="chat-welcome"><div class="chat-welcome-icon"><span class="chat-welcome-logo">VOID</span></div><h2>Hola, ${firstName}<br>¿Por dónde empezamos?</h2><p>Cuéntame qué tienes en mente — un problema, una idea, una pregunta.<br>Estoy aquí para ayudarte a pensar con claridad.</p></div>`;
    } else {
      this.chatHistory.forEach(msg => this.appendMessageUI(msg.role, msg.content));
      this.scrollToBottom();
    }
    this.updateSidebarHistory();
  },

  async clearChat() {
    // Delete all conversations on server
    apiFetch(API.convs + '?action=clear', { method: 'POST' });
    this.conversations = [];
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

  async sendMessage() {
    if (this.isTyping) return;
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    const hasFiles = this.pendingFiles.length > 0;
    if (!text && !hasFiles) return;

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

    // Store in history — content includes file context for AI, display is clean
    const fileContext = this.buildFileContextFrom(attachedFiles);
    const fullContent = text + fileContext;
    this.chatHistory.push({ role: 'user', content: fullContent });
    this.appendMessageWithFiles('user', text || '📎 Archivos adjuntos', attachedFiles);
    await this.syncCurrentConv();
    this.updateSidebarHistory();

    this.isTyping = true;
    document.getElementById('chat-send').disabled = true;
    const typingId = this.addTypingIndicator();

    let responseText = '';
    if (this.useProxy) {
      // Server-side proxy: API key stored securely in DB, never exposed to browser
      responseText = await this.fetchViaProxy(text, attachedFiles);
    } else if (this.apiKey && this.apiProvider === 'gemini') {
      responseText = await this.fetchGeminiAI(text, attachedFiles);
    } else if (this.apiKey && this.apiProvider === 'openai') {
      responseText = await this.fetchOpenAI(text, attachedFiles);
    } else {
      responseText = await this.fetchMockAI(text);
    }

    this.removeTypingIndicator(typingId);
    this.chatHistory.push({ role: 'assistant', content: responseText });
    this.appendMessageUI('ai', responseText);
    await this.syncCurrentConv();

    this.isTyping = false;
    document.getElementById('chat-send').disabled = false;
    input.focus();
  },

  appendMessageUI(role, text) {
    const inner = document.getElementById('chat-messages-inner');
    const isAI = role === 'ai' || role === 'assistant';
    const uiRole = isAI ? 'ai' : 'user';
    const msg = document.createElement('div');
    msg.className = 'msg ' + uiRole;
    const avatar = isAI ? 'V' : (this.currentUser ? this.currentUser.name.slice(0, 1).toUpperCase() : 'U');
    const name = isAI ? 'VOID' : 'Tú';
    const safeText = isAI ? text.replace(/\n/g, '<br>') : this.escapeHtml(text).replace(/\n/g, '<br>');
    msg.innerHTML = '<div class="msg-avatar ' + uiRole + '">' + avatar + '</div><div class="msg-content"><div class="msg-name">' + name + '</div><div class="msg-bubble ' + uiRole + '">' + safeText + '</div></div>';
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

  getFileIcon(name) {
    const ext = name.split('.').pop().toLowerCase();
    const map = { pdf: '📄', jpg: '🖼️', jpeg: '🖼️', png: '🖼️', gif: '🖼️', webp: '🖼️', csv: '📊', json: '📋', js: '💻', ts: '💻', py: '🐍', html: '🌐', css: '🎨', md: '📝', txt: '📄', docx: '📝', xml: '📋', yaml: '⚙️', yml: '⚙️' };
    return map[ext] || '📎';
  },

  formatSize(bytes) {
    if (bytes < 1024) return bytes + 'B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + 'KB';
    return (bytes / (1024 * 1024)).toFixed(1) + 'MB';
  },

  async handleFiles(fileList) {
    const MAX = 5, MAX_MB = 10;
    const files = Array.from(fileList).slice(0, MAX - this.pendingFiles.length);
    for (const file of files) {
      if (file.size > MAX_MB * 1024 * 1024) { this.showToast('⚠️ ' + file.name + ' supera los 10MB'); continue; }
      const isImage = file.type.startsWith('image/');
      const fileData = await this.readFile(file, isImage);
      this.pendingFiles.push({ name: file.name, size: file.size, type: file.type, mimeType: file.type, isImage, ...fileData });
    }
    this.renderAttachmentPreviews();
    // Reset input so same file can be re-added
    document.getElementById('file-input').value = '';
  },

  readFile(file, isImage) {
    return new Promise((resolve) => {
      const reader = new FileReader();
      if (isImage) {
        reader.onload = e => {
          const base64 = e.target.result.split(',')[1];
          resolve({ base64, content: null });
        };
        reader.readAsDataURL(file);
      } else {
        // For binary like DOCX, try text; fallback gracefully
        reader.onload = e => resolve({ content: e.target.result, base64: null });
        reader.onerror = () => resolve({ content: '[No se pudo leer el archivo]', base64: null });
        reader.readAsText(file, 'UTF-8');
      }
    });
  },

  renderAttachmentPreviews() {
    const container = document.getElementById('chat-attachments');
    container.innerHTML = '';
    this.pendingFiles.forEach((f, i) => {
      const chip = document.createElement('div');
      chip.className = 'attach-chip' + (f.isImage ? ' is-image' : '');
      if (f.isImage && f.base64) {
        chip.innerHTML = `<img class="attach-chip-thumb" src="data:${f.mimeType};base64,${f.base64}"><span class="attach-chip-name">${this.escapeHtml(f.name)}</span><span class="attach-chip-size">${this.formatSize(f.size)}</span><button class="attach-chip-remove" onclick="app.removeAttachment(${i})"><svg style="width:12px;height:12px"><use href="#ico-close"/></svg></button>`;
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

  buildFileContext() {
    // Builds a text block injected into the prompt describing attached files
    if (!this.pendingFiles.length) return '';
    let ctx = '\n\n[ARCHIVOS ADJUNTOS]\n';
    this.pendingFiles.forEach(f => {
      if (f.isImage) {
        ctx += `• ${f.name} (imagen)\n`;
      } else {
        const preview = f.content ? f.content.slice(0, 8000) : '';
        ctx += `• ${f.name}:\n\`\`\`\n${preview}${f.content && f.content.length > 8000 ? '\n...[truncado]' : ''}\n\`\`\`\n`;
      }
    });
    return ctx;
  },

  appendMessageWithFiles(role, text, files) {
    const inner = document.getElementById('chat-messages-inner');
    const isAI = role === 'ai' || role === 'assistant';
    const uiRole = isAI ? 'ai' : 'user';
    const msg = document.createElement('div');
    msg.className = 'msg ' + uiRole;
    const avatar = isAI ? 'V' : (this.currentUser ? this.currentUser.name.slice(0, 1).toUpperCase() : 'U');
    const name = isAI ? 'VOID' : 'Tú';

    let attachHtml = '';
    if (files && files.length) {
      attachHtml = '<div class="msg-attachments">' + files.map(f =>
        `<span class="msg-attach-badge">${this.getFileIcon(f.name)} ${this.escapeHtml(f.name)}</span>`
      ).join('') + '</div>';
    }

    const safeText = isAI ? text.replace(/\n/g, '<br>') : this.escapeHtml(text).replace(/\n/g, '<br>');
    msg.innerHTML = `<div class="msg-avatar ${uiRole}">${avatar}</div><div class="msg-content"><div class="msg-name">${name}</div><div class="msg-bubble ${uiRole}">${attachHtml}${safeText}</div></div>`;
    inner.appendChild(msg);
    this.scrollToBottom();
  },



  escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  },

  // ==========================================
  // AI ENGINES
  // ==========================================
  // ==========================================
  // SERVER-SIDE AI PROXY (API key stored in DB)
  // ==========================================
  async fetchViaProxy(text, files = []) {
    const SYSTEM = 'Eres VOID, una IA minimalista, precisa y profunda. Tu nombre evoca el vacío — como un agujero negro, absorbes las preguntas y devuelves respuestas densas y compactas. Cuando el usuario adjunte archivos, analízalos en detalle y responde sobre su contenido. Usa ocasionalmente el símbolo ✦ al final de respuestas importantes.';

    // Build messages array in OpenAI format (proxy handles Gemini conversion)
    const history = this.chatHistory.slice(-10).map(m => ({
      role: m.role === 'assistant' ? 'assistant' : 'user',
      content: m.content,
    }));

    // Add file content to the latest user message
    const lastMsg = history[history.length - 1];
    if (lastMsg && files && files.length) {
      let extra = '\n\n[ARCHIVOS ADJUNTOS]\n';
      files.forEach(f => {
        if (!f.isImage) {
          extra += `\n• ${f.name}:\n\`\`\`\n${(f.content || '').slice(0, 6000)}\n\`\`\`\n`;
        } else {
          extra += `\n• Imagen adjunta: ${f.name}\n`;
        }
      });
      lastMsg.content += extra;
    }

    const messages = [{ role: 'system', content: SYSTEM }, ...history];

    try {
      const res = await apiFetch(API.proxy, {
        method: 'POST',
        body: JSON.stringify({ messages, provider: this.apiProvider, model: this.apiModel || defaultModel(this.apiProvider) }),
      });
      if (!res.ok) return '⚠️ ' + (res.error || 'Error del servidor') + ' ✦';
      return res.data.text || 'Sin respuesta del núcleo. ✦';
    } catch (err) {
      console.error(err);
      return '⚠️ Error de conexión con el servidor. ✦';
    }
  },

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
      if (!f.isImage) {
        const preview = f.content ? f.content.slice(0, 8000) : '';
        ctx += `\n• Archivo: ${f.name}\n\`\`\`\n${preview}${f.content && f.content.length > 8000 ? '\n...[truncado a 8000 chars]' : ''}\n\`\`\`\n`;
      } else {
        ctx += `\n• Imagen adjunta: ${f.name}\n`;
      }
    });
    return ctx;
  },

  async fetchGeminiAI(text, files = []) {
    const SYSTEM = 'Eres VOID, una IA minimalista, precisa y profunda. Tu nombre evoca el vacío — como un agujero negro, absorbes las preguntas y devuelves respuestas densas y compactas. Cuando el usuario adjunte archivos, analízalos en detalle y responde sobre su contenido. Usa ocasionalmente el símbolo ✦ al final de respuestas importantes.';
    try {
      const history = this.chatHistory.slice(-10, -1).map(m => ({
        role: m.role === 'assistant' ? 'model' : 'user',
        parts: [{ text: m.content }]
      }));

      // Build parts for current message — text + images natively, text files injected as text
      const parts = [];
      if (text) parts.push({ text });

      files.forEach(f => {
        if (f.isImage && f.base64) {
          parts.push({ inlineData: { mimeType: f.mimeType, data: f.base64 } });
        } else if (f.content) {
          parts.push({ text: `\n[Contenido de ${f.name}]:\n${f.content.slice(0, 8000)}` });
        }
      });

      if (!parts.length) parts.push({ text: '(Sin texto — analiza los archivos adjuntos)' });

      const body = {
        system_instruction: { parts: [{ text: SYSTEM }] },
        contents: [...history, { role: 'user', parts }],
        generationConfig: { temperature: 0.9, maxOutputTokens: 2048 }
      };
      const res = await fetch(
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' + this.apiKey,
        { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
      );
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        const msg = err.error && err.error.message ? err.error.message : 'HTTP ' + res.status;
        if (res.status === 429 || msg.toLowerCase().includes('quota') || msg.toLowerCase().includes('rate')) {
          return '⚠️ Límite de uso alcanzado en Gemini. Espera unos minutos o activa facturación en <a href="https://aistudio.google.com" target="_blank">Google AI Studio</a>. ✦';
        }
        return '⚠️ Error de Gemini: ' + msg + '. Verifica tu API Key. ✦';
      }
      const data = await res.json();
      return (data.candidates?.[0]?.content?.parts?.[0]?.text) || 'Sin respuesta del núcleo. ✦';
    } catch (err) {
      console.error(err);
      return '⚠️ Error de conexión con Gemini. Verifica tu API Key en ajustes. ✦';
    }
  },

  async fetchOpenAI(text, files = []) {
    const SYSTEM = 'Eres VOID, una IA minimalista, precisa y profunda. Tu nombre evoca el vacío — como un agujero negro, absorbes las preguntas y devuelves respuestas densas y compactas. Cuando el usuario adjunte archivos, analízalos en detalle. Usa ocasionalmente el símbolo ✦ al final de respuestas.';
    try {
      const history = this.chatHistory.slice(-10).map(m => ({ role: m.role === 'assistant' ? 'assistant' : 'user', content: m.content }));

      // Build content array for GPT-4o multimodal
      const content = [];
      if (text) content.push({ type: 'text', text });
      files.forEach(f => {
        if (f.isImage && f.base64) {
          content.push({ type: 'image_url', image_url: { url: `data:${f.mimeType};base64,${f.base64}`, detail: 'auto' } });
        } else if (f.content) {
          content.push({ type: 'text', text: `\n[Contenido de ${f.name}]:\n${f.content.slice(0, 8000)}` });
        }
      });
      if (!content.length) content.push({ type: 'text', text: '(Sin texto — analiza los archivos adjuntos)' });

      // Replace last history entry with multimodal content if there are files
      const messages = [{ role: 'system', content: SYSTEM }, ...history.slice(0, -1)];
      messages.push({ role: 'user', content: files.length ? content : (text || '') });

      const res = await fetch('https://api.openai.com/v1/chat/completions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + this.apiKey },
        body: JSON.stringify({ model: 'gpt-4o-mini', messages })
      });
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        return '⚠️ Error de OpenAI: ' + (err.error?.message || 'HTTP ' + res.status) + '. ✦';
      }
      const data = await res.json();
      return data.choices[0].message.content;
    } catch (err) {
      console.error(err);
      return '⚠️ Error de conexión con OpenAI. Verifica tu API Key en ajustes. ✦';
    }
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
    const label = document.getElementById('apikey-label');
    const hint = document.getElementById('apikey-hint');
    const input = document.getElementById('settings-apikey');
    if (provider === 'gemini') {
      label.textContent = 'Google Gemini API Key';
      input.placeholder = 'AIza...';
      hint.innerHTML = 'Obtén tu clave en <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>';
    } else {
      label.textContent = 'OpenAI API Key';
      input.placeholder = 'sk-...';
      hint.innerHTML = 'Obtén tu clave en <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>';
    }
    // Clear input when switching so each provider feels independent
    input.value = '';
    // Reset temp model and re-render model selector for new provider
    this._tempModel = defaultModel(provider);
    this.renderModelSelector(provider, this._tempModel);
  },

  openSettings() {
    this._tempProvider = this.apiProvider;
    document.querySelectorAll('.provider-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.provider === this.apiProvider);
    });
    const label = document.getElementById('apikey-label');
    const hint = document.getElementById('apikey-hint');
    const input = document.getElementById('settings-apikey');
    if (this.apiProvider === 'gemini') {
      label.textContent = 'Google Gemini API Key';
      input.placeholder = 'AIza...';
      hint.innerHTML = 'Obtén tu clave en <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>';
    } else {
      label.textContent = 'OpenAI API Key';
      input.placeholder = 'sk-...';
      hint.innerHTML = 'Obtén tu clave en <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>';
    }
    // Don't pre-fill the key field (server stores it, never expose raw to client)
    input.value = '';
    input.placeholder = this.useProxy ? 'Clave guardada en servidor — introduce una nueva para cambiarla' : (this.apiProvider === 'gemini' ? 'AIza...' : 'sk-...');
    // Render model selector
    this._tempModel = this.apiModel || defaultModel(this.apiProvider);
    this.renderModelSelector(this.apiProvider, this._tempModel);
    document.getElementById('modal-settings').classList.add('active');
    this.updateModelStatus();
  },

  closeSettings() {
    // Revert any unsaved provider change
    this._tempProvider = null;
    document.getElementById('modal-settings').classList.remove('active');
  },

  async saveSettings() {
    const key = document.getElementById('settings-apikey').value.trim();
    const provider = this._tempProvider || this.apiProvider;
    const model = this._tempModel || this.apiModel || defaultModel(provider);
    this._tempProvider = null;
    this._tempModel = null;
    const res = await apiFetch(API.user + '?action=settings', {
      method: 'PUT',
      body: JSON.stringify({ api_key: key, api_provider: provider, api_model: model }),
    });
    if (!res.ok) { this.showToast('\u26a0\ufe0f ' + res.error); return; }
    this.apiProvider = provider;
    this.apiModel = model;
    this.useProxy = !!key; // if key saved, use server proxy
    this.apiKey = key;     // also keep locally for direct mode fallback
    this.updateModelStatus();
    this.closeSettings();
    this.showToast('Ajustes guardados \u2756');
  },

  updateModelStatus() {
    const dot = document.getElementById('model-status-dot');
    const text = document.getElementById('model-status-text');
    const badge = document.querySelector('.status-badge');
    const isConnected = this.useProxy || this.apiKey;
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
    const ring = document.getElementById('cursor-ring');
    document.addEventListener('mousemove', e => {
      cursor.style.left = e.clientX + 'px'; cursor.style.top = e.clientY + 'px';
      ring.style.left = e.clientX + 'px'; ring.style.top = e.clientY + 'px';
    });
    document.addEventListener('mousedown', () => { cursor.style.width = '6px'; cursor.style.height = '6px'; ring.style.width = '44px'; ring.style.height = '44px'; });
    document.addEventListener('mouseup', () => { cursor.style.width = '10px'; cursor.style.height = '10px'; ring.style.width = '32px'; ring.style.height = '32px'; });
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
    this.initAdminHotkey();
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
    const cells = document.querySelectorAll('[data-count]');
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
        const target = parseInt(el.dataset.target);
        const suffix = el.textContent.replace(/[0-9]/g, '');
        let current = 0;
        const step = Math.ceil(target / 60);
        const t = setInterval(() => {
          current = Math.min(current + step, target);
          el.textContent = current + (target >= 100 ? '%' : 'K');
          if (current >= target) clearInterval(t);
        }, 24);
        observer.unobserve(el);
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

  // ==========================================
  // ADMIN PANEL (Ctrl + Shift + Alt + A)
  // ==========================================
  openAdminModal() {
    const modal = document.getElementById('modal-admin');
    if (!modal) return;
    modal.classList.add('active');
    this.loadAdminWhitelist();
  },

  closeAdminModal() {
    document.getElementById('modal-admin').classList.remove('active');
  },

  async loadAdminWhitelist() {
    const listEl = document.getElementById('admin-wl-list');
    listEl.innerHTML = '<p class="admin-loading">Cargando…</p>';

    const res = await apiFetch(API.whitelist + '?action=list', {
      headers: { 'X-Admin-Secret': ADMIN_SECRET },
    });

    if (!res.ok) {
      listEl.innerHTML = '<p class="admin-error">⚠️ ' + res.error + '</p>';
      return;
    }

    const rows = res.data;
    if (!rows.length) {
      listEl.innerHTML = '<p class="admin-empty">No hay solicitudes aún.</p>';
      return;
    }

    const statusLabel = {
      approved: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg> Aprobado`,
      pending:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pendiente`,
      rejected: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;flex-shrink:0"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Rechazado`,
    };
    const statusClass = { approved: 'status-approved', pending: 'status-pending', rejected: 'status-rejected' };

    listEl.innerHTML = `
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Email</th>
              <th>Estado</th>
              <th>Solicitado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            ${rows.map(r => `
              <tr id="admin-row-${r.id}">
                <td class="admin-name">${r.name || '—'}</td>
                <td class="admin-email">${r.email}</td>
                <td><span class="admin-status ${statusClass[r.status] || ''}">${statusLabel[r.status] || r.status}</span></td>
                <td class="admin-date">${r.requested_at.slice(0,16)}</td>
                <td class="admin-actions">
                  ${r.status !== 'approved' ? `<button class="btn-admin-approve" onclick="app.adminApprove('${r.email}', ${r.id})">Aprobar</button>` : ''}
                  ${r.status !== 'rejected' ? `<button class="btn-admin-reject" onclick="app.adminReject('${r.email}', ${r.id})">Rechazar</button>` : ''}
                </td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
  },

  async adminApprove(email, rowId) {
    const res = await apiFetch(API.whitelist + '?action=approve', {
      method: 'POST',
      headers: { 'X-Admin-Secret': ADMIN_SECRET },
      body: JSON.stringify({ email }),
    });
    if (res.ok) { this.showToast('✅ ' + email + ' aprobado'); this.loadAdminWhitelist(); }
    else this.showToast('⚠️ ' + res.error);
  },

  async adminReject(email, rowId) {
    const res = await apiFetch(API.whitelist + '?action=reject', {
      method: 'POST',
      headers: { 'X-Admin-Secret': ADMIN_SECRET },
      body: JSON.stringify({ email }),
    });
    if (res.ok) { this.showToast('❌ ' + email + ' rechazado'); this.loadAdminWhitelist(); }
    else this.showToast('⚠️ ' + res.error);
  },

  initAdminHotkey() {
    // Combinación secreta: Ctrl + Shift + Alt + A
    document.addEventListener('keydown', (e) => {
      if (e.ctrlKey && e.shiftKey && e.altKey && e.key === 'A') {
        e.preventDefault();
        this.openAdminModal();
      }
    });
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
