<?php

return [
    'application' => [
        'code' => env('WAADBY_APPLICATION_CODE', 'laravel-application'),
        'name' => env('WAADBY_APPLICATION_NAME', env('APP_NAME', 'Laravel application')),
        'version' => env('WAADBY_APPLICATION_VERSION', '0.0.0'),
    ],
    'enabled' => env('ACCESS_OPERATIONS_ENABLED', false),
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
