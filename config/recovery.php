<?php

return [
    'enabled' => (bool) env('BACKUPS_ENABLED', false),
    'key' => env('BACKUP_ENCRYPTION_KEY'), // independent 32-byte base64 key; recover separately from the archive and APP_KEY
    'disk' => env('BACKUP_DISK', 'recovery'),
    'prefix' => 'shopbonus-backups',
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
    'max_bytes' => (int) env('BACKUP_MAX_BYTES', 536870912),
    'max_files' => (int) env('BACKUP_MAX_FILES', 20000),
    'overdue_minutes' => (int) env('BACKUP_OVERDUE_MINUTES', 120),
    'restore_connection' => env('BACKUP_RESTORE_CONNECTION'), // explicitly configured isolated MySQL server connection
    'media_roots' => ['public' => storage_path('app/public'), 'cms' => storage_path('app/private/cms'), 'banners' => storage_path('app/private/banners'), 'avatars' => storage_path('app/private/avatars'), 'profiles' => storage_path('app/private/public-profiles')],
];
