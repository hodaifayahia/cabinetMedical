<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clinic Identity
    |--------------------------------------------------------------------------
    |
    | Basic information about the clinic. These values are surfaced across the
    | admin panel (Filament brand name), printable documents (prescriptions,
    | invoices, receipts) and the custom Vue application.
    |
    */

    'name' => env('CLINIC_NAME', 'Drclick'),
    'address' => env('CLINIC_ADDRESS', ''),
    'phone' => env('CLINIC_PHONE', ''),
    'email' => env('CLINIC_EMAIL', ''),

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | The operational timezone for the clinic. Defaults to the application
    | timezone (config/app.php) so there is a single source of truth.
    |
    */

    'timezone' => env('CLINIC_TIMEZONE', env('APP_TIMEZONE', 'UTC')),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | All monetary values are stored as integer minor units (e.g. cents) and
    | formatted for display using these settings. "minor_unit" is the number
    | of decimal places (2 = cents). Never use floats for money.
    |
    */

    'currency' => [
        'code' => env('CLINIC_CURRENCY_CODE', 'DZD'),
        'symbol' => env('CLINIC_CURRENCY_SYMBOL', 'DA'),
        'locale' => env('CLINIC_CURRENCY_LOCALE', 'fr_DZ'),
        'minor_unit' => (int) env('CLINIC_CURRENCY_MINOR_UNIT', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Appointments
    |--------------------------------------------------------------------------
    |
    | Default consultation slot length (minutes) used when a doctor profile
    | does not define its own duration, plus scheduling guard rails.
    |
    */

    'appointments' => [
        'default_duration' => (int) env('CLINIC_APPOINTMENT_DURATION', 30),
        'min_duration' => (int) env('CLINIC_APPOINTMENT_MIN_DURATION', 5),
        'max_duration' => (int) env('CLINIC_APPOINTMENT_MAX_DURATION', 240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inventory
    |--------------------------------------------------------------------------
    |
    | Thresholds that drive low-stock and expiration warnings. Individual
    | products may override the low-stock threshold with their own value.
    |
    */

    'inventory' => [
        'low_stock_threshold' => (int) env('CLINIC_LOW_STOCK_THRESHOLD', 10),
        'expiry_warning_days' => (int) env('CLINIC_EXPIRY_WARNING_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Point of Sale / Payments
    |--------------------------------------------------------------------------
    |
    | The set of accepted payment methods can be toggled here. The cashier
    | workflow can also require an open cash session before taking cash.
    |
    */

    'payments' => [
        'require_open_cash_session' => (bool) env('CLINIC_REQUIRE_CASH_SESSION', true),
        'methods' => [
            'cash' => (bool) env('CLINIC_PAYMENT_CASH', true),
            'card' => (bool) env('CLINIC_PAYMENT_CARD', true),
            'bank_transfer' => (bool) env('CLINIC_PAYMENT_BANK_TRANSFER', false),
            'insurance' => (bool) env('CLINIC_PAYMENT_INSURANCE', false),
            'other' => (bool) env('CLINIC_PAYMENT_OTHER', false),
        ],
    ],

];
