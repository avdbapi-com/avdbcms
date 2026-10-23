<?php

namespace App\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Support\Facades\Mail;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;

class BackgroundEventSubscriber
{
    /**
     * Handle user have new subscriber events.
     */
    public function sendJobFailedEmail(JobFailed $event): void {

        $message = $event->exception->getMessage(). ' at ' . $event->exception->getFile(). ':' .$event->exception->getLine();

        $this->sendFailureMail($message, $event->job->resolveName(). ' - FAILED');
    }

    /**
     * Handle user have new subscriber events.
     */
    public function sendScheduledTaskFailedEmail(ScheduledTaskFailed $event): void {

        $message = $event->exception->getMessage(). ' at ' . $event->exception->getFile(). ':' .$event->exception->getLine();

        $this->sendFailureMail($message, $event->task->mutexName(). ' - FAILED');
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            JobFailed::class => 'sendJobFailedEmail',
            ScheduledTaskFailed::class => 'sendScheduledTaskFailedEmail',
        ];
    }

    private function sendFailureMail(string $message, string $subject): void
    {
        try {
            Mail::raw($message, function (Message $mail) use ($subject) {
                $mail->to(config('mail.server_mail'))->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('Background failure email could not be sent: '.$e->getMessage());
        }
    }
}
