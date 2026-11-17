<?php

namespace App\Enums;

/**
 * An outside app a member connects their own account to from the Automations center, like
 * monday.com's Gmail, Outlook and Google Calendar integrations. Gmail and Google Calendar share
 * one Google account, so a member who connected Google for Gmail only grants the Calendar scopes
 * the first time they pick a Calendar recipe (Google merges them, see `include_granted_scopes`).
 */
enum ExternalService: string
{
    case Gmail = 'gmail';
    case GoogleCalendar = 'google_calendar';
    case Outlook = 'outlook';

    public function provider(): ExternalProvider
    {
        return match ($this) {
            self::Gmail, self::GoogleCalendar => ExternalProvider::Google,
            self::Outlook => ExternalProvider::Microsoft,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Gmail => 'Gmail',
            self::GoogleCalendar => 'Google Calendar',
            self::Outlook => 'Outlook',
        };
    }

    /**
     * The OAuth scopes this service needs on top of the provider's sign in scopes.
     *
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::Gmail => [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
            ],
            self::GoogleCalendar => [
                'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
                'https://www.googleapis.com/auth/calendar.events',
            ],
            self::Outlook => ['Mail.Read', 'Mail.Send'],
        };
    }

    /**
     * Whether members can pick and connect the service. Outlook is switched off until the client
     * asks for it: its accounts, automations and enum value stay, so existing rows keep loading,
     * but it is never offered or connected again.
     */
    public function isEnabled(): bool
    {
        return $this !== self::Outlook;
    }

    /**
     * Services whose accounts can be read for new emails or used to send them.
     *
     * @return array<int, self>
     */
    public static function mailServices(): array
    {
        return array_values(array_filter([self::Gmail, self::Outlook], fn (self $service) => $service->isEnabled()));
    }

    /**
     * The services members can connect today, in the order the Integrations dialog lists them.
     *
     * @return array<int, self>
     */
    public static function enabled(): array
    {
        return array_values(array_filter(self::cases(), fn (self $service) => $service->isEnabled()));
    }

    /**
     * @return array<int, string>
     */
    public static function enabledValues(): array
    {
        return array_map(fn (self $service) => $service->value, self::enabled());
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $service) => $service->value, self::cases());
    }
}
