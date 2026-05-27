# Google A2A: как работает протокол и как интегрировать его в Laravel + Neuron

## Кратко

A2A, Agent2Agent, - открытый протокол для взаимодействия независимых AI-агентов. Он был представлен Google и передан в open-source экосистему A2A/Linux Foundation. Его задача - дать агентам общий язык: находить друг друга, понимать доступные навыки, безопасно передавать задачи, получать статусы и результаты, не раскрывая внутренние инструменты, память и реализацию агента.

Для текущего проекта это означает, что Laravel-приложение может стать A2A server, то есть удаленным агентом, доступным другим агентам по HTTP. Neuron остается внутренним движком агента: он отвечает за LLM provider, инструкции, tools/toolkits, память, RAG или workflow. A2A добавляет внешний протокольный слой: Agent Card, JSON-RPC endpoint, задачи, сообщения и артефакты.

Текущий проект:

- Laravel `^13.8`, PHP `^8.3`.
- Установлен `neuron-core/neuron-ai` `^3.14`.
- В проекте пока нет `app/Neuron`, `app/A2A`, `routes/api.php` и `config/neuron.php`.
- Docker уже поднимает `app`, `queue-worker`, `nginx`, `postgres`, `pgweb`; `QUEUE_CONNECTION=database`, `DB_CONNECTION=pgsql`.

Рекомендуемый путь интеграции: добавить пакет `neuron-core/a2a`, создать A2A server через Artisan, реализовать task repository на PostgreSQL, запускать выполнение через Laravel Queue, а результат и промежуточные статусы доставлять через A2A push notifications, если клиент передал webhook configuration.

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

В Laravel helper из `neuron-core/a2a` card для конкретного агента публикуется под route prefix:

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

Важно: `neuron-core/a2a` `1.0.0` в README показывает Agent Card формата A2A `0.3.0` с полями `protocolVersion`, `url`, `preferredTransport`. Актуальная спецификация A2A `1.0` использует `supportedInterfaces`. Перед production-интеграцией нужно проверить, какую версию ожидают ваши клиенты. Для совместимости с текущим PHP-пакетом разумно начать с версии, которую он генерирует, а отдельной задачей проверить поддержку A2A 1.0.

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

`neuron-core/a2a` на момент проверки README перечисляет JSON-RPC methods в стиле:

- `message/send`
- `tasks/get`
- `tasks/list`
- `tasks/cancel`
- `agent/getAuthenticatedExtendedCard`

Это еще один признак версии/совместимости, который нужно проверить тестом с реальным A2A client.

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

Пример для текущего `neuron-core/a2a` может отличаться:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "message/send",
  "params": {
    "messages": [
      {
        "role": "user",
        "parts": [
          {
            "kind": "text",
            "text": "Hello"
          }
        ]
      }
    ]
  }
}
```

## Архитектура интеграции в текущем проекте

Предлагаемая структура:

```text
app/
  A2A/
    RuntimeAgentServer.php
    RuntimeAgentTaskRepository.php
    RuntimeAgentMessageHandler.php
    RuntimeAgentPushNotifier.php
    RuntimeAgentPushNotificationRepository.php
  Jobs/
    ProcessA2ATask.php
  Models/
    A2ATask.php
    A2ATaskPushNotification.php
  Neuron/
    Agents/
      RuntimeAgent.php
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

- `RuntimeAgentServer`: объявляет Agent Card и связывает A2A server с repository/handler.
- `RuntimeAgentTaskRepository`: сохраняет и читает A2A Task из PostgreSQL.
- `RuntimeAgentMessageHandler`: принимает входной A2A Message, создает/обновляет Task, сохраняет notification config и отправляет `ProcessA2ATask` в Laravel Queue.
- `ProcessA2ATask`: выполняет Neuron Agent асинхронно, обновляет Task state/artifacts/history.
- `RuntimeAgentPushNotifier`: отправляет A2A-compatible webhook notifications клиенту при смене статуса и появлении artifacts.
- `RuntimeAgent`: обычный Neuron agent с provider, instructions, tools, chat history.
- `routes/api.php`: публикует A2A endpoint.

