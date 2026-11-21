<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/app';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
            Route::middleware('web')->group(base_path('routes/erp.php'));
            // Portals and public pages of the React app (login, teacher, parent,
            // public forms, print documents). Each file sets its own auth middleware.
            foreach (glob(base_path('routes/portals/*.php')) as $portalRoutes) {
                Route::middleware('web')->group($portalRoutes);
            }
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        // Shared across all compatibility-credential endpoints. Never key on DOB,
        // a caller-controlled teacher code, or the container's proxy IP.
        RateLimiter::for('teacher-credentials', function (Request $request) {
            if (!$request->isMethod('post')) return Limit::none();
            if (app(\App\Modules\IdentityAccess\Services\SiteAccessPolicy::class)->isAdministrator($request->user())) return Limit::none();
            $teacher = $request->input('teacher_name', '');
            $teacher = is_string($teacher) ? mb_strtolower(trim($teacher)) : '';
            return Limit::perMinute(30)->by('teacher:'.hash('sha256', $teacher));
        });
        // Sales app requests all arrive from the Worker, so limit per signed-in user, not per IP.
        RateLimiter::for('sales-api', function (Request $request) {
            return Limit::perMinute(240)->by('sales-user:'.optional($request->user())->id);
        });
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}

