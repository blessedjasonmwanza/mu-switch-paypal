<?php

namespace App\Enums;

/**
 * AI providers an account can choose for its agents in the dashboard.
 */
enum AiProvider: string
{
    case ANTHROPIC = 'anthropic';
    case OPENAI = 'openai';
    case GEMINI = 'gemini';
    case MISTRAL = 'mistral';
    case GROQ = 'groq';
    case XAI = 'xai';
    case DEEPSEEK = 'deepseek';
    case OPENROUTER = 'openrouter';

    public function label(): string
    {
        return match ($this) {
            self::ANTHROPIC => 'Anthropic (Claude)',
            self::OPENAI => 'OpenAI',
            self::GEMINI => 'Google Gemini',
            self::MISTRAL => 'Mistral',
            self::GROQ => 'Groq',
            self::XAI => 'xAI (Grok)',
            self::DEEPSEEK => 'DeepSeek',
            self::OPENROUTER => 'OpenRouter',
        };
    }

    /**
     * The .env variable holding this provider's fallback key.
     */
    public function envKey(): string
    {
        return strtoupper($this->value).'_API_KEY';
    }

    /**
     * Every provider as a dashboard option.
     *
     * @return list<array{value: string, label: string, envKey: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $provider): array => [
            'value' => $provider->value,
            'label' => $provider->label(),
            'envKey' => $provider->envKey(),
        ], self::cases());
    }
}
