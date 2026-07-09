<?php
declare(strict_types=1);

return [
  'db' => [
    'host'    => 'localhost',
    'port'    => 3306,
    'name'    => 'kommrag_ingest',
    'user'    => 'CHANGEME',
    'pass'    => 'CHANGEME',
    'charset' => 'utf8mb4',
  ],

  /*
    PHP CLI Binary für den Cron-Worker.
    Bei Standard-Setups kann der Block entfallen (Fallback: PHP_BINARY).
    Netcup-Webhosting Beispiel:
  */
  'cli' => [
    'php'          => '/usr/local/php85/bin/php',
    'ini_scan_dir' => '/usr/local/php85/etc/conf.d',
  ],

  'logs' => [
    'dir' => __DIR__ . '/../var/logs',
  ],

  'storage' => [
    'base_dir'        => __DIR__ . '/../storage/pdf',
    'public_base_url' => null,
  ],

  'upload' => [
    'max_bytes'    => 50 * 1024 * 1024,
    'allowed_mime' => ['application/pdf'],
  ],

  'mistral_api_key' => 'CHANGEME',

  'system2' => [
    'sftp' => [
      'host'             => 'CHANGEME',
      'port'             => 22,
      'username'         => 'CHANGEME',
	   /*
       OpenSSH Private Key (ed25519) als String.
       Zeilenumbrüche als \n, komplett inkl. Header/Footer.
       */
      'private_key' => <<<'KEY'
		-----BEGIN OPENSSH PRIVATE KEY-----
		CHANGEME
		-----END OPENSSH PRIVATE KEY-----
		KEY,
      'remote_base_dir'  => '/home/kommrag/ingest/incoming',
    ],
  ],
];
