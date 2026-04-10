<?php

namespace Metalogico\MailBeat\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Metalogico\MailBeat\Jobs\SendMailBeatJob;
use Symfony\Component\Mime\Address;

class MailSentListener
{
    public function handle(MessageSent $event): void
    {
        $message   = $event->message;
        $anonymize = config('mail-beat.anonymize', true);

        $to = implode(', ', array_map(
            function (Address $address) use ($anonymize): string {
                $addr = $address->getAddress();
                return $anonymize ? $this->anonymize($addr) : $addr;
            },
            $message->getTo() ?? []
        ));

        $from = implode(', ', array_map(
            fn (Address $address) => $address->getAddress(),
            $message->getFrom() ?? []
        ));

        $payload = [
            'timeUnixNano' => (string) (now()->timestamp * 1_000_000_000),
            'mail.to'      => $to,
            'mail.from'    => $from,
            'mail.subject' => $message->getSubject() ?? '',
            'mail.size_bytes' => strlen($message->toString()),
        ];

        dispatch(new SendMailBeatJob($payload))
            ->onConnection(config('mail-beat.queue.connection'))
            ->onQueue(config('mail-beat.queue.name'));
    }

    private function anonymize(string $address): string
    {
        [$local, $domain] = explode('@', $address, 2);

        return str_repeat('*', strlen($local)) . '@' . $domain;
    }
}
