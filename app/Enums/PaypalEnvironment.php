<?php

namespace App\Enums;

enum PaypalEnvironment: string
{
    case SANDBOX = 'sandbox';
    case LIVE = 'live';

    /**
     * The REST API host for this environment.
     */
    public function apiUrl(): string
    {
        return match ($this) {
            self::SANDBOX => 'https://api-m.sandbox.paypal.com',
            self::LIVE => 'https://api-m.paypal.com',
        };
    }

}
