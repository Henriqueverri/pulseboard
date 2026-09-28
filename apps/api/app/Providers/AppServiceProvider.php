<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Policies\CustomerPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ProductPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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
    }
}
