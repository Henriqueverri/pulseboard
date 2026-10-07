<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Creates (or refreshes) the public demo organization without touching any other data.
 *
 * Safe to run on every container start: without --refresh an existing demo keeps its data,
 * the demo passwords are synced with DEMO_PASSWORD, missing demo external ids are filled in
 * (a demo seeded before they existed) and the sales history is extended up to
 * today (only days after the latest sale are added). --refresh rebuilds the demo
 * organization's catalog and ~6-month history so it ends today again, and removes
 * the API keys visitors created and the demo accounts' access tokens. The command
 * itself never creates API keys or access tokens.
 *
 * While AI_ENABLED is on, the demo organization (and only it) is opted into
 * Insights on behalf of the demo owner, so a visitor who turned it off finds it on
 * again after the next start. With AI_ENABLED off the opt-in is left as it is,
 * like PUT /organization/insights, which refuses to enable it then.
 */
class DemoCommand extends Command
{
    public const ORGANIZATION_SLUG = 'pulseboard-demo';

    public const ORGANIZATION_NAME = 'PulseBoard Demo Store';

    private const MIN_PASSWORD_LENGTH = 12;

    protected $signature = 'pulseboard:demo
        {--refresh : Delete the demo organization\'s API keys, products, customers and transactions and the demo accounts\' access tokens, and seed them again}';

    protected $description = 'Create or refresh the public demo organization and its owner/member accounts';

    public function handle(): int
    {
        $password = config('demo.password');

        if (! is_string($password) || mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->error('Set DEMO_PASSWORD (at least '.self::MIN_PASSWORD_LENGTH.' characters) before seeding the demo.');

            return self::FAILURE;
        }

        $organization = Organization::query()->where('slug', self::ORGANIZATION_SLUG)->first();
        $accounts = [
            Organization::ROLE_OWNER => ['email' => config('demo.owner_email'), 'name' => 'Demo Owner'],
            Organization::ROLE_MEMBER => ['email' => config('demo.member_email'), 'name' => 'Demo Member'],
        ];

        if ($problem = $this->conflict($organization, $accounts)) {
            $this->error($problem);

            return self::FAILURE;
        }

        if ($organization !== null && ! $this->option('refresh')) {
            [$filled, $added] = DB::transaction(function () use ($organization, $accounts, $password): array {
                $this->syncAccounts($organization, $accounts, $password);
                $this->optIntoInsights($organization, $accounts[Organization::ROLE_OWNER]['email']);
                $seeder = new DemoDataSeeder;

                return [$seeder->backfillExternalIds($organization), $seeder->extend($organization)];
            });

            if ($filled > 0) {
                $this->info("Filled the missing external ids of {$filled} demo products and customers.");
            }

            $this->info($added > 0
                ? "Demo organization already exists; passwords synced and {$added} transactions added up to today."
                : 'Demo organization already exists; passwords synced and history already up to date. Use --refresh to rebuild its data.');

            return self::SUCCESS;
        }

        DB::transaction(function () use (&$organization, $accounts, $password): void {
            if ($organization === null) {
                $organization = Organization::query()->create([
                    'name' => self::ORGANIZATION_NAME,
                    'slug' => self::ORGANIZATION_SLUG,
                    'currency' => 'BRL',
                ]);
            } else {
                $this->wipe($organization, $accounts);
            }

            $this->syncAccounts($organization, $accounts, $password);
            $this->optIntoInsights($organization, $accounts[Organization::ROLE_OWNER]['email']);

            (new DemoDataSeeder)->setContainer($this->laravel)->__invoke(['organization' => $organization]);
        });

        $this->info(sprintf(
            'Demo organization seeded: %d products, %d customers, %d transactions.',
            $organization->products()->count(),
            $organization->customers()->count(),
            $organization->transactions()->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * The demo must never take over a real account or organization.
     *
     * @param  array<string, array{email: string, name: string}>  $accounts
     */
    private function conflict(?Organization $organization, array $accounts): ?string
    {
        if ($organization !== null) {
            $owner = User::query()->where('email', $accounts[Organization::ROLE_OWNER]['email'])->first();

            if ($owner === null || $owner->roleIn($organization) !== Organization::ROLE_OWNER) {
                return 'The slug "'.self::ORGANIZATION_SLUG.'" belongs to an organization not owned by the demo owner.';
            }
        }

        foreach ($accounts as ['email' => $email]) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                continue;
            }

            $otherOrganizations = $user->organizations()
                ->when($organization, fn ($query) => $query->whereKeyNot($organization->id))
                ->exists();

            if ($organization === null || $otherOrganizations) {
                return "{$email} is already used by an account outside the demo organization.";
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{email: string, name: string}>  $accounts
     */
    private function syncAccounts(Organization $organization, array $accounts, string $password): void
    {
        foreach ($accounts as $role => ['email' => $email, 'name' => $name]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill(['name' => $user->name ?? $name, 'password' => $password])->save();

            $organization->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);
        }
    }

    private function optIntoInsights(Organization $organization, string $ownerEmail): void
    {
        if (! config('ai.enabled') || $organization->insightsEnabled()) {
            return;
        }

        $organization->enableInsights(User::query()->where('email', $ownerEmail)->first());
        $this->info('Insights enabled for the demo organization.');
    }

    /**
     * @param  array<string, array{email: string, name: string}>  $accounts
     */
    private function wipe(Organization $organization, array $accounts): void
    {
        // Keys created by visitors go with the data they ingested.
        ApiKey::query()->forOrganization($organization)->delete();

        // The demo accounts are shared: visitors' devices sign in again after a refresh.
        PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', User::query()->whereIn('email', array_column($accounts, 'email'))->select('id'))
            ->delete();

        // Items cascade from transactions; they restrict product deletion, hence the order.
        Transaction::query()->forOrganization($organization)->delete();
        Customer::withTrashed()->forOrganization($organization)->forceDelete();
        Product::withTrashed()->forOrganization($organization)->forceDelete();
    }
}
