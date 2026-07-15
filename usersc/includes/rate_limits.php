<?php
/*
Rate limiting defaults for NEW UserSpice installs.

The framework file (users/includes/rate_limits.php) ships with EXTREMELY high limits so the
rate-limiting machinery never blocks a legitimate user out of the box. That is deliberately
permissive. This file — which lives in usersc/ (your site space, never touched by updates) —
ships a sensible, protective set of defaults so a fresh install is actually guarded against
brute force from day one.

The array below FULLY REPLACES the framework defaults. It defines every action the framework
knows about, so nothing is left unconfigured (an unconfigured action is NOT rate limited).
Raise or lower any value to fit your environment; if you run behind a shared NAT (university,
corporate, CGNAT) you may want higher ip_max values.

To override just a few values instead of the whole set, delete/comment the array below and use
the per-key style shown at the bottom of this file.
*/

$rateLimits = [
    // Authentication attempts
    'login_attempt' => [
        // Limits based on IP address. Higher avoids blocking legitimate users on shared networks (universities, corporate NATs).
        'ip_max' => 20,           // Max FAILED attempts from a single IP.
        'ip_window' => 900,       // 15-minute window for the IP failure count to reset.

        // Limits based on User ID/Username. Lower quickly blocks targeted brute-force on a specific account.
        'user_max' => 5,          // Max FAILED attempts for a single user account.
        'user_window' => 300,     // 5-minute window for a user's failure count to reset.

        // Circuit breaker for a single misbehaving identifier (IP or User). Limit on TOTAL attempts (successful + failed).
        'total_max' => 50,        // Max TOTAL attempts from one IP or for one User.
        'total_window' => 900     // 15-minute window.
    ],

    // Multi-Factor Authentication (MFA)
    'totp_verify' => [
        'ip_max' => 10,
        'ip_window' => 600,
        'user_max' => 5,
        'user_window' => 600,
        'total_max' => 30,
        'total_window' => 600
    ],

    'totp_verify_and_activate' => [
        'ip_max' => 5,
        'ip_window' => 3600,
        'user_max' => 3,
        'user_window' => 3600,
        'total_max' => 20,
        'total_window' => 3600
    ],

    'totp_regenerate_backup_codes' => [
        'ip_max' => 3,
        'ip_window' => 3600,
        'user_max' => 2,
        'user_window' => 3600,
        'total_max' => 10,
        'total_window' => 3600
    ],

    // Passkey operations
    'passkey_register' => [
        'ip_max' => 8,
        'ip_window' => 3600,
        'user_max' => 3,
        'user_window' => 3600,
        'total_max' => 25,
        'total_window' => 3600
    ],

    'passkey_verify' => [
        'ip_max'            => 30,
        'ip_window'         => 600,   // 30 fails / 10 min / IP
        'user_max'          => 10,
        'user_window'       => 600,   // 10 fails / 10 min / account
        'credential_max'    => 6,
        'credential_window' => 900,   // 6 fails / 15 min / cred
        'total_max'         => 100,
        'total_window'      => 900
    ],

    'passkey_store' => [
        'ip_max' => 8,
        'ip_window' => 3600,
        'user_max' => 3,
        'user_window' => 3600,
        'total_max' => 25,
        'total_window' => 3600
    ],

    // Passkey assertion-challenge issuance (login/step-up). Low sensitivity, but should not be spammed.
    'passkey_auth' => [
        'ip_max' => 30,
        'ip_window' => 600,
        'user_max' => 15,
        'user_window' => 600,
        'total_max' => 100,
        'total_window' => 900
    ],

    // Read-only passkey diagnostics endpoints — lenient, but bounded so they can't be hammered.
    'passkey_diagnostics' => [
        'ip_max' => 60,
        'ip_window' => 300,
        'user_max' => 30,
        'user_window' => 300,
        'total_max' => 200,
        'total_window' => 300
    ],

    'passkey_network-test' => [
        'ip_max' => 60,
        'ip_window' => 300,
        'user_max' => 30,
        'user_window' => 300,
        'total_max' => 200,
        'total_window' => 300
    ],

    // Password and account recovery
    'password_reset_request' => [
        'ip_max' => 5,
        'ip_window' => 3600,
        'email_max' => 3,
        'email_window' => 3600,
        'total_max' => 25,
        'total_window' => 3600
    ],

    'password_reset_submit' => [
        'ip_max' => 8,
        'ip_window' => 1800,
        'token_max' => 4,
        'token_window' => 1800,
        'total_max' => 30,
        'total_window' => 1800
    ],

    // Registration and verification
    'registration_attempt' => [
        'ip_max' => 5,
        'ip_window' => 3600,
        'total_max' => 20,
        'total_window' => 3600
    ],

    'email_verification' => [
        'ip_max' => 5,
        'ip_window' => 3600,
        'email_max' => 4,
        'email_window' => 3600,
        'total_max' => 30,
        'total_window' => 300
    ]
];

// You can also override individual limits instead of replacing the whole array above.
// $rateLimits['login_attempt']['ip_max'] = 50;
// $rateLimits['login_attempt']['ip_window'] = 1800;

// During development you may want much more lenient limits. Uncomment to multiply every _max by 100.
/*
if (defined('US_ENVIRONMENT') && US_ENVIRONMENT === 'development') {
    foreach ($rateLimits as $action => &$limits) {
        foreach ($limits as $key => &$value) {
            if (strpos($key, '_max') !== false) {
                $value = (int)($value * 100);
            }
        }
    }
    unset($limits, $value); // Clean up references
}
*/