## Пошаговая интеграция

### Шаг 1. Установить A2A пакет

```bash
composer require neuron-core/a2a
```

Пакет `neuron-core/a2a` `1.0.0` требует PHP `^8.1`, что подходит проекту на PHP `^8.3`. В Packagist он указывает `laravel/framework: ^12.0` и `neuron-core/neuron-ai: ^2.6` как `suggests`, а не hard requirements. Так как проект уже на Laravel 13 и Neuron 3.14, после установки обязательно выполнить:

```bash
composer test
php artisan route:list
```

Если Composer или runtime покажет несовместимость с Laravel 13/Neuron 3, нужно зафиксировать версию пакета или рассмотреть тонкий собственный JSON-RPC controller поверх интерфейсов A2A.

### Шаг 2. Зарегистрировать service provider

В проекте используется Laravel 13 style через `bootstrap/providers.php`. После установки добавить provider туда:

```php
<?php

use App\Providers\AppServiceProvider;
use NeuronCore\A2A\Laravel\A2AServiceProvider;

return [
    AppServiceProvider::class,
    A2AServiceProvider::class,
];
```

### Шаг 3. Сгенерировать A2A server

```bash
php artisan make:a2a RuntimeAgent
```

Ожидаемые файлы:

- `app/A2A/RuntimeAgentServer.php`
- `app/A2A/RuntimeAgentTaskRepository.php`
- `app/A2A/RuntimeAgentMessageHandler.php`

Сгенерированные классы нужно доработать вручную: repository должен сохранять задачи, а handler должен ставить выполнение в очередь. Neuron вызывается в queue job, а не внутри HTTP request.

### Шаг 4. Создать `routes/api.php`

В текущем Laravel skeleton есть только `routes/web.php` и `routes/console.php`. Если `routes/api.php` отсутствует, создать файл и убедиться, что Laravel bootstrap подключает API routes в версии 13. Минимальный route:

```php
<?php

use App\A2A\RuntimeAgentServer;
use Illuminate\Support\Facades\Route;
use NeuronCore\A2A\Laravel\A2A;

Route::middleware(['auth:sanctum'])->group(function (): void {
    A2A::route('/a2a/runtime', RuntimeAgentServer::class);
});
```

Если приложение не использует Sanctum, лучше завести отдельный middleware для machine-to-machine token, например `auth.a2a`, и валидировать `Authorization: Bearer ...` через hashed token в БД или secrets manager.

После регистрации должны быть доступны:

```text
POST /api/a2a/runtime
GET  /api/a2a/runtime/.well-known/agent-card.json
```

Точный prefix зависит от того, как Laravel 13 bootstrap подключает `routes/api.php` в проекте.

### Шаг 5. Реализовать Neuron Agent

Для установленного `neuron-core/neuron-ai` 3.x базовый класс находится в `NeuronAI\Agent\Agent`.

```php
<?php

namespace App\Neuron\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\SystemPrompt;

class RuntimeAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            key: config('services.openai.key'),
            model: config('services.openai.model', 'gpt-4.1-mini'),
        );
    }

    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are an A2A-compatible runtime assistant inside a Laravel application.',
                'Answer only with information you are authorized to expose.',
            ],
            steps: [
                'Understand the requested task.',
                'Use available tools only when needed.',
                'Return concise, verifiable output.',
            ],
            output: [
                'Prefer text/plain unless the request explicitly asks for JSON.',
            ],
        );
    }
}
```

Добавить env/config:

```dotenv
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4.1-mini
```

```php
// config/services.php
'openai' => [
    'key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
],
```

Provider можно заменить на Gemini, Anthropic, Ollama или OpenAI-compatible endpoint, не меняя A2A слой.

### Шаг 6. Сделать Message Handler асинхронным

Идея handler:

1. Принять A2A `Task` и входящие `Message[]`.
2. Извлечь `TextPart` или `DataPart`.
3. Сохранить входные сообщения в `history`.
4. Если клиент передал `taskPushNotificationConfig`, сохранить webhook config.
5. Поставить `ProcessA2ATask` в Laravel Queue.
6. Сразу вернуть Task со статусом `SUBMITTED` или `WORKING`.

