<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;

class ConsoleSafetyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event) {

            // Allow destructive commands only in local or testing
            if (app()->environment(['local', 'testing'])) {
                return;
            }

            $blocked = [
                'migrate:fresh',
                'migrate:refresh',
                'migrate:reset',
                'db:wipe',
            ];

            if (in_array($event->command, $blocked, true)) {
                fwrite(STDERR, "\n❌ Blocked command '{$event->command}' outside local/testing.\n\n");
                exit(1);
            }
        });
    }
}
