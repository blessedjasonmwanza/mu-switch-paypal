<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * An account's AI provider, model and key for its agents. Blank fields fall
 * back to .env.
 */
class AiSetting extends Model
{
    protected $fillable = ['provider', 'model', 'api_key'];

    protected $hidden = ['api_key'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'api_key' => 'encrypted',
        ];
    }
}
