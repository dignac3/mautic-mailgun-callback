<?php

return [
    'name'        => 'Mailgun Webhook Handler',
    'description' => 'Handles Mailgun webhooks (bounces, spam complaints, unsubscribes) and updates Mautic DNC accordingly.',
    'version'     => '1.0.0',
    'author'      => 'Mautic Community',

    'parameters' => [
        'mailgun_webhook_signing_key' => '',
    ],
];

