<?php

namespace App\Providers;

use App\Support\ProviderCallLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Prezet\Prezet\Actions\GetHeadings;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Use our heading extractor so the docs "On this page" ids match the
        // in-body anchor ids (otherwise punctuated headings don't scroll).
        $this->app->bind(
            GetHeadings::class,
            \App\Support\Prezet\GetHeadings::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureHttpTimeouts();
        $this->logProviderHttpCalls();
    }

    /**
     * Connecting to a gateway fails fast, so an unreachable host (DNS failure,
     * refused, dead endpoint) lets the switch fall back safely: a connection
     * that never opened means the customer was never charged.
     *
     * Once connected, we wait generously (3 minutes by default) for an answer:
     * the gateway may already have initiated the collection, so cutting it off
     * early and re-routing could bill the customer twice. The wait is still
     * bounded, so a gateway that never answers can't hold a worker forever;
     * the verify endpoint reconciles whatever is left unresolved.
     */
    protected function configureHttpTimeouts(): void
    {
        $connectTimeout = (int) config('services.gateways.connect_timeout');
        $timeout = (int) config('services.gateways.timeout');

        $options = [
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
        ];

        if (env('HTTP_VERIFY_SSL') !== null) {
            $options['verify'] = (bool) filter_var(env('HTTP_VERIFY_SSL'), FILTER_VALIDATE_BOOLEAN);
        } elseif ($this->app->isLocal()) {
            $options['verify'] = false;
        }

        Http::globalOptions($options);

        // PHP's own execution limit would otherwise expire first and kill a web
        // request mid-call, leaving the payment's outcome unknown. Give every
        // outgoing call room for the whole wait. Console processes (queue
        // workers) have no limit and must not be given one.
        if (! $this->app->runningInConsole()) {
            Http::globalRequestMiddleware(function ($request) use ($connectTimeout, $timeout) {
                if (function_exists('set_time_limit')) {
                    @set_time_limit($connectTimeout + $timeout + 30);
                }

                return $request;
            });
        }
    }

    /**
     * Persist the switch's outgoing gateway calls (request + response) against
     * the provider currently being invoked, for the per-provider call history.
     */
    protected function logProviderHttpCalls(): void
    {
        Event::listen(RequestSending::class, [ProviderCallLogger::class, 'recordRequest']);
        Event::listen(ResponseReceived::class, [ProviderCallLogger::class, 'recordResponse']);
        Event::listen(ConnectionFailed::class, [ProviderCallLogger::class, 'recordConnectionFailure']);
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
