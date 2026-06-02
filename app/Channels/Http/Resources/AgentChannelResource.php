<?php

namespace App\Channels\Http\Resources;

use App\Channels\Models\AgentChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentChannel
 */
class AgentChannelResource extends JsonResource
{
    private readonly bool $revealSettings;

    /**
     * @param  mixed  $resource
     */
    public function __construct($resource, mixed $revealSettings = false)
    {
        parent::__construct($resource);
        $this->revealSettings = $revealSettings === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $settingsKeys = array_values(array_filter(array_keys($settings), static fn ($k): bool => is_string($k)));

        $data = [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'version' => $this->aggregate_version,
            'agent_id' => $this->agent_id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'settings_keys' => $settingsKeys,
            'has_settings' => $settingsKeys !== [],
            'metadata' => $this->metadata,
            'enabled' => $this->enabled,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->revealSettings) {
            $data['settings'] = $this->decodedSettingsForApi($settings);
        }

        if ($this->type === 'telegram') {
            $data['telegram_webhook_url'] = rtrim((string) config('app.public_url'), '/')
                .'/api/integrations/telegram/webhooks/'.$this->uuid;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function decodedSettingsForApi(array $settings): array
    {
        $out = [];

        foreach ($settings as $key => $value) {
            if (! is_string($key) || $value === null) {
                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $out[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $out[$key] = $value;

                continue;
            }

            if (is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }
}
