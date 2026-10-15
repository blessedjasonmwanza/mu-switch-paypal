<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesAccountAiCredentials;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * A minimal prompt that proves an account's AI credentials work.
 */
#[MaxTokens(16)]
#[Timeout(20)]
class ConnectionCheck implements Agent
{
    use Promptable, UsesAccountAiCredentials;

    public function instructions(): Stringable|string
    {
        return 'Reply with the single word OK.';
    }
}
