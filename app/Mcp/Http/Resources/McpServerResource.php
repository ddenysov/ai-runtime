<?php

namespace App\Mcp\Http\Resources;

use App\Mcp\Models\McpServer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin McpServer
 */
class McpServerResource extends JsonResource
{
    private readonly bool $revealEnv;

    public function __construct(mixed $resource, mixed $revealEnv = false)
    {
        parent::__construct($resource);

        $this->revealEnv = $revealEnv === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $env = is_array($this->env) ? $this->env : [];
        $envKeys = array_values(array_filter(array_keys($env), static fn (mixed $key): bool => is_string($key)));

        $data = [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'version' => $this->aggregate_version,
            'name' => $this->name,
            'transport' => $this->transport,
            'command' => $this->command,
            'args' => $this->args ?? [],
            'cwd' => $this->cwd,
            'env_keys' => $envKeys,
            'has_env' => $envKeys !== [],
            'metadata' => $this->metadata,
            'enabled' => $this->enabled,
            'last_test' => [
                'at' => $this->last_tested_at?->toIso8601String(),
                'status' => $this->last_test_status,
                'message' => $this->last_test_message,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->revealEnv) {
            $data['env'] = $this->decodedEnvForApi($env);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $env
     * @return array<string, string>
     */
    private function decodedEnvForApi(array $env): array
    {
        $out = [];

        foreach ($env as $key => $value) {
            if (! is_string($key) || $value === null) {
                continue;
            }

            if (is_string($value)) {
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
