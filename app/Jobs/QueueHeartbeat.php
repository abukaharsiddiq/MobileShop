<?php

namespace App\Jobs;

use App\Models\SystemHeartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

// Monitoring er chhoto job: worker chalu thakle heartbeat porbe (na porle alert).
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        SystemHeartbeat::beat('queue', ['queue' => $this->job?->getQueue()]);
    }
}
