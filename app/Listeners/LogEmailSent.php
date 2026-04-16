<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSent;

class LogEmailSent
{
    /**
     * Handle the MessageSent event.
     * Updates the log entry to sent status.
     */
    public function handle(MessageSent $event): void
    {
        try {
            $message = $event->message;
            $headers = $message->getHeaders();

            if (!$headers->has(EmailLog::LOG_ID_HEADER)) {
                return;
            }

            $header = $headers->get(EmailLog::LOG_ID_HEADER);
            $logId = $header && method_exists($header, 'getBodyAsString')
                ? trim($header->getBodyAsString())
                : null;

            if ($logId && $log = EmailLog::find($logId)) {
                $log->update(['status' => EmailLog::STATUS_SENT]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Email log (sent) failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
