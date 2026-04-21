## Description

AI service providing synchronous prompting, async prompt queuing, backend communication, and workflow execution.

- **Service:** `$api->ai`
- **Class:** `Zolinga\AI\Service\AiService`
- **Module:** zolinga-ai
- **Event:** `system:service:ai`

The service forwards backend-specific `options` to the configured AI backend. For queued async pipelines you can define default request-level options and override them per `step` or `qc` item.

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
- `options`: Optional backend options applied to every prompt in the request.
- `removeInvalidLinks`: Optional flag used by article generation handlers.

### Pipeline Step Format

When `prompt` is an array, each item may define:

- `prompt`: Prompt or QC criteria text.
- `type`: `step` or `qc`.
- `options`: Optional backend options for that specific step.

Step options are merged on top of request-level options, so step-level values override the defaults for matching keys. The same merge behavior applies to `qc` steps.

Example:

```php
$aiEvent = new AiEvent('my:callback:event', OriginEnum::INTERNAL, [
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
			'prompt' => '- No marketing language.\n- No external links.',
			'type' => 'qc',
			'options' => [
				'temperature' => 0,
			],
		],
		[
			'prompt' => 'Rewrite this for founders:\n\n{{input}}',
			'type' => 'step',
			'options' => [
				'temperature' => 0.4,
			],
		],
	],
], [
	'jobId' => 42,
]);

$api->ai->promptAsync($aiEvent);
```
