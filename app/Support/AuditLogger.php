<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Request;

/**
 * Writes one immutable {@see AuditLog} row per call. Called from every Administration
 * action worth a paper trail: role changes, user ban/unban, department CRUD, board
 * ownership reassignment, panic mode activate/deactivate, authentication-policy changes,
 * and forced session logouts.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function log(string $event, string $description, ?User $actor = null, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $actor?->id,
            'event' => $event,
            'description' => $description,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
