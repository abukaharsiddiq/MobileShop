<?php

return [

    // Event: label ar template e ki ki placeholder deya jay
    'events' => [
        'sale_confirmation' => ['label' => 'Sale confirmation & receipt', 'vars' => ['customer', 'shop', 'invoice', 'total', 'paid', 'due', 'date']],
        'payment_received'  => ['label' => 'Payment received', 'vars' => ['customer', 'shop', 'invoice', 'amount', 'due', 'date', 'method']],
        'installment_due'   => ['label' => 'Installment due reminder', 'vars' => ['customer', 'shop', 'invoice', 'installment_no', 'amount', 'due_date']],
        'warranty_expiry'   => ['label' => 'Warranty expiry reminder', 'vars' => ['customer', 'shop', 'product', 'type', 'warranty_no', 'end_date']],
        'repair_received'   => ['label' => 'Repair received', 'vars' => ['customer', 'shop', 'job_no', 'device']],
        'repair_ready'      => ['label' => 'Repair ready to collect', 'vars' => ['customer', 'shop', 'job_no', 'device', 'total', 'due']],
        'repair_delivered'  => ['label' => 'Repair delivered', 'vars' => ['customer', 'shop', 'job_no', 'device', 'total', 'warranty_until']],
    ],

    'channels' => ['sms' => 'SMS', 'whatsapp' => 'WhatsApp'],

    // Provider driver: kon channel, ki credential lage (form er field; shob encrypted thake)
    'drivers' => [
        'bulksmsbd' => [
            'channel' => 'sms', 'label' => 'BulkSMSBD (bulksmsbd.net)', 'class' => \App\Services\Notifications\BulkSmsBdGateway::class,
            'fields' => ['api_key' => 'API key'],
        ],
        'http_sms' => [
            'channel' => 'sms', 'label' => 'Other SMS gateway (HTTP API)', 'class' => \App\Services\Notifications\HttpSmsGateway::class,
            'fields' => ['url' => 'API URL', 'method' => 'Method (GET/POST)', 'api_key_param' => 'API key parameter name', 'api_key' => 'API key',
                'to_param' => 'Number parameter name', 'message_param' => 'Message parameter name', 'sender_param' => 'Sender ID parameter name',
                'success_contains' => 'Text in a successful response'],
        ],
        'whatsapp_cloud' => [
            'channel' => 'whatsapp', 'label' => 'WhatsApp Cloud API (Meta)', 'class' => \App\Services\Notifications\WhatsAppCloudGateway::class,
            'fields' => ['phone_number_id' => 'Phone number ID', 'access_token' => 'Access token', 'template_name' => 'Approved template name (one {{1}} body variable)',
                'template_language' => 'Template language (e.g. en)', 'app_secret' => 'App secret (webhook signature)', 'verify_token' => 'Webhook verify token'],
        ],
    ],

    // Chesta: prothom bar + ei koyta (minute) por por; tarpor failed (hate abar pathano jay)
    'max_attempts' => (int) env('NOTIFY_MAX_ATTEMPTS', 3),
    'backoff_minutes' => [1, 5, 15],
    'queue' => env('NOTIFY_QUEUE', 'notifications'),

    // Reminder (daily command)
    'installment_days_before' => (int) env('NOTIFY_INSTALLMENT_DAYS_BEFORE', 2),
    'installment_overdue_every_days' => (int) env('NOTIFY_INSTALLMENT_OVERDUE_EVERY', 7),
    'warranty_days_before' => (int) env('NOTIFY_WARRANTY_DAYS_BEFORE', 7),

];
