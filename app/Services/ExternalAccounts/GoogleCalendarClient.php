<?php

namespace App\Services\ExternalAccounts;

use App\Models\ExternalAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Google Calendar through its REST API: the calendars an account can write to, and creating,
 * updating and deleting the events a sync automation keeps for items.
 */
class GoogleCalendarClient
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    private const CALENDAR_CACHE_SECONDS = 60;

    public function __construct(private readonly ExternalApiClient $client) {}

    /**
     * Calendars the account may add events to, its primary calendar first.
     *
     * @return array<int, array{id: string, name: string, is_primary: bool}>
     *
     * @throws ExternalAccountException
     */
    public function writableCalendars(ExternalAccount $account, bool $fresh = false): array
    {
        $cache_key = "external_accounts:{$account->id}:calendars";
        if ($fresh) {
            Cache::forget($cache_key);
        }

        return Cache::remember($cache_key, self::CALENDAR_CACHE_SECONDS, function () use ($account) {
            $items = $this->client->call(
                $account,
                fn (PendingRequest $http) => $http->get(self::API.'/users/me/calendarList', ['minAccessRole' => 'writer', 'maxResults' => 250]),
                'Google Calendar could not list your calendars.',
            )->json('items') ?? [];

            $calendars = array_map(fn (array $item) => [
                'id' => (string) $item['id'],
                'name' => (string) ($item['summaryOverride'] ?? $item['summary'] ?? $item['id']),
                'is_primary' => (bool) ($item['primary'] ?? false),
            ], array_values(array_filter((array) $items, 'is_array')));

            usort($calendars, fn (array $a, array $b) => [$b['is_primary'], $a['name']] <=> [$a['is_primary'], $b['name']]);

            return $calendars;
        });
    }

    /**
     * Creates the event, or updates `$event_id` when given. An event deleted in Google meanwhile is
     * created again. Returns the event id.
     *
     * @param  array<string, mixed>  $event
     *
     * @throws ExternalAccountException
     */
    public function saveEvent(ExternalAccount $account, string $calendar_id, ?string $event_id, array $event): string
    {
        $events_url = self::API.'/calendars/'.rawurlencode($calendar_id).'/events';

        if ($event_id !== null) {
            try {
                $updated = $this->client->call(
                    $account,
                    fn (PendingRequest $http) => $http->patch($events_url.'/'.rawurlencode($event_id), $event),
                    'Google Calendar could not update the event.',
                )->json();

                // A deleted event can still be read with the status "cancelled" until Google purges it.
                if (($updated['status'] ?? null) !== 'cancelled') {
                    return (string) $updated['id'];
                }
            } catch (ExternalAccountException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }
            }
        }

        return (string) $this->client->call(
            $account,
            fn (PendingRequest $http) => $http->post($events_url, $event),
            'Google Calendar could not create the event.',
        )->json('id');
    }

    /**
     * Deletes the event, an event already gone counts as deleted.
     *
     * @throws ExternalAccountException
     */
    public function deleteEvent(ExternalAccount $account, string $calendar_id, string $event_id): void
    {
        try {
            $this->client->call(
                $account,
                fn (PendingRequest $http) => $http->delete(self::API.'/calendars/'.rawurlencode($calendar_id).'/events/'.rawurlencode($event_id)),
                'Google Calendar could not delete the event.',
            );
        } catch (ExternalAccountException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
        }
    }
}
