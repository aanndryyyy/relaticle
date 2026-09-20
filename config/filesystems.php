<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        // Laravel Cloud injects AWS_ENDPOINT_URL and AWS_REGION for the bucket it
        // attaches as the default disk; the Laravel skeleton reads AWS_ENDPOINT and
        // AWS_DEFAULT_REGION. Accept either so an attached bucket works untouched.
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', env('AWS_REGION')),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT', env('AWS_ENDPOINT_URL')),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        /*
         * Second bucket for browser-readable uploads, kept separate because a
         * Cloudflare R2 bucket carries one visibility for every object in it and
         * cannot mix public and private files. Credentials are set by hand: only
         * the default bucket is injected automatically.
         *
         * No 'visibility' key here. R2 rejects per-object ACL headers with a
         * NotImplemented error; the bucket itself is what grants public reads.
         */
        's3_public' => [
            'driver' => 's3',
            'key' => env('AWS_PUBLIC_ACCESS_KEY_ID'),
            'secret' => env('AWS_PUBLIC_SECRET_ACCESS_KEY'),
            'region' => env('AWS_PUBLIC_DEFAULT_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('AWS_PUBLIC_BUCKET'),
            'url' => env('AWS_PUBLIC_URL'),
            'endpoint' => env('AWS_PUBLIC_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_PUBLIC_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
