# Google A2A: как работает протокол и как интегрировать его в Laravel + Neuron

## Кратко

A2A, Agent2Agent, - открытый протокол для взаимодействия независимых AI-агентов. Он был представлен Google и передан в open-source экосистему A2A/Linux Foundation. Его задача - дать агентам общий язык: находить друг друга, понимать доступные навыки, безопасно передавать задачи, получать статусы и результаты, не раскрывая внутренние инструменты, память и реализацию агента.

Для текущего проекта это означает, что Laravel-приложение может стать A2A server, то есть удаленным агентом, доступным другим агентам по HTTP. Neuron остается внутренним движком агента: он отвечает за LLM provider, инструкции, tools/toolkits, память, RAG или workflow. A2A добавляет внешний протокольный слой: Agent Card, JSON-RPC endpoint, задачи, сообщения и артефакты.

Текущий проект:

- Laravel `^13.8`, PHP `^8.3`.
- Установлен `neuron-core/neuron-ai` `^3.14`.
- В проекте пока нет `app/Neuron`, `app/A2A`, `routes/api.php` и `config/neuron.php`.
- Docker уже поднимает `app`, `queue-worker`, `nginx`, `postgres`, `pgweb`; `QUEUE_CONNECTION=database`, `DB_CONNECTION=pgsql`.

Рекомендуемый путь интеграции: реализовать A2A protocol layer внутри приложения без `neuron-core/a2a`, чтобы наружу сразу поддерживать актуальную спецификацию A2A и не зависеть от старых naming conventions пакета. Neuron agents лучше создавать через фабрику по `slug`: на первом этапе slug ищется в Laravel config с несколькими дефолтными агентами, а позже тот же контракт можно переключить на настройки из БД.

## Что решает A2A

Без общего протокола каждый агент интегрируется как кастомный API: разные схемы auth, разные форматы сообщений, разные способы получить статус долгой задачи. A2A стандартизирует эти зоны:

- Discovery: агент публикует Agent Card с описанием себя, URL, версий протокола, transport, skills, input/output media types и auth.
- Делегирование работы: клиент-агент отправляет сообщение и получает Task или прямой Message.
- Долгие задачи: Task имеет состояние, историю и artifacts; клиент может запрашивать статус, отменять задачу, получать streaming updates или push notifications.
- Мультимодальность: контент передается через Parts: text, file/raw bytes, file URL, structured JSON data.
- Безопасность: Agent Card объявляет security schemes; запросы идут поверх HTTPS и стандартных HTTP auth patterns.
- Непрозрачность реализации: клиенту не нужно знать prompts, tools, память или внутренний workflow удаленного агента.

## Основные роли

`A2A Client` - агент или приложение, которое ищет подходящий remote agent и отправляет ему задачу. Например, внешний orchestrator или другой агент компании.

`A2A Server`, он же remote agent, - сервис, который публикует Agent Card и принимает A2A-запросы. В нашем случае это Laravel-приложение.

`Neuron Agent` - внутренняя реализация поведения. Он не обязан знать про HTTP и JSON-RPC. Он получает пользовательский ввод из A2A message handler и возвращает результат, который handler упаковывает обратно в A2A Task/Artifact.

## Как устроен протокол

### 1. Agent Card

Agent Card - публичный JSON manifest, обычно доступный по well-known URL:

```text
GET /.well-known/agent-card.json
```

В этом проекте Agent Card публикуется собственным Laravel controller под route prefix конкретного агента:

```text
GET /a2a/{agent}/.well-known/agent-card.json
```

В актуальной спецификации A2A 1.0 ключевые поля Agent Card:

```json
{
  "name": "AI Runtime Agent",
  "description": "Agent that answers project-specific questions and runs approved workflows.",
  "supportedInterfaces": [
    {
      "url": "https://example.com/a2a/runtime",
      "protocolBinding": "JSONRPC",
      "protocolVersion": "1.0"
    }
  ],
  "provider": {
    "organization": "Company Name",
    "url": "https://example.com"
  },
  "version": "1.0.0",
  "capabilities": {
    "streaming": false,
    "pushNotifications": true,
    "extendedAgentCard": false
  },
  "securitySchemes": {
    "bearer": {
      "httpAuthSecurityScheme": {
        "scheme": "bearer",
        "bearerFormat": "JWT"
      }
    }
  },
  "securityRequirements": [
    {
      "bearer": []
    }
  ],
  "defaultInputModes": ["text/plain", "application/json"],
  "defaultOutputModes": ["text/plain", "application/json"],
  "skills": [
    {
      "id": "runtime_assistant",
      "name": "Runtime Assistant",
      "description": "Answers questions and executes approved runtime workflows.",
      "tags": ["laravel", "neuron", "runtime"],
      "examples": ["Explain current queue status", "Generate a summary for this document"],
      "inputModes": ["text/plain"],
      "outputModes": ["text/plain"]
    }
  ]
}
```

Решение: проект сам реализует Agent Card по актуальной спецификации A2A. Внутренние DTO должны быть версионированы, чтобы новая версия стандарта добавлялась отдельным namespace/adapter, а не переписыванием runtime logic.

### 2. Message и Part

Message - один ход общения между клиентом и агентом. У него есть:

- `role`: user или agent.
- `parts`: массив фрагментов контента.
- `messageId`, `taskId`, `contextId`, `metadata`.

Part содержит ровно один тип полезной нагрузки:

- `text` для обычного текста.
- `data` для structured JSON.
- `raw` для base64 bytes.
- `url` для ссылки на файл.

Для Laravel/Neuron лучше на первом этапе поддержать `text/plain` и `application/json`, а файлы добавить позже, когда появится понятная политика хранения и проверки URL.

### 3. Task

Task - основная единица работы в A2A. В ней хранятся:

- `id`: server-generated task id.
- `contextId`: общий контекст для связанных задач и сообщений.
- `status`: текущее состояние и статусное сообщение.
- `history`: сообщения, которые сервер решил сохранить.
- `artifacts`: результаты работы агента.
- `metadata`: технические данные.

Состояния Task:

