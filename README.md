# VOID — Documentación técnica

VOID es una interfaz de chat con IA minimalista y de alto rendimiento, con autenticación de usuarios, sistema de whitelist, historial de conversaciones persistente y soporte para múltiples proveedores de IA y modelos.

---

## Estructura de archivos

```
void/
├── index.html              ← Página principal (landing + chat)
├── style.css               ← Estilos globales
├── script.js               ← Lógica frontend completa
├── favicon.png             ← Icono
├── .htaccess               ← Configuración Apache (rutas, seguridad)
├── Dockerfile              ← Imagen Docker (PHP 8.2 + Apache)
├── api/
│   ├── auth.php            ← Registro, login, logout, /me
│   ├── user.php            ← Perfil, avatar, API key, proveedor, modelo
│   ├── whitelist.php       ← Solicitudes, aprobación y conteo de whitelist
│   ├── conversations.php   ← Historial de conversaciones
│   └── proxy.php           ← Proxy IA (Gemini / OpenAI / Anthropic) — la key nunca sale del servidor
├── includes/
│   ├── db.php              ← Conexión SQLite + migraciones automáticas
│   └── auth.php            ← Sesiones, helpers JSON, CORS
└── data/                   ← Creado automáticamente (void.sqlite)
```

---

## Requisitos del servidor

| Requisito | Versión mínima |
|---|---|
| PHP | 8.2+ |
| Extensiones PHP | `pdo`, `pdo_sqlite`, `curl`, `json` |
| Servidor web | Apache (con `mod_rewrite`) o Nginx |
| SQLite | 3.x (incluido con PHP por defecto) |

> **¿Hosting compartido?** La mayoría (cPanel, Plesk, Hostinger, SiteGround...) incluyen todo. Sube los archivos por FTP y funciona sin configuración adicional.

---

## Instalación

### 1. Sube los archivos

Sube **todo el contenido** de esta carpeta a la raíz de tu dominio (o a una subcarpeta).

```
public_html/
├── index.html
├── style.css
├── script.js
├── .htaccess
├── api/
└── includes/
```

> ⚠️ **No subas la carpeta `/data/`** — se crea automáticamente con los permisos correctos. Si ya existe, asegúrate de que el servidor tenga permisos de escritura sobre ella.

### 2. Permisos

```bash
chmod 755 api/ includes/
chmod 644 api/*.php includes/*.php
# La carpeta data/ se crea sola
```

### 3. Variables de entorno

| Variable | Descripción | Valor por defecto |
|---|---|---|
| `VOID_ADMIN_SECRET` | Clave para acciones de admin en la whitelist | `void-admin-2025-secret` |
| `GEMINI_API_KEY` | API key de Google Gemini (activo) | — |
| `OPENAI_API_KEY` | API key de OpenAI (próximamente) | — |
| `ANTHROPIC_API_KEY` | API key de Anthropic (próximamente) | — |

> ⚠️ **Cambia `VOID_ADMIN_SECRET` en producción.**

El proveedor activo se determina automáticamente según qué variable de entorno esté configurada, en este orden de prioridad: `GEMINI_API_KEY` → `OPENAI_API_KEY` → `ANTHROPIC_API_KEY`.

### 4. Primer acceso

Abre tu dominio. La base de datos SQLite se crea automáticamente en la primera visita. Solicita acceso desde la landing y apruébate desde la API de admin.

---

## Despliegue con Docker

```bash
docker build -t void .
docker run -p 8080:8080 \
  -e VOID_ADMIN_SECRET=tu-clave-secreta \
  -e GEMINI_API_KEY=tu-key-gemini \
  void
```

El servidor escucha en el puerto `8080`. Los servicios de hosting gestionan el SSL y el dominio externamente.

---

## Sistema de Whitelist

### Flujo

