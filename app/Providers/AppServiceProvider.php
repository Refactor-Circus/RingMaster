<?php

namespace App\Providers;

use App\Runner\Runner;
use App\Support\NavCounts;
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
        $this->extendPath();

        View::composer(['layouts.app', 'layouts::app'], function (\Illuminate\View\View $view): void {
            $counts = NavCounts::get();
            $view->with('waitingOnYou', $counts['inbox']);
            $view->with('queued', $counts['queued']);
            $view->with('runner', Runner::alive() ? (Runner::paused() ? 'wait' : 'live') : 'idle');
        });
    }

    /**
     * Adds runner.path's folders to PATH, so commands run from a web request
     * (gh, git, claude) are found as they are from a terminal.
     */
    protected function extendPath(): void
    {
        $path = getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin';
        $current = explode(':', $path);
        $extra = array_filter(explode(':', (string) config('ring-master.runner.path')), fn (string $dir): bool => $dir !== '' && ! in_array($dir, $current, true) && is_dir($dir));

        if ($extra === []) {
            return;
        }

        $path = implode(':', [...$extra, ...$current]);
        putenv("PATH={$path}");
        $_ENV['PATH'] = $_SERVER['PATH'] = $path;
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