- `SUBMITTED`: задача принята.
- `WORKING`: агент обрабатывает задачу.
- `COMPLETED`: задача успешно завершена.
- `FAILED`: задача завершилась ошибкой.
- `CANCELED`: задача отменена.
- `INPUT_REQUIRED`: агенту нужны дополнительные данные от клиента.
- `REJECTED`: агент отказался выполнять задачу.
- `AUTH_REQUIRED`: нужны credentials или более сильная авторизация.

Для Neuron-интеграции результат LLM не стоит возвращать только как status message. По модели A2A итоговые данные лучше отдавать в `artifacts`, а `history` использовать для контекста диалога.

### 4. Operations

A2A задает абстрактные операции и несколько bindings. Самый практичный binding для Laravel - JSON-RPC over HTTP(S).

В A2A 1.0 JSON-RPC methods:

| Operation | JSON-RPC method | REST endpoint equivalent |
| --- | --- | --- |
| Send message | `SendMessage` | `POST /message:send` |
| Send streaming message | `SendStreamingMessage` | `POST /message:stream` |
| Get task | `GetTask` | `GET /tasks/{id}` |
| List tasks | `ListTasks` | `GET /tasks` |
| Cancel task | `CancelTask` | `POST /tasks/{id}:cancel` |
| Subscribe to task | `SubscribeToTask` | `POST /tasks/{id}:subscribe` |
| Get extended card | `GetExtendedAgentCard` | `GET /extendedAgentCard` |

Проект не принимает старые method names как основной публичный contract. Если позже понадобится совместимость со старым клиентом, ее нужно добавить отдельным adapter, например `App\A2A\Protocol\V0_3`, который маппит legacy methods в общий application service.

Пример JSON-RPC запроса в стиле A2A 1.0:

```json
{
  "jsonrpc": "2.0",
  "id": "req-1",
  "method": "SendMessage",
  "params": {
    "message": {
      "messageId": "msg-1",
      "role": "ROLE_USER",
      "parts": [
        {
          "text": "Summarize the current runtime state",
          "mediaType": "text/plain"
        }
      ]
    },
    "configuration": {
      "returnImmediately": true
    }
  }
}
```

Protocol layer должен валидировать `jsonrpc`, `id`, `method` и `params`, затем передавать уже нормализованную command-модель в application service. Queue, repository, Neuron factory и notifier не должны знать, какая версия A2A пришла на вход.

## Архитектура интеграции в текущем проекте

Предлагаемая структура:

```text
app/
  A2A/
    Actions/
      SendMessageAction.php
      GetTaskAction.php
      ListTasksAction.php
      CancelTaskAction.php
    Http/
      A2AJsonRpcController.php
      AgentCardController.php
      A2AToolResultWebhookController.php
    Protocol/
      Contracts/
        ProtocolAdapter.php
      V1/
        A2A1ProtocolAdapter.php
        AgentCardFactory.php
        JsonRpcRequest.php
        JsonRpcResponse.php
        Dto/
          Task.php
          Message.php
          Part.php
          Artifact.php
          PushNotificationConfig.php
      Shared/
        JsonRpcError.php
        A2ATaskMapper.php
    RuntimeAgentTaskRepository.php
    RuntimeAgentPushNotifier.php
    RuntimeAgentPushNotificationRepository.php
  Jobs/
    ProcessA2ATask.php
  Models/
    A2ATask.php
    A2ATaskPushNotification.php
  Neuron/
    RuntimeAgentFactory.php
    Agents/
      ConfigurableRuntimeAgent.php
config/
  runtime-agents.php
database/
  migrations/
    xxxx_xx_xx_xxxxxx_create_a2a_tasks_table.php
    xxxx_xx_xx_xxxxxx_create_a2a_task_push_notifications_table.php
routes/
  api.php
docs/
  a2a-laravel-neuron-integration.md
```

Граница ответственности:

- `A2AJsonRpcController`: принимает JSON-RPC over HTTP, выбирает protocol adapter по Agent Card/interface version или заголовку и возвращает JSON-RPC response.
- `AgentCardController`: публикует Agent Card для актуальной версии A2A.
- `A2A1ProtocolAdapter`: валидирует и маппит A2A 1.0 requests/responses в общую command-модель приложения.
- `SendMessageAction`, `GetTaskAction`, `ListTasksAction`, `CancelTaskAction`: application layer, не завязанный на конкретную версию A2A.
- `RuntimeAgentTaskRepository`: сохраняет и читает A2A Task из PostgreSQL через `A2ATaskMapper`.
- `ProcessA2ATask`: выполняет Neuron Agent асинхронно, обновляет Task state/artifacts/history.
- `RuntimeAgentPushNotifier`: отправляет A2A-compatible webhook notifications клиенту при смене статуса и появлении artifacts.
- `RuntimeAgentFactory`: создает Neuron agent по `slug`, загружая provider/model/instructions/tools/RAG settings из config или, позже, из БД.
- `ConfigurableRuntimeAgent`: один универсальный Neuron agent, которому фабрика передает настройки вместо создания отдельного PHP-класса на каждый агент.
- `config/runtime-agents.php`: реестр дефолтных агентов, доступных по slug.
- `routes/api.php`: публикует A2A endpoint.

## Пошаговая интеграция

### Шаг 1. Не использовать `neuron-core/a2a`

Решение для проекта: не подключать `neuron-core/a2a`. Текущая версия пакета не должна определять публичный protocol contract приложения. Мы реализуем A2A самостоятельно в `app/A2A/Protocol`, чтобы поддерживать актуальную спецификацию и иметь управляемую миграцию на будущие версии стандарта.

Composer должен содержать только Neuron как runtime dependency:

```json
"require": {
  "php": "^8.3",
  "laravel/framework": "^13.8",
  "laravel/tinker": "^3.0",
  "neuron-core/neuron-ai": "^3.14"
}
```

### Шаг 2. Создать версионируемый protocol layer

Базовый принцип: каждая версия A2A живет в отдельном namespace и реализует общий `ProtocolAdapter`.

```php
interface ProtocolAdapter
{
    public function version(): string;

    public function handle(JsonRpcEnvelope $request): JsonRpcEnvelope;

    public function agentCard(RuntimeAgentDefinition $agent): array;
}
```

Для A2A 1.0 создать `App\A2A\Protocol\V1\A2A1ProtocolAdapter`. Когда появится A2A 1.1/2.0, добавляется `Protocol\V1_1` или `Protocol\V2`, а общие application actions остаются прежними.