```
Usuario solicita acceso (nombre + email + contraseña)
        ↓
Queda en estado "pending"
        ↓
Admin aprueba vía API → estado "approved" + se crea cuenta en users
        ↓
Usuario puede hacer login
```

### Estados posibles

| Estado | Descripción |
|---|---|
| `pending` | Solicitud enviada, pendiente de revisión |
| `approved` | Acceso concedido, puede hacer login |
| `rejected` | Solicitud rechazada |

### Aprobar / rechazar (admin)

```bash
# Aprobar
curl -X POST https://tu-dominio.com/api/whitelist.php?action=approve \
  -H "Content-Type: application/json" \
  -H "X-Admin-Secret: tu-clave-secreta" \
  -d '{"email": "usuario@example.com"}'

# Rechazar
curl -X POST https://tu-dominio.com/api/whitelist.php?action=reject \
  -H "Content-Type: application/json" \
  -H "X-Admin-Secret: tu-clave-secreta" \
  -d '{"email": "usuario@example.com"}'

# Listar todas las solicitudes
curl https://tu-dominio.com/api/whitelist.php?action=list \
  -H "X-Admin-Secret: tu-clave-secreta"
```

El contador de la landing (`/api/whitelist.php?action=count`) es público y muestra el total de aprobados en tiempo real.

---

## Proveedores de IA y modelos disponibles

La API key se configura como variable de entorno en el servidor. **Nunca se expone al navegador.**

### Google Gemini ✅ Activo

| Modelo | ID | Característica |
|---|---|---|
| Gemini 2.5 Flash | `gemini-2.5-flash` | Recomendado, por defecto |
| Gemini 2.5 Pro | `gemini-2.5-pro` | Máxima capacidad |
| Gemini 2.0 Flash | `gemini-2.0-flash` | Rápido |
| Gemini 2.0 Flash Lite | `gemini-2.0-flash-lite` | Ligero, bajo coste |

