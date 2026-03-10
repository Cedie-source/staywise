<?php
/**
 * PayMongo Configuration
 * 
 * Sign up at https://paymongo.com to get your API keys.
 * Use test keys for development, live keys for production.
 * 
 * GCash payments are processed through PayMongo's payment gateway.
 */

return [
    // PayMongo API Keys (get from https://dashboard.paymongo.com/developers)
    'secret_key'  => getenv('PAYMONGO_SECRET_KEY') ?: 'sk_live_FPKkjxzLHHJmZ4geQ5hhdW2d',
    'public_key'  => getenv('PAYMONGO_PUBLIC_KEY') ?: 'pk_live_AykY9pPbiiRNZjGNttaHJhGB',

    // Webhook secret for verifying PayMongo webhook signatures
    'webhook_secret' => getenv('PAYMONGO_WEBHOOK_SECRET') ?: '',

    // Base URL of your application (used for redirect URLs)
    'base_url' => 'http://localhost/StayWise',

    // Currency (PayMongo uses PHP for Philippine Peso)
    'currency' => 'PHP',

    // Enabled payment methods
    'enabled_methods' => ['gcash', 'grab_pay', 'card'],

    // Test mode flag
    'test_mode' => true,
];
