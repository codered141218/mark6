<?php
// Defaults. On Railway / Docker set the DATABASE_URL (or DB_*) environment variables instead of editing files;
// on a normal PHP host copy this file to config/config.php and fill in your Supabase / PostgreSQL details.
return [
    'db' => [
        // Supabase: Project Settings → Database → Connection string. Use the Session pooler host
        // (aws-0-<region>.pooler.supabase.com, port 5432, user postgres.<project-ref>) on IPv4-only hosts such as Railway.
        'host'     => '127.0.0.1',
        'port'     => 5432,
        'database' => 'postgres',
        'username' => 'postgres',
        'password' => '',
        'schema'   => 'public',     // schema that holds the Mark6 tables
        'sslmode'  => 'prefer',     // 'require' for Supabase
    ],
    'app' => [
        'timezone' => 'Asia/Manila',
        // Set to true only while developing: shows full error details in the browser.
        'debug'    => false,
        // Base URL path if the app is not at the web root, e.g. '/mark6' for http://localhost/mark6
        'base_path' => '',
        // Behind Railway / a reverse proxy: trust X-Forwarded-Proto to detect https (secure cookies).
        'trust_proxy' => true,
    ],
];
