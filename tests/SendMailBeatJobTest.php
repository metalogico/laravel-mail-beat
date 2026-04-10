<?php

namespace Metalogico\MailBeat\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Metalogico\MailBeat\Jobs\SendMailBeatJob;

class SendMailBeatJobTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function makePayload(array $overrides = []): array
    {
        return array_merge([
            'timeUnixNano'    => '1712700000000000000',
            'mail.to'         => 'recipient@example.com',
            'mail.from'       => 'sender@example.com',
            'mail.subject'    => 'Hello World',
            'mail.size_bytes' => 4821,
        ], $overrides);
    }

    private function dispatchAndCapture(array $payload): Request
    {
        Http::fake();

        (new SendMailBeatJob($payload))->handle();

        $requests = Http::recorded();
        $this->assertCount(1, $requests, 'Expected exactly one HTTP request.');

        return $requests[0][0];
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    public function test_posts_to_correct_endpoint(): void
    {
        $request = $this->dispatchAndCapture($this->makePayload());

        $this->assertSame('http://collector:4318/v1/logs', $request->url());
        $this->assertSame('POST', $request->method());
    }

    public function test_endpoint_trailing_slash_is_normalised(): void
    {
        config(['mail-beat.endpoint' => 'http://collector:4318/']);

        $request = $this->dispatchAndCapture($this->makePayload());

        $this->assertSame('http://collector:4318/v1/logs', $request->url());
    }

    public function test_resource_attributes_contain_service_name_and_env(): void
    {
        $request = $this->dispatchAndCapture($this->makePayload());

        $body       = $request->data();
        $attributes = $body['resourceLogs'][0]['resource']['attributes'];

        $this->assertContains(
            ['key' => 'service.name', 'value' => ['stringValue' => 'TestApp']],
            $attributes
        );

        $this->assertContains(
            ['key' => 'deployment.environment', 'value' => ['stringValue' => 'testing']],
            $attributes
        );
    }

    public function test_scope_name_is_laravel_mail_beat(): void
    {
        $request = $this->dispatchAndCapture($this->makePayload());

        $scope = $request->data()['resourceLogs'][0]['scopeLogs'][0]['scope'];

        $this->assertSame('laravel-mail-beat', $scope['name']);
    }

    public function test_log_record_body_is_subject(): void
    {
        $request = $this->dispatchAndCapture($this->makePayload());

        $body = $request->data()['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['body'];

        $this->assertSame(['stringValue' => 'Hello World'], $body);
    }

    public function test_log_record_severity_is_info(): void
    {
        $request = $this->dispatchAndCapture($this->makePayload());

        $record = $request->data()['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

        $this->assertSame('INFO', $record['severityText']);
    }

    public function test_time_unix_nano_is_string_in_payload(): void
    {
        $request = $this->dispatchAndCapture($this->makePayload());

        $record = $request->data()['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

        $this->assertIsString($record['timeUnixNano']);
        $this->assertSame('1712700000000000000', $record['timeUnixNano']);
    }

    public function test_log_record_attributes_contain_mail_fields(): void
    {
        $request = $this->dispatchAndCapture($this->makePayload());

        $attributes = $request->data()['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'];

        $this->assertContains(
            ['key' => 'mail.to', 'value' => ['stringValue' => 'recipient@example.com']],
            $attributes
        );
        $this->assertContains(
            ['key' => 'mail.from', 'value' => ['stringValue' => 'sender@example.com']],
            $attributes
        );
        $this->assertContains(
            ['key' => 'mail.subject', 'value' => ['stringValue' => 'Hello World']],
            $attributes
        );
    }

    public function test_size_bytes_uses_int_value(): void
    {
        $request = $this->dispatchAndCapture($this->makePayload(['mail.size_bytes' => 4821]));

        $attributes = $request->data()['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'];

        $this->assertContains(
            ['key' => 'mail.size_bytes', 'value' => ['intValue' => 4821]],
            $attributes
        );
    }

    public function test_silent_failure_when_http_throws(): void
    {
        Http::fake(fn () => throw new \Exception('Connection refused'));

        // Must not throw — rescue() swallows the exception
        (new SendMailBeatJob($this->makePayload()))->handle();

        $this->assertTrue(true); // reached here = silent failure works
    }
}
