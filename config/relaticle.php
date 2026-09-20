<?php

declare(strict_types=1);

return [

    'contact' => [
        'email' => env('CONTACT_EMAIL', 'hello@relaticle.com'),
    ],

    'enterprise' => [
        'starting_price_yearly' => 20_000,
    ],

    'company' => [
        'name' => env('RELATICLE_COMPANY_NAME', 'Relaticle'),
        'address' => env('RELATICLE_COMPANY_ADDRESS', ''),
    ],

    'deletion' => [
        'grace_period_days' => 30,
        'reminder_days_before' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspaces
    |--------------------------------------------------------------------------
    |
    | How many workspaces one user may own. Workspaces they were invited into
    | belong to someone else and never count. A workspace scheduled for
    | deletion still occupies a slot until the grace period above elapses.
    |
    */

    'workspaces' => [
        'max_owned_per_user' => (int) env('RELATICLE_MAX_OWNED_WORKSPACES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Access
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of email addresses allowed to open the Horizon
    | dashboard outside the local environment. Empty denies everyone, so a
    | deployment that never sets this exposes nothing.
    |
    */

    'horizon' => [
        'admin_emails' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('HORIZON_ADMIN_EMAILS', '')),
        ))),
    ],

    'features' => [
        'account_deletion' => (bool) env('RELATICLE_FEATURE_ACCOUNT_DELETION', false),
        'onboard_seed' => (bool) env('RELATICLE_FEATURE_ONBOARD_SEED', true),
        'social_auth' => (bool) env('RELATICLE_FEATURE_SOCIAL_AUTH', true),
        'documentation' => (bool) env('RELATICLE_FEATURE_DOCUMENTATION', true),
        'billing' => (bool) env('RELATICLE_FEATURE_BILLING', false),
        'signup_challenge' => (bool) env('RELATICLE_FEATURE_SIGNUP_CHALLENGE', false),
        'support_menu' => (bool) env('RELATICLE_FEATURE_SUPPORT_MENU', false),
        'blog' => (bool) env('RELATICLE_FEATURE_BLOG', false),
        'setup_conversation' => (bool) env('RELATICLE_FEATURE_SETUP_CONVERSATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Disks
    |--------------------------------------------------------------------------
    |
    | Uploads split into two classes. Logos and legacy rich-editor images are
    | served straight to the browser, so they live on the public disk. Pending
    | uploads are staged before they become media and must never be reachable
    | by URL, so they live on the private disk.
    |
    | The defaults reproduce the single-server layout. A deployment running
    | more than one application replica, or one with an ephemeral filesystem
    | such as Laravel Cloud, must point both at object storage: a request that
    | stages an upload on one replica is finished by another, and a local disk
    | does not survive a deploy. See docs/laravel-cloud.md.
    |
    */

    'storage' => [
        'public_disk' => (string) env('FILESYSTEM_PUBLIC_DISK', 'public'),
        'private_disk' => (string) env('FILESYSTEM_PRIVATE_DISK', 'local'),
    ],

];
