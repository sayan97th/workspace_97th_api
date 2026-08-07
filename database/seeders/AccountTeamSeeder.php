<?php

namespace Database\Seeders;

use App\Models\AccountTeam;
use App\Models\User;
use Illuminate\Database\Seeder;

class AccountTeamSeeder extends Seeder
{
    /**
     * Job titles for the pre-existing "97th Floor" staff roster, keyed by
     * email — mirrors the frontend's now-retired `teams-data.ts` mock so the
     * real Teams view looks identical to the mock it replaces.
     *
     * @var array<string, string>
     */
    private array $job_titles = [
        'josh.moody@97thfloor.com' => 'VP of Client Services',
        'blake.denton@97thfloor.com' => 'Head of Accounts',
        'brandon.stewart@97thfloor.com' => 'Account Director',
        'rachel.tonkovich@97thfloor.com' => 'Head of HR',
        'paxton.gray@97thfloor.com' => 'Account Manager',
        'hayley.robinson@97thfloor.com' => 'Account Manager',
        'sam.rivera@97thfloor.com' => 'Head of IT',
        'haley.brooks@97thfloor.com' => 'Finance Manager',
        'jon.mattingly@97thfloor.com' => 'Guest Consultant',
        'danny.olsen@97thfloor.com' => 'Head of Search',
        'mike.powell@97thfloor.com' => 'Head of Design',
        'jasmin.cole@97thfloor.com' => 'Marketing Consultant',
        'kate.sherwood@97thfloor.com' => 'Content Strategist',
        'devin.marsh@97thfloor.com' => 'SEO Specialist',
        'nora.fields@97thfloor.com' => 'Creative Director',
        'owen.hart@97thfloor.com' => 'PPC Specialist',
        'priya.nair@97thfloor.com' => 'Partner Consultant',
        'liam.foster@97thfloor.com' => 'Web Developer',
        'maya.ortiz@97thfloor.com' => 'Project Manager',
    ];

    /**
     * The account owner shown with the "OWNER" badge in the Teams member
     * table — mirrors the mock's `TEAMS_OWNER_ID`.
     */
    private string $owner_email = 'josh.moody@97thfloor.com';

    /**
     * Seed teams, mirroring the frontend's retired `TEAMS_SEED` mock.
     *
     * @var array<int, array{name: string, member_emails: array<int, string>}>
     */
    private array $teams = [
        [
            'name' => 'Account Directors',
            'member_emails' => [
                'josh.moody@97thfloor.com',
                'blake.denton@97thfloor.com',
                'brandon.stewart@97thfloor.com',
                'rachel.tonkovich@97thfloor.com',
                'paxton.gray@97thfloor.com',
                'hayley.robinson@97thfloor.com',
            ],
        ],
        [
            'name' => 'Department Heads',
            'member_emails' => [
                'blake.denton@97thfloor.com',
                'danny.olsen@97thfloor.com',
                'mike.powell@97thfloor.com',
                'nora.fields@97thfloor.com',
            ],
        ],
        [
            'name' => 'Team Josh',
            'member_emails' => [
                'josh.moody@97thfloor.com',
                'hayley.robinson@97thfloor.com',
                'paxton.gray@97thfloor.com',
                'jasmin.cole@97thfloor.com',
            ],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->job_titles as $email => $job_title) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                continue;
            }

            $user->update(['job_title' => $job_title]);
            $user->assignRole($email === $this->owner_email ? 'super_admin' : 'staff');
        }

        $creator_id = User::where('email', 'ernesto@97thfloor.com')->value('id');

        foreach ($this->teams as $team_data) {
            $team = AccountTeam::firstOrCreate(
                ['name' => $team_data['name']],
                ['created_by_id' => $creator_id],
            );

            $member_ids = User::whereIn('email', $team_data['member_emails'])->pluck('id');
            $team->members()->syncWithoutDetaching($member_ids);
        }
    }
}
