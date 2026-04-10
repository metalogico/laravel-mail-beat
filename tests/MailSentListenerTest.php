<?php

namespace Metalogico\MailBeat\Tests;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Queue;
use Metalogico\MailBeat\Jobs\SendMailBeatJob;
use Metalogico\MailBeat\Listeners\MailSentListener;
use ReflectionProperty;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MailSentListenerTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function makeEvent(Email $email): MessageSent
    {
        $from = $email->getFrom();
        $to   = $email->getTo();

        $envelope = new Envelope(
            new Address($from[0]->getAddress()),
            array_map(fn (Address $a) => new Address($a->getAddress()), $to)
        );

        return new MessageSent(new SentMessage($email, $envelope));
    }

    private function jobPayload(SendMailBeatJob $job): array
    {
        $prop = new ReflectionProperty(SendMailBeatJob::class, 'payload');
        $prop->setAccessible(true);

        return $prop->getValue($job);
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    public function test_dispatches_send_mail_beat_job(): void
    {
        Queue::fake();

        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Hello World')
            ->text('body');

        (new MailSentListener)->handle($this->makeEvent($email));

        Queue::assertPushed(SendMailBeatJob::class);
    }

    public function test_payload_contains_correct_from_and_anonymized_to(): void
    {
        Queue::fake();

        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Subject')
            ->text('body');

        (new MailSentListener)->handle($this->makeEvent($email));

        Queue::assertPushed(SendMailBeatJob::class, function (SendMailBeatJob $job) {
            $payload = $this->jobPayload($job);

            // mail.from is never anonymized; mail.to local part becomes asterisks
            return $payload['mail.to']   === '*********@example.com'
                && $payload['mail.from'] === 'sender@example.com';
        });
    }

    public function test_multiple_recipients_are_comma_separated_and_anonymized(): void
    {
        Queue::fake();

        $email = (new Email())
            ->from('sender@example.com')
            ->to('alice@example.com', 'bob@example.com')
            ->subject('Subject')
            ->text('body');

        (new MailSentListener)->handle($this->makeEvent($email));

        Queue::assertPushed(SendMailBeatJob::class, function (SendMailBeatJob $job) {
            $payload = $this->jobPayload($job);

            return $payload['mail.to'] === '*****@example.com, ***@example.com';
        });
    }

    public function test_anonymization_preserves_local_part_length(): void
    {
        Queue::fake();

        $email = (new Email())
            ->from('sender@example.com')
            ->to('brandolin@infofactory.it')
            ->subject('Subject')
            ->text('body');

        (new MailSentListener)->handle($this->makeEvent($email));

        Queue::assertPushed(SendMailBeatJob::class, function (SendMailBeatJob $job) {
            $to = $this->jobPayload($job)['mail.to'];
            [$local] = explode('@', $to);

            return $local === '*********' // strlen('brandolin') === 9
                && str_ends_with($to, '@infofactory.it');
        });
    }

    public function test_anonymization_can_be_disabled(): void
    {
        Queue::fake();

        config(['mail-beat.anonymize' => false]);

        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Subject')
            ->text('body');

        (new MailSentListener)->handle($this->makeEvent($email));

        Queue::assertPushed(SendMailBeatJob::class, function (SendMailBeatJob $job) {
            return $this->jobPayload($job)['mail.to'] === 'recipient@example.com';
        });
    }

    public function test_payload_contains_subject(): void
    {
        Queue::fake();

        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('My Subject')
            ->text('body');

        (new MailSentListener)->handle($this->makeEvent($email));

        Queue::assertPushed(SendMailBeatJob::class, function (SendMailBeatJob $job) {
            return $this->jobPayload($job)['mail.subject'] === 'My Subject';
        });
    }

    public function test_payload_contains_positive_size_bytes(): void
    {
        Queue::fake();

        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Subject')
            ->text('body');

        (new MailSentListener)->handle($this->makeEvent($email));

        Queue::assertPushed(SendMailBeatJob::class, function (SendMailBeatJob $job) {
            $sizeBytes = $this->jobPayload($job)['mail.size_bytes'];

            return is_int($sizeBytes) && $sizeBytes > 0;
        });
    }

    public function test_payload_time_unix_nano_is_string(): void
    {
        Queue::fake();

        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Subject')
            ->text('body');

        (new MailSentListener)->handle($this->makeEvent($email));

        Queue::assertPushed(SendMailBeatJob::class, function (SendMailBeatJob $job) {
            return is_string($this->jobPayload($job)['timeUnixNano']);
        });
    }

    public function test_job_is_dispatched_on_configured_queue(): void
    {
        Queue::fake();

        config(['mail-beat.queue.name' => 'telemetry']);

        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Subject')
            ->text('body');

        (new MailSentListener)->handle($this->makeEvent($email));

        Queue::assertPushed(SendMailBeatJob::class, function (SendMailBeatJob $job) {
            return $job->queue === 'telemetry';
        });
    }
}