### Шаг 3. Создать `routes/api.php`

В текущем Laravel skeleton есть только `routes/web.php` и `routes/console.php`. Если `routes/api.php` отсутствует, создать файл и убедиться, что Laravel bootstrap подключает API routes в версии 13. Минимальный route:

```php
<?php

use App\A2A\Http\A2AJsonRpcController;
use App\A2A\Http\AgentCardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.a2a'])->group(function (): void {
    Route::post('/a2a/runtime', A2AJsonRpcController::class);
});

Route::get('/a2a/runtime/.well-known/agent-card.json', AgentCardController::class);
```

`auth.a2a` должен валидировать `Authorization: Bearer ...` через hashed token в БД или secrets manager. Пользовательская session auth здесь не подходит, потому что A2A endpoint является machine-to-machine API.

После регистрации должны быть доступны:

```text
POST /api/a2a/runtime
GET  /api/a2a/runtime/.well-known/agent-card.json
```

Точный prefix зависит от того, как Laravel 13 bootstrap подключает `routes/api.php` в проекте.

### Шаг 4. Реализовать фабрику Neuron agents

Для установленного `neuron-core/neuron-ai` 3.x базовый класс находится в `NeuronAI\Agent\Agent`. Но в этом проекте не стоит создавать отдельный PHP-класс под каждого агента. Лучше сделать один конфигурируемый agent class и фабрику, которая создает экземпляр по `slug`.

На первом этапе `slug` ищется в `config/runtime-agents.php`. В config можно держать несколько дефолтных агентов: инструкции, модель, provider, включенные tools/toolkits, RAG настройки, memory policy и output modes. Позже источник настроек можно заменить на таблицу `runtime_agents` или похожую модель, не меняя A2A handler/job.

Пример config:

```php
<?php

return [
    'default' => env('RUNTIME_AGENT_DEFAULT_SLUG', 'runtime_assistant'),

    'agents' => [
        'runtime_assistant' => [
            'name' => 'Runtime Assistant',
            'description' => 'Answers project-specific runtime questions.',
            'provider' => 'openai',
            'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            'instructions' => [
                'background' => [
                    'You are an A2A-compatible runtime assistant inside a Laravel application.',
                    'Answer only with information you are authorized to expose.',
                ],
                'steps' => [
                    'Understand the requested task.',
                    'Use available tools only when needed.',
                    'Return concise, verifiable output.',
                ],
                'output' => [
                    'Prefer text/plain unless the request explicitly asks for JSON.',
                ],
            ],
            'tools' => [],
            'rag' => null,
            'memory' => null,
            'input_modes' => ['text/plain'],
            'output_modes' => ['text/plain'],
        ],

        'docs_assistant' => [
            'name' => 'Docs Assistant',
            'description' => 'Answers questions using approved documentation sources.',
            'provider' => 'openai',
            'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            'instructions' => [
                'background' => [
                    'You answer from approved project documentation.',
                ],
                'steps' => [
                    'Search retrieval sources before answering project-specific questions.',
                    'Cite uncertainty when documentation is missing.',
                ],
                'output' => [
                    'Return concise answers with references when available.',
                ],
            ],
            'tools' => [],
            'rag' => [
                'index' => 'project_docs',
                'top_k' => 5,
            ],
            'memory' => null,
            'input_modes' => ['text/plain'],
            'output_modes' => ['text/plain'],
        ],
    ],
];
```

Универсальный agent получает настройки в constructor:

```php
<?php

namespace App\Neuron\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\SystemPrompt;

class ConfigurableRuntimeAgent extends Agent
{
    public function __construct(
        private readonly AIProviderInterface $configuredProvider,
        private readonly array $definition,
    ) {
    }

    protected function provider(): AIProviderInterface
    {
        return $this->configuredProvider;
    }

    public function instructions(): string
    {
        $instructions = $this->definition['instructions'];

        return (string) new SystemPrompt(
            background: $instructions['background'] ?? [],
            steps: $instructions['steps'] ?? [],
            output: $instructions['output'] ?? [],
        );
    }
}
```

Фабрика отвечает за lookup по slug и сборку provider:

```php
<?php

namespace App\Neuron;

use App\Neuron\Agents\ConfigurableRuntimeAgent;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

class RuntimeAgentFactory
{
    public function make(?string $slug = null): Agent
    {
        $slug ??= config('runtime-agents.default');
        $definition = config("runtime-agents.agents.{$slug}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Runtime agent [{$slug}] is not configured.");
        }

        return new ConfigurableRuntimeAgent(
            configuredProvider: $this->provider($definition),
            definition: Arr::add($definition, 'slug', $slug),
        );
    }

    private function provider(array $definition): AIProviderInterface
    {
        return match ($definition['provider'] ?? 'openai') {
            'openai' => new OpenAI(
                key: config('services.openai.key'),
                model: $definition['model'] ?? config('services.openai.model', 'gpt-4.1-mini'),
            ),
            default => throw new InvalidArgumentException('Unsupported runtime agent provider.'),
        };
    }
}
```

Добавить env/config:

```dotenv
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4.1-mini
RUNTIME_AGENT_DEFAULT_SLUG=runtime_assistant
```

```php
// config/services.php
'openai' => [
    'key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
],
```

Provider можно заменить на Gemini, Anthropic, Ollama или OpenAI-compatible endpoint, не меняя A2A слой. Для этого фабрика должна расширить `provider()` и прочитать нужные поля из definition.

В будущем config можно заменить на repository:

```php
$definition = $this->runtimeAgents->findEnabledBySlug($slug);
```

Так A2A endpoint, queue job и task storage продолжат работать с тем же `agent_slug`, а настройки агента можно будет редактировать через админку: модель, инструкции, RAG index, набор tools, memory policy, лимиты и feature flags.

### Шаг 5. Сделать `SendMessageAction` асинхронным

Идея application action:

