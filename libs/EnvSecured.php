<?php
namespace EnvSecured;

use RuntimeException;
use Throwable;

class EnvSecured {
	private const MAX_CONFIG_PAIRS = 500;
	private const MAX_CONFIG_KEY_LENGTH = 128;
	private const MAX_CONFIG_VALUE_LENGTH = 65536;
	private const MAX_INTERPOLATION_DEPTH = 50;

	protected bool		$allowSession;
	protected bool		$defineConst;
	protected string	$rootDir;
	protected string	$configsPath;
	protected string	$configFilePath;
	protected ?string	$studioVaultPath = null;
	protected ?string	$cspNonce = null;
	protected $EnvSecuredCrypto;
	
	public function __construct(string $rootDir) {
		$this->ensureDir($rootDir);
		if(is_dir($rootDir)) {
			$this->rootDir = $rootDir;
			$this->configsPath = $this->rootDir . DIRECTORY_SEPARATOR . 'configs';
			$this->ensureDir($this->configsPath);
			$this->EnvSecuredCrypto = new EnvSecuredCrypto($rootDir);
			$this->setEncConfigPath();
			$this->setStudioVaultPath();
			
			// Check session
			$this->allowSession = defined('ENV_SECURED_CONFIG_ALLOW_SESSION') ? (bool)ENV_SECURED_CONFIG_ALLOW_SESSION : false;
			if ($this->allowSession && session_status() === PHP_SESSION_NONE) {session_start();}
			
			$this->defineConst = defined('ENV_SECURED_CONFIG_DEFINE_CONST') ? (bool)ENV_SECURED_CONFIG_DEFINE_CONST : true;
		} else {
			echo "Configuration directory error." . PHP_EOL;
			exit;
		}
	}
	
	// -------------------- Entry point --------------------
	public function run(): void {
		// POST: processing form saving
		if (
			($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
			&& (!empty($_POST['cfg_key']) || ($_POST['save_mode'] ?? '') === 'migrate_studio')
		) {
			$this->assertCsrfToken();
			$this->checkAndSaveNewValues();
		}
		
		// GET: if the config already exists
		if ($this->hasStudioVault() || is_file($this->configFilePath)) {
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
	public static function get(?string $key = null) {
		if ($key === null) return $GLOBALS['SRVENV'] ?? [];
		return $GLOBALS['SRVENV'][$key] ?? null;
	}

	// -------------------- File paths --------------------

	// * Path to the encrypted config *
	private function setEncConfigPath(): void {
		$prefix = defined('ENV_SECURED_CONFIG_SCHEMA') ? (ENV_SECURED_CONFIG_SCHEMA . '.') : '';
		$this->configFilePath = $this->configsPath . DIRECTORY_SEPARATOR . $prefix . 'config.enc';
	}

	private function setStudioVaultPath(): void {
		$path = defined('ENV_SECURED_STUDIO_FILE') ? (string)ENV_SECURED_STUDIO_FILE : '';
		if ($path === '') {
			$prefix = defined('ENV_SECURED_CONFIG_SCHEMA') ? (ENV_SECURED_CONFIG_SCHEMA . '.') : '';
			$path = $this->configsPath . DIRECTORY_SEPARATOR . $prefix . 'config.envs';
		}

		$this->studioVaultPath = $this->resolvePath($path);
	}

	private function resolvePath(string $path): string {
		if ($path === '') {
			return $path;
		}

		if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, DIRECTORY_SEPARATOR)) {
			return $path;
		}

		return $this->rootDir . DIRECTORY_SEPARATOR . $path;
	}

	private function hasStudioVault(): bool {
		return $this->studioVaultPath !== null && is_file($this->studioVaultPath);
	}

	private function shouldUseStudioStorage(): bool {
		$format = defined('ENV_SECURED_STORAGE_FORMAT') ? strtolower((string)ENV_SECURED_STORAGE_FORMAT) : '';
		if ($format === 'legacy') {
			return false;
		}
		if ($format === 'studio') {
			return true;
		}

		if ($this->hasStudioVault()) {
			return true;
		}

		return !is_file($this->configFilePath);
	}

	private function canMigrateLegacyToStudio(): bool {
		return !$this->hasStudioVault() && is_file($this->configFilePath);
	}

	// -------------------- File/Permissions Utilities --------------------

	private function ensureDir(string $dir, int $mode = 0700): void {
		if (!is_dir($dir)) {
			@mkdir($dir, $mode, true);
		}
	}

	private function ensureSessionStarted(): void {
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}
	}

	private function getCsrfToken(): string {
		$this->ensureSessionStarted();
		if (empty($_SESSION['ENV_SECURED_CSRF_TOKEN']) || !is_string($_SESSION['ENV_SECURED_CSRF_TOKEN'])) {
			$_SESSION['ENV_SECURED_CSRF_TOKEN'] = bin2hex(random_bytes(32));
		}

		return $_SESSION['ENV_SECURED_CSRF_TOKEN'];
	}

	private function assertCsrfToken(): void {
		$this->ensureSessionStarted();
		$expected = $_SESSION['ENV_SECURED_CSRF_TOKEN'] ?? '';
		$actual = $_POST['csrf_token'] ?? '';
		if (!is_string($expected) || !is_string($actual) || $expected === '' || !hash_equals($expected, $actual)) {
			http_response_code(403);
			$this->renderPageForm('Invalid CSRF token. Reload the page and try again.');
			exit;
		}
	}

	private function getCspNonce(): string {
		if ($this->cspNonce === null) {
			$this->cspNonce = base64_encode(random_bytes(16));
		}

		return $this->cspNonce;
	}

	private function sendSecurityHeaders(): void {
		if (headers_sent()) {
			return;
		}

		$nonce = $this->getCspNonce();
		header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
		header('X-Frame-Options: DENY');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		header('Expires: 0');
		header('X-Content-Type-Options: nosniff');
	}