Obtén tu API key en [Google AI Studio](https://aistudio.google.com/apikey).

Gemini incluye **fallback automático entre modelos**: si el modelo preferido devuelve error 429 (cuota agotada), el proxy reintenta automáticamente con los modelos siguientes en orden de prioridad.

### OpenAI 🔜 Próximamente

| Modelo | ID | Característica |
|---|---|---|
| GPT-4o | `gpt-4o` | Flagship, por defecto |
| GPT-4o Mini | `gpt-4o-mini` | Rápido y económico |
| GPT-4 Turbo | `gpt-4-turbo` | Alta capacidad |
| GPT-3.5 Turbo | `gpt-3.5-turbo` | Más económico |
| o1 Mini | `o1-mini` | Razonamiento avanzado |

Obtén tu API key en [OpenAI Platform](https://platform.openai.com/api-keys).

> El botón está desactivado en la UI hasta que se configure `OPENAI_API_KEY`. Ver sección **Activar OpenAI o Anthropic**.

### Anthropic 🔜 Próximamente

| Modelo | ID | Característica |
|---|---|---|
| Claude Opus 4.6 | `claude-opus-4-6` | Máxima capacidad |
| Claude Sonnet 4.6 | `claude-sonnet-4-6` | Equilibrado, por defecto |
| Claude Haiku 4.5 | `claude-haiku-4-5-20251001` | Rápido y ligero |

Obtén tu API key en [Anthropic Console](https://console.anthropic.com/).

> El botón está desactivado en la UI hasta que se configure `ANTHROPIC_API_KEY`. Ver sección **Activar OpenAI o Anthropic**.

---

## Activar OpenAI o Anthropic

Cuando tengas las API keys, sigue estos pasos:

**1. Configura la variable de entorno en tu servidor:**
```bash
# Railway / Render / Fly.io → añade en el panel de entorno:
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
```

**2. Activa el botón en `index.html`** — busca el botón correspondiente y elimina `disabled`, la clase `provider-soon`, el atributo `title` y el `<span class="soon-badge">`:

```html
<!-- Antes (desactivado) -->
<button class="provider-btn provider-soon" data-provider="openai" disabled title="Próximamente">
  <span class="provider-icon">⬡</span> OpenAI
  <span class="soon-badge">Soon</span>
</button>

<!-- Después (activo) -->
<button class="provider-btn" data-provider="openai" onclick="app.selectProvider('openai')">
  <span class="provider-icon">⬡</span> OpenAI
</button>
```

---

## API Endpoints

### Auth — `/api/auth.php`

| Método | Acción | Body | Descripción |
|---|---|---|---|
| `POST` | `register` | `{name, email, password}` | Registro (requiere estar en whitelist) |
| `POST` | `login` | `{email, password}` | Login |
| `POST` | `logout` | — | Cerrar sesión |
| `GET`  | `me` | — | Usuario autenticado actual |

### Usuario — `/api/user.php`

| Método | Acción | Body | Descripción |
|---|---|---|---|
| `PUT` | `profile` | `{name, email, password_current?, password_new?, password_confirm?}` | Actualizar perfil |
| `PUT` | `avatar` | `{avatar: "data:image/..."}` | Subir avatar en base64 (máx. 2 MB) |
| `PUT` | `settings` | `{api_provider, api_model}` | Guardar proveedor y modelo preferidos |
| `GET` | `settings` | — | Ver ajustes actuales |

### Whitelist — `/api/whitelist.php`

| Método | Acción | Auth | Body | Descripción |
|---|---|---|---|---|
| `POST` | `request` | Pública | `{name, email, password}` | Solicitar acceso |
| `GET`  | `check` | Pública | `?email=...` | Comprobar estado de un email |
| `GET`  | `count` | Pública | — | Total de usuarios aprobados |
| `GET`  | `list` | Admin | — | Listar todas las solicitudes |
| `POST` | `approve` | Admin | `{email}` | Aprobar email |
| `POST` | `reject` | Admin | `{email}` | Rechazar email |

### Conversaciones — `/api/conversations.php`

| Método | Acción | Descripción |
|---|---|---|
| `GET` | `list` | Listar conversaciones del usuario |
| `POST` | `save` | Guardar o actualizar conversación |
| `DELETE` | `delete&id=xxx` | Eliminar conversación |
| `POST` | `clear` | Eliminar todas las conversaciones |

### Proxy IA — `/api/proxy.php`

| Método | Body | Descripción |
|---|---|---|
| `POST` | `{messages, provider, model, stream?}` | Chat con el modelo configurado |
| `POST` | `{action: "title", messages, provider, model}` | Generar título para una conversación |

El proxy recupera la API key de las variables de entorno del servidor, nunca del cliente. Los mensajes siguen el formato estándar (`role` + `content`); el proxy convierte automáticamente al formato nativo de cada proveedor.

**Cabeceras de respuesta del proxy (modo streaming):**

| Cabecera | Descripción |
|---|---|
| `X-VOID-Provider` | Proveedor usado en la petición |
| `X-VOID-Model` | Modelo usado en la petición |
| `X-VOID-HasKey` | `yes` si hay key configurada en servidor |

---

## Base de datos

SQLite en `data/void.sqlite`. Esquema gestionado por migraciones automáticas en `includes/db.php` — no hace falta ejecutar nada manualmente.

### Tablas

**`users`**
| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INTEGER PK | Autoincremental |
| `name` | TEXT | Nombre del usuario |
| `email` | TEXT UNIQUE | Email (case-insensitive) |
| `password` | TEXT | Hash bcrypt |
| `avatar` | TEXT | Data URL base64 o NULL |
| `api_provider` | TEXT | Proveedor preferido (`gemini`, `openai`, `anthropic`) |
| `api_model` | TEXT | Modelo seleccionado |
| `created_at` | TEXT | Fecha de creación |

**`whitelist`**
| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INTEGER PK | Autoincremental |
| `name` | TEXT | Nombre del solicitante |
| `email` | TEXT UNIQUE | Email |
| `password_hash` | TEXT | Hash de la contraseña solicitada |
| `status` | TEXT | `pending`, `approved`, `rejected` |
| `requested_at` | TEXT | Fecha de solicitud |
| `reviewed_at` | TEXT | Fecha de revisión |

**`sessions`**
| Campo | Tipo | Descripción |
|---|---|---|
| `token` | TEXT PK | Token aleatorio de 64 chars |
| `user_id` | INTEGER FK | Referencia a `users.id` |
| `created_at` | TEXT | Fecha de creación |
| `last_seen` | TEXT | Última actividad (renueva cada hora) |

**`conversations`**
| Campo | Tipo | Descripción |
|---|---|---|
| `id` | TEXT PK | UUID generado en cliente |
| `user_id` | INTEGER FK | Referencia a `users.id` |
| `title` | TEXT | Título generado automáticamente |
| `messages` | TEXT | JSON con el historial completo |
| `created_at` | TEXT | Fecha de creación |
| `updated_at` | TEXT | Última modificación |

---

## Seguridad

| Característica | Implementación |
|---|---|
| Contraseñas | `password_hash()` con bcrypt — nunca en texto plano |
| Sesiones | Token de 64 chars aleatorio, cookie `HttpOnly` + `SameSite=Lax`, TTL 30 días |
| API Keys | Guardadas como variables de entorno del servidor, **nunca enviadas al navegador** |
| SQL Injection | Todas las queries usan PDO prepared statements |
| Admin | Cabecera `X-Admin-Secret` con `hash_equals()` para evitar timing attacks |
| Datos sensibles | `/data/` e `/includes/` bloqueados vía `.htaccess` y Nginx |
| CORS | Origen dinámico con credenciales, configurable por entorno |

---

## Compatibilidad móvil

VOID está optimizado para móvil en todos los breakpoints:

| Breakpoint | Comportamiento |
|---|---|
| `> 900px` | Layout completo con sidebar colapsable |
| `≤ 900px` | Sidebar deslizante con backdrop, topbar adaptado |
| `≤ 600px` | Layout compacto, modales en bottom sheet, botones táctiles |
| `≤ 390px` | Ajustes adicionales para iPhone SE y pantallas pequeñas |

**Soporte iOS específico:**
- `viewport-fit=cover` + `env(safe-area-inset-*)` para respetar el notch y la barra home
- `height: 100dvh` en el chat para evitar que la barra de Safari corte el fondo
- `height: 100svh` como fallback cuando la barra del navegador está visible
- `font-size: max(0.92rem, 16px)` en el textarea para evitar el zoom automático al hacer foco

**Topbar del chat:**
- `gap` garantizado entre el selector de modelo y los botones de acción
- `.chat-model-selector` se recorta con ellipsis si la pantalla es muy estrecha
- `.model-dot` tiene dimensiones fijas para no deformarse en ningún tamaño de pantalla

---

## Configuración con Nginx

```nginx
location /api/ {
    try_files $uri $uri/ =404;
}

location /data/ {
    deny all;
    return 404;
}

location /includes/ {
    deny all;
    return 404;
}
```

---

## Migrar a MySQL (opcional)

Edita `includes/db.php` para cambiar el DSN:

```php
// SQLite (por defecto):
$pdo = new PDO('sqlite:' . DB_PATH, null, null, [...]);

// MySQL:
$pdo = new PDO('mysql:host=localhost;dbname=void;charset=utf8mb4', 'usuario', 'contraseña', [...]);
```

Adapta las queries de `migrate()`:
- `AUTOINCREMENT` → `AUTO_INCREMENT`
- `datetime('now')` → `NOW()`
- `INTEGER PRIMARY KEY AUTOINCREMENT` → `INT PRIMARY KEY AUTO_INCREMENT`