Neuron должен вызываться не внутри HTTP request, а в queue job. Так A2A endpoint быстро отвечает клиенту, а долгие LLM/tool/RAG workflow не держат соединение открытым.

Скелет логики:

```php
<?php

namespace App\A2A;

use App\Jobs\ProcessA2ATask;
use NeuronCore\A2A\Contract\MessageHandlerInterface;
use NeuronCore\A2A\Enum\TaskState;
use NeuronCore\A2A\Model\Task;
use NeuronCore\A2A\Model\TaskStatus;

class RuntimeAgentMessageHandler implements MessageHandlerInterface
{
    public function __construct(
        private RuntimeAgentTaskRepository $tasks,
        private RuntimeAgentPushNotificationRepository $pushNotifications,
    ) {
    }

    public function handle(Task $task, array $messages): Task
    {
        $queuedTask = new Task(
            id: $task->id,
            contextId: $task->contextId,
            status: new TaskStatus(
                state: TaskState::SUBMITTED,
                message: null,
            ),
            history: [
                ...($task->history ?? []),
                ...$messages,
            ],
        );

        $this->tasks->save($queuedTask);

        // Если SendMessageConfiguration содержит taskPushNotificationConfig,
        // сохранить ее рядом с task id. Названия полей зависят от версии A2A SDK.
        // $this->pushNotifications->saveFromRequest($queuedTask->id, $configuration);

        ProcessA2ATask::dispatch($queuedTask->id);

        return $queuedTask;
    }
}
```

Этот пример нужно подогнать под фактические constructors `neuron-core/a2a` после установки. Важно сохранить архитектурную идею: A2A handler не должен выполнять LLM-inference синхронно; он только адаптирует протокол, сохраняет Task и запускает queue job.

### Шаг 7. Выполнить Task в Laravel Queue

`ProcessA2ATask` делает всю дорогую работу: переводит Task в `WORKING`, вызывает Neuron, сохраняет `COMPLETED`/`FAILED`, отправляет push notification события.