1. Принять нормализованную command-модель из A2A 1.0 adapter: `Task` и входящий `Message`.
2. Извлечь `TextPart` или `DataPart`.
3. Определить `agent_slug`: из route, skill id, metadata или взять дефолт из `config/runtime-agents.php`.
4. Сохранить входные сообщения в `history`, а выбранный slug в `metadata.agent_slug`.
5. Если клиент передал `taskPushNotificationConfig`, сохранить webhook config.
6. Поставить `ProcessA2ATask` в Laravel Queue.
7. Сразу вернуть Task со статусом `SUBMITTED` или `WORKING`.

Neuron должен вызываться не внутри HTTP request, а в queue job. Так A2A endpoint быстро отвечает клиенту, а долгие LLM/tool/RAG workflow не держат соединение открытым.

Скелет логики:

```php
<?php

namespace App\A2A;

use App\A2A\Protocol\Shared\A2ATaskState;
use App\A2A\Protocol\V1\Dto\Task;
use App\A2A\Protocol\V1\Dto\TaskStatus;
use App\Jobs\ProcessA2ATask;

class SendMessageAction
{
    public function __construct(
        private RuntimeAgentTaskRepository $tasks,
        private RuntimeAgentPushNotificationRepository $pushNotifications,
    ) {
    }

    public function handle(Task $task, array $messages, ?array $configuration = null): Task
    {
        $agentSlug = $task->metadata['agent_slug'] ?? config('runtime-agents.default');

        $queuedTask = new Task(
            id: $task->id,
            contextId: $task->contextId,
            status: new TaskStatus(
                state: A2ATaskState::SUBMITTED,
                message: null,
            ),
            history: [
                ...($task->history ?? []),
                ...$messages,
            ],
            metadata: [
                ...($task->metadata ?? []),
                'agent_slug' => $agentSlug,
            ],
        );

        $this->tasks->save($queuedTask);

        if ($configuration !== null) {
            $this->pushNotifications->saveFromConfiguration($queuedTask->id, $configuration);
        }

        ProcessA2ATask::dispatch($queuedTask->id);

        return $queuedTask;
    }
}
```

Этот пример опирается на собственные DTO приложения. Важно сохранить архитектурную идею: A2A HTTP request не должен выполнять LLM-inference синхронно; protocol adapter валидирует запрос, application action сохраняет Task и запускает queue job.

### Шаг 6. Выполнить Task в Laravel Queue

`ProcessA2ATask` делает всю дорогую работу: переводит Task в `WORKING`, вызывает Neuron, сохраняет `COMPLETED`/`FAILED`, отправляет push notification события.

```php
<?php

namespace App\Jobs;

use App\A2A\RuntimeAgentPushNotifier;
use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\Protocol\Shared\A2ATaskState;
use App\A2A\Protocol\V1\Dto\Artifact;
use App\A2A\Protocol\V1\Dto\Message;
use App\A2A\Protocol\V1\Dto\Task;
use App\A2A\Protocol\V1\Dto\TaskStatus;
use App\A2A\Protocol\V1\Dto\TextPart;
use App\Neuron\RuntimeAgentFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

class ProcessA2ATask implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $taskId,
    ) {
    }

    public function handle(
        RuntimeAgentTaskRepository $tasks,
        RuntimeAgentPushNotifier $notifier,
        RuntimeAgentFactory $agents,
    ): void {
        $task = $tasks->find($this->taskId);

        if ($task === null) {
            return;
        }

        $task = $this->withState($task, A2ATaskState::WORKING);
        $tasks->save($task);
        $notifier->sendStatusUpdate($task);

        try {
            $input = $this->extractText($task->history ?? []);

            $agent = $agents->make($task->metadata['agent_slug'] ?? null);

            $response = $agent
                ->chat(new UserMessage($input))
                ->getMessage()
                ->getContent();

            $agentMessage = new Message(
                role: 'agent',
                parts: [new TextPart($response ?? '')],
            );

            $completed = new Task(
                id: $task->id,
                contextId: $task->contextId,
                status: new TaskStatus(
                    state: A2ATaskState::COMPLETED,
                    message: $agentMessage,
                ),
                artifacts: [
                    new Artifact(
                        parts: [new TextPart($response ?? '')],
                    ),
                ],
                history: [
                    ...($task->history ?? []),
                    $agentMessage,
                ],
            );

            $tasks->save($completed);
            $notifier->sendArtifactUpdate($completed);
            $notifier->sendStatusUpdate($completed);
        } catch (Throwable $exception) {
            $failed = $this->withState($task, A2ATaskState::FAILED);
            $tasks->save($failed);
            $notifier->sendStatusUpdate($failed);

            report($exception);
        }
    }

    private function withState(Task $task, A2ATaskState $state): Task
    {
        return new Task(
            id: $task->id,
            contextId: $task->contextId,
            status: new TaskStatus(state: $state),
            artifacts: $task->artifacts ?? [],
            history: $task->history ?? [],
            metadata: $task->metadata ?? [],
        );
    }

    private function extractText(array $messages): string
    {
        $chunks = [];

        foreach ($messages as $message) {
            foreach ($message->parts as $part) {
                if ($part instanceof TextPart) {
                    $chunks[] = $part->text;
                }
            }
        }

        return trim(implode("\n", $chunks));
    }
}
```

В production job должен иметь timeout, retry policy и idempotency guard: повторный запуск не должен повторно выполнять уже terminal task (`COMPLETED`, `FAILED`, `CANCELED`, `REJECTED`).

### Шаг 7. Реализовать Task Repository на PostgreSQL

Решение для этого проекта: хранить A2A Task как JSONB через явный mapper `Task <-> array`, а не через PHP `serialize()`. Так как protocol DTO принадлежат приложению, мы сами поддерживаем стабильные `toArray()`/`fromArray()` и версионируем payload. Это защищает от изменений стандарта и позволяет индексировать поля, нужные для поиска и наблюдаемости.

В таблице нужно отдельно индексировать `id`, `context_id`, `state`, `agent_slug`, `protocol_profile`, `created_at`, `updated_at`, `completed_at`. В `payload` хранится нормализованный JSON: `status`, `history`, `artifacts`, `metadata`.

Миграция:

```php
Schema::create('a2a_tasks', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->uuid('context_id')->nullable()->index();
    $table->string('state')->index();
    $table->string('agent_slug')->nullable()->index();
    $table->string('protocol_profile')->default('a2a')->index();
    $table->jsonb('payload');
    $table->timestamp('completed_at')->nullable()->index();
    $table->timestamps();
});
```

