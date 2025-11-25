<?php
if (class_exists('EnvSecuredCrypto')) {
	class EnvSecured {
		protected bool		$allowSession;
		protected string	$rootDir;
		protected string	$configsPath;
		protected string	$configFilePath;
		protected $EnvSecuredCrypto;
		
		public function __construct(string $rootDir) {
			if(is_dir($rootDir)) {
				$this->rootDir = $rootDir;
				$this->configsPath = $this->rootDir . DIRECTORY_SEPARATOR . 'configs';
				$this->ensureDir($this->configsPath);
				$this->EnvSecuredCrypto = new EnvSecuredCrypto($rootDir);
				$this->setEncConfigPath();
				
				// Check session
				$this->allowSession = defined('ENV_SECURED_CONFIG_ALLOW_SESSION') ? (bool)ENV_SECURED_CONFIG_ALLOW_SESSION : false;
				if ($this->allowSession && session_status() === PHP_SESSION_NONE) {session_start();}
			} else {
				echo "RootDir is not exist: " . $rootDir . PHP_EOL;
				exit;
			}
		}
		
		// -------------------- Entry point --------------------
		public function run(): void {
			// POST: processing form saving
			if (
				($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
				&& !empty($_POST['cfg_key'])
			) {
				$this->checkAndSaveNewValues();
			}
			
			// GET: if the config already exists
			if (is_file($this->configFilePath)) {
				$allowEdit = defined('ENV_SECURED_CONFIG_ALLOW_EDIT') ? (bool)ENV_SECURED_CONFIG_ALLOW_EDIT : false;

				// Mode - show form with existing config (editing)
				if ($allowEdit && !empty($_GET['set_config'])) {
					$this->loadFromFile();
				}

				// Normal combat mode - just put the decrypted config into the session/global
				$this->setSessionVar();
				// We don't output anything so that the file can be simply included in the working code.
				return;
			}

			// GET: config.enc not yet available - show the initial setup form
			$this->renderPageForm();
			exit;
		}

		// -------------------- Getter --------------------
		// Using:
		// $host = EnvSecured::get('DB_HOST');
		public static function get(string $key = null) {
			if ($key === null) return $GLOBALS['SRVENV'] ?? [];
			return $GLOBALS['SRVENV'][$key] ?? null;
		}

		// -------------------- File paths --------------------

		// * Path to the encrypted config *
		private function setEncConfigPath(): void {
			$prefix = defined('ENV_SECURED_CONFIG_SCHEMA') ? (ENV_SECURED_CONFIG_SCHEMA . '.') : '';
			$this->configFilePath = $this->configsPath . DIRECTORY_SEPARATOR . $prefix . 'config.enc';
		}

		// -------------------- File/Permissions Utilities --------------------

		private function ensureDir(string $dir, int $mode = 0700): void {
			if (!is_dir($dir)) {
				@mkdir($dir, $mode, true);
			}
		}

		private function atomicWrite(string $data, int $chmod = 0600): void {
			$tmp = $this->configFilePath . '.tmp_' . bin2hex(random_bytes(6));
			if (file_put_contents($tmp, $data, LOCK_EX) === false) {
				throw new RuntimeException("Cannot write temp file: $tmp");
			}

			@chmod($tmp, $chmod);

			if (!@rename($tmp, $this->configFilePath)) {
				@unlink($tmp);
				throw new RuntimeException("Cannot move temp into place: ".$this->configFilePath);
			}
		}

		// -------------------- Encryption / Decryption --------------------

		private function encSaveArray(array $arr): void {
			$json = json_encode($arr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($json === false) {
				throw new RuntimeException('JSON encode failed');
			}

			$blob = $this->EnvSecuredCrypto->encrypt($json);
			if ($blob === false) {
				throw new RuntimeException('Encrypt failed');
			}

			$this->atomicWrite($blob, 0600);
		}

		private function encLoadArray(): array {
			$blobB64 = @file_get_contents($this->configFilePath);
			if ($blobB64 === false) {
				throw new RuntimeException("Cannot read: " . $this->configFilePath);
			}

			$json = $this->EnvSecuredCrypto->decrypt(trim($blobB64));
			if ($json === false) {
				throw new RuntimeException('Decrypt failed (wrong key or corrupted file)');
			}

			$arr = json_decode($json, true);
			if (!is_array($arr)) {
				throw new RuntimeException('JSON decode failed');
			}

			return $arr;
		}

		// -------------------- Working with POST --------------------

		// ***********************************************************
		// Processing form saving
		// - save_mode = enc => save to config.enc
		// - save_mode = json_download => download JSON to the browser
		// ***********************************************************
		private function checkAndSaveNewValues(): void {
			$keys   = $_POST['cfg_key']   ?? [];
			$values = $_POST['cfg_value'] ?? [];

			if (!is_array($keys) || !is_array($values)) {
				http_response_code(400);
				$this->renderPageForm('The format of the sent data is incorrect.');
				exit;
			}

			$pairs = [];
			$data  = [];

			$count = max(count($keys), count($values));

			for ($i = 0; $i < $count; $i++) {
				$k = isset($keys[$i])   ? trim((string)$keys[$i])   : '';
				$v = isset($values[$i]) ? (string)$values[$i] : '';

				// Empty line entirely - skip
				if ($k === '' && $v === '') {
					continue;
				}

				// Save for possible re-rendering of the form in case of an error
				$pairs[] = [
					'key'   => $k,
					'value' => $v,
				];

				// Key is required
				if ($k === '') {
					http_response_code(400);
					$this->renderPageForm('One of the lines does not contain a key name.', $pairs);
					exit;
				}

				$data[$k] = $v;
			}

			if (!$data) {
				http_response_code(400);
				$this->renderPageForm('At least one key=value pair must be specified.', $pairs);
				exit;
			}

			// Service timestamp
			$data['saved_at'] = gmdate('c');

			$saveMode = $_POST['save_mode'] ?? 'enc'; // enc | json_download

			try {
				// Option 1: Just download the JSON to your browser
				if ($saveMode === 'json_download') {
					$json = json_encode(
						$data,
						JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
					);
					if ($json === false) {
						throw new RuntimeException('JSON encode failed');
					}

					$filename = (defined('ENV_SECURED_CONFIG_SCHEMA') ? ENV_SECURED_CONFIG_SCHEMA . '.' : '') . 'config.json';

					// remove all buffers to return a clean file
					while (ob_get_level()) {
						ob_end_clean();
					}

					header('Content-Type: application/json; charset=utf-8');
					header('Content-Disposition: attachment; filename="' . $filename . '"');
					header('Content-Length: ' . strlen($json));

					echo $json;
					exit;
				}

				// Option 2: save encrypted config.enc
				$this->encSaveArray($data);
				$this->setSessionVar();
				$this->renderPageSuccess();
			} catch (Throwable $e) {
				http_response_code(500);
				$error = 'Save error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
				$this->renderPageForm($error, $pairs);
				exit;
			}
		}

		// -------------------- Session / loading existing --------------------

		private function setSessionVar(): array {
			try {
				$env = $this->encLoadArray();
				$GLOBALS['SRVENV'] = $env;
				if ($this->allowSession) $_SESSION['ENV'] = $env;
			} catch (Throwable $e) {
				http_response_code(500);
				$this->renderPageForm('Read/decrypt error: ' . $e->getMessage());
				exit;
			}
			return $env;
		}

		private function loadFromFile(): void {
			$prefill	= [];
			$error		= null;

			try {
				$data = $this->setSessionVar();

				foreach ($data as $k => $v) {
					if ($k === 'saved_at') {
						continue;// the service field is not editable
					}
					$prefill[] = [
						'key'   => (string)$k,
						'value' => (string)$v,
					];
				}
			} catch (Throwable $e) {
				$error = 'Read/decrypt error: ' . $e->getMessage();
			}
			
			$this->renderPageForm($error, $prefill);
			exit;
		}

		// -------------------- Page Rendering --------------------

		private function renderPageForm( ?string $error = null, array $prefill = [], ?string $success = null ): void {
			$successHtml	= $success ? '<div class="msg msg-success">' . htmlspecialchars($success, ENT_QUOTES, 'UTF-8') . '</div>' : '';
			$errorHtml		= $error ? '<div class="msg msg-error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';

			if (!$prefill) {
				$prefill = [
					['key' => 'DB_HOST',        'value' => ''],
					['key' => 'DB_NAME',        'value' => ''],
					['key' => 'DB_USER',        'value' => ''],
					['key' => 'DB_PASSWORD',    'value' => ''],
					['key' => 'YOUR_API_TOKEN', 'value' => ''],
				];
			}

			$self = htmlspecialchars($_SERVER['PHP_SELF'] ?? '/env_secured/_init.php', ENT_QUOTES, 'UTF-8');

			echo $this->includeTemplate('page_form',[
					'self'    => $self,
					'success' => $successHtml,
					'error'   => $errorHtml,
					'prefill' => $prefill,
			]);
			exit;
		}

		private function renderPageSuccess(): void {
			echo $this->includeTemplate('page_success', []);
			exit;
		}

		// ********************************************
		// Including a template with the $vars variable
		// ********************************************
		private function includeTemplate(string $fileName, array $vars = []): string {
			ob_start();
			/** @var array $vars */
			$vars = $vars;
			include __DIR__ . "/html/$fileName.php";
			return ob_get_clean();
		}
	}
} else {
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => '[EnvSecuredCrypto] module not loaded'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}