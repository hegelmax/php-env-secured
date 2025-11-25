<?php if (session_status() === PHP_SESSION_NONE) {session_start();}

//const CONFIG_ALLOW_EDIT = true;
//const CONFIG_SCHEMA = 'default';

if (file_exists(__DIR__ . '/libs/cls.EnvSecuredCrypto.php'))	require_once __DIR__ . '/libs/cls.EnvSecuredCrypto.php';
if (file_exists(__DIR__ . '/libs/cls.EnvSecuredConfig.php'))	require_once __DIR__ . '/libs/cls.EnvSecuredConfig.php';
if (class_exists('EnvSecuredConfig')) {
	$EnvSecuredConfig = new EnvSecuredConfig(__DIR__);
	$EnvSecuredConfig->run();
} else {
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => '[EnvSecuredConfig] module not loaded'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}