Repository responsibilities:

- `save(Task $task)`: upsert по `id`.
- `find(string $taskId)`: вернуть deserialized Task или `null`.
- `findAll(array $filters, ?int $limit, ?int $offset)`: поддержать фильтры по `contextId` и `state`.
- `count(array $filters)`: для pagination.
- `generateTaskId()` и `generateContextId()`: использовать `Str::uuid()`.

`A2ATaskMapper` должен быть явным и покрывать только реально поддержанные типы:

- `Task`: `id`, `contextId`, `status`, `history`, `artifacts`, `metadata`.
- `TaskStatus`: `state`, `message`, `timestamp`, если поле поддерживается выбранным contract.
- `Message`: `role`, `parts`, `messageId`, `taskId`, `contextId`, `metadata`, если эти поля есть в contract.
- `Part`: discriminator `kind` или эквивалентный ключ: `text`, `data`, `file`.
- `Artifact`: `id`, `parts`, `metadata`.

Если mapper встречает неподдержанный `Part`, он должен отклонить запрос validation error на входе или сохранить задачу как `FAILED` с понятным статусным сообщением. Для MVP достаточно `TextPart` и `DataPart`; файлы лучше включать позже вместе с политикой storage, URL validation и malware scanning.

### Шаг 8. Реализовать A2A push notifications

A2A standard поддерживает уведомления через `PushNotificationConfig`: клиент передает webhook URL и authentication info, а сервер отправляет HTTP POST с тем же типом событий, что и streaming response.

Для async queue flow это основной канал уведомлений:

1. Клиент вызывает `SendMessage` и передает `taskPushNotificationConfig`.
2. Laravel сохраняет config, привязанный к `task_id`.
3. `SendMessage` возвращает Task со статусом `SUBMITTED`.
4. Queue job переводит Task в `WORKING`, `COMPLETED` или `FAILED`.
5. На каждое важное изменение `RuntimeAgentPushNotifier` отправляет webhook POST.
6. Клиент подтверждает доставку HTTP `2xx`.

Payload по стандарту A2A является `StreamResponse` и содержит ровно один тип события:

```json
{
  "statusUpdate": {
    "taskId": "task-uuid",
    "contextId": "context-uuid",
    "status": {
      "state": "TASK_STATE_COMPLETED",
      "timestamp": "2026-05-27T14:05:00Z"
    }
  }
}
```

Для результата задачи отправляется artifact update:

```json
{
  "artifactUpdate": {
    "taskId": "task-uuid",
    "contextId": "context-uuid",
    "artifact": {
      "artifactId": "artifact-uuid",
      "parts": [
        {
          "text": "Final answer",
          "mediaType": "text/plain"
        }
      ]
    },
    "lastChunk": true
  }
}
```

HTTP request:

```http
POST https://client.example.com/a2a/webhooks/tasks
Authorization: Bearer client-provided-token
Content-Type: application/a2a+json

{ "statusUpdate": { "...": "..." } }
```

Минимальная таблица для notification configs:

```php
Schema::create('a2a_task_push_notifications', function (Blueprint $table): void {
    $table->id();
    $table->uuid('task_id')->index();
    $table->string('config_id')->nullable()->index();
    $table->string('url');
    $table->string('auth_scheme')->nullable();
    $table->text('auth_credentials')->nullable();
    $table->string('notification_token')->nullable();
    $table->unsignedInteger('failed_attempts')->default(0);
    $table->timestamp('last_attempted_at')->nullable();
    $table->timestamp('disabled_at')->nullable();
    $table->jsonb('payload')->nullable();
    $table->timestamps();
});
```

`RuntimeAgentPushNotifier` должен:

- отправлять `statusUpdate` при `WORKING`, `COMPLETED`, `FAILED`, `CANCELED`, `INPUT_REQUIRED`;
- отправлять `artifactUpdate` при появлении итогового artifact;
- использовать `Content-Type: application/a2a+json`;
- добавлять auth header согласно `PushNotificationConfig.authentication`;
- если клиент передал `token`, добавлять его в `X-A2A-Notification-Token`;
- считать любой HTTP `2xx` успешной доставкой;
- делать retry с exponential backoff через отдельную queue job;
- быть идемпотентным, потому что webhook delivery может повторяться.

Если актуальная A2A спецификация требует CRUD methods для push notification configs (`CreateTaskPushNotificationConfig`, `GetTaskPushNotificationConfig`, `ListTaskPushNotificationConfigs`, `DeleteTaskPushNotificationConfig`), нужно подключить их к этой же таблице. MVP может начать с config внутри `SendMessage`, но таблица должна быть рассчитана на отдельные notification config operations.

### Шаг 9. Описать Agent Card

Минимальный `agentCard()` в server должен честно отражать текущие возможности:

- `streaming: false`, пока нет SSE.
- `pushNotifications: true`, потому что async completion доставляется через стандартный A2A webhook.
- `defaultInputModes: ['text/plain']`, если handler поддерживает только текст.
- `defaultOutputModes: ['text/plain']`.
- `skills`: маленький набор реально поддержанных действий.
- `securitySchemes`: то, что реально проверяет middleware.

Не стоит рекламировать JSON, файлы, streaming или broad admin skills до реализации и тестов. `pushNotifications` можно включать только после того, как notifier реально отправляет стандартный A2A webhook payload.

## Безопасность

A2A endpoint является machine-to-machine API и потенциально запускает LLM/tools. Минимальные требования:

- Только HTTPS вне локальной разработки.
- `GET /.well-known/agent-card.json` может быть публичным, но только с минимальным Agent Card без секретных skills, внутренней topology и admin-возможностей.
- `POST /a2a/runtime` закрыть отдельным machine-to-machine middleware, например `auth.a2a`, а не пользовательской session auth.
- Для MVP использовать Bearer token: хранить hash токена в БД или секретах окружения, сравнивать через constant-time check, логировать только token id/client id.
- Для production рассмотреть OAuth2/OIDC client credentials или mTLS, если endpoint будет доступен внешним партнерам.
- Rate limiting на route group.
- Явные allowlists для tools/actions, которые может выполнить Neuron Agent.
- Логировать `task_id`, `context_id`, requester, duration, model, token usage, итоговое state.
- Не писать raw secrets в Task history/artifacts.
- Ограничить размер входных `parts`, количество сообщений и размер файлов.
- Для `url` file parts скачивать только по allowlist доменов и с timeout.
- Для исходящих push notifications использовать credentials из `PushNotificationConfig.authentication`, хранить их зашифрованно и не логировать.
- Для входящих webhook результатов от remote A2A tools выпускать per-tool-call Bearer token или signed JWT, проверять `remote_task_id`, `context_id`, `tool_call_id` и dedupe hash события.

