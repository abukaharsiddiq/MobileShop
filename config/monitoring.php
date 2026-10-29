<?php

/*
| Monitoring ar alert. `monitor:check` (protii 5 minute, scheduler) check gulo chalay; critical/warning alert
| khole, email (ALERT_EMAILS) ar log channel e janay, thik hole "resolved".
*/

return [
    // koma diye: admin@shop.com,owner@shop.com
    'alert_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALERT_EMAILS', ''))))),

    // existing logging: 'stack' (ba 'slack' - LOG_SLACK_WEBHOOK_URL dile)
    'alert_log_channel' => env('ALERT_LOG_CHANNEL') ?: env('LOG_CHANNEL', 'stack'),

    // khola alert abar janano (minute)
    'renotify_minutes' => (int) env('ALERT_RENOTIFY_MINUTES', 120),

    'queues' => ['default', 'notifications'],

    'thresholds' => [
        'queue_stuck_minutes'     => 15,   // job eto minute dhore pending
        'queue_heartbeat_minutes' => 30,   // worker eto minute dhore chup
        'backup_max_age_hours'    => 26,
        'backup_verify_days'      => 8,
        'errors_per_hour'         => 20,   // er beshi hole critical
        'disk_free_mb'            => 1024,
        'integrity_max_age_hours' => 30,
    ],
];
