# laravel-mail-beat

A Laravel package that intercepts every outgoing email and ships metadata to an OpenTelemetry-compatible collector via OTLP/HTTP. Install it across multiple Laravel applications to build a centralized email observability dashboard without touching your existing mail code.

**No migrations. No schema changes. No extra dependencies. A dead collector never breaks email delivery.**

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.1+ |
| Laravel | 10.x, 11.x, 12.x, 13.x |

---

## Installation

```bash
composer require metalogico/laravel-mail-beat
```

The package registers itself automatically via Laravel's package auto-discovery. No service provider registration required.

Add the collector endpoint to your `.env`:

```env
MAIL_BEAT_ENDPOINT=https://your-collector.example.com
```

That's it. Every email sent through Laravel's mail system will now emit an OTLP log record to your collector.

---

## Configuration

By default, no config file is needed. If you want to customise settings, publish the config:

```bash
php artisan vendor:publish --tag=mail-beat-config
```

This creates `config/mail-beat.php`:

```php
return [
    'endpoint'  => env('MAIL_BEAT_ENDPOINT', 'http://localhost:4318'),
    'timeout'   => env('MAIL_BEAT_TIMEOUT', 2),
    'anonymize' => env('MAIL_BEAT_ANONYMIZE', true),
    'queue' => [
        'connection' => env('MAIL_BEAT_QUEUE_CONNECTION'),
        'name'       => env('MAIL_BEAT_QUEUE', 'default'),
    ],
];
```

### Environment variables

| Variable | Default | Description |
|---|---|---|
| `MAIL_BEAT_ENDPOINT` | `http://localhost:4318` | Base URL of your OTLP/HTTP collector |
| `MAIL_BEAT_TIMEOUT` | `2` | HTTP timeout in seconds for collector requests |
| `MAIL_BEAT_ANONYMIZE` | `true` | Anonymize recipient addresses before shipping (see [Privacy & GDPR](#privacy--gdpr)) |
| `MAIL_BEAT_QUEUE_CONNECTION` | *(project default)* | Queue connection to use for beat jobs |
| `MAIL_BEAT_QUEUE` | `default` | Queue name to use for beat jobs |

---

## How it works

1. Laravel fires a `MessageSent` event after every outgoing email.
2. `MailSentListener` catches the event, extracts metadata, and dispatches a `SendMailBeatJob`.
3. The job builds an OTLP/HTTP JSON payload and POSTs it to `{MAIL_BEAT_ENDPOINT}/v1/logs`.
4. The entire HTTP call is wrapped in `rescue()` — any exception (network error, timeout, unreachable collector) is silently swallowed. Your email was already sent; the beat is best-effort.

The job inherits your project's queue configuration. If your app uses `sync`, the beat fires immediately in the same request. If your app uses `redis` or `database`, the beat is queued normally — no extra workers needed.

---

## Data sent to the collector

Each email produces one OTLP log record. The payload follows the [OTLP/HTTP JSON](https://opentelemetry.io/docs/specs/otlp/#otlphttp) specification.

### Resource attributes

Attached at the resource level, enabling cross-service aggregation in your dashboard:

| Attribute | Source |
|---|---|
| `service.name` | `config('app.name')` |
| `deployment.environment` | `config('app.env')` |

### Log record attributes

| Attribute | Description |
|---|---|
| `mail.to` | Recipient address(es), comma-separated. Anonymized by default (see [Privacy & GDPR](#privacy--gdpr)) |
| `mail.from` | Sender address(es), comma-separated |
| `mail.subject` | Email subject |
| `mail.size_bytes` | Full serialized message size in bytes |

The log record body is set to the email subject for human-readable display in log viewers.

### Example payload

```json
{
  "resourceLogs": [{
    "resource": {
      "attributes": [
        { "key": "service.name",            "value": { "stringValue": "my-app" } },
        { "key": "deployment.environment",  "value": { "stringValue": "production" } }
      ]
    },
    "scopeLogs": [{
      "scope": { "name": "laravel-mail-beat" },
      "logRecords": [{
        "timeUnixNano": "1712700000000000000",
        "severityText": "INFO",
        "body": { "stringValue": "Welcome to my-app!" },
        "attributes": [
          { "key": "mail.to",         "value": { "stringValue": "****@example.com" } },
          { "key": "mail.from",       "value": { "stringValue": "hello@my-app.com" } },
          { "key": "mail.subject",    "value": { "stringValue": "Welcome to my-app!" } },
          { "key": "mail.size_bytes", "value": { "intValue": 4821 } }
        ]
      }]
    }]
  }]
}
```

---

## Queue behaviour

The beat job respects your application's queue configuration. You can optionally isolate beat traffic to a dedicated queue to keep it separate from your main workload.

| `QUEUE_CONNECTION` | `MAIL_BEAT_QUEUE_CONNECTION` | Result |
|---|---|---|
| `sync` | *(not set)* | Beat fires synchronously in the same request |
| `database` | *(not set)* | Beat queued on `default` via database driver |
| `redis` | *(not set)* | Beat queued on `default` via redis driver |
| `redis` | `redis` + `MAIL_BEAT_QUEUE=telemetry` | Beat isolated on `telemetry` queue via redis |

To isolate beat jobs:

```env
MAIL_BEAT_QUEUE_CONNECTION=redis
MAIL_BEAT_QUEUE=telemetry
```

---

## Privacy & GDPR

By default, the local part of every recipient address is replaced with asterisks before the data leaves your application:

```
user@example.com  →  ****@example.com
```

The domain is preserved to allow domain-level analytics (e.g. "how many emails went to gmail.com?"). The exact address is never shipped to the collector.

This behaviour is enabled by default to align with GDPR's data minimisation principle. Recipient email addresses are personal data; the collector does not need them to provide observability value.

To disable anonymization (e.g. your collector is a trusted, compliant internal system):

```env
MAIL_BEAT_ANONYMIZE=false
```

> `mail.from` (the sender) is never anonymized, as it is typically a system address belonging to your own application.

---

## Out of scope

This package is a data producer only. The following are intentionally not supported:

- Email open / click tracking
- Storing emails in the local database
- Delivery status webhooks or callbacks
- Retry logic for failed collector requests
- Batching multiple emails into one OTLP request
- Any dashboard or UI

---

## License

MIT — see [LICENSE](LICENSE).
