<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Policies\ApiKeyPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ProductPolicy;
use App\Policies\TransactionPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public const INGEST_REQUESTS_PER_MINUTE = 120;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(ApiKey::class, ApiKeyPolicy::class);

        // SPA-only authentication: there are no personal access tokens, so a bearer
        // token on an internal route must never be looked up (it would be a 500).
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);

        // Per key, so one integration cannot exhaust another's budget.
        RateLimiter::for('ingest', function (Request $request): Limit {
            $apiKey = $request->attributes->get('api_key');

            return Limit::perMinute(self::INGEST_REQUESTS_PER_MINUTE)
                ->by($apiKey instanceof ApiKey ? 'api-key:'.$apiKey->id : 'ip:'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many requests.',
                    'code' => 'rate_limited',
                ], 429, $headers));
        });
    }
}
