# laravel-mail-beat — Technical Specification

## Overview

`laravel-mail-beat` is a Laravel package that intercepts every outgoing email and ships metadata to an OpenTelemetry-compatible collector via OTLP/HTTP (JSON format). It is designed to be installed across multiple Laravel projects to enable a centralized, aggregated email observability dashboard.

**Key principles:**
- Zero friction: no DB migrations, no schema changes, no extra dependencies
- Silent failure: a dead collector must never break email delivery
- Inherits queue behavior from the host project (no opinion on transport)
- No PHP `ext-opentelemetry` required — pure HTTP + JSON

---

## Package Identity

| Field                 | Value                        |
|-----------------------|------------------------------|
| Package name          | `{vendor}/laravel-mail-beat` |
| Namespace             | `{Vendor}\MailBeat`          |
| Laravel compatibility | 10.x, 11.x, 12.x             |
| PHP compatibility     | 8.1+                         |
| License               | MIT                          |

---

## File Structure

```
laravel-mail-beat/
├── composer.json
├── README.md
├── config/
│   └── mail-beat.php
└── src/
    ├── MailBeatServiceProvider.php
    ├── Jobs/
    │   └── SendMailBeatJob.php
    └── Listeners/
        └── MailSentListener.php
```

---

## Config File

**Path:** `config/mail-beat.php`

```php
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
```

---

## Service Provider

**Class:** `MailBeatServiceProvider extends ServiceProvider`

Responsibilities:
1. Register config file (`mail-beat.php`) with `mergeConfigFrom`
2. Publish config via `php artisan vendor:publish --tag=mail-beat-config`
3. Register `MailSentListener` on `Illuminate\Mail\Events\MessageSent`

```php
public function boot(): void
{
    $this->publishes([
        __DIR__.'/../config/mail-beat.php' => config_path('mail-beat.php'),
    ], 'mail-beat-config');

    Event::listen(
        MessageSent::class,
        MailSentListener::class
    );
}
```

Auto-discovery must be configured in `composer.json`:

```json
"extra": {
    "laravel": {
        "providers": [
            "{Vendor}\\MailBeat\\MailBeatServiceProvider"
        ]
    }
}
```

---

## Listener

**Class:** `MailSentListener`

**Event:** `Illuminate\Mail\Events\MessageSent`

Extracts metadata from the sent message and dispatches `SendMailBeatJob`.

**Metadata to extract:**

| Field | Source | OTLP attribute key |
|---|---|---|
| To addresses | `$message->getTo()` | `mail.to` |
| From addresses | `$message->getFrom()` | `mail.from` |
| Subject | `$message->getSubject()` | `mail.subject` |
| Message size (bytes) | `strlen($message->toString())` | `mail.size_bytes` |
| Timestamp (Unix nano) | `now()->timestamp * 1_000_000_000` | `timeUnixNano` |

**Important notes:**
- `$event->message` is a `Symfony\Component\Mime\Email` object
- `getTo()` and `getFrom()` return arrays of `Symfony\Component\Mime\Address`; extract via `->getAddress()` and `->getName()`
- Multiple recipients must be serialized as comma-separated strings

**Dispatch:**

```php
dispatch(new SendMailBeatJob($payload))
    ->onConnection(config('mail-beat.queue.connection'))
    ->onQueue(config('mail-beat.queue.name'));
```

---

## Job

**Class:** `SendMailBeatJob implements ShouldQueue`

**Constructor:** accepts `array $payload` (the pre-built log record attributes)

**handle() method responsibilities:**
1. Build the full OTLP/HTTP JSON payload (see format below)
2. POST to `{endpoint}/v1/logs` via `Illuminate\Support\Facades\Http`
3. Wrap everything in `rescue()` — silent failure on any exception

**Queue behavior:** inherits `QUEUE_CONNECTION` from the host project. If `sync`, executes immediately. If `database`, `redis`, etc., queues normally. No retry logic in the package — let the host project's queue configuration decide.

---

## OTLP/HTTP Payload Format

The job builds and sends the following JSON to `{endpoint}/v1/logs`:

