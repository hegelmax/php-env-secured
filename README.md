# 📦 EnvSecured — Encrypted Configuration Manager for PHP

[EnvSecured](https://github.com/hegelmax/php-env-secured) is a lightweight, secure, and self-contained PHP module for storing sensitive configuration values (API keys, database credentials, tokens, secrets) in an **encrypted file** and provides a clean interface to access them in runtime.

---

# ⭐ Key Features

- 🔒 **EnvSecured Studio vault** (`config.envs`) for new configs
- 🔁 Legacy encrypted config file (`config.enc`) remains supported
- 🌐 **Browser-based UI** for editing settings
- 📤 **JSON export** (download)
- 📥 **JSON import** (load file into form)
- 🔑 **Automatic key generation** (`keys/*.key`)
- 🧬 **Server-bound encryption** (fingerprint-based)
- 🧩 **Zero global functions** — everything wrapped in PHP classes
- 🚀 **Drop-in integration** into any project
- ⚙️ Can be used:
  - **with Composer**
  - **without Composer**

---

# 🗂️ Project Structure

```
env_secured/
├── _init.php                    → Bootloader (entry point)
├── libs/
│   ├── EnvSecured.php           → Main config manager
│   ├── EnvSecuredCrypto.php     → Encryption engine
│   └── html/
│       ├── page_form.php        → UI template: config editor
│       ├── page_success.php     → UI template: success page
│       └── page_error.php       → UI template: error page
├── configs/                     → Encrypted config files (auto-created)
│   ├── config.envs              → EnvSecured Studio vault (auto-created)
│   └── config.enc               → Legacy encrypted config (still supported)
└── keys/                        → Key files (auto-created)
    ├── sodium.key               → Internal crypto key
    ├── secret.key               → Master secret key
    └── studio_password_*.enc    → Local encrypted Studio password cache
```

Both `configs/` and `keys/` directories are created automatically on first use if they do not exist.

---

# 📦 Installation

## Option A — Composer (recommended)

```bash
composer require hegelmax/env-secured
```

## Option B — No Composer

Download the directory:

```
env_secured/
```

and place it anywhere in your project.

---

# 🚀 Quick Start (Composer version)

```php
require __DIR__ . '/vendor/autoload.php';

use EnvSecured\EnvSecured;

$envRoot = __DIR__ . '/env'; // Directory for configs/ and keys/

$env = new EnvSecured($envRoot);
$env->run();

// Retrieve configuration
$config = EnvSecured::get();          // full array
$dbHost = EnvSecured::get('DB_HOST'); // single value
```

---

# 🚀 Quick Start (No Composer)

Copy `init.php.sample` to your project (e.g. as `_init.php`) and adjust paths, then include it:

```php
require __DIR__ . '/env_secured/_init.php';
```

Then read configuration via:

```php
$env = EnvSecured::get();  // array
echo EnvSecured::get('API_URL'); 
```

---

# 🖥️ First Run — Creating Config

When no encrypted config exists, opening your init script in a browser shows the Config Editor UI:

```
/_init.php
```

UI allows:

### ✔ Editing KEY=value rows  
### ✔ Saving encrypted config (`config.enc`)
### ✔ Downloading JSON  
### ✔ Loading JSON into form  

Folders created automatically:

```
env/
  configs/
    config.enc
  keys/
    sodium.key
    secret.key
```

---

# 🔒 Storage and Encryption Model

New configs are written as EnvSecured Studio-compatible `.envs` vaults:

- JSON project model with `Settings`, `Crypto`, `Variables`, and `Values`
- default storage mode: `WholeJson`
- vault password KDF: PBKDF2-HMAC-SHA256, 300000 iterations
- payload encryption: AES-256-CBC + HMAC-SHA256
- encrypted payload format: `Nonce`, `Ciphertext`, `Tag`

The PHP runtime can read Studio modes:

- `Open`
- `SecretsOnly`
- `AllValues`
- `WholeJson`

Legacy `config.enc` remains readable/writable when `ENV_SECURED_STORAGE_FORMAT = 'legacy'` or when only the old file exists.

Legacy local encryption and the Studio password cache use:

- 256-bit `sodium.key`
- 256-bit `secret.key`
- machine + project fingerprint
- XSalsa20-Poly1305 (libsodium)
- unique nonce per encryption
- atomic writes to prevent corruption

Conceptually:

```
fingerprint = HASH( hostname | projectRoot | secret.key )
finalKey    = HASH( fingerprint | sodium.key )
cipher      = base64( nonce | secretbox(plaintext, nonce, finalKey) )
```

For the Studio password cache, PHP also binds encryption to the absolute `.envs` path:

```
cacheKey = HASH( fingerprint | sodium.key | "studio-password-cache|/absolute/path/config.envs" )
```

So copying only `keys/*.key` is not enough to decrypt the cached password outside the same machine/path context.

---

# 🛡️ Why It's Safe

- Keys stored outside web root (in `env_secured/keys/`)
- Config stored encrypted (`env_secured/configs/config.enc`)
- No plaintext config on server
- No global functions → no name collisions
- Atomic writes for safe file operations
- Encryption relies on libsodium (modern & secure)
- Browser editor POST requests are protected with a session-bound CSRF token
- Security headers on all UI responses: `Content-Security-Policy` (with nonce), `X-Frame-Options: DENY`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`
- Input limits: max 500 key/value pairs, key ≤ 128 chars, value ≤ 64 KB

---

# ⚙️ Configuration in Code

Once EnvSecured loads the config:

### 1️⃣ Array access

```php
$config = EnvSecured::get();
echo $config['DB_HOST'];
```

### 2️⃣ Single value

```php
echo EnvSecured::get('API_TOKEN');
```

### 3️⃣ Global constants

If constant autodefine is enabled:

```php
echo API_TOKEN;
```

Enable via:

```php
const ENV_SECURED_CONFIG_DEFINE_CONST = true;
```

---

# 🛠️ Optional Constants

Place them **before** calling EnvSecured.

```php
const ENV_SECURED_CONFIG_SCHEMA           = 'prod';
const ENV_SECURED_CONFIG_ALLOW_EDIT       = false;
const ENV_SECURED_CONFIG_ALLOW_SESSION    = true;
const ENV_SECURED_CONFIG_DEFINE_CONST     = true;

const ENV_SECURED_STORAGE_FORMAT          = 'studio'; // studio (default for new configs) | legacy
const ENV_SECURED_STUDIO_FILE             = __DIR__ . '/env/configs/config.envs';
const ENV_SECURED_STUDIO_PASSWORD         = 'change-me'; // optional; otherwise POST, ENVSECURED_PASSWORD, or local cache
const ENV_SECURED_STUDIO_ENCRYPTION_MODE  = 'WholeJson'; // Open | SecretsOnly | AllValues | WholeJson
const ENV_SECURED_STUDIO_SERVICE          = 'backend'; // optional runtime scope
const ENV_SECURED_STUDIO_ENVIRONMENT      = 'prod'; // optional runtime scope

const ENV_SECURED_DEFAULTS = [
    ['key' => 'DB_HOST', 'value' => 'localhost'],
    ['key' => 'API_URL', 'value' => 'https://localhost/api'],
];
```

`ENV_SECURED_CONFIG_SCHEMA` is a legacy file-name prefix, not a Studio service or environment. Use `ENV_SECURED_STUDIO_SERVICE` and `ENV_SECURED_STUDIO_ENVIRONMENT` for Studio scopes.

Note: The browser editor always starts a PHP session (to issue a CSRF token), regardless of `ENV_SECURED_CONFIG_ALLOW_SESSION`. If your application starts its own session before including EnvSecured, that is fine — `session_start()` will not be called twice. If your application starts a session after, include EnvSecured first, or call `session_start()` yourself beforehand.

Warning: `ENV_SECURED_CONFIG_ALLOW_SESSION=true` stores the decrypted config in `$_SESSION['ENV']`. Default PHP session handlers usually store sessions as plaintext files, so this may write secrets to disk outside EnvSecured encryption. Prefer `EnvSecured::get()` unless you control and protect PHP session storage.

---

# 🔧 Requirements

- PHP **8.1+**
- `ext-sodium` enabled
- `ext-openssl` enabled
- Writable directory for:
  - `configs/`
  - `keys/`

---

# 🧩 EnvSecured Studio Compatibility

PHP now writes new configs in Studio's `.envs` project-vault structure. Simple PHP `KEY=value` rows are stored as Studio variables and values; by default, the whole JSON project is encrypted with the Studio password.

The PHP editor can set the per-variable `Secret` flag:

- `Secret`: controls Studio `IsSecret`; used by `SecretsOnly`.

The file protection selector maps to Studio modes:

- `Open`
- `Secrets only` -> `SecretsOnly`
- `All values` -> `AllValues`
- `Masked / whole vault` -> `WholeJson`

Compatibility limits:

- PHP preserves legacy `config.enc`, but that old format is not a Studio vault.
- PHP can consume Studio service/environment scopes and interpolation.
- The browser editor is still a simple key/value editor, so it cannot expose the full Studio UI model such as scope matrix, manifests, validation settings, generated values, and export masks.
- For complex Studio projects, prefer editing in EnvSecured Studio and use PHP as the runtime reader/writer for known keys.

---

# 💻 JSON Import / Export

EnvSecured supports configuration migration via JSON file, that can be useful for:

- migrations
- backups
- moving configs between servers
- Dev → Prod workflows

### Export (Download JSON)

Downloads a readable `.json` file containing all config values.

### Import (Load JSON)

Loads a `.json` file directly in the browser and fills the config form.

> No data is sent to the server until **Save (encrypted)** is pressed.

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
require_once __DIR__ . '/env_secured/libs/EnvSecuredCrypto.php';

$crypto = new \EnvSecured\EnvSecuredCrypto(__DIR__ . '/env_secured');
$cipher = $crypto->encrypt("test");
var_dump($cipher);
```

Then ensure:

```php
$crypto->decrypt($cipher) === "test";
```

---

# 📄 License

MIT License. Free for commercial use.

---

© 2025 Maxim Hegel
