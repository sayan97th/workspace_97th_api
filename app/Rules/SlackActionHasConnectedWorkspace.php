<?php

namespace App\Rules;

use App\Models\BoardAutomation;
use App\Models\SlackInstallation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Rejects a Slack automation action while no Slack workspace is connected, so the
 * automation is refused up front instead of silently doing nothing every time it fires.
 */
class SlackActionHasConnectedWorkspace implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (in_array($value, BoardAutomation::slackActions(), true) && SlackInstallation::current() === null) {
            $fail('Connect Slack in Administration before using a Slack action.');
        }
    }
}
