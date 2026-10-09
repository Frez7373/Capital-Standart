<?php
declare(strict_types=1);

return [
    'app_name' => getenv('BANK_APP_NAME') ?: 'Капитал-Стандарт',
    'savings_annual_rate_percent' => (float)(getenv('SAVINGS_ANNUAL_RATE_PERCENT') ?: '5.0'),
    'app_env' => getenv('APP_ENV') ?: 'production',
    'base_url' => rtrim(getenv('APP_BASE_URL') ?: '', '/'),
    'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Stockholm',
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'cci_bank',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];
