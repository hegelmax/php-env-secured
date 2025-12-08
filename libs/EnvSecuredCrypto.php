<?php
namespace EnvSecured;

use RuntimeException;

class EnvSecuredCrypto {
	protected string $rootDir;
	protected string $keysPath;
	protected readonly string $keySodium;
	protected readonly string $keySecret;
	
	public function __construct(string $rootDir) {
		if(is_dir($rootDir)) {
			try {
				$this->rootDir		= $rootDir;
				$this->keysPath		= $this->ensureDir($this->rootDir . DIRECTORY_SEPARATOR . 'keys');
				$this->keySodium	= $this->getOrCreate256bitKey('sodium');
				$this->keySecret	= $this->getOrCreate256bitKey('secret');
			} catch (\RuntimeException $e) {
				echo "Error: " . $e->getMessage() . PHP_EOL;
				exit;
			}
		} else {
			echo "RootDir is not exist: " . $rootDir . PHP_EOL;
			exit;
		}
	}

	// =================================================================
	// PUBLIC METHODS (THESE ARE CALLED FROM OTHER SCRIPTS)
	// =================================================================

	// * Returns the unique server fingerprint *
	public function fingerprint(): string {
		$projectRoot = realpath(dirname($this->rootDir));

		$parts = [
			php_uname('n'),
			$projectRoot,
			$this->keySecret
		];

		return sodium_bin2hex(
			sodium_crypto_generichash(implode('|', $parts), '', 32)
		);
	}

	// * Encryption *
	public function encrypt(string $plaintext): string|false {
		$keyHex = $this->deriveFinalKey();
		$keyBin = sodium_hex2bin($keyHex);

		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$cipher = sodium_crypto_secretbox($plaintext, $nonce, $keyBin);

		return base64_encode($nonce . $cipher);
	}

	// *Decryption*
	public function decrypt(string $b64): string|false {
		$keyHex = $this->deriveFinalKey();
		$keyBin = sodium_hex2bin($keyHex);

		$bin = base64_decode($b64, true);
		if ($bin === false) return false;

		$nonceSize = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if (strlen($bin) < $nonceSize) return false;

		$nonce = substr($bin, 0, $nonceSize);
		$cipher = substr($bin, $nonceSize);

		return sodium_crypto_secretbox_open($cipher, $nonce, $keyBin);
	}

	// ============================================================
	// INTERNAL METERS (NOT CALLED OUTSIDE)
	// ============================================================

	private function deriveFinalKey(): string {
		$fingerprint	= $this->fingerprint();
		$raw			= $fingerprint . '|' . $this->keySodium;

		return sodium_bin2hex(
			sodium_crypto_generichash($raw, '', 32)
		);
	}

	private function ensureDir(string $dir, int $mode = 0700): string {
		if (!is_dir($dir)) {
			if (!mkdir($dir, $mode, true) && !is_dir($dir)) {
				throw new \RuntimeException("Failed to create directory: {$dir}");
			}
		}
		
		return $dir;
	}

	// ============================================================
	// Generate or download a 256-bit hex key.
	// ============================================================
	private function getOrCreate256bitKey(string $file_name): string {
		$file_path = $this->keysPath . DIRECTORY_SEPARATOR . $file_name . '.key';
		
		if (is_file($file_path)) {
			$content = trim(@file_get_contents($file_path));
			if ($content !== '') {
				return $content;
			}
		}

		$random	= random_bytes(32);
		$hex	= sodium_bin2hex($random);

		if (file_put_contents($file_path, $hex, LOCK_EX) === false) {
			throw new \RuntimeException("Failed to write key to file: {$file_path}");
		}

		chmod($file_path, 0600);

		return $hex;
	}
}
