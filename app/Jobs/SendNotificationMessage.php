<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ekta message pathano (Laravel queue). Abar chesta NotificationService nije delay diye notun job e kore;
 * ei job ekbar-i chole (worker crash e message "sending" thake - log e dekha jay, hate abar pathano jay).
 */
class SendNotificationMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public int $messageId) {}

    public function handle(NotificationService $notifications): void
    {
        $notifications->deliver($this->messageId);
    }
}
