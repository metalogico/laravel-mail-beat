<?php

return [
    /*
     * OTLP/HTTP collector endpoint.
     * The package will POST to {endpoint}/v1/logs.
     */
    'endpoint' => env('MAIL_BEAT_ENDPOINT', 'http://localhost:4318'),

    /*
     * HTTP timeout in seconds for the collector request.
     */
    'timeout' => env('MAIL_BEAT_TIMEOUT', 2),

    /*
     * Anonymise the recipient (mail.to) before shipping to the collector.
     * When enabled, the local part of each address is replaced with asterisks
     * of the same length — e.g. brandolin@infofactory.it → *********@infofactory.it.
     * Enabled by default to comply with GDPR. Set to false only if your collector
     * is considered a trusted, compliant data processor.
     */
    'anonymize' => env('MAIL_BEAT_ANONYMIZE', true),

    /*
     * Queue configuration.
     * Set connection/queue to null to inherit the project default.
     * Example: set MAIL_BEAT_QUEUE_CONNECTION=redis and MAIL_BEAT_QUEUE=telemetry
     * to isolate beat jobs from the main application queue.
     */
    'queue' => [
        'connection' => env('MAIL_BEAT_QUEUE_CONNECTION'),   // null = project default
        'name'       => env('MAIL_BEAT_QUEUE', 'default'),
    ],
];
