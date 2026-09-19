<?php

namespace App\Services\Notification;

use App\Enums\EmailDigestFrequency;
use App\Jobs\SendEmailJob;
use App\Mail\Notifications\NotificationDigestEmail;
use App\Models\Notification;
use App\Models\User;

/**
 * Builds and queues the unread-notifications summary email for users who
 * opted into a daily or weekly digest (`users.email_digest_frequency`).
 * Notifications the user already read, dismissed or snoozed are left out, so
 * the digest only ever lists what is still waiting for them.
 */
class NotificationDigestService
{
    /** How many notifications the email lists, the rest is summarized as a count. */
    private const MAX_LISTED = 10;

    /**
     * Queues one digest email per user on `$frequency` that has anything unread
     * inside its window. Returns how many emails were queued.
     */
    public function sendDue(EmailDigestFrequency $frequency): int
    {
        if ($frequency === EmailDigestFrequency::Off) {
            return 0;
        }

        $since = now()->subHours($frequency->windowInHours());
        $sent = 0;

        User::query()
            ->where('is_active', true)
            ->where('email_digest_frequency', $frequency->value)
            ->chunkById(100, function ($users) use ($since, $frequency, &$sent) {
                foreach ($users as $user) {
                    $unread = Notification::query()
                        ->where('user_id', $user->id)
                        ->visible()
                        ->unread()
                        ->where('type', '!=', Notification::TYPE_TEST)
                        ->where('created_at', '>=', $since)
                        ->with(['actor', 'board'])
                        ->orderByDesc('created_at')
                        ->get();

                    if ($unread->isEmpty()) {
                        continue;
                    }

                    SendEmailJob::dispatch(
                        new NotificationDigestEmail($user, $unread->take(self::MAX_LISTED), $unread->count(), $frequency),
                        $user->email,
                    );
                    $sent++;
                }
            });

        return $sent;
    }
}
