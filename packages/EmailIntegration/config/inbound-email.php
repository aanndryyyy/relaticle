<?php

declare(strict_types=1);

return [
    'domain' => env('INBOUND_EMAIL_DOMAIN', 'relaticle.email'),
    'webhook_secret' => env('INBOUND_EMAIL_WEBHOOK_SECRET'),
    'max_raw_email_bytes' => (int) env('INBOUND_EMAIL_MAX_RAW_BYTES', 30 * 1024 * 1024),
    'max_attachment_bytes' => (int) env('INBOUND_EMAIL_MAX_ATTACHMENT_BYTES', 20 * 1024 * 1024),
    'max_attachments' => (int) env('INBOUND_EMAIL_MAX_ATTACHMENTS', 20),
    'raw_disk' => env('INBOUND_EMAIL_RAW_DISK', 'local'),
];
