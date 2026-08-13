<?php

return [
    'application' => [
        'code' => env('WAADBY_APPLICATION_CODE', 'laravel-application'),
        'name' => env('WAADBY_APPLICATION_NAME', env('APP_NAME', 'Laravel application')),
        'version' => env('WAADBY_APPLICATION_VERSION', '0.0.0'),
    ],
    'enabled' => env('ACCESS_OPERATIONS_ENABLED', false),
    'remote_agent' => [
        'enabled' => env('WAADBY_OPERATIONS_REMOTE_AGENT_ENABLED', false),
        'state_path' => env('WAADBY_OPERATIONS_REMOTE_STATE_PATH', storage_path('app/private/waadby-operations-agent')),
        'replay_store' => env('WAADBY_OPERATIONS_REMOTE_REPLAY_STORE'),
        'clock_skew_seconds' => min(60, max(0, (int) env('WAADBY_OPERATIONS_REMOTE_CLOCK_SKEW', 30))),
        'maximum_token_ttl_seconds' => min(90, max(30, (int) env('WAADBY_OPERATIONS_REMOTE_TOKEN_TTL', 60))),
        'maximum_body_bytes' => min(1048576, max(1024, (int) env('WAADBY_OPERATIONS_REMOTE_MAX_BODY', 262144))),
        'allow_local_testing_http' => (bool) env('WAADBY_OPERATIONS_REMOTE_ALLOW_LOCAL_HTTP', false),
    ],
    'backup' => [
        'disk' => env('WAADBY_OPERATIONS_BACKUP_DISK', 'local'),
        'directory' => env('WAADBY_OPERATIONS_BACKUP_DIRECTORY', 'waadby-operations/backups'),
        'key' => env('WAADBY_OPERATIONS_BACKUP_KEY'),
        'auto_verify' => true,
        'persistent_paths' => [],
        'sensitive_variables' => [],
        'include_code_snapshot' => false,
        'code_paths' => [],
        'excluded_names' => [
            '.git', '.env', 'vendor', 'node_modules', 'logs', 'cache', 'sessions', 'views', 'backups',
        ],
    ],
    'database' => [
        'enabled' => true,
        'mysqldump_binary' => env('WAADBY_OPERATIONS_MYSQLDUMP', 'mysqldump'),
    ],
];
