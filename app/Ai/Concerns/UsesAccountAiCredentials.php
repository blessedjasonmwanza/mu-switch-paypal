<?php

namespace App\Ai\Concerns;

use App\Models\User;
use App\Support\Ai\AiCredentials;

/**
 * Runs an agent with an account's AI credentials (dashboard first, then .env).
 */
trait UsesAccountAiCredentials
{
    private ?User $credentialsOwner = null;

    /**
     * Use this account's AI provider, model and key.
     */
    public function usingCredentialsOf(?User $user): static
    {
        $this->credentialsOwner = $user;

        return $this;
    }

    public function provider(): string
    {
        return AiCredentials::for($this->credentialsOwner)->providerName();
    }

    public function model(): ?string
    {
        return AiCredentials::for($this->credentialsOwner)->model;
    }
}