	private function atomicWrite(string $data, int $chmod = 0600): void {
		$tmp = $this->configFilePath . '.tmp_' . bin2hex(random_bytes(6));
		if (file_put_contents($tmp, $data, LOCK_EX) === false) {
			throw new RuntimeException('Cannot write config file.');
		}

		@chmod($tmp, $chmod);

		if (!@rename($tmp, $this->configFilePath)) {
			@unlink($tmp);
			throw new RuntimeException('Cannot save config file.');
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

	private function studioSaveArray(array $arr, array $meta = [], ?string $encryptionMode = null): void {
		if ($this->studioVaultPath === null) {
			throw new RuntimeException('Studio vault path is not configured.');
		}

		$this->ensureDir(dirname($this->studioVaultPath));
		$project = $this->hasStudioVault() ? $this->loadStudioProject() : $this->createStudioProject();
		$this->ensureStudioDefaultService($project);
		$this->ensureStudioConfiguredEnvironment($project);
		$this->setStudioProjectEncryptionMode($project, $encryptionMode);
		$this->upsertStudioValues($project, $arr, $meta);
		$this->saveStudioProject($project);
	}

	private function createStudioProject(): array {
		return [
			'Version' => '1.0',
			'ProjectId' => bin2hex(random_bytes(16)),
			'ProjectName' => defined('ENV_SECURED_STUDIO_PROJECT_NAME') ? (string)ENV_SECURED_STUDIO_PROJECT_NAME : basename((string)realpath(dirname($this->rootDir))),
			'Description' => '',
			'Settings' => [
				'EncryptionMode' => defined('ENV_SECURED_STUDIO_ENCRYPTION_MODE') ? (string)ENV_SECURED_STUDIO_ENCRYPTION_MODE : 'WholeJson',
				'EncryptAllValues' => false,
				'GeneratedFileHeader' => true,
				'OutputFormat' => 'CONFIG',
				'OutputExtension' => '.env',
				'OutputGlobalMask' => 'apps\\.env{.ext}',
				'OutputEnvironmentMask' => 'apps\\.env.{env}{.ext}',
				'OutputServiceMask' => 'apps\\{service}\\.env{.ext}',
				'OutputServiceEnvironmentMask' => 'apps\\{service}\\.env.{env}{.ext}',
				'OutputStructuredSingleFile' => false,
				'OutputStructuredSingleFileMask' => '{project_name}{.ext}',
				'OutputServiceManifest' => false,
				'OutputDataFiles' => true,
				'OutputServiceManifestMask' => 'apps\\{service}\\.env.example',
				'OutputServiceManifestValueMode' => 'Empty',
				'OutputTargets' => [],
				'CliExportPasswordRequiredPolicy' => true,
			],
			'Crypto' => [
				'Kdf' => 'PBKDF2-HMAC-SHA256',
				'Iterations' => 300000,
			],
			'Environments' => [],
			'Services' => [
				$this->createStudioDefaultService(),
			],
			'Variables' => [],
			'Contracts' => [],
			'Values' => [],
		];
	}

	private function createStudioDefaultService(): array {
		$id = $this->getStudioDefaultServiceId();
		return [
			'Id' => $id,
			'Name' => $id,
			'DisplayName' => 'Project',
			'Description' => '',
			'OutputFolder' => $id,
			'DefaultPrefix' => '',
			'ConfigName' => $id,
			'TomlName' => $id,
			'YamlName' => $id,
			'XmlName' => $id,
			'JsonName' => $id,
			'SortOrder' => 0,
			'IsActive' => true,
			'AllowSharedVariablesWithoutContract' => true,
		];
	}

	private function getStudioDefaultServiceId(): string {
		$id = defined('ENV_SECURED_STUDIO_DEFAULT_SERVICE') ? (string)ENV_SECURED_STUDIO_DEFAULT_SERVICE : 'project';
		$id = trim($id);
		return $id !== '' ? $id : 'project';
	}

	private function ensureStudioDefaultService(array &$project): void {
		$project['Services'] = isset($project['Services']) && is_array($project['Services']) ? $project['Services'] : [];
		$serviceId = $this->getStudioDefaultServiceId();
		$hasService = false;
		foreach ($project['Services'] as $service) {
			if (is_array($service) && (string)($service['Id'] ?? '') === $serviceId) {
				$hasService = true;
				break;
			}
		}

		if (!$hasService) {
			$service = $this->createStudioDefaultService();
			$service['SortOrder'] = count($project['Services']) * 10;
			$project['Services'][] = $service;
		}

		$project['Variables'] = isset($project['Variables']) && is_array($project['Variables']) ? $project['Variables'] : [];
		$ownerlessVariableIds = [];
		foreach ($project['Variables'] as &$variable) {
			if (!is_array($variable)) {
				continue;
			}

			if ((string)($variable['OwnerServiceId'] ?? '') === '') {
				$variable['OwnerServiceId'] = $serviceId;
				if (!empty($variable['Id'])) {
					$ownerlessVariableIds[(string)$variable['Id']] = true;
				}
			}
		}
		unset($variable);

		if (!$ownerlessVariableIds) {
			return;
		}

		$project['Values'] = isset($project['Values']) && is_array($project['Values']) ? $project['Values'] : [];
		foreach ($project['Values'] as &$value) {
			if (!is_array($value) || empty($ownerlessVariableIds[(string)($value['VariableId'] ?? '')])) {
				continue;
			}

			$scope = (int)($value['Scope'] ?? -1);
			if ($scope === 0) {
				$value['Scope'] = 2;
				$value['ServiceId'] = $serviceId;
			} elseif ($scope === 1) {
				$value['Scope'] = 3;
				$value['ServiceId'] = $serviceId;
			}
		}
		unset($value);
	}

	private function getStudioConfiguredEnvironmentId(): ?string {
		$environmentId = defined('ENV_SECURED_STUDIO_ENVIRONMENT') ? (string)ENV_SECURED_STUDIO_ENVIRONMENT : (string)getenv('ENVSECURED_ENVIRONMENT');
		$environmentId = trim($environmentId);
		return $environmentId !== '' ? $environmentId : null;
	}

	private function ensureStudioConfiguredEnvironment(array &$project): void {
		$environmentId = $this->getStudioConfiguredEnvironmentId();
		if ($environmentId === null) {
			return;
		}

		$project['Environments'] = isset($project['Environments']) && is_array($project['Environments']) ? $project['Environments'] : [];
		foreach ($project['Environments'] as $environment) {
			if (is_array($environment) && (string)($environment['Id'] ?? '') === $environmentId) {
				return;
			}
		}

		$project['Environments'][] = [
			'Id' => $environmentId,
			'Name' => $environmentId,
			'DisplayName' => $environmentId,
			'ConfigName' => $environmentId,
			'TomlName' => $environmentId,
			'YamlName' => $environmentId,
			'XmlName' => $environmentId,
			'JsonName' => $environmentId,
			'SortOrder' => count($project['Environments']) * 10,
			'IsActive' => true,
		];
	}

	private function upsertStudioValues(array &$project, array $data, array $meta = []): void {
		$project['Variables'] = isset($project['Variables']) && is_array($project['Variables']) ? $project['Variables'] : [];
		$project['Values'] = isset($project['Values']) && is_array($project['Values']) ? $project['Values'] : [];
		$nextSort = count($project['Variables']) * 10;

		foreach ($data as $key => $value) {
			if ($key === 'saved_at') {
				continue;
			}

			$key = (string)$key;
			if ($key === '') {
				continue;
			}

			$variableIndex = $this->findStudioVariableIndex($project, $key);
			if ($variableIndex === null) {
				$project['Variables'][] = [
					'Id' => bin2hex(random_bytes(16)),
					'Key' => $key,
					'DisplayName' => $key,
					'Description' => '',
					'Type' => 0,
					'IsSecret' => true,
					'AllowSharedSecret' => true,
					'AllowNull' => false,
					'AllowBlank' => true,
					'IsActive' => true,
					'OwnerServiceId' => $this->getStudioDefaultServiceId(),
					'IsGenerated' => false,
					'GeneratorLength' => 32,
					'SortOrder' => $nextSort,
				];
				$variableIndex = array_key_last($project['Variables']);
				$nextSort += 10;
			}

			$project['Variables'][$variableIndex]['IsSecret'] = (bool)($meta[$key]['secret'] ?? true);
			if (!array_key_exists('IsActive', $project['Variables'][$variableIndex])) {
				$project['Variables'][$variableIndex]['IsActive'] = true;
			}
			$project['Variables'][$variableIndex]['AllowSharedSecret'] = true;

			$variable = $project['Variables'][$variableIndex];
			$target = $this->studioValueTargetForVariable($variable);
			$valueIndex = $this->findStudioValueIndex(
				$project,
				(string)$variable['Id'],
				$target['Scope'],
				$target['ServiceId'],
				$target['EnvironmentId']
			);

			$valueRow = [
				'Id' => $valueIndex === null ? bin2hex(random_bytes(16)) : ($project['Values'][$valueIndex]['Id'] ?? bin2hex(random_bytes(16))),
				'VariableId' => (string)$variable['Id'],
				'Scope' => $target['Scope'],
				'EnvironmentId' => $target['EnvironmentId'],
				'ServiceId' => $target['ServiceId'],
				'IsEncrypted' => false,
				'Value' => (string)$value,
				'EncryptedValue' => null,
				'UpdatedAt' => gmdate('c'),
			];

			if ($valueIndex === null) {
				$project['Values'][] = $valueRow;
			} else {
				$project['Values'][$valueIndex] = array_replace($project['Values'][$valueIndex], $valueRow);
			}
		}
	}

	private function studioValueTargetForVariable(array $variable): array {
		$serviceId = $this->selectStudioServiceIdForSave($variable);
		$environmentId = $this->selectStudioEnvironmentIdForSave();
		$ownerServiceId = (string)($variable['OwnerServiceId'] ?? '');

		if ($ownerServiceId !== '') {
			$targetServiceId = $serviceId ?: $ownerServiceId;
			return [
				'Scope' => $environmentId ? 3 : 2,
				'ServiceId' => $targetServiceId,
				'EnvironmentId' => $environmentId,
			];
		}

		return [
			'Scope' => $environmentId ? 1 : 0,
			'ServiceId' => null,
			'EnvironmentId' => $environmentId,
		];
	}

	private function selectStudioServiceIdForSave(array $variable): ?string {
		$serviceId = defined('ENV_SECURED_STUDIO_SERVICE') ? (string)ENV_SECURED_STUDIO_SERVICE : (string)getenv('ENVSECURED_SERVICE');
		if ($serviceId !== '') {
			return $serviceId;
		}

		$ownerServiceId = (string)($variable['OwnerServiceId'] ?? '');
		return $ownerServiceId !== '' ? $ownerServiceId : null;
	}

	private function selectStudioEnvironmentIdForSave(): ?string {
		return $this->getStudioConfiguredEnvironmentId();
	}

	private function findStudioVariableIndex(array $project, string $key): ?int {
		foreach (($project['Variables'] ?? []) as $index => $variable) {
			if (is_array($variable) && (string)($variable['Key'] ?? '') === $key) {
				return (int)$index;
			}
		}

		return null;
	}

	private function findStudioValueIndex(array $project, string $variableId, int $scope, ?string $serviceId, ?string $environmentId): ?int {
		foreach (($project['Values'] ?? []) as $index => $value) {
			if (
				is_array($value)
				&& (string)($value['VariableId'] ?? '') === $variableId
				&& (int)($value['Scope'] ?? -1) === $scope
				&& $this->nullableStudioId($value['ServiceId'] ?? null) === $this->nullableStudioId($serviceId)
				&& $this->nullableStudioId($value['EnvironmentId'] ?? null) === $this->nullableStudioId($environmentId)
			) {
				return (int)$index;
			}
		}

		return null;
	}

	private function saveStudioProject(array $project): void {
		$project['Values'] = isset($project['Values']) && is_array($project['Values']) ? $project['Values'] : [];
		$mode = $this->getStudioEncryptionMode($project);
		$payload = $project;

		if ($mode !== 'Open') {
			$key = $this->ensureStudioCrypto($payload);
			if ($mode === 'WholeJson') {
				$this->clearStudioValueEncryption($payload);
				$json = $this->encodeStudioJson($payload);
				$output = $this->encodeStudioJson([
					'Format' => 'EnvSecured.EncryptedProject.v1',
					'Crypto' => $payload['Crypto'],
					'Payload' => $this->encryptStudioPayload($json, $key),
				], true);
				$this->atomicWriteStudio($output, 0600);
				return;
			}

			$this->encryptStudioValuesForStorage($payload, $key, $mode);
		} else {
			$this->clearStudioCryptoKeyCheck($payload);
			$this->clearStudioValueEncryption($payload);
		}

		$this->atomicWriteStudio($this->encodeStudioJson($payload, true), 0600);
	}

	private function ensureStudioCrypto(array &$project): string {
		$project['Crypto'] = isset($project['Crypto']) && is_array($project['Crypto']) ? $project['Crypto'] : [];
		$project['Crypto']['Kdf'] = 'PBKDF2-HMAC-SHA256';
		$project['Crypto']['Iterations'] = (int)($project['Crypto']['Iterations'] ?? 300000) ?: 300000;
		if (empty($project['Crypto']['Salt'])) {
			$project['Crypto']['Salt'] = base64_encode(random_bytes(32));
		}

		$key = $this->deriveStudioVaultKey($project['Crypto']);
		if (!isset($project['Crypto']['KeyCheck']) || !is_array($project['Crypto']['KeyCheck'])) {
			$project['Crypto']['KeyCheck'] = $this->encryptStudioPayload('EnvSecuredVaultKeyCheck:v1', $key);
		}

		return $key;
	}

	private function encryptStudioValuesForStorage(array &$project, string $key, string $mode): void {
		foreach ($project['Values'] as &$value) {
			if (!is_array($value)) {
				continue;
			}

			$variable = $this->findStudioVariableById($project, (string)($value['VariableId'] ?? ''));
			$shouldEncrypt = $mode === 'AllValues' || ($mode === 'SecretsOnly' && $variable !== null && $this->studioBool($variable, 'IsSecret', false));
			if (!$shouldEncrypt) {
				$value['IsEncrypted'] = false;
				$value['EncryptedValue'] = null;
				continue;
			}

			$value['EncryptedValue'] = $this->encryptStudioPayload((string)($value['Value'] ?? ''), $key);
			$value['Value'] = null;
			$value['IsEncrypted'] = true;
		}
		unset($value);
	}

	private function clearStudioValueEncryption(array &$project): void {
		foreach ($project['Values'] as &$value) {
			if (!is_array($value)) {
				continue;
			}

			$value['IsEncrypted'] = false;
			$value['EncryptedValue'] = null;
		}
		unset($value);
	}

	private function clearStudioCryptoKeyCheck(array &$project): void {
		if (isset($project['Crypto']) && is_array($project['Crypto'])) {
			$project['Crypto']['KeyCheck'] = null;
		}
	}

	private function findStudioVariableById(array $project, string $variableId): ?array {
		foreach (($project['Variables'] ?? []) as $variable) {
			if (is_array($variable) && (string)($variable['Id'] ?? '') === $variableId) {
				return $variable;
			}
		}

		return null;
	}

	private function getStudioEncryptionMode(array $project): string {
		$mode = (string)($project['Settings']['EncryptionMode'] ?? 'WholeJson');
		$normalized = strtolower($mode);
		return match ($normalized) {
			'open' => 'Open',
			'secretsonly', 'secrets-only' => 'SecretsOnly',
			'allvalues', 'all-values' => 'AllValues',
			'wholejson', 'whole-json' => 'WholeJson',
			default => 'WholeJson',
		};
	}

	private function setStudioProjectEncryptionMode(array &$project, ?string $mode): void {
		if ($mode === null || $mode === '') {
			return;
		}

		$normalized = $this->normalizeStudioEncryptionMode($mode);
		$project['Settings'] = isset($project['Settings']) && is_array($project['Settings']) ? $project['Settings'] : [];
		$project['Settings']['EncryptionMode'] = $normalized;
		$project['Settings']['EncryptAllValues'] = $normalized === 'AllValues';
	}

	private function normalizeStudioEncryptionMode(string $mode): string {
		return match (strtolower(trim($mode))) {
			'open' => 'Open',
			'secrets', 'secretsonly', 'secrets-only' => 'SecretsOnly',
			'all', 'allvalues', 'all-values' => 'AllValues',
			'masked', 'whole', 'wholejson', 'whole-json' => 'WholeJson',
			default => 'WholeJson',
		};
	}

	private function getCurrentStudioEncryptionMode(): string {
		if (!$this->hasStudioVault()) {
			return defined('ENV_SECURED_STUDIO_ENCRYPTION_MODE')
				? $this->normalizeStudioEncryptionMode((string)ENV_SECURED_STUDIO_ENCRYPTION_MODE)
				: 'WholeJson';
		}

		try {
			return $this->getStudioEncryptionMode($this->loadStudioProject());
		} catch (Throwable) {
			return 'WholeJson';
		}
	}

	private function encryptStudioPayload(string $plainText, string $key): array {
		if (!extension_loaded('openssl')) {
			throw new RuntimeException("PHP extension 'openssl' is required to write EnvSecured Studio encrypted vaults.");
		}

		$iv = random_bytes(16);
		$ciphertext = openssl_encrypt($plainText, 'aes-256-cbc', substr($key, 0, 32), OPENSSL_RAW_DATA, $iv);
		if ($ciphertext === false) {
			throw new RuntimeException('EnvSecured Studio payload encrypt failed.');
		}

		return [
			'Alg' => 'AES-256-CBC-HMAC-SHA256',
			'Nonce' => base64_encode($iv),
			'Ciphertext' => base64_encode($ciphertext),
			'Tag' => base64_encode(hash_hmac('sha256', $iv . $ciphertext, substr($key, 32, 32), true)),
		];
	}

	private function encodeStudioJson(array $payload, bool $pretty = false): string {
		$options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ($pretty) {
			$options |= JSON_PRETTY_PRINT;
		}

		$json = json_encode($payload, $options);
		if ($json === false) {
			throw new RuntimeException('Studio vault JSON encode failed: ' . json_last_error_msg());
		}

		return $json;
	}

	private function atomicWriteStudio(string $data, int $chmod = 0600): void {
		if ($this->studioVaultPath === null) {
			throw new RuntimeException('Studio vault path is not configured.');
		}

		$tmp = $this->studioVaultPath . '.tmp_' . bin2hex(random_bytes(6));
		if (file_put_contents($tmp, $data, LOCK_EX) === false) {
			throw new RuntimeException('Cannot write Studio vault.');
		}

		@chmod($tmp, $chmod);
		if (!@rename($tmp, $this->studioVaultPath)) {
			@unlink($tmp);
			throw new RuntimeException('Cannot save Studio vault.');
		}
	}

	private function encLoadArray(): array {
		$blobB64 = @file_get_contents($this->configFilePath);
		if ($blobB64 === false) {
			throw new RuntimeException('Cannot read config file.');
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

	private function loadConfigArray(): array {
		if ($this->hasStudioVault()) {
			return $this->loadStudioVaultArray();
		}

		return $this->encLoadArray();
	}

	private function loadStudioVaultArray(): array {
		return $this->buildStudioEffectiveConfig($this->loadStudioProject());
	}

	private function loadStudioProject(): array {
		if ($this->studioVaultPath === null) {
			throw new RuntimeException('Studio vault path is not configured.');
		}

		$json = @file_get_contents($this->studioVaultPath);
		if ($json === false) {
			throw new RuntimeException('Cannot read Studio vault.');
		}

		$root = $this->jsonDecodeObject($json, 'Studio vault JSON decode failed');
		$project = $this->isStudioEncryptedEnvelope($root)
			? $this->decryptStudioProjectEnvelope($root)
			: $root;

		$this->decryptStudioProjectValues($project);
		return $project;
	}

	private function jsonDecodeObject(string $json, string $message): array {
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new RuntimeException($message . ': ' . json_last_error_msg());
		}

		return $data;
	}

	private function isStudioEncryptedEnvelope(array $root): bool {
		return ($root['Format'] ?? null) === 'EnvSecured.EncryptedProject.v1';
	}

	private function decryptStudioProjectEnvelope(array $envelope): array {
		$crypto = $envelope['Crypto'] ?? null;
		$payload = $envelope['Payload'] ?? null;
		if (!is_array($crypto) || !is_array($payload)) {
			throw new RuntimeException('Invalid EnvSecured Studio encrypted envelope.');
		}

		$key = $this->deriveStudioVaultKey($crypto);
		$projectJson = $this->decryptStudioPayload($payload, $key);
		$project = $this->jsonDecodeObject($projectJson, 'Decrypted Studio project JSON decode failed');
		$project['Crypto'] = $crypto;

		return $project;
	}

	private function decryptStudioProjectValues(array &$project): void {
		$project['Values'] = isset($project['Values']) && is_array($project['Values']) ? $project['Values'] : [];
		$values = $project['Values'] ?? [];
		$settings = $project['Settings'] ?? [];
		$hasEncryptedValues = is_array($values) && count(array_filter($values, static function ($value): bool {
			return is_array($value) && !empty($value['IsEncrypted']) && isset($value['EncryptedValue']);
		})) > 0;

		$hasEncryptedCliPolicy = is_array($settings) && isset($settings['CliExportPasswordRequiredEncrypted']);
		if (!$hasEncryptedValues && !$hasEncryptedCliPolicy) {
			return;
		}

		$key = $this->deriveStudioVaultKey($project['Crypto'] ?? []);
		foreach ($project['Values'] as &$value) {
			if (!is_array($value) || empty($value['IsEncrypted']) || !isset($value['EncryptedValue']) || !is_array($value['EncryptedValue'])) {
				continue;
			}

			$value['Value'] = $this->decryptStudioPayload($value['EncryptedValue'], $key);
			$value['IsEncrypted'] = false;
		}
		unset($value);
	}

	private function deriveStudioVaultKey(array $crypto): string {
		if (!extension_loaded('openssl')) {
			throw new RuntimeException("PHP extension 'openssl' is required to read EnvSecured Studio encrypted vaults.");
		}

		$password = $this->getStudioPassword();
		if ($password === '') {
			throw new RuntimeException('EnvSecured Studio vault password is required. Define ENV_SECURED_STUDIO_PASSWORD or ENVSECURED_PASSWORD.');
		}

		$saltB64 = (string)($crypto['Salt'] ?? '');
		$salt = base64_decode($saltB64, true);
		if ($salt === false || $salt === '') {
			throw new RuntimeException('Invalid EnvSecured Studio crypto salt.');
		}

		$iterations = (int)($crypto['Iterations'] ?? 300000);
		if ($iterations <= 0) {
			$iterations = 300000;
		}

		$key = hash_pbkdf2('sha256', $password, $salt, $iterations, 64, true);
		$keyCheck = $crypto['KeyCheck'] ?? null;
		if (is_array($keyCheck)) {
			$check = $this->decryptStudioPayload($keyCheck, $key);
			if ($check !== 'EnvSecuredVaultKeyCheck:v1') {
				throw new RuntimeException('EnvSecured Studio vault key check failed.');
			}
		}

		return $key;
	}

	private function getStudioPassword(): string {
		$password = isset($_POST['studio_password']) ? (string)$_POST['studio_password'] : '';
		if ($password === '' && defined('ENV_SECURED_STUDIO_PASSWORD')) {
			$password = (string)ENV_SECURED_STUDIO_PASSWORD;
		}
		if ($password === '') {
			$password = (string)getenv('ENVSECURED_PASSWORD');
		}

		if ($password !== '') {
			$this->cacheStudioPassword($password);
			return $password;
		}

		return $this->loadCachedStudioPassword();
	}

	private function cacheStudioPassword(string $password): void {
		if ($password === '') {
			return;
		}

		$cacheFile = $this->getStudioPasswordCacheFile();
		$this->ensureDir(dirname($cacheFile));
		$payload = json_encode([
			'vault' => $this->studioVaultPath,
			'password' => $password,
			'saved_at' => gmdate('c'),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if ($payload === false) {
			throw new RuntimeException('Studio password cache JSON encode failed.');
		}

		$encrypted = $this->EnvSecuredCrypto->encryptBound($payload, $this->getStudioPasswordCacheContext());
		if ($encrypted === false) {
			throw new RuntimeException('Studio password cache encryption failed.');
		}

		$tmp = $cacheFile . '.tmp_' . bin2hex(random_bytes(6));
		if (file_put_contents($tmp, $encrypted, LOCK_EX) === false) {
			throw new RuntimeException('Cannot write Studio password cache.');
		}
		@chmod($tmp, 0600);
		if (!@rename($tmp, $cacheFile)) {
			@unlink($tmp);
			throw new RuntimeException('Cannot save Studio password cache.');
		}
	}

	private function loadCachedStudioPassword(): string {
		foreach ($this->getStudioPasswordCacheFiles() as $cacheFile) {
			if (!is_file($cacheFile)) {
				continue;
			}

			$encrypted = @file_get_contents($cacheFile);
			if ($encrypted === false || trim($encrypted) === '') {
				continue;
			}

			$json = $this->EnvSecuredCrypto->decryptBound(trim($encrypted), $this->getStudioPasswordCacheContext());
			if ($json === false) {
				continue;
			}

			$payload = json_decode($json, true);
			if (!is_array($payload) || ($payload['vault'] ?? null) !== $this->studioVaultPath) {
				continue;
			}

			return (string)($payload['password'] ?? '');
		}

		return '';
	}

	private function getStudioPasswordCacheFile(): string {
		$cacheKey = hash('sha256', (string)$this->studioVaultPath);
		return $this->rootDir . DIRECTORY_SEPARATOR . 'keys' . DIRECTORY_SEPARATOR . 'studio_password_' . $cacheKey . '.enc';
	}

	private function getLegacyStudioPasswordCacheFile(): string {
		$cacheKey = hash('sha256', (string)$this->studioVaultPath);
		return $this->rootDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'studio_password_' . $cacheKey . '.enc';
	}

	private function getStudioPasswordCacheFiles(): array {
		return [
			$this->getStudioPasswordCacheFile(),
			$this->getLegacyStudioPasswordCacheFile(),
		];
	}

	private function getStudioPasswordCacheContext(): string {
		return 'studio-password-cache|' . (string)$this->studioVaultPath;
	}

	private function decryptStudioPayload(array $payload, string $key): string {
		if (($payload['Alg'] ?? 'AES-256-CBC-HMAC-SHA256') !== 'AES-256-CBC-HMAC-SHA256') {
			throw new RuntimeException('Unsupported EnvSecured Studio payload algorithm.');
		}

		$iv = base64_decode((string)($payload['Nonce'] ?? ''), true);
		$ciphertext = base64_decode((string)($payload['Ciphertext'] ?? ''), true);
		$tag = base64_decode((string)($payload['Tag'] ?? ''), true);
		if ($iv === false || $ciphertext === false || $tag === false) {
			throw new RuntimeException('Invalid EnvSecured Studio encrypted payload.');
		}

		$expectedTag = hash_hmac('sha256', $iv . $ciphertext, substr($key, 32, 32), true);
		if (!hash_equals($expectedTag, $tag)) {
			throw new RuntimeException('EnvSecured Studio payload authentication failed.');
		}

		$plain = openssl_decrypt($ciphertext, 'aes-256-cbc', substr($key, 0, 32), OPENSSL_RAW_DATA, $iv);
		if ($plain === false) {
			throw new RuntimeException('EnvSecured Studio payload decrypt failed.');
		}

		return $plain;
	}

	private function buildStudioEffectiveConfig(array $project): array {
		$serviceId = $this->selectStudioServiceId($project);
		$environmentId = $this->selectStudioEnvironmentId($project);
		$variables = $project['Variables'] ?? [];
		if (!is_array($variables)) {
			return [];
		}

		usort($variables, static function (array $left, array $right): int {
			return ((int)($left['SortOrder'] ?? 0) <=> (int)($right['SortOrder'] ?? 0))
				?: strcmp((string)($left['Key'] ?? ''), (string)($right['Key'] ?? ''));
		});

		$result = [];
		foreach ($variables as $variable) {
			if (!is_array($variable) || !$this->studioBool($variable, 'IsActive', true)) {
				continue;
			}

			$key = (string)($variable['Key'] ?? '');
			if ($key === '') {
				continue;
			}

			$value = $this->buildStudioRawValue($project, $variable, $serviceId, $environmentId);
			if ($value !== null) {
				$result[$key] = $value;
			}
		}

		foreach ($result as $key => $value) {
			$result[$key] = $this->interpolateStudioValue((string)$value, $result);
		}

		return $result;
	}

	private function buildStudioEditablePrefill(): array {
		$project = $this->loadStudioProject();
		$serviceId = $this->selectStudioServiceIdForSave([]);
		$environmentId = $this->selectStudioEnvironmentIdForSave();
		$variables = $project['Variables'] ?? [];
		if (!is_array($variables)) {
			return [];
		}

		usort($variables, static function (array $left, array $right): int {
			return ((int)($left['SortOrder'] ?? 0) <=> (int)($right['SortOrder'] ?? 0))
				?: strcmp((string)($left['Key'] ?? ''), (string)($right['Key'] ?? ''));
		});

		$prefill = [];
		foreach ($variables as $variable) {
			if (!is_array($variable)) {
				continue;
			}

			$key = (string)($variable['Key'] ?? '');
			if ($key === '') {
				continue;
			}

			$target = $this->studioValueTargetForVariable($variable);
			if ($serviceId !== null) {
				$target['ServiceId'] = $serviceId;
			}
			if ($environmentId !== null) {
				$target['EnvironmentId'] = $environmentId;
				$target['Scope'] = $target['ServiceId'] === null ? 1 : 3;
			}

			$direct = $this->findLastStudioValue(
				$project,
				(string)($variable['Id'] ?? ''),
				$this->studioScopeName($target['Scope']),
				$target['ServiceId'],
				$target['EnvironmentId']
			);
			$value = $direct !== null
				? (string)($direct['Value'] ?? '')
				: (string)($this->buildStudioRawValue($project, $variable, $serviceId, $environmentId) ?? '');

			$prefill[] = [
				'key' => $key,
				'value' => $value,
				'secret' => $this->studioBool($variable, 'IsSecret', true),
			];
		}

		return $prefill;
	}

	private function selectStudioServiceId(array $project): ?string {
		$serviceId = defined('ENV_SECURED_STUDIO_SERVICE') ? (string)ENV_SECURED_STUDIO_SERVICE : (string)getenv('ENVSECURED_SERVICE');
		if ($serviceId !== '') {
			return $serviceId;
		}

		$services = $project['Services'] ?? [];
		if (!is_array($services)) {
			return null;
		}

		foreach ($services as $service) {
			if (is_array($service) && $this->studioBool($service, 'IsActive', true) && !empty($service['Id'])) {
				return (string)$service['Id'];
			}
		}

		return null;
	}

	private function selectStudioEnvironmentId(array $project): ?string {
		$environmentId = defined('ENV_SECURED_STUDIO_ENVIRONMENT') ? (string)ENV_SECURED_STUDIO_ENVIRONMENT : (string)getenv('ENVSECURED_ENVIRONMENT');
		if ($environmentId !== '') {
			return $environmentId;
		}

		$environments = $project['Environments'] ?? [];
		if (!is_array($environments)) {
			return null;
		}

		foreach ($environments as $environment) {
			if (is_array($environment) && $this->studioBool($environment, 'IsActive', true) && !empty($environment['Id'])) {
				return (string)$environment['Id'];
			}
		}

		return null;
	}

	private function buildStudioRawValue(array $project, array $variable, ?string $serviceId, ?string $environmentId): ?string {
		$selected = null;
		foreach ($this->studioValuePrecedence($project, $variable, $serviceId, $environmentId) as $candidate) {
			if ($candidate !== null) {
				$selected = $candidate;
			}
		}

		return $selected === null ? null : (string)($selected['Value'] ?? '');
	}

	private function studioValuePrecedence(array $project, array $variable, ?string $serviceId, ?string $environmentId): array {
		$variableId = (string)($variable['Id'] ?? '');
		$ownerServiceId = (string)($variable['OwnerServiceId'] ?? '');
		if ($ownerServiceId !== '') {
			if (!$this->studioVariableVisibleToService($project, $variable, $serviceId)) {
				return [];
			}

			$items = [
				$this->findLastStudioValue($project, $variableId, 'Service', $ownerServiceId, null),
				$this->findLastStudioValue($project, $variableId, 'ServiceEnvironment', $ownerServiceId, $environmentId),
			];

			if ($ownerServiceId !== (string)$serviceId && $this->studioCanOverrideVariableForService($project, $variable, $serviceId)) {
				$items[] = $this->findLastStudioValue($project, $variableId, 'Service', $serviceId, null);
				$items[] = $this->findLastStudioValue($project, $variableId, 'ServiceEnvironment', $serviceId, $environmentId);
			}

			return $items;
		}

		$items = [
			$this->findLastStudioValue($project, $variableId, 'Global', null, null),
			$this->findLastStudioValue($project, $variableId, 'Environment', null, $environmentId),
		];

		if ($serviceId !== null && $serviceId !== '') {
			$services = $project['Services'] ?? [];
			if (is_array($services)) {
				usort($services, static function (array $left, array $right): int {
					return ((int)($left['SortOrder'] ?? 0) <=> (int)($right['SortOrder'] ?? 0))
						?: strcmp((string)($left['Name'] ?? ''), (string)($right['Name'] ?? ''));
				});

				foreach ($services as $service) {
					$otherServiceId = (string)($service['Id'] ?? '');
					if ($otherServiceId === '' || $otherServiceId === $serviceId || !$this->studioVariableSharedFromService($project, $variableId, $otherServiceId)) {
						continue;
					}

					$items[] = $this->findLastStudioValue($project, $variableId, 'Service', $otherServiceId, null);
					$items[] = $this->findLastStudioValue($project, $variableId, 'ServiceEnvironment', $otherServiceId, $environmentId);
				}
			}

			$items[] = $this->findLastStudioValue($project, $variableId, 'Service', $serviceId, null);
			$items[] = $this->findLastStudioValue($project, $variableId, 'ServiceEnvironment', $serviceId, $environmentId);
		}

		return $items;
	}

	private function findLastStudioValue(array $project, string $variableId, string $scope, ?string $serviceId, ?string $environmentId): ?array {
		$values = $project['Values'] ?? [];
		if (!is_array($values)) {
			return null;
		}

		$found = null;
		foreach ($values as $value) {
			if (!is_array($value)) {
				continue;
			}

			if (
				(string)($value['VariableId'] ?? '') === $variableId
				&& $this->studioScopeName($value['Scope'] ?? null) === $scope
				&& $this->nullableStudioId($value['ServiceId'] ?? null) === $this->nullableStudioId($serviceId)
				&& $this->nullableStudioId($value['EnvironmentId'] ?? null) === $this->nullableStudioId($environmentId)
			) {
				$found = $value;
			}
		}

		return $found;
	}

	private function studioScopeName(mixed $scope): string {
		if (is_int($scope)) {
			return ['Global', 'Environment', 'Service', 'ServiceEnvironment'][$scope] ?? '';
		}

		if (is_numeric($scope)) {
			return ['Global', 'Environment', 'Service', 'ServiceEnvironment'][(int)$scope] ?? '';
		}

		return (string)$scope;
	}

	private function studioVariableVisibleToService(array $project, array $variable, ?string $serviceId): bool {
		if ((string)($variable['OwnerServiceId'] ?? '') === (string)$serviceId) {
			return true;
		}

		if ($serviceId === null || $serviceId === '') {
			return false;
		}

		$contract = $this->findStudioContract($project, (string)($variable['Id'] ?? ''), $serviceId);
		return $contract !== null && $this->studioBool($contract, 'VisibleToService', false);
	}

	private function studioCanOverrideVariableForService(array $project, array $variable, ?string $serviceId): bool {
		if ($serviceId === null || $serviceId === '') {
			return false;
		}

		if ((string)($variable['OwnerServiceId'] ?? '') === $serviceId) {
			return true;
		}

		$contract = $this->findStudioContract($project, (string)($variable['Id'] ?? ''), $serviceId);
		return $contract !== null
			&& $this->studioBool($contract, 'VisibleToService', false)
			&& $this->studioBool($contract, 'AllowOverride', false);
	}

	private function studioVariableSharedFromService(array $project, string $variableId, string $sourceServiceId): bool {
		if ($sourceServiceId === '') {
			return true;
		}

		$contract = $this->findStudioContract($project, $variableId, $sourceServiceId);
		return $contract === null || $this->studioBool($contract, 'ShareWithOtherServices', true);
	}

	private function findStudioContract(array $project, string $variableId, string $serviceId): ?array {
		$contracts = $project['Contracts'] ?? [];
		if (!is_array($contracts)) {
			return null;
		}

		foreach ($contracts as $contract) {
			if (
				is_array($contract)
				&& (string)($contract['VariableId'] ?? '') === $variableId
				&& (string)($contract['ServiceId'] ?? '') === $serviceId
			) {
				return $contract;
			}
		}

		return null;
	}

	private function interpolateStudioValue(string $value, array $valuesByKey, array $stack = []): string {
		if ($value === '') {
			return $value;
		}
		if (count($stack) >= self::MAX_INTERPOLATION_DEPTH) {
			return $value;
		}

		return (string)preg_replace_callback('/\{\{(?<key>[A-Za-z_][A-Za-z0-9_]*)\}\}/', function (array $match) use ($valuesByKey, $stack): string {
			$key = $match['key'];
			if (!array_key_exists($key, $valuesByKey) || in_array($key, $stack, true)) {
				return $match[0];
			}

			$nextStack = $stack;
			$nextStack[] = $key;
			return $this->interpolateStudioValue((string)$valuesByKey[$key], $valuesByKey, $nextStack);
		}, $value);
	}

	private function studioBool(array $item, string $key, bool $default): bool {
		if (!array_key_exists($key, $item)) {
			return $default;
		}

		return filter_var($item[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
	}

	private function nullableStudioId(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
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
		$secrets = $_POST['cfg_secret'] ?? [];

		if (!is_array($keys) || !is_array($values) || !is_array($secrets)) {
			http_response_code(400);
			$this->renderPageForm('The format of the sent data is incorrect.');
			exit;
		}

		if (count($keys) > self::MAX_CONFIG_PAIRS || count($values) > self::MAX_CONFIG_PAIRS) {
			http_response_code(400);
			$this->renderPageForm('Too many keys.');
			exit;
		}

		$pairs = [];
		$data  = [];
		$meta  = [];
		$saveMode = $_POST['save_mode'] ?? 'enc'; // enc | json_download | migrate_studio
		$needsStudioMode = $this->shouldUseStudioStorage() || $saveMode === 'migrate_studio';
		$studioEncryptionMode = $needsStudioMode
			? $this->normalizeStudioEncryptionMode((string)($_POST['studio_encryption_mode'] ?? $this->getCurrentStudioEncryptionMode()))
			: null;

		if ($saveMode === 'migrate_studio') {
			try {
				$legacyData = $this->encLoadArray();
				unset($legacyData['saved_at']);
				if (!$legacyData) {
					throw new RuntimeException('Legacy config is empty.');
				}

				$legacyMeta = [];
				foreach ($legacyData as $legacyKey => $_) {
					$legacyMeta[(string)$legacyKey] = [
						'secret' => true,
					];
				}

				$this->studioSaveArray($legacyData, $legacyMeta, $studioEncryptionMode);
				$this->setSessionVar();
				$this->renderPageSuccess();
				return;
			} catch (Throwable $e) {
				http_response_code(500);
				$error = 'Migration error: ' . $e->getMessage();
				$this->renderPageForm($error, $pairs);
				exit;
			}
		}

		$count = max(count($keys), count($values));

		for ($i = 0; $i < $count; $i++) {
			$k = isset($keys[$i])   ? trim((string)$keys[$i])   : '';
			$v = isset($values[$i]) ? (string)$values[$i] : '';
			$isSecret = isset($secrets[$i]) ? filter_var($secrets[$i], FILTER_VALIDATE_BOOLEAN) : true;

			// Empty line entirely - skip
			if ($k === '' && $v === '') {
				continue;
			}

			// Save for possible re-rendering of the form in case of an error
			$pairs[] = [
				'key'   => $k,
				'value' => $v,
				'secret' => $isSecret,
			];

			// Key is required
			if ($k === '') {
				http_response_code(400);
				$this->renderPageForm('One of the lines does not contain a key name.', $pairs);
				exit;
			}

			if (strlen($k) > self::MAX_CONFIG_KEY_LENGTH) {
				http_response_code(400);
				$this->renderPageForm('One of the key names is too long.', $pairs);
				exit;
			}

			if (strlen($v) > self::MAX_CONFIG_VALUE_LENGTH) {
				http_response_code(400);
				$this->renderPageForm('One of the values is too long.', $pairs);
				exit;
			}

			$data[$k] = $v;
			$meta[$k] = [
				'secret' => $isSecret,
			];
		}

		if (!$data) {
			http_response_code(400);
			$this->renderPageForm('At least one key=value pair must be specified.', $pairs);
			exit;
		}

		// Service timestamp
		$data['saved_at'] = gmdate('c');

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

			// Option 2: save encrypted config
			if ($this->shouldUseStudioStorage()) {
				$this->studioSaveArray($data, $meta, $studioEncryptionMode);
			} else {
				$this->encSaveArray($data);
			}
			$this->setSessionVar();
			$this->renderPageSuccess();
		} catch (Throwable $e) {
			http_response_code(500);
			$error = 'Save error: ' . $e->getMessage();
			$this->renderPageForm($error, $pairs);
			exit;
		}
	}

	// -------------------- Session / loading existing --------------------

	private function setSessionVar(): array {
		try {
			$env = $this->loadConfigArray();
			$GLOBALS['SRVENV'] = $env;
			if ($this->allowSession) $_SESSION['ENV'] = $env;
			if ($this->defineConst) {
				// Also define constants automatically
				foreach ($env as $k => $v) {
					if (!defined($k)) {
						// Valid PHP constant name check (OPTIONAL, but safe)
						if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $k)) {
							define($k, $v);
						}
					}
				}
			}
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
			if ($this->hasStudioVault()) {
				$prefill = $this->buildStudioEditablePrefill();
				$this->setSessionVar();
				$this->renderPageForm(null, $prefill);
				exit;
			}

			$data = $this->setSessionVar();

			foreach ($data as $k => $v) {
				if ($k === 'saved_at') {
					continue;// the service field is not editable
				}
				$prefill[] = [
					'key'   => (string)$k,
					'value' => (string)$v,
					'secret' => true,
				];
			}
		} catch (Throwable $e) {
			$error = 'Read/decrypt error: ' . $e->getMessage();
		}
		
		$this->renderPageForm($error, $prefill);
		exit;
	}

	// -------------------- Page Rendering --------------------

	private function getDefaultPrefill(): array {
		if (defined('ENV_SECURED_DEFAULTS') && is_array(ENV_SECURED_DEFAULTS)) {

			// нормализация формата
			$normalized = [];
			foreach (ENV_SECURED_DEFAULTS as $item) {
				if (!is_array($item)) continue;
				$key = $item['key'] ?? '';
				$value = $item['value'] ?? '';
				if ($key !== '') {
					$normalized[] = [
						'key' => $key,
				'value' => $value,
				'secret' => array_key_exists('secret', $item) ? (bool)$item['secret'] : true,
			];
				}
			}

			if ($normalized) return $normalized;
		}

		// fallback → старые дефолты
		return [
			['key' => 'DB_HOST',        'value' => '', 'secret' => false],
			['key' => 'DB_NAME',        'value' => '', 'secret' => false],
			['key' => 'DB_USER',        'value' => '', 'secret' => false],
			['key' => 'DB_PASSWORD',    'value' => '', 'secret' => true],
			['key' => 'YOUR_API_TOKEN', 'value' => '', 'secret' => true],
		];
	}

	private function renderPageForm( ?string $error = null, array $prefill = [], ?string $success = null ): void {
		$this->sendSecurityHeaders();
		$successHtml	= $success ? '<div class="msg msg-success">' . htmlspecialchars($success, ENT_QUOTES, 'UTF-8') . '</div>' : '';
		$errorHtml		= $error ? '<div class="msg msg-error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';
		$prefill		= $prefill ?: $this->getDefaultPrefill();

		echo $this->includeTemplate('page_form',[
				'success' => $successHtml,
				'error'   => $errorHtml,
				'prefill' => $prefill,
				'studioMode' => $this->shouldUseStudioStorage(),
				'studioEncryptionMode' => ($this->shouldUseStudioStorage() || $this->canMigrateLegacyToStudio()) ? $this->getCurrentStudioEncryptionMode() : null,
				'canMigrateStudio' => $this->canMigrateLegacyToStudio(),
				'csrfToken' => $this->getCsrfToken(),
				'cspNonce' => $this->getCspNonce(),
		]);
		exit;
	}

	private function renderPageSuccess(): void {
		$this->sendSecurityHeaders();
		echo $this->includeTemplate('page_success', [
			'cspNonce' => $this->getCspNonce(),
		]);
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