```json
{
  "resourceLogs": [
    {
      "resource": {
        "attributes": [
          {
            "key": "service.name",
            "value": { "stringValue": "<APP_NAME from config('app.name')>" }
          },
          {
            "key": "deployment.environment",
            "value": { "stringValue": "<APP_ENV from config('app.env')>" }
          }
        ]
      },
      "scopeLogs": [
        {
          "scope": {
            "name": "laravel-mail-beat"
          },
          "logRecords": [
            {
              "timeUnixNano": "<unix timestamp in nanoseconds as string>",
              "severityText": "INFO",
              "body": {
                "stringValue": "<email subject>"
              },
              "attributes": [
                { "key": "mail.to",         "value": { "stringValue": "user@example.com" } },
                { "key": "mail.from",        "value": { "stringValue": "app@example.com" } },
                { "key": "mail.subject",     "value": { "stringValue": "Welcome!" } },
                { "key": "mail.size_bytes",  "value": { "intValue": 4821 } }
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

**Notes:**
- `service.name` and `deployment.environment` go in `resource.attributes` (not log attributes) — this is the correct OTLP level for resource-level metadata and enables cross-service aggregation in dashboards
- `timeUnixNano` must be a **string** (not integer) per OTLP spec — JavaScript/JSON cannot represent nanosecond precision as a number without precision loss
- `body.stringValue` should be the email subject — it's the most human-readable summary for log viewers
- `scope.name` identifies the instrumentation library

---

## HTTP Request Details

| Property       | Value                                       |
|----------------|---------------------------------------------|
| Method         | `POST`                                      |
| URL            | `{config('mail-beat.endpoint')}/v1/logs`    |
| Headers        | `Content-Type: application/json`            |
| Timeout        | `config('mail-beat.timeout')` seconds       |
| Error handling | `rescue()` swallows all exceptions silently |

---

## Environment Variables

| Variable                     | Default                    | Description                    |
|------------------------------|----------------------------|--------------------------------|
| `MAIL_BEAT_ENDPOINT`         | `http://localhost:4318`    | OTLP collector URL             |
| `MAIL_BEAT_TIMEOUT`          | `2`                        | HTTP timeout in seconds        |
| `MAIL_BEAT_QUEUE_CONNECTION` | *(null — project default)* | Queue connection for beat jobs |
| `MAIL_BEAT_QUEUE`            | `default`                  | Queue name for beat jobs       |

---

## composer.json Requirements

```json
{
    "require": {
        "php": "^8.1",
        "laravel/framework": "^10.0|^11.0|^12.0"
    },
    "require-dev": {
        "orchestra/testbench": "^8.0|^9.0"
    }
}
```

No additional dependencies. Uses only Laravel's built-in `Http` facade and `Queue` system.

---

## Installation (end user)

```bash
composer require {vendor}/laravel-mail-beat
```

Optional — publish config only if customization is needed:

```bash
php artisan vendor:publish --tag=mail-beat-config
```

Add to `.env`:

```
MAIL_BEAT_ENDPOINT=https://your-collector.example.com
```

That's it. No migrations, no service registration, no code changes required.

---

## Behavior Matrix

| `QUEUE_CONNECTION` | `MAIL_BEAT_QUEUE_CONNECTION`          | Result                                       |
|--------------------|---------------------------------------|----------------------------------------------|
| `sync`             | *(not set)*                           | Beat fires synchronously, same request       |
| `database`         | *(not set)*                           | Beat queued on `default` queue via database  |
| `redis`            | *(not set)*                           | Beat queued on `default` queue via redis     |
| `redis`            | `redis` + `MAIL_BEAT_QUEUE=telemetry` | Beat isolated on `telemetry` queue via redis |

---

## What Is NOT In Scope

- Email open/click tracking (pixel tracking)
- Storing emails in the local database
- Webhooks or delivery status callbacks
- Retry logic for failed beat deliveries
- Batching multiple emails into a single OTLP request
- Any dashboard or UI — the package is a data producer only
- `ext-opentelemetry` PHP extension dependency
