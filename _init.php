<?php
//const ENV_SECURED_CONFIG_SCHEMA			= 'default';
//const ENV_SECURED_CONFIG_ALLOW_EDIT		= true;
//const ENV_SECURED_CONFIG_ALLOW_SESSION	= true;

if (file_exists(__DIR__ . '/libs/cls.EnvSecuredCrypto.php'))	require_once __DIR__ . '/libs/cls.EnvSecuredCrypto.php';
if (file_exists(__DIR__ . '/libs/cls.EnvSecured.php'))			require_once __DIR__ . '/libs/cls.EnvSecured.php';
if (class_exists('EnvSecured')) {
	$EnvSecured = new EnvSecured(__DIR__);
	$EnvSecured->run();
} else {
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => '[EnvSecured] module not loaded'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}