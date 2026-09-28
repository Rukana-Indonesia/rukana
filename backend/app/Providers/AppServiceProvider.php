<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\Announcement;
use App\Models\Complaint;
use App\Models\LetterRequest;
use App\Models\MonthlyInsight;
use App\Models\Rt;
use App\Models\Rw;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\Village;
use App\Support\Phone;
use App\Support\Scope\ScopeResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ScopeResolver::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Nama tipe di kolom polimorfik (ratings, audit_logs, notifications, ai_tool_calls)
        // tidak boleh bergantung pada nama kelas PHP.
        Relation::enforceMorphMap([
            'user' => User::class,
            'village' => Village::class,
            'rw' => Rw::class,
            'rt' => Rt::class,
            'service_type' => ServiceType::class,
            'letter_request' => LetterRequest::class,
            'complaint' => Complaint::class,
            'announcement' => Announcement::class,
            'monthly_insight' => MonthlyInsight::class,
        ]);

        // RW/RT hanya dikelola operator di desanya sendiri.
        Gate::define('manage-region', fn (User $user, Rw|Rt $region) => $user->role === Role::Operator
            && $region->village_id === $user->village_id);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.Phone::normalize((string) $request->input('phone')).'|'.$request->ip()),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        RateLimiter::for('ticket-check', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
