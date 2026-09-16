<?php

declare(strict_types=1);

return [
    'title' => 'Mailbox import complete',
    'body' => ':count emails imported from :email.',
    'retry_success' => [
        'title' => 'Import retry complete',
        'body' => 'Previously missing messages from :email are imported. :count emails are in Relaticle now.',
    ],
    'failures' => [
        'title' => 'Mailbox import finished with missing emails',
        'body' => ':count email(s) could not be imported from :email after automatic retries. Other emails were imported successfully.',
        'retry' => 'Retry failed imports',
        'unavailable' => 'No failed imports are available to retry for this import.',
    ],
    'mail' => [
        'subject' => 'Your mailbox import is complete',
        'retry_subject' => 'Your mailbox import retry succeeded',
        'greeting' => 'Hello :name,',
        'line' => 'We finished importing :count emails from :email. New mail will keep syncing automatically.',
        'retry_line' => 'We imported the messages that were missing from :email. :count emails are in Relaticle now.',
    ],
];
