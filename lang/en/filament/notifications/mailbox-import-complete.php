<?php

declare(strict_types=1);

return [
    'title' => 'Mailbox import complete',
    'body' => ':count emails imported from :email.',
    'retry_success' => [
        'title' => 'Import retry complete',
        'body' => 'Previously missing messages from :email are imported. :count emails are in Relaticle now.',
    ],
    'mail' => [
        'subject' => 'Your mailbox import is complete',
        'retry_subject' => 'Your mailbox import retry succeeded',
        'greeting' => 'Hello :name,',
        'line' => 'We finished importing :count emails from :email. New mail will keep syncing automatically.',
        'retry_line' => 'We imported the messages that were missing from :email. :count emails are in Relaticle now.',
    ],
];
