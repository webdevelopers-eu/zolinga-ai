## Description

AI service providing synchronous prompting, async prompt queuing, backend communication, and workflow execution.

- **Service:** `$api->ai`
- **Class:** `Zolinga\AI\Service\AiService`
- **Module:** zolinga-ai
- **Event:** `system:service:ai`

The service forwards backend-specific `options` to the configured AI backend. You can define backend defaults in `config.ai.backends.<name>.options` and also pass `options` directly to `$api->ai->prompt()`. For synchronous `prompt()` calls these two arrays are merged, and matching keys from the configured backend currently override the same keys passed in the method call. For queued async pipelines you can define default request-level options and override them per `step` or `qc` item.

## Synchronous Usage

Plain text answer:

```php
$answer = $api->ai->prompt(
	'default',
	'Write a short introduction to trademark monitoring for SaaS founders.'
);
```

Prompt with backend options:

```php
$answer = $api->ai->prompt(
	'default',
	'Summarize this article in 5 bullet points.',
	null,
	[
		'temperature' => 0.2,
		'num_ctx' => 8192,
	]
);
```

If the selected backend also defines `config.ai.backends.default.options` or `config.ai.backends.<name>.options`, those configured values are sent too. For matching keys, the backend configuration currently wins over the values passed in `$api->ai->prompt()`.

Structured response:

```php
$answer = $api->ai->prompt(
	'default',
	'Extract the company name and filing country from the text.',
	[
		'type' => 'object',
		'properties' => [
			'company' => ['type' => 'string'],
			'country' => ['type' => 'string'],
		],
		'required' => ['company', 'country'],
	],
	[
		'temperature' => 0,
	]
);
```

## Async Usage

Queue an async prompt:

```php
$api->ai->promptAsync($event);
```

Process queued prompts:

```bash
bin/zolinga ai:generate
```

### Async Request Keys

`$event->request` supports these keys:

- `ai`: Backend name from `config.ai.backends.*`.
- `prompt`: A plain prompt string, or a pipeline array.
- `format`: JSON schema array for structured output, or `null` for plain text.
- `options`: Optional backend options applied to every prompt in the request before any step-level overrides are applied.
- `removeInvalidLinks`: Optional flag used by article generation handlers.
- `priority`: Float between 0 and 1 (exclusive). Higher values are processed first. Default: `0.5`.

### Pipeline Step Format

When `prompt` is an array, each item may define:

- `prompt`: Prompt or QC criteria text.
- `type`: `step` or `qc`.
- `options`: Optional backend options for that specific step.

Step options are merged on top of request-level options, so step-level values override the defaults for matching keys. The same merge behavior applies to `qc` steps. The merged step options are then passed to `$api->ai->prompt()`, where configured backend `options` are still applied as the final layer.

The **fifth argument** (`$response`) is an `ArrayAccess|array` that you can pre-fill with arbitrary data. This data is preserved through the async round-trip and delivered to your callback listener alongside the generated text. Use it to store record IDs, relation keys, entity types, or any other metadata your listener needs to process the result.

Example:

```php
$aiEvent = new AiEvent(
    'my-unique-id',          // required — duplicate UUIDs are silently ignored
    'my:callback:event',     // dispatched after generation completes
    OriginEnum::INTERNAL,
    [                         // $request
        'ai' => 'default',
        'options' => [
            'temperature' => 0.1,
            'num_ctx' => 8192,
        ],
        'prompt' => [
            [
                'prompt' => 'Write a factual draft about EU trademark opposition periods.',
                'type' => 'step',
            ],
            [
                'prompt' => "- No marketing language.\n- No external links.",
                'type' => 'qc',
                'options' => [
                    'temperature' => 0,
                ],
            ],
            [
                'prompt' => "Rewrite this for founders:\n\n{{input}}",
                'type' => 'step',
                'options' => [
                    'temperature' => 0.4,
                ],
            ],
        ],
    ],
    [                         // $response — pre-fill with custom metadata
        'articleId' => 42,
        'sectionKey' => 'opposition-period',
    ],
);

$api->ai->promptAsync($aiEvent);
```
