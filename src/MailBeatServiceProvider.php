<?php

namespace Metalogico\MailBeat;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Metalogico\MailBeat\Listeners\MailSentListener;

class MailBeatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/mail-beat.php',
            'mail-beat'
        );
    }

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
}
