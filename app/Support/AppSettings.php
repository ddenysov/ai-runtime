<?php

namespace App\Support;

use App\Models\AppSetting;

class AppSettings
{
    public const GROUP_PROMPTS = 'prompts';

    public const KEY_PROMPT_GENERATOR_AGENT_ID = 'prompt_generator_agent_id';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            self::GROUP_PROMPTS => [
                self::KEY_PROMPT_GENERATOR_AGENT_ID => $this->nullableInt(
                    self::GROUP_PROMPTS,
                    self::KEY_PROMPT_GENERATOR_AGENT_ID,
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    public function update(array $payload): array
    {
        if (array_key_exists(self::GROUP_PROMPTS, $payload) && is_array($payload[self::GROUP_PROMPTS])) {
            $prompts = $payload[self::GROUP_PROMPTS];

            if (array_key_exists(self::KEY_PROMPT_GENERATOR_AGENT_ID, $prompts)) {
                $this->set(
                    self::GROUP_PROMPTS,
                    self::KEY_PROMPT_GENERATOR_AGENT_ID,
                    $prompts[self::KEY_PROMPT_GENERATOR_AGENT_ID],
                );
            }
        }

        return $this->all();
    }

    public function promptGeneratorAgentId(): ?int
    {
        return $this->nullableInt(self::GROUP_PROMPTS, self::KEY_PROMPT_GENERATOR_AGENT_ID);
    }

    private function nullableInt(string $group, string $key): ?int
    {
        $value = $this->get($group, $key);

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    private function get(string $group, string $key): mixed
    {
        $setting = AppSetting::query()
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        if ($setting === null) {
            return null;
        }

        return $setting->value;
    }

    private function set(string $group, string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            AppSetting::query()
                ->where('group', $group)
                ->where('key', $key)
                ->delete();

            return;
        }

        AppSetting::query()->updateOrCreate(
            [
                'group' => $group,
                'key' => $key,
            ],
            [
                'value' => $value,
            ],
        );
    }
}
