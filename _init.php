<?php

namespace EnvSecured;

//	const ENV_SECURED_CONFIG_SCHEMA			= 'your_schema';	// default = default
//	const ENV_SECURED_CONFIG_ALLOW_EDIT		= true;				// default = false
//	const ENV_SECURED_CONFIG_ALLOW_SESSION	= true;				// default = false
//	const ENV_SECURED_CONFIG_DEFINE_CONST	= false;			// default = true
//	const ENV_SECURED_DEFAULTS				= [
//			['key' => 'DB_HOST'				,'value' => 'localhost'],
//			['key' => 'API_KEY'				,'value' => '**API_KEY**'],
//			['key' => 'API_URL'				,'value' => 'https://dev.local/api'],
//			['key' => 'TELEGRAM_BOT_TOKEN'	,'value' => '**TELEGRAM_BOT_TOKEN**'],
//			['key' => 'REDIS_URL'			,'value' => 'redis://127.0.0.1'],
//		];

$EnvSecured = new EnvSecured(__DIR__);
$EnvSecured->run();