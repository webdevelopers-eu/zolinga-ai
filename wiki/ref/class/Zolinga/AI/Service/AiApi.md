## Synchronous AI API

Example of calling blocking AI API:

```php
echo $api->ai->prompt('default', 'Write a blog post about the Zolinga platform.');
```

Structured answer:
```php
$resp = $api->ai->prompt('default', 'John is 25 years old and he is available for work.', format: [
    "type" => "object",
    "properties" => [
        "name" => ["type" => "string"],
        "age" => ["type" => "integer"],
        "available" => ["type" => "boolean"]
    ],
    "required" => ["name", "age", "available"]
]);
print_r($resp);
```

## Asynchronous AI API

Use `$api->ai->promptAsync()` to queue a request for background processing. The request is stored in the database and processed later by `bin/zolinga ai:generate`. When done, your callback event is dispatched.

### AiEvent Request Format

| Key | Type | Required | Default | Description |
|---|---|---|---|---|
| `ai` | string | yes | `"default"` | Backend name from `config.ai.backends.*` |
| `prompt` | string or array | yes | `[]` | A plain prompt string, or an array of pipeline steps (see below) |
| `format` | array or null | no | `null` | JSON Schema for structured output, or `null` for plain text |
| `removeInvalidLinks` | bool | no | `false` | Strip invalid links from generated HTML |

Pipeline step format (when `prompt` is an array):
```php
[
    ['prompt' => 'Write a draft about X.', 'type' => 'step'],
    ['prompt' => '- Must not contain links.', 'type' => 'qc'],
    ['prompt' => 'Refine this: {{input}}', 'type' => 'step'],
]
```

### AiEvent Response Format

After processing, `$event->response['data']` contains the AI output (string for plain text, array if `format` was set). Any custom keys you set in `$event->response` are preserved.

### Example

```php
$aiEvent = new AiEvent("my-callback-event", OriginEnum::INTERNAL, [
    'ai' => 'default',
    'prompt' => 'Write a blog reasonably long post about the Zolinga platform.',
    'format' => null
], [
    'myCustomId' => 42 // preserved and available in your callback
]);
$aiEvent->uuid = 'my-unique-id'; // optional
$api->ai->promptAsync($aiEvent);
```

When the AI generates the article, it will trigger the specified `my-callback-event` event.
You should have your [event listener](:Zolinga Core:Events and Listeners) for the `my-callback-event` event to handle the generated article.

### Pipeline Example

```php
$aiEvent = new AiEvent("my-callback-event", OriginEnum::INTERNAL, [
    'ai' => 'default',
    'prompt' => [
        ['prompt' => 'Write a 500-word article about AI.', 'type' => 'step'],
        ['prompt' => '- No personal names.\n- No external links.', 'type' => 'qc'],
        ['prompt' => 'Add an introduction and conclusion to:\n\n{{input}}', 'type' => 'step'],
    ],
]);
$api->ai->promptAsync($aiEvent);
```

If a `qc` check fails the entire pipeline restarts (up to 3 retries).

## Configuration

For details refer to the [Zolinga AI Configuration](:Zolinga AI:Configuation) documentation.