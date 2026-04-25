## Synchronous AI API

Example of calling blocking AI API:

```php
echo $api->ai->prompt(
    'default',
    'Write a blog post about the Zolinga platform.'
);
```

Prompt with backend options:

```php
$resp = $api->ai->prompt(
    'default',
    'Summarize the text for a technical audience.',
    null,
    [
        'temperature' => 0.2,
        'num_ctx' => 8192,
    ]
);
```

If the selected backend defines `config.ai.backends.default.options` or `config.ai.backends.<name>.options`, those configured values are merged into the request too. For matching keys, the configured backend values currently override the values passed in `$api->ai->prompt()`.

Structured answer:

```php
$resp = $api->ai->prompt(
    'default',
    'John is 25 years old and he is available for work.',
    format: [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'available' => ['type' => 'boolean']
        ],
        'required' => ['name', 'age', 'available']
    ],
    options: [
        'temperature' => 0,
    ]
);
print_r($resp);
```

`options` are forwarded to the configured backend and merged with the backend's configured `options` defaults. Use them for backend-specific controls such as context length or temperature. For synchronous `$api->ai->prompt()` calls, matching keys from the configured backend currently take precedence.

## Asynchronous AI API

Use `$api->ai->promptAsync()` to queue a request for background processing. The request is stored in the database and processed later by `bin/zolinga ai:generate`. When done, your callback event is dispatched. For detailed background debug logs enable the global config setting `config.ai.log` to log every run into `data/zolinga-ai/ai.log`.

### AiEvent Request Format

| Key | Type | Required | Default | Description |
|---|---|---|---|---|
| `ai` | string | yes | `default` | Backend name from `config.ai.backends.*` |
| `prompt` | string or array | yes | `[]` | A plain prompt string, or an array of pipeline steps (see below) |
| `format` | array or null | no | `null` | JSON Schema for structured output, or `null` for plain text |
| `options` | array | no | `[]` | Default backend options applied to every step and QC check in the request before any step-level overrides |
| `removeInvalidLinks` | bool | no | `false` | Strip invalid links from generated HTML |

Pipeline step format (when `prompt` is an array):

```php
[
    [
        'prompt' => 'Write a draft about X.',
        'type' => 'step',
    ],
    [
        'prompt' => '- Must not contain links.',
        'type' => 'qc',
    ],
    [
        'prompt' => 'Refine this: {{input}}',
        'type' => 'step',
    ],
]
```

Step-level backend overrides are also supported:

```php
[
    [
        'prompt' => 'Write a draft about X.',
        'type' => 'step',
    ],
    [
        'prompt' => '- Must not contain links.',
        'type' => 'qc',
        'options' => [
            'temperature' => 0,
        ],
    ],
    [
        'prompt' => 'Refine this: {{input}}',
        'type' => 'step',
        'options' => [
            'temperature' => 0.4,
        ],
    ],
]
```

If request-level `options` are present, each step's `options` are merged on top of them. Matching keys in the step override the request-level defaults. The merged step options are then passed to `$api->ai->prompt()`, where configured backend `options` are also applied.

### AiEvent Response Format

After processing, `$event->response['data']` contains the AI output. It is a string for plain-text prompts and an array when `format` is set. Any custom keys you set in `$event->response` are preserved.

### Example

```php
$aiEvent = new AiEvent('my-callback-event', OriginEnum::INTERNAL, [
    'ai' => 'default',
    'prompt' => 'Write a reasonably long blog post about the Zolinga platform.',
    'format' => null,
    'options' => [
        'temperature' => 0.3,
    ],
], [
    'myCustomId' => 42,
]);
$aiEvent->uuid = 'my-unique-id';
$api->ai->promptAsync($aiEvent);
```

When the AI generates the content, it will trigger the specified callback event. Your listener for that event receives the same response payload plus any custom response fields you stored before queueing.

The `myCustomId` response field is preserved and available in your callback.
The `uuid` assignment is optional.

You should have your [event listener](:Zolinga Core:Events and Listeners) for the `my-callback-event` event to handle the generated article.

### Pipeline Example

```php
$aiEvent = new AiEvent('my-callback-event', OriginEnum::INTERNAL, [
    'ai' => 'default',
    'options' => [
        'temperature' => 0.1,
        'num_ctx' => 8192,
    ],
    'prompt' => [
        [
            'prompt' => 'Write a 500-word article about AI.',
            'type' => 'step',
        ],
        [
            'prompt' => '- No personal names.\n- No external links.',
            'type' => 'qc',
            'options' => [
                'temperature' => 0,
            ],
        ],
        [
            'prompt' => 'Add an introduction and conclusion to:\n\n{{input}}',
            'type' => 'step',
            'options' => [
                'temperature' => 0.4,
            ],
        ],
    ],
]);
$api->ai->promptAsync($aiEvent);
```

If a `qc` check fails, the entire pipeline restarts up to 3 times.

## Configuration

For details refer to the [Zolinga AI Configuration](:Zolinga AI:Configuation) documentation.