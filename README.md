# VOID — Documentación técnica

VOID es una interfaz de chat con IA minimalista y de alto rendimiento, con autenticación de usuarios, sistema de whitelist, historial de conversaciones persistente y soporte para múltiples proveedores e IA y modelos.

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
│   └── proxy.php           ← Proxy IA (Gemini / OpenAI) — la key nunca sale del servidor
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

> ⚠️ **Cambia `VOID_ADMIN_SECRET` en producción.** Úsala en la cabecera `X-Admin-Secret` para aprobar/rechazar solicitudes de whitelist.

### 4. Primer acceso

Abre tu dominio. La base de datos SQLite se crea automáticamente en la primera visita. Solicita acceso desde la landing y apruébate desde la API de admin.

---

## Despliegue con Docker

El proyecto incluye un `Dockerfile` listo para Railway, Render, Fly.io u otros servicios de contenedores.

```bash
# Build local
docker build -t void .
docker run -p 8080:8080 -e VOID_ADMIN_SECRET=tu-clave-secreta void
```

El servidor escucha en el puerto `8080`. Los servicios de hosting gestionan el SSL y el dominio externamente.

---

## Sistema de Whitelist

El acceso a VOID está limitado por un sistema de whitelist. Los usuarios solicitan acceso y un administrador los aprueba manualmente.

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

## Proveedores e IA y modelos disponibles

Los usuarios configuran su proveedor, modelo y API key desde los ajustes del chat. La key se guarda cifrada en el servidor y **nunca se expone al navegador**.

### Google Gemini

| Modelo | ID | Característica |
|---|---|---|
| Gemini 2.0 Flash | `gemini-2.0-flash` | Rápido, por defecto |
| Gemini 2.0 Flash Lite | `gemini-2.0-flash-lite` | Ligero, bajo coste |
| Gemini 1.5 Pro | `gemini-1.5-pro` | Máxima capacidad |
| Gemini 1.5 Flash | `gemini-1.5-flash` | Equilibrado |

Obtén tu API key en [Google AI Studio](https://aistudio.google.com/apikey).

### OpenAI

| Modelo | ID | Característica |
|---|---|---|
| GPT-4o | `gpt-4o` | Flagship, por defecto |
| GPT-4o Mini | `gpt-4o-mini` | Rápido y económico |
| GPT-4 Turbo | `gpt-4-turbo` | Alta capacidad |
| GPT-3.5 Turbo | `gpt-3.5-turbo` | Más económico |
| o1 Mini | `o1-mini` | Razonamiento avanzado |

Obtén tu API key en [OpenAI Platform](https://platform.openai.com/api-keys).

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
| `PUT` | `settings` | `{api_key, api_provider, api_model}` | Guardar proveedor, modelo y API key |
| `GET` | `settings` | — | Ver ajustes (key enmascarada como `***`) |

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
| `POST` | `{messages: [...], provider: "openai"\|"gemini", model: "gpt-4o"\|...}` | Enviar mensajes al modelo configurado |

El proxy recupera la API key del servidor, nunca del cliente. Los mensajes siguen el formato estándar de OpenAI (`role` + `content`); el proxy convierte automáticamente al formato de Gemini si es necesario.

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
| `api_key` | TEXT | API key del proveedor (guardada en servidor) |
| `api_provider` | TEXT | `gemini` o `openai` (por defecto `gemini`) |
| `api_model` | TEXT | Modelo seleccionado (por defecto del proveedor) |
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
| `title` | TEXT | Título de la conversación |
| `messages` | TEXT | JSON con el historial completo |
| `created_at` | TEXT | Fecha de creación |
| `updated_at` | TEXT | Última modificación |

---

## Seguridad

| Característica | Implementación |
|---|---|
| Contraseñas | `password_hash()` con bcrypt — nunca en texto plano |
| Sesiones | Token de 64 chars aleatorio, cookie `HttpOnly` + `SameSite=Lax`, TTL 30 días |
| API Keys | Guardadas en BD, **nunca enviadas al navegador** — el proxy las inyecta server-side |
| SQL Injection | Todas las queries usan PDO prepared statements |
| Admin | Cabecera `X-Admin-Secret` con `hash_equals()` para evitar timing attacks |
| Datos sensibles | `/data/` e `/includes/` bloqueados vía `.htaccess` y Nginx |
| CORS | Origen dinámico con credenciales, configurable por entorno |

---

## Configuración con Nginx

Si usas Nginx en lugar de Apache, añade esto a tu bloque `server {}`:

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
