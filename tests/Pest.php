<?php

use App\Models\SlackAppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Saves the Slack app credentials the way an administrator does from Administration >
 * Integrations, the only place the Slack integration reads them from.
 */
function saveSlackAppCredentials(array $overrides = []): SlackAppSetting
{
    SlackAppSetting::query()->delete();

    return SlackAppSetting::create(array_merge([
        'client_id' => '1234.5678',
        'client_secret' => 'client-secret',
        'signing_secret' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'redirect_uri' => 'https://api.example.com/api/integrations/slack/callback',
    ], $overrides));
}
