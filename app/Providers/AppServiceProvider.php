<?php

namespace App\Providers;

use App\Payments\MockPaymentProvider;
use App\Payments\PaymentProviderContract;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap this binding for a real gateway adapter in production. Everything above
        // the PaymentProviderContract interface (PayoutService, jobs, tests) is written
        // against the contract only and has no knowledge that this is a mock.
        $this->app->singleton(PaymentProviderContract::class, MockPaymentProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
