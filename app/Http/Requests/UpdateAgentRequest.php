<?php

namespace App\Http\Requests;

use App\Mcp\Models\McpServer;
use App\Models\AiProviderModel;
use App\Neuron\BuiltinRuntimeTools;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ai_provider_model_id' => ['sometimes', 'integer', 'exists:ai_provider_models,id'],
            'instructions' => ['sometimes', 'array'],
            'instructions.background' => ['required_with:instructions', 'array', 'min:1'],
            'instructions.background.*' => ['required', 'string', 'max:2000'],
            'instructions.steps' => ['sometimes', 'array'],
            'instructions.steps.*' => ['required', 'string', 'max:2000'],
            'instructions.output' => ['sometimes', 'array'],
            'instructions.output.*' => ['required', 'string', 'max:2000'],
            'subagents' => ['sometimes', 'array'],
            'subagents.*' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', 'distinct'],
            'tools' => ['sometimes', 'array'],
            'tools.*.slug' => ['required', 'string', 'max:255', 'distinct'],
            'tools.*.is_enabled' => ['sometimes', 'boolean'],
            'tools.*.config' => ['nullable', 'array'],
            'tools.*.config.server_uuid' => ['sometimes', 'string', 'uuid'],
            'tools.*.config.tool_name' => ['sometimes', 'string', 'max:255'],
            'tools.*.config.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tools.*.config.description' => ['sometimes', 'nullable', 'string'],
            'tools.*.config.input_schema' => ['sometimes', 'nullable', 'array'],
            'state_processors' => ['sometimes', 'array'],
            'state_processors.*.agent_state_processor_id' => ['required', 'integer', 'exists:agent_state_processors,id', 'distinct'],
            'state_processors.*.is_enabled' => ['sometimes', 'boolean'],
            'state_processors.*.trigger' => ['sometimes', 'string', 'in:after_response'],
            'state_processors.*.scope' => ['sometimes', 'string', 'in:conversation,global'],
            'state_processors.*.injection_title' => ['sometimes', 'string', 'max:255'],
            'state_processors.*.injection_instructions' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'state_processors.*.state_filters' => ['sometimes', 'nullable', 'array'],
            'state_processors.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('ai_provider_model_id')) {
                    return;
                }

                $providerModelId = $this->integer('ai_provider_model_id');

                if ($providerModelId === 0) {
                    return;
                }

                $isAvailable = AiProviderModel::query()
                    ->whereKey($providerModelId)
                    ->where('is_active', true)
                    ->whereHas('provider', fn ($query) => $query->where('is_active', true))
                    ->exists();

                if (! $isAvailable) {
                    $validator->errors()->add('ai_provider_model_id', 'The selected provider model is inactive or unavailable.');
                }
            },
            function (Validator $validator): void {
                $tools = $this->input('tools', []);
                if (! is_array($tools)) {
                    return;
                }

                foreach ($tools as $index => $tool) {
                    if (! is_array($tool)) {
                        continue;
                    }

                    $slug = $tool['slug'] ?? null;
                    if (! is_string($slug)) {
                        continue;
                    }

                    if (BuiltinRuntimeTools::isBuiltin($slug)) {
                        continue;
                    }

                    if (! str_starts_with($slug, 'mcp:')) {
                        $validator->errors()->add("tools.{$index}.slug", 'Unsupported runtime tool.');

                        continue;
                    }

                    if (! (bool) ($tool['is_enabled'] ?? false)) {
                        continue;
                    }

                    $config = $tool['config'] ?? [];
                    $serverUuid = is_array($config) ? ($config['server_uuid'] ?? null) : null;
                    $toolName = is_array($config) ? ($config['tool_name'] ?? null) : null;

                    if (! is_string($serverUuid) || ! is_string($toolName)) {
                        $validator->errors()->add("tools.{$index}.config", 'Enabled MCP tools require server_uuid and tool_name.');

                        continue;
                    }

                    $serverExists = McpServer::query()
                        ->where('uuid', $serverUuid)
                        ->where('enabled', true)
                        ->exists();

                    if (! $serverExists) {
                        $validator->errors()->add("tools.{$index}.config.server_uuid", 'The selected MCP server is unavailable.');
                    }
                }
            },
        ];
    }
}
