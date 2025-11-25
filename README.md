# 📦 EnvSecured — Encrypted Configuration Manager for PHP

[EnvSecured](https://github.com/hegelmax/php-env-secured) is a lightweight, secure, and self-contained PHP module for storing sensitive configuration values (API keys, database credentials, tokens) in an encrypted form.

It provides:

- 🔒 **Encrypted config file** (`config.enc`)
- 🌐 **Browser-based UI** for editing settings
- 📤 **JSON export** (download)
- 📥 **JSON import** (load file into form)
- 🔑 **Automatic key generation**
- 🧩 **Zero global functions** — everything wrapped in PHP classes
- 🚀 **Drop-in integration** into any project

---

# 🗂️ Project Structure

```
env_secured/
├── _init.php                    → Bootloader (entry point)
├── libs/
│   ├── cls.EnvSecured.php       → Main config manager
│   ├── cls.EnvSecuredCrypto.php → Encryption engine
│   └── html/
│       ├── page_form.php        → UI template: config editor
│       ├── page_success.php     → UI template: success page
│       └── page_error.php       → UI template: error page
├── configs/                     → Encrypted config files (auto-created)
│   └── config.enc               → Main encrypted config (auto-created)
└── keys/                        → Key files (auto-created)
    ├── sodium.key               → Internal crypto key
    └── secret.key               → Master secret key
```

Both `configs/` and `keys/` directories are created automatically on first use if they do not exist.

---

# 🚀 Quick Start

## 1. Include the EnvSecured module

Place the `env_secured/` directory anywhere inside your project and add:

```php
require_once __DIR__ . '/env_secured/_init.php';
```

## 2. First run — create encrypted config

Open in browser:

```
https://your-site.com/env_secured/_init.php
```

You will see a UI for entering configuration variables.

Click:

- **Save (encrypted)** — creates or updates `configs/config.enc`
- **Download JSON** — exports settings for migration
- **Load JSON** — imports exported config into the form (in browser only)

---

# 🔒 Encryption Model

EnvSecured uses:

- `secret.key` — auto-generated 256-bit master key
- `sodium.key` — additional internal key
- A server fingerprint (host + project path)
- `sodium_crypto_secretbox()` with XSalsa20-Poly1305
- Auto-generated nonce per message
- Base64-encoded cipher structure

Conceptually:

```
fingerprint = HASH( hostname | projectRoot | secret.key )
finalKey    = HASH( fingerprint | sodium.key )
cipher      = base64( nonce | secretbox(plaintext, nonce, finalKey) )
```

---

# 🛡️ Why It's Safe

- Keys stored outside web root (in `env_secured/keys/`)
- Config stored encrypted (`env_secured/configs/config.enc`)
- No plaintext config on server
- No global functions → no name collisions
- Atomic writes for safe file operations
- Encryption relies on libsodium (modern & secure)

---

# 💻 JSON Import / Export

EnvSecured supports configuration migration between environments:

### Export (Download JSON)

Downloads a readable `.json` file containing all config values.

### Import (Load JSON)

Loads a `.json` file directly in the browser and fills the config form.

> No data is sent to the server until **Save (encrypted)** is pressed.

---

# 🧩 Using Config in Your Application

After initialization:

```php
require_once __DIR__ . '/env_secured/_init.php';

// Full array
$env = $GLOBALS['SRVENV'];

// Direct access
echo $env['DB_HOST'];
echo $env['API_KEY'];

// Or via helper
echo EnvSecured::get('DB_HOST');
```

---

# 🔧 Requirements

- PHP 8.1+
- `ext-sodium` enabled
- Writable `env_secured/configs/` and `env_secured/keys/` directories

---

# 📤 Migrating Between Servers

1. On old server → open UI → **Download JSON**
2. Transfer the downloaded file to the new server
3. On new server → open UI → **Load JSON**
4. Click **Save (encrypted)**

A new encrypted config is generated automatically for the new environment; secret keys remain private.

---

# 🧪 Self-Test (Optional)

Temporary snippet:

```php
require_once __DIR__ . '/env_secured/_init.php';

$cipher = (new EnvSecuredCrypto(__DIR__ . '/env_secured'))->encrypt("test");
var_dump($cipher);
```

Then ensure:

```php
(new EnvSecuredCrypto(__DIR__ . '/env_secured'))->decrypt($cipher) === "test";
```

---

# 📄 License

MIT License. Free for commercial use.

---

© 2025 EnvSecured Module
