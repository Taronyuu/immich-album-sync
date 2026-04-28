<?php

namespace App\Providers;

use App\Auth\ImmichUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Auth::provider('immich', fn () => new ImmichUserProvider());
    }
}
