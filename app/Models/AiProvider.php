<?php

namespace App\Models;

use App\Enums\AiProviderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class AiProvider extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'type',
        'credentials',
        'is_active',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected $appends = [
        'masked_credentials',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $provider): void {
            $provider->assertSupportedCredentials();
        });
    }

    protected function casts(): array
    {
        return [
            'type' => AiProviderType::class,
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function credential(string $key): mixed
    {
        return Arr::get($this->credentials ?? [], $key);
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiProviderModel::class);
    }

    public function getMaskedCredentialsAttribute(): array
    {
        return collect($this->credentials ?? [])
            ->mapWithKeys(fn (mixed $value, string $key): array => [
                $key => is_string($value) ? $this->maskSecret($value) : '******',
            ])
            ->all();
    }

    public function assertSupportedCredentials(): void
    {
        match ($this->type instanceof AiProviderType ? $this->type : AiProviderType::tryFrom((string) $this->type)) {
            AiProviderType::GEMINI => $this->assertRequiredStringCredential('key'),
            default => throw new InvalidArgumentException('Unsupported AI provider type.'),
        };
    }

    private function assertRequiredStringCredential(string $key): void
    {
        $value = $this->credential($key);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("AI provider credential [{$key}] is required.");
        }
    }

    private function maskSecret(string $value): string
    {
        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('*', max(6, $length));
        }

        return substr($value, 0, 4).str_repeat('*', 6).substr($value, -4);
    }
}
