<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('feed:publish-scheduled')->everyMinute();

Schedule::command('automations:run-date-triggers')->dailyAt('08:00');

Schedule::command('board:send-due-date-reminders')->dailyAt('08:00');

Schedule::command('items:run-recurrences')->dailyAt('06:00');

Schedule::command('notifications:wake-snoozed')->everyMinute();

Schedule::command('notifications:send-digests daily')->dailyAt('08:00');

Schedule::command('notifications:send-digests weekly')->weeklyOn(1, '08:00');