## Queue и A2A notifications

Целевая модель для этого проекта - асинхронная. `SendMessage` не должен ждать Neuron inference. Он должен создать Task, сохранить входные сообщения, сохранить push notification config, поставить job в очередь и вернуть Task со статусом `SUBMITTED`.

Основной flow:

1. Client отправляет `SendMessage`.
2. Laravel создает Task `SUBMITTED`.
3. Laravel сохраняет `taskPushNotificationConfig`, если он передан.
4. Laravel dispatches `ProcessA2ATask`.
5. Job переводит Task в `WORKING` и отправляет `statusUpdate`.
6. Job вызывает Neuron Agent.
7. Job сохраняет artifacts, переводит Task в `COMPLETED` или `FAILED`.
8. Job отправляет `artifactUpdate` и финальный `statusUpdate`.
9. Client может в любой момент вызвать `GetTask`, даже если webhook не дошел.

SSE streaming можно добавить позже через `SendStreamingMessage`/`SubscribeToTask`, если собственная реализация и инфраструктура поддерживают `text/event-stream`. Для текущего Docker setup queue + push notifications проще и надежнее: `queue-worker` уже есть, PostgreSQL queue backend подходит для старта, а при росте нагрузки можно перейти на Redis.

## A2A agent как асинхронный tool

Следующий важный сценарий: один Neuron Agent должен уметь вызвать другого агента как tool по A2A. Так как удаленный агент может работать долго, вызывающий parent agent не должен держать Laravel worker. Нужна durable pause/resume модель: parent agent сохраняет состояние выполнения, завершает текущую job, а после A2A push notification запускается новая job, которая продолжает parent agent уже с результатом tool call.

Это не sleep процесса в памяти. Это сохраненная continuation state в БД.

Решение для этой части: строить parent orchestration на Neuron Workflow, потому что у Workflow уже есть interruption/resume primitives. Workflow node может вызвать `$this->interrupt(...)`, Neuron сохранит состояние через persistence layer, а позже workflow можно поднять заново с тем же `resumeToken`. Для Laravel лучше использовать `EloquentPersistence` или `DatabasePersistence` поверх PostgreSQL. Обычный `Agent::chat()` не дает такого durable pause/resume сам по себе; если parent останется обычным Agent, continuation придется моделировать вручную через chat history, pending tool calls и queue jobs.

Flow:

1. `ParentAgentJob` запускает parent Neuron Agent.
2. Parent agent вызывает tool `RemoteA2AAgentTool`.
3. Tool отправляет `SendMessage` удаленному A2A agent.
4. В запросе tool передает `taskPushNotificationConfig` с webhook URL этого Laravel приложения.
5. Tool сохраняет `agent_run_id`, `tool_call_id`, remote `task_id`, входные аргументы и continuation state.
6. Tool прерывает дальнейшее выполнение parent agent через контролируемое состояние `WAITING_FOR_TOOL`.
7. `ParentAgentJob` завершается, worker освобождается.
8. Remote A2A agent выполняет свою queue job.
9. Remote A2A agent отправляет `artifactUpdate` и финальный `statusUpdate` на webhook.
10. Webhook валидирует notification, сохраняет tool result и dispatches `ResumeParentAgentJob`.
11. `ResumeParentAgentJob` загружает parent run, добавляет результат tool call в историю/состояние агента и продолжает выполнение.

Схема:

```text
ParentAgentJob
  -> Parent Neuron Agent
  -> RemoteA2AAgentTool
  -> create remote A2A task with taskPushNotificationConfig
  -> save continuation state
  -> mark tool call as waiting
  -> release worker

Remote A2A Agent
  -> process task asynchronously
  -> send statusUpdate / artifactUpdate webhook

A2A Webhook Controller
  -> validate notification
  -> store tool result
  -> dispatch ResumeParentAgentJob

ResumeParentAgentJob
  -> load parent agent run
  -> inject tool result
  -> continue parent Neuron Agent
```

### Состояние parent agent run

Для durable resume нужны отдельные таблицы. Названия можно уточнить при реализации, но модель должна хранить parent run, Neuron workflow resume token, tool calls и связь с remote A2A task.

```php
Schema::create('agent_runs', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('agent_class');
    $table->string('state')->index(); // running, waiting_for_tool, completed, failed, canceled
    $table->string('workflow_resume_token')->nullable()->index();
    $table->string('workflow_persistence')->nullable();
    $table->jsonb('input')->nullable();
    $table->jsonb('conversation_state')->nullable();
    $table->jsonb('workflow_state')->nullable();
    $table->timestamp('resumable_at')->nullable();
    $table->timestamps();
});

Schema::create('agent_tool_calls', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->uuid('agent_run_id')->index();
    $table->string('tool_name');
    $table->string('state')->index(); // pending, waiting, completed, failed
    $table->jsonb('arguments')->nullable();
    $table->jsonb('result')->nullable();
    $table->text('error')->nullable();
    $table->timestamps();
});

Schema::create('a2a_child_tasks', function (Blueprint $table): void {
    $table->id();
    $table->uuid('agent_run_id')->index();
    $table->uuid('tool_call_id')->index();
    $table->string('remote_agent_url');
    $table->string('remote_task_id')->index();
    $table->string('remote_context_id')->nullable()->index();
    $table->string('state')->index(); // submitted, working, completed, failed, canceled
    $table->jsonb('agent_card')->nullable();
    $table->jsonb('request_payload')->nullable();
    $table->jsonb('last_notification')->nullable();
    $table->timestamps();
});
```

`workflow_resume_token` является основным ключом для возобновления Neuron Workflow. `conversation_state` и `workflow_state` остаются полезными для observability, fallback и ручной реконструкции, но не должны становиться главным механизмом resume, если Workflow persistence работает стабильно.

