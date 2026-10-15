<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiCredentialRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'model' => ['nullable', 'string', 'max:100'],
            // Blank keeps the saved key.
            'api_key' => ['nullable', 'string', 'min:8', 'max:500'],
        ];
    }
}