```php
<?php

namespace App\Jobs;

use App\A2A\RuntimeAgentPushNotifier;
use App\A2A\RuntimeAgentTaskRepository;
use App\Neuron\Agents\RuntimeAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronCore\A2A\Enum\TaskState;
use NeuronCore\A2A\Model\Artifact;
use NeuronCore\A2A\Model\Message;
use NeuronCore\A2A\Model\Part\TextPart;
use NeuronCore\A2A\Model\Task;
use NeuronCore\A2A\Model\TaskStatus;
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
    ): void {
        $task = $tasks->find($this->taskId);

        if ($task === null) {
            return;
        }

        $task = $this->withState($task, TaskState::WORKING);
        $tasks->save($task);
        $notifier->sendStatusUpdate($task);

        try {
            $input = $this->extractText($task->history ?? []);

            $response = RuntimeAgent::make()
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
                    state: TaskState::COMPLETED,
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
            $failed = $this->withState($task, TaskState::FAILED);
            $tasks->save($failed);
            $notifier->sendStatusUpdate($failed);

            report($exception);
        }
    }

    private function withState(Task $task, TaskState $state): Task
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

### Шаг 8. Реализовать Task Repository на PostgreSQL

Для разработки можно начать с сериализованного payload в JSONB. Для production лучше отдельно индексировать `id`, `context_id`, `state`, `created_at`, `updated_at`.

Миграция:

```php
Schema::create('a2a_tasks', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->uuid('context_id')->nullable()->index();
    $table->string('state')->index();
    $table->jsonb('payload');
    $table->timestamps();
});
```

Repository responsibilities:

- `save(Task $task)`: upsert по `id`.
- `find(string $taskId)`: вернуть deserialized Task или `null`.
- `findAll(array $filters, ?int $limit, ?int $offset)`: поддержать фильтры по `contextId` и `state`.
- `count(array $filters)`: для pagination.
- `generateTaskId()` и `generateContextId()`: использовать `Str::uuid()`.

Если библиотека не дает стабильный JSON serializer для `Task`, лучше сделать explicit mapper `Task <-> array`, а не хранить PHP `serialize()`. Это уменьшит риск при обновлениях классов.

### Шаг 9. Реализовать A2A push notifications

A2A standard поддерживает уведомления через `PushNotificationConfig`: клиент передает webhook URL и authentication info, а сервер отправляет HTTP POST с тем же типом событий, что и streaming response.

Для async queue flow это основной канал уведомлений:

1. Клиент вызывает `SendMessage`/`message/send` и передает `taskPushNotificationConfig`.
2. Laravel сохраняет config, привязанный к `task_id`.
3. `message/send` возвращает Task со статусом `SUBMITTED`.
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
- считать любой HTTP `2xx` успешной доставкой;
- делать retry с exponential backoff через отдельную queue job;
- быть идемпотентным, потому что webhook delivery может повторяться.

Если пакет `neuron-core/a2a` уже реализует CRUD methods для push notification configs (`CreateTaskPushNotificationConfig`, `GetTaskPushNotificationConfig`, `ListTaskPushNotificationConfigs`, `DeleteTaskPushNotificationConfig` или их `0.3` аналоги), нужно подключить их к этой же таблице. Если пакет поддерживает только config внутри `SendMessageConfiguration`, MVP все равно должен сохранять и использовать этот config.

### Шаг 10. Описать Agent Card

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
- Bearer token, OAuth2/OIDC или mTLS; не оставлять endpoint публичным без auth.
- Rate limiting на route group.
- Явные allowlists для tools/actions, которые может выполнить Neuron Agent.
- Логировать `task_id`, `context_id`, requester, duration, model, token usage, итоговое state.
- Не писать raw secrets в Task history/artifacts.
- Ограничить размер входных `parts`, количество сообщений и размер файлов.
- Для `url` file parts скачивать только по allowlist доменов и с timeout.
- Для push notifications использовать credentials из `PushNotificationConfig.authentication`, хранить их зашифрованно и не логировать.

## Queue и A2A notifications

Целевая модель для этого проекта - асинхронная. `message/send` не должен ждать Neuron inference. Он должен создать Task, сохранить входные сообщения, сохранить push notification config, поставить job в очередь и вернуть Task со статусом `SUBMITTED`.

Основной flow:

1. Client отправляет `message/send` или `SendMessage`.
2. Laravel создает Task `SUBMITTED`.
3. Laravel сохраняет `taskPushNotificationConfig`, если он передан.
4. Laravel dispatches `ProcessA2ATask`.
5. Job переводит Task в `WORKING` и отправляет `statusUpdate`.
6. Job вызывает Neuron Agent.
7. Job сохраняет artifacts, переводит Task в `COMPLETED` или `FAILED`.
8. Job отправляет `artifactUpdate` и финальный `statusUpdate`.
9. Client может в любой момент вызвать `tasks/get`, даже если webhook не дошел.

SSE streaming можно добавить позже через `SendStreamingMessage`/`SubscribeToTask`, если пакет и инфраструктура поддерживают `text/event-stream`. Для текущего Docker setup queue + push notifications проще и надежнее: `queue-worker` уже есть, PostgreSQL queue backend подходит для старта, а при росте нагрузки можно перейти на Redis.

## Тестирование

Минимальный набор проверок:

```bash
composer test
php artisan route:list
curl -s http://localhost/api/a2a/runtime/.well-known/agent-card.json | jq
```

Проверка JSON-RPC запроса зависит от версии метода, которую реально принимает `neuron-core/a2a`.

Вариант для A2A 0.3 style из README пакета:

```bash
curl -X POST http://localhost/api/a2a/runtime \
  -H "Authorization: Bearer ${A2A_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "message/send",
    "params": {
      "messages": [
        {
          "role": "user",
          "parts": [
            {"kind": "text", "text": "Say hello from the Laravel runtime"}
          ]
        }
      ],
      "configuration": {
        "taskPushNotificationConfig": {
          "url": "https://client.example.com/a2a/webhooks/tasks",
          "authentication": {
            "scheme": "Bearer",
            "credentials": "client-webhook-token"
          }
        }
      }
    }
  }'