Важный нюанс Neuron Workflow: node, в котором произошел interrupt, может быть выполнен заново при resume. Поэтому node, создающий remote A2A task, должен быть идемпотентным: сначала искать существующий `a2a_child_tasks` по `agent_run_id` + `tool_call_id`, и только если его нет, создавать новую remote task.

### Tool contract

`RemoteA2AAgentTool` должен выглядеть для parent agent как обычный tool, но внутри он не возвращает финальный результат сразу. Он создает remote task и переводит run в ожидание.

Псевдокод:

```php
final class RemoteA2AAgentTool
{
    public function __invoke(array $arguments, AgentRun $run, AgentToolCall $toolCall): never
    {
        $response = $this->a2aClient->sendMessage(
            agentUrl: $arguments['agent_url'],
            message: $arguments['message'],
            pushNotificationUrl: route('a2a.tool-results.webhook'),
            pushNotificationToken: $this->issueWebhookToken($run, $toolCall),
        );

        A2AChildTask::create([
            'agent_run_id' => $run->id,
            'tool_call_id' => $toolCall->id,
            'remote_agent_url' => $arguments['agent_url'],
            'remote_task_id' => $response->taskId,
            'remote_context_id' => $response->contextId,
            'state' => 'submitted',
            'request_payload' => $arguments,
        ]);

        $toolCall->update(['state' => 'waiting']);
        $run->update(['state' => 'waiting_for_tool']);

        throw new AgentRunPaused('Waiting for remote A2A tool result.');
    }
}
```

В реализации через Neuron Workflow вместо собственного `AgentRunPaused` лучше использовать штатный workflow interrupt. Главное требование остается тем же: job должна завершиться штатно, состояние должно быть сохранено в persistence layer, а worker не должен висеть в ожидании remote result.

### Webhook resume flow

Webhook для результатов remote tool должен принимать стандартные A2A `StreamResponse` события:

- `statusUpdate`: обновить `a2a_child_tasks.state`.
- `artifactUpdate`: сохранить artifact как потенциальный tool result.
- terminal `statusUpdate`: если remote task завершился, dispatch `ResumeParentAgentJob`.

Псевдокод:

```php
final class A2AToolResultWebhookController
{
    public function __invoke(Request $request): Response
    {
        $notification = A2AStreamResponse::fromArray($request->all());

        $childTask = A2AChildTask::query()
            ->where('remote_task_id', $notification->taskId())
            ->firstOrFail();

        $this->authorizeNotification($request, $childTask);

        DB::transaction(function () use ($notification, $childTask): void {
            $childTask->update([
                'state' => $notification->state(),
                'last_notification' => $notification->toArray(),
            ]);

            if ($notification->hasArtifact()) {
                AgentToolCall::query()
                    ->whereKey($childTask->tool_call_id)
                    ->update([
                        'state' => 'completed',
                        'result' => $notification->artifactAsToolResult(),
                    ]);
            }

            if ($notification->isTerminal()) {
                ResumeParentAgentJob::dispatch($childTask->agent_run_id);
            }
        });

        return response()->noContent();
    }
}
```

`ResumeParentAgentJob` должен проверять идемпотентность: если parent run уже `completed` или tool call уже был применен, повторный webhook не должен повторно продолжать агента.

### Resume parent agent

Новая job продолжает выполнение parent agent:

1. Заблокировать `agent_runs` row через `lockForUpdate`.
2. Проверить, что run в `waiting_for_tool`.
3. Найти completed `agent_tool_calls`, которые еще не применены к conversation/workflow state.
4. Загрузить Neuron Workflow через тот же persistence backend и `workflow_resume_token`.
5. Перевести run в `running`.
6. Передать результат remote tool как resume payload в формат, который понимает workflow node.
7. Если parent agent снова вызывает remote A2A tool, повторить pause flow.
8. Если parent agent закончил работу, сохранить итог и перевести run в `completed`.

Критичное правило: parent agent не должен продолжаться прямо внутри webhook request. Webhook только сохраняет событие и ставит `ResumeParentAgentJob` в очередь.

### Idempotency и correlation

Для такой схемы нужны стабильные correlation ids:

- `agent_run_id`: весь parent run.
- `tool_call_id`: конкретный вызов tool.
- `remote_task_id`: Task у удаленного A2A agent.
- `remote_context_id`: контекст удаленного A2A agent.
- `notification_event_id` или hash payload: защита от повторной обработки webhook.

Remote A2A webhook delivery по стандарту может быть at-least-once, поэтому все операции должны быть идемпотентными. Уникальные индексы:

```php
$table->unique(['agent_run_id', 'tool_call_id']);
$table->unique(['remote_agent_url', 'remote_task_id']);
$table->unique(['tool_call_id', 'notification_event_hash']);
```

Если remote task завершился `FAILED`, `CANCELED` или `REJECTED`, parent agent должен получить это как failed tool result и сам решить, продолжать, ретраить другим агентом или завершать run с ошибкой.

## Тестирование

Минимальный набор проверок:

```bash
composer test
php artisan route:list
curl -s http://localhost/api/a2a/runtime/.well-known/agent-card.json | jq
```

Проверка JSON-RPC запроса должна идти по актуальному A2A contract, который реализует `App\A2A\Protocol\V1`.

```bash
curl -X POST http://localhost/api/a2a/runtime \
  -H "Authorization: Bearer ${A2A_TOKEN}" \
  -H "A2A-Version: 1.0" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": "req-1",
    "method": "SendMessage",
    "params": {
      "message": {
        "messageId": "msg-1",
        "role": "ROLE_USER",
        "parts": [
          {"text": "Say hello from the Laravel runtime", "mediaType": "text/plain"}
        ]
      },
      "configuration": {
        "taskPushNotificationConfig": {
          "pushNotificationConfig": {
            "url": "https://client.example.com/a2a/webhooks/tasks",
            "authentication": {
              "scheme": "Bearer",
              "credentials": "client-webhook-token"
            }
          }
        }
      }
    }
  }'
```

Feature tests:

