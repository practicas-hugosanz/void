# VOID — Backend PHP Setup

## Estructura de archivos

```
void/
├── index.html            ← Página principal (sin cambios de diseño)
├── style.css             ← Estilos (sin cambios)
├── script.js             ← JS con llamadas al backend PHP
├── .htaccess             ← Configuración Apache
├── api/
│   ├── auth.php          ← Registro, login, logout, /me
│   ├── user.php          ← Perfil, avatar, API key/proveedor
│   ├── conversations.php ← Historial de conversaciones
│   └── proxy.php         ← Proxy IA (Gemini / OpenAI)
├── includes/
│   ├── db.php            ← Conexión SQLite + migraciones
│   └── auth.php          ← Sesiones, helpers JSON
└── data/                 ← Creado automáticamente (void.sqlite)
```

---

## Requisitos del servidor

| Requisito | Versión mínima |
|---|---|
| PHP | 8.1+ |
| Extensiones PHP | `pdo`, `pdo_sqlite`, `curl`, `json` |
| Servidor web | Apache (con `mod_rewrite`) o Nginx |
| SQLite | 3.x (incluido con PHP por defecto) |

> **¿Tienes hosting compartido?** La mayoría (cPanel, Plesk, Hostinger, SiteGround...) incluyen todo lo necesario. Sube los archivos por FTP y listo.

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

> ⚠️ **No subas la carpeta `/data/`** — se crea automáticamente. Si ya existe, asegúrate de que el servidor tenga permisos de escritura sobre ella.

### 2. Permisos

```bash
chmod 755 api/ includes/
chmod 644 api/*.php includes/*.php
# La carpeta data/ se crea sola con permisos correctos
```

### 3. Primer acceso

Abre tu dominio en el navegador. La base de datos SQLite se crea automáticamente en la primera visita. Puedes registrarte directamente desde la interfaz.

---

## Configuración con Nginx

Si usas Nginx en lugar de Apache, añade esto a tu `server {}`:

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

## Seguridad incluida

| Característica | Detalle |
|---|---|
| Contraseñas | `password_hash()` con bcrypt — nunca en texto plano |
| Sesiones | Token aleatorio de 64 chars, cookie `HttpOnly` + `SameSite=Lax` |
| API Keys | Guardadas en base de datos, **nunca enviadas al navegador** |
| SQL Injection | Todas las queries usan PDO prepared statements |
| CSRF | SameSite cookies + credenciales explícitas |
| Datos sensibles | `/data/` y `/includes/` bloqueados vía `.htaccess` |

---

## API Endpoints

### Auth

| Método | URL | Body | Descripción |
|---|---|---|---|
| `POST` | `/api/auth.php?action=register` | `{name, email, password}` | Registro |
| `POST` | `/api/auth.php?action=login` | `{email, password}` | Login |
| `POST` | `/api/auth.php?action=logout` | — | Cerrar sesión |
| `GET`  | `/api/auth.php?action=me` | — | Usuario actual |

### Usuario

| Método | URL | Body | Descripción |
|---|---|---|---|
| `PUT` | `/api/user.php?action=profile` | `{name, email, password_current?, password_new?, password_confirm?}` | Actualizar perfil |
| `PUT` | `/api/user.php?action=avatar` | `{avatar: "data:image/..."}` | Subir avatar (base64) |
| `PUT` | `/api/user.php?action=settings` | `{api_key, api_provider}` | Guardar API Key |
| `GET` | `/api/user.php?action=settings` | — | Ver ajustes (key enmascarada) |

### Conversaciones

| Método | URL | Descripción |
|---|---|---|
| `GET` | `/api/conversations.php?action=list` | Listar conversaciones |
| `POST` | `/api/conversations.php?action=save` | Guardar/actualizar conversación |
| `DELETE` | `/api/conversations.php?action=delete&id=xxx` | Eliminar conversación |
| `POST` | `/api/conversations.php?action=clear` | Eliminar todas |

### Proxy IA

| Método | URL | Body | Descripción |
|---|---|---|---|
| `POST` | `/api/proxy.php` | `{messages: [...], provider?}` | Enviar al modelo IA |

---

## Migrar usuarios existentes (localStorage → DB)

Si tenías usuarios registrados en la versión anterior (localStorage), no se migran automáticamente. Los usuarios deberán crear una nueva cuenta. Las contraseñas antiguas no estaban hasheadas, así que es una buena oportunidad para empezar limpio.

---

## Cambiar a MySQL (opcional)

Si prefieres MySQL en lugar de SQLite, edita `includes/db.php`:

```php
// Cambia esta línea:
$pdo = new PDO('sqlite:' . DB_PATH, null, null, [...]);

// Por:
$pdo = new PDO('mysql:host=localhost;dbname=void;charset=utf8mb4', 'usuario', 'contraseña', [...]);
```

Y adapta las queries de `migrate()` para usar `AUTO_INCREMENT` en lugar de `AUTOINCREMENT` y `NOW()` en lugar de `datetime('now')`.
