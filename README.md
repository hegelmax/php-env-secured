# 📦 EnvSecured — Encrypted Configuration Manager for PHP

[EnvSecured](https://github.com/hegelmax/php-env-secured) is a lightweight, secure, and self-contained PHP module for storing sensitive configuration values (API keys, database credentials, tokens, secrets) in an **encrypted file** and provides a clean interface to access them in runtime.

---

# ⭐ Key Features

- 🔒 **Encrypted config file** (`config.enc`)
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
│   └── config.enc               → Main encrypted config (auto-created)
└── keys/                        → Key files (auto-created)
    ├── sodium.key               → Internal crypto key
    └── secret.key               → Master secret key
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

```php
require __DIR__ . '/env_secured/init.php';
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
/env_secured/init.php
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

# 🔒 Encryption Model

EnvSecured uses:

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

---

# 🛡️ Why It's Safe

- Keys stored outside web root (in `env_secured/keys/`)
- Config stored encrypted (`env_secured/configs/config.enc`)
- No plaintext config on server
- No global functions → no name collisions
- Atomic writes for safe file operations
- Encryption relies on libsodium (modern & secure)

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
const ENV_SECURED_CONFIG_SCHEMA       = 'prod';
const ENV_SECURED_CONFIG_ALLOW_EDIT   = false;
const ENV_SECURED_CONFIG_ALLOW_SESSION = true;
const ENV_SECURED_CONFIG_DEFINE_CONST = true;

const ENV_SECURED_DEFAULTS = [
    ['key' => 'DB_HOST', 'value' => 'localhost'],
    ['key' => 'API_URL', 'value' => 'https://localhost/api'],
];
```

---

# 🔧 Requirements

- PHP **8.1+**
- `ext-sodium` enabled
- Writable directory for:
  - `configs/`
  - `keys/`

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

© 2025 Maxim Hegel