- Agent Card returns 200 and expected skills.
- Unauthorized request returns 401.
- Valid `SendMessage` creates Task row with `SUBMITTED` state and dispatches `ProcessA2ATask`.
- Valid `SendMessage` stores `taskPushNotificationConfig`.
- Queue job maps user text into Neuron `UserMessage`.
- Successful queue job creates `COMPLETED` task with artifact.
- Successful queue job sends `artifactUpdate` and final `statusUpdate` webhook.
- Neuron exception creates `FAILED` task without leaking secrets and sends failure `statusUpdate`.
- Remote A2A tool creates child task, saves continuation state and releases worker.
- A2A tool result webhook dispatches `ResumeParentAgentJob`.
- `ResumeParentAgentJob` injects tool result and continues parent run exactly once.
- `GetTask` returns persisted task.
- `CancelTask` rejects terminal tasks and cancels cancellable tasks if implemented.

## Наблюдаемость

Для production нужно видеть:

- количество A2A requests по method;
- latency по method и skill;
- task state distribution;
- LLM provider/model latency;
- token usage и стоимость;
- errors по категориям: auth, validation, provider, timeout, tool;
- queue wait time для async tasks;
- push notification delivery success/failure, retry count и webhook latency;
- parent agent pause/resume count, wait duration by tool, duplicate webhook count.

Если в проекте уже используется Inspector или Laravel logging stack, A2A handler должен добавлять structured context:

```php
logger()->info('A2A task completed', [
    'task_id' => $task->id,
    'context_id' => $task->contextId,
    'agent' => 'runtime',
    'state' => 'completed',
    'notification_delivery' => 'queued',
]);
```

## Риски и решения

Версия A2A. Стандарт будет развиваться, и breaking changes не должны протекать в queue/job/Neuron layer. Решение: держать публичный contract в `App\A2A\Protocol\V1`, покрывать его integration tests и добавлять новые версии через отдельные adapters (`V1_1`, `V2`) поверх тех же application actions.

Laravel 13. Так как A2A реализуется собственными controllers/actions, нет зависимости от Laravel support matrix стороннего A2A-пакета. Решение: держать protocol layer обычным Laravel HTTP code и проверять его feature tests.

Neuron 3.x. A2A protocol layer не должен зависеть от деталей Neuron API. Решение: писать Neuron code по установленной версии 3.x в `RuntimeAgentFactory`/jobs, а A2A adapter держать тонким и ограниченным DTO/JSON-RPC mapping.

Конфигурируемые agents. Если создавать PHP-класс под каждого агента, проект быстро обрастет однотипными классами только ради разных instructions/model/RAG settings. Решение: использовать `RuntimeAgentFactory`, хранить дефолтные definitions в `config/runtime-agents.php`, искать агента по `slug`, а позже перенести definitions в БД без изменения A2A protocol layer.

Хранение Task. PHP `serialize()` быстро, но плохо переносит обновления классов и версии протокола. Решение: JSONB + explicit mapper `Task <-> array` для собственных DTO, с `protocol_profile`/payload version и integration tests.

Webhook delivery. A2A push notifications имеют at-least-once semantics, поэтому клиент может получить дубликаты. Решение: включать `taskId`, `contextId`, event type и artifact id в payload, а клиентскую сторону проектировать идемпотентной.

Durable resume. Parent agent нельзя держать в памяти между remote A2A request и webhook result. Решение: для parent orchestration использовать Neuron Workflow interrupt/resume с `EloquentPersistence` или `DatabasePersistence`, хранить `workflow_resume_token` в `agent_runs`, а remote tool correlation - в `agent_tool_calls` и `a2a_child_tasks`.

Безопасность tools. A2A делает агента доступным внешним системам. Решение: начинать без destructive tools, добавлять allowlists и audit log до подключения реальных действий.

## Практический MVP для этого репозитория

1. Не использовать `neuron-core/a2a`; публичный A2A contract реализуется в приложении.
2. Создать `App\A2A\Protocol\Contracts\ProtocolAdapter`.
3. Создать `App\A2A\Protocol\V1\A2A1ProtocolAdapter`, DTO и JSON-RPC envelope classes.
4. Создать `routes/api.php`, route `POST /a2a/runtime` и `GET /a2a/runtime/.well-known/agent-card.json`.
5. Создать `config/runtime-agents.php` с несколькими дефолтными агентами, доступными по `slug`.
6. Создать `App\Neuron\RuntimeAgentFactory` и `App\Neuron\Agents\ConfigurableRuntimeAgent`.
7. Сохранять выбранный `agent_slug` в Task metadata или выводить его из A2A route/skill.
8. Реализовать только `text/plain` input/output.
9. Сохранять Task в PostgreSQL table `a2a_tasks`.
10. Сохранять A2A push notification configs в `a2a_task_push_notifications`.
11. Сделать `SendMessage` queue-first: вернуть `SUBMITTED`, dispatch `ProcessA2ATask`.
12. В job создать agent через `RuntimeAgentFactory::make($agentSlug)`, вызвать Neuron, сохранить artifact, перевести Task в `COMPLETED`/`FAILED`.
13. Отправлять стандартные A2A `statusUpdate` и `artifactUpdate` webhook notifications.
14. Добавить `RemoteA2AAgentTool`, который создает child A2A task и переводит parent run в `waiting_for_tool`.
15. Добавить таблицы `agent_runs`, `agent_tool_calls`, `a2a_child_tasks` для durable pause/resume.
16. Добавить webhook для remote tool results и `ResumeParentAgentJob`.
17. Закрыть endpoint Bearer-token middleware.
18. Добавить feature tests для Agent Card, auth, async `SendMessage`, `GetTask`, agent factory lookup по slug, queue job, webhook delivery и parent resume.
19. Зафиксировать protocol compatibility matrix в тестах: текущий `V1` adapter должен соответствовать актуальной A2A spec, будущие версии добавляются отдельными adapters.

## Источники

- Official A2A specification: https://a2a-protocol.org/latest/specification/
- A2A GitHub repository: https://github.com/a2aproject/A2A
- Google Developers Blog announcement: https://developers.googleblog.com/a2a-a-new-era-of-agent-interoperability/
- Neuron AI documentation: https://docs.neuron-ai.dev/
- Neuron Laravel package: https://github.com/neuron-core/neuron-laravel
