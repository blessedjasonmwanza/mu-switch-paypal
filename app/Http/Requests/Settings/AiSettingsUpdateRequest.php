<?php

namespace App\Http\Requests\Settings;

use App\Enums\AiProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiSettingsUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Null means "use .env" for everything.
            'provider' => ['nullable', Rule::enum(AiProvider::class)],
            'model' => ['nullable', 'string', 'max:100'],
            // Blank keeps the stored key (or the .env key when none is stored).
            'api_key' => ['nullable', 'string', 'min:8', 'max:500'],
        ];
    }
}