```

Вариант для A2A 1.0 style:

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
- Valid `message/send` creates Task row with `SUBMITTED` state and dispatches `ProcessA2ATask`.
- Valid `message/send` stores `taskPushNotificationConfig`.
- Queue job maps user text into Neuron `UserMessage`.
- Successful queue job creates `COMPLETED` task with artifact.
- Successful queue job sends `artifactUpdate` and final `statusUpdate` webhook.
- Neuron exception creates `FAILED` task without leaking secrets and sends failure `statusUpdate`.
- `tasks/get` returns persisted task.
- `tasks/cancel` rejects terminal tasks and cancels cancellable tasks if implemented.

## Наблюдаемость

Для production нужно видеть:

- количество A2A requests по method;
- latency по method и skill;
- task state distribution;
- LLM provider/model latency;
- token usage и стоимость;
- errors по категориям: auth, validation, provider, timeout, tool;
- queue wait time для async tasks;
- push notification delivery success/failure, retry count и webhook latency.

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

Версия A2A. Спецификация A2A 1.0 и README `neuron-core/a2a` используют разные naming conventions для Agent Card и JSON-RPC methods. Решение: после установки зафиксировать фактический contract через integration tests и явно указать `protocolVersion` в Agent Card.

Laravel 13. Пакет A2A указывает Laravel `^12.0` как suggested framework support. Решение: проверить install/runtime на Laravel 13; если helper provider несовместим, использовать framework-agnostic `A2AServer` внутри собственного Laravel controller.

Neuron 3.x. README A2A показывает старый импорт `NeuronAI\Agent`, а в проекте установлен `NeuronAI\Agent\Agent`. Решение: писать Neuron code по установленной версии 3.x и держать A2A adapter тонким.

Хранение Task. PHP `serialize()` быстро, но плохо переносит обновления классов. Решение: JSONB + explicit mapper, либо сериализация, предоставленная самой библиотекой, если она стабильна.

Webhook delivery. A2A push notifications имеют at-least-once semantics, поэтому клиент может получить дубликаты. Решение: включать `taskId`, `contextId`, event type и artifact id в payload, а клиентскую сторону проектировать идемпотентной.

Безопасность tools. A2A делает агента доступным внешним системам. Решение: начинать без destructive tools, добавлять allowlists и audit log до подключения реальных действий.

## Практический MVP для этого репозитория

1. Установить `neuron-core/a2a`.
2. Добавить `A2AServiceProvider` в `bootstrap/providers.php`.
3. Создать `routes/api.php` и route `/a2a/runtime`.
4. Сгенерировать `RuntimeAgent` server через `php artisan make:a2a RuntimeAgent`.
5. Создать `App\Neuron\Agents\RuntimeAgent` на `NeuronAI\Agent\Agent`.
6. Реализовать только `text/plain` input/output.
7. Сохранять Task в PostgreSQL table `a2a_tasks`.
8. Сохранять A2A push notification configs в `a2a_task_push_notifications`.
9. Сделать `message/send` queue-first: вернуть `SUBMITTED`, dispatch `ProcessA2ATask`.
10. В job вызвать Neuron, сохранить artifact, перевести Task в `COMPLETED`/`FAILED`.
11. Отправлять стандартные A2A `statusUpdate` и `artifactUpdate` webhook notifications.
12. Закрыть endpoint Bearer-token middleware.
13. Добавить feature tests для Agent Card, auth, async `message/send`, queue job и webhook delivery.
14. После MVP решить, нужна ли поддержка A2A 1.0 `SendMessage` или достаточно формата, который предоставляет текущий PHP пакет.

## Источники

- Official A2A specification: https://a2a-protocol.org/latest/specification/
- A2A GitHub repository: https://github.com/a2aproject/A2A
- Google Developers Blog announcement: https://developers.googleblog.com/a2a-a-new-era-of-agent-interoperability/
- `neuron-core/a2a` Packagist README: https://packagist.org/packages/neuron-core/a2a
- Neuron AI documentation: https://docs.neuron-ai.dev/
- Neuron Laravel package: https://github.com/neuron-core/neuron-laravel
