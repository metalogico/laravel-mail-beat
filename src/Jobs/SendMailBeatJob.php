<?php

namespace Metalogico\MailBeat\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendMailBeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $payload) {}

    public function handle(): void
    {
        rescue(function () {
            $body = [
                'resourceLogs' => [
                    [
                        'resource' => [
                            'attributes' => [
                                [
                                    'key'   => 'service.name',
                                    'value' => ['stringValue' => config('app.name')],
                                ],
                                [
                                    'key'   => 'deployment.environment',
                                    'value' => ['stringValue' => config('app.env')],
                                ],
                            ],
                        ],
                        'scopeLogs' => [
                            [
                                'scope' => [
                                    'name' => 'laravel-mail-beat',
                                ],
                                'logRecords' => [
                                    [
                                        'timeUnixNano' => $this->payload['timeUnixNano'],
                                        'severityText' => 'INFO',
                                        'body'         => ['stringValue' => $this->payload['mail.subject']],
                                        'attributes'   => [
                                            [
                                                'key'   => 'mail.to',
                                                'value' => ['stringValue' => $this->payload['mail.to']],
                                            ],
                                            [
                                                'key'   => 'mail.from',
                                                'value' => ['stringValue' => $this->payload['mail.from']],
                                            ],
                                            [
                                                'key'   => 'mail.subject',
                                                'value' => ['stringValue' => $this->payload['mail.subject']],
                                            ],
                                            [
                                                'key'   => 'mail.size_bytes',
                                                'value' => ['intValue' => $this->payload['mail.size_bytes']],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            Http::timeout(config('mail-beat.timeout'))
                ->withHeader('Content-Type', 'application/json')
                ->post(rtrim(config('mail-beat.endpoint'), '/').'/v1/logs', $body);
        });
    }
}
