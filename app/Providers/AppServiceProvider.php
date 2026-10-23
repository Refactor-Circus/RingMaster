<?php

namespace App\Providers;

use App\Enums\AttentionStatus;
use App\Enums\TaskStatus;
use App\Models\AttentionItem;
use App\Models\Task;
use App\Runner\Runner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureDefaults();

        View::composer(['layouts.app', 'layouts::app'], function (\Illuminate\View\View $view): void {
            $view->with('waitingOnYou', AttentionItem::query()
                ->where('status', AttentionStatus::Open)
                ->whereHas('spec.project.workspace', fn ($query) => $query->where('enabled', true))
                ->count());
            $view->with('queued', Task::query()->where('status', TaskStatus::Queued)->count());
            $view->with('runner', Runner::alive() ? (Runner::paused() ? 'wait' : 'live') : 'idle');
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
