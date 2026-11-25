<?php

return [
    'max_transaction' => (int) env('FINANCE_MAX_TRANSACTION', 1000000000),
    'platform_monthly_amount' => (int) env('PLATFORM_MONTHLY_AMOUNT', 100000),
    'platform_bank_name' => env('PLATFORM_BANK_NAME'),
    'platform_account_number' => env('PLATFORM_ACCOUNT_NUMBER'),
    'platform_account_name' => env('PLATFORM_ACCOUNT_NAME'),
];
