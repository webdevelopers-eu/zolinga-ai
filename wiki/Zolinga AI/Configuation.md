## Configuring AI Backends

The AI module ships a list of **backends**. Each backend declares a set of **capabilities** and callers pick a backend by capability (or capability pattern), not by name. The module currently supports the [Ollama backend API](https://ollama.com/download).

The backend list lives at `config/zolinga-ai/ai-backends.json` (Zolinga URI: `config://zolinga-ai/ai-backends.json`).

If the file does not exist, the AI service starts with **no backends** and logs a warning.

## Quick Start

Create `config/zolinga-ai/ai-backends.json` with a JSON array of backend objects:

```json
[
    {
        "type": "ollama",
        "url": "https://login:password@ollama.example.com/api",
        "model": "gemma3:27b",
        "capabilities": ["default", "search:*", "translate:en-*"],
        "think": false,
        "replace": [
            {"search": "/^.*?<\\/think>\\s*/su", "replace": ""}
        ]
    }
]
```

Call it from PHP by capability (a string or array of required capabilities). The service picks the most specific backend whose capabilities cover all the ones you asked for:

```php
$api->ai->prompt('default', 'Hello, who are you?');
$api->ai->prompt(['search:images', 'workflow'], 'Find images of a blue labrador.');
$api->ai->prompt(['translate:en-cs'], 'Translate this to Czech.');
```

## Capability Matching

Capabilities are short strings (e.g. `default`, `workflow`, `search:images`, `translate:en-cs`). A backend declares an array of capabilities it can serve. A caller asks for one or more capabilities. A backend matches if **every** requested capability matches at least one of the backend's capabilities. Matching uses PHP's `fnmatch`, so `*` and `?` are wildcards.

Scoring: the matcher prefers **more specific** backends (fewer wildcards in the matched capability pair) and returns the backend with the highest score. A backend that matches with all wildcards is returned immediately on the first hit; otherwise the best score wins.

Examples (with the `gemma3:27b` backend above having `["default", "search:*", "translate:en-*"]`):

| Caller asks for                          | Matches? | Reason                                                |
| ---------------------------------------- | -------- | ----------------------------------------------------- |
| `'default'`                              | yes      | exact match                                           |
| `['default', 'search:images']`           | yes      | both covered                                          |
| `['default', 'search:*']`                | yes      | wildcard on caller side                               |
| `['search:images', 'voice']`             | no       | `voice` is not declared on the backend                |
| `'translate:en-cs'`                      | yes      | matches `translate:en-*`                              |
| `'workflow'`                             | no       | not declared on this backend                          |

You can run several backends behind different capability sets. The first one whose capabilities cover your request wins:

```json
[
    {
        "type": "ollama",
        "url": "https://user:pass@ai.example.com/api",
        "model": "gemma3:27b",
        "capabilities": ["default", "workflow", "search:*", "article-*"],
        "think": false
    },
    {
        "type": "ollama",
        "url": "https://user:pass@cloud.example.com/api",
        "model": "qwen3.5:cloud",
        "capabilities": ["translate:*"]
    }
]
```

`$api->ai->prompt('default', ...)` picks the gemma3 backend. `$api->ai->prompt(['translate:en-cs', 'oxford-dictionary'], ...)` matches a backend whose `capabilities` cover both — for example one that declares `["translate:en-*"]` (or `["translate:en-cs"]` specifically, which would score higher).

> **Tip:** A capability of `"*"` matches anything. Use it sparingly, and prefer a more specific tag like `default` so the matcher still has meaningful scoring.

## Backend Properties

Each entry in the array is an object. The table below lists every property the service understands. Anything not listed here is ignored.

| Property        | Type           | Required | Default                                            | Description |
| --------------- | -------------- | -------- | -------------------------------------------------- | ----------- |
| `type`          | string         | no       | `"ollama"`                                         | Backend type. Only `ollama` is implemented (`Zolinga\AI\Enum\AiTypeEnum`). |
| `url`           | string         | **yes**  | -                                                  | Base URL of the backend API endpoint. May embed basic auth credentials (`https://user:pass@host/api`). The service appends `/generate` to this URL. |
| `model`         | string         | **yes**  | -                                                  | Model identifier passed to the backend in the `model` field of every request. |
| `capabilities`  | string array   | **yes**  | -                                                  | Capabilities the backend can serve. Wildcards `*` and `?` are supported. At least one capability is required. |
| `systemPrompt`  | string         | no       | `config.ai.systemPrompt` (or "You are a very capable content creator.") | Per-backend system prompt override. |
| `think`         | bool           | no       | `null` (omitted from request)                      | When set, forwarded to the backend as Ollama's `think` parameter to enable or disable the model's extended thinking mode. |
| `options`       | object         | no       | `null`                                             | Map of model request options forwarded as Ollama `options`, for example `temperature`, `repeat_penalty`, `presence_penalty`, `num_ctx`. |
| `concurrency`   | int            | no       | `1`                                                | Maximum number of concurrent in-flight requests to this backend's URL. Implemented via the registry lock service. |
| `replace`       | object array   | no       | `[]`                                               | Regex search/replace rules applied to the generated text (see [Response Post-Processing](#response-post-processing)). |

A backend object with only `url`/`model` and no `capabilities` fails to load with an error logged to the `ai` channel.

## Response Post-Processing

Plain-text model output is run through every `replace` rule in the order they appear. JSON-schema output is decoded first and is **not** post-processed. Each rule has the shape:

```json
{
    "search": "/regex pattern/u",
    "replace": "replacement string",
    "description": "optional human-readable note"
}
```

The `search` value is a PCRE pattern (you must include the delimiters and any flags). The `replace` value supports backreferences (`$1`, `$2`, ...). If a rule fails to apply, the error is logged and the original text is kept.

A few real-world rules from the production config:

```json
"replace": [
    {"search": "/^.*?<\\/think>\\s*/su", "replace": ""},
    {"search": "/[“”]/us", "replace": "\""},
    {"search": "/[‒–—―]/u", "replace": " - "},
    {"search": "/\\[[^]]*?anchor text[^]]*?\\]/iu", "replace": "[this]"},
    {"search": "/https?:\\/\\/(www\\.)?example\\.com/", "replace": ""},
    {"search": "/ipdefender\\.(org|com|net)/iu", "replace": "ipdefender.eu"},
    {"search": "/\\(https?:\\/\\/(?:www\\.)?ipdefender\\.eu([^)]*)\\)/iu", "replace": "(\\1)"},
    {"search": "/((?:^>.*\\n)+)(#{1,6}\\s)/mu", "replace": "\\1\\n\\2"}
]
```

What they do:

1. Strip any leading `<think>...</think>` block some reasoning models emit.
2. Normalize curly double quotes to ASCII `"`.
3. Replace typographic dashes with ` - `.
4. Replace links containing the placeholder phrase `anchor text` with `[this]`.
5. Drop example.com anchors (cheap smoke test for prompts).
6. Rewrite old ipdefender domains to `ipdefender.eu`.
7. Convert absolute ipdefender links to relative form.
8. Ensure a blank line between a quote block and the following heading.

The first rule (`<think>` strip) is the most commonly needed; if your model does not emit thinking tokens you can drop it.

## Full Example

A production-shaped configuration with two backends, one for general content work and one for translations:

```json
[
    {
        "type": "ollama",
        "url": "https://user:pass@ai.example.com/api",
        "model": "gemma3:27b",
        "capabilities": [
            "default",
            "workflow",
            "search:*",
            "article-anonymize",
            "article-title",
            "article-marketing",
            "article-judge",
            "vyhledavani"
        ],
        "think": false,
        "replace": [
            {"search": "/^.*?<\\/think>\\s*/su", "replace": ""},
            {"search": "/[“”]/us", "replace": "\""},
            {"search": "/[‒–—―]/u", "replace": " - "},
            {"search": "/\\[[^]]*?anchor text[^]]*?\\]/iu", "replace": "[this]"},
            {"search": "/https?:\\/\\/(www\\.)?example\\.com/", "replace": ""},
            {"search": "/ipdefender\\.(org|com|net)/iu", "replace": "ipdefender.eu"},
            {"search": "/\\(https?:\\/\\/(?:www\\.)?ipdefender\\.eu([^)]*)\\)/iu", "replace": "(\\1)"},
            {"search": "/((?:^>.*\\n)+)(#{1,6}\\s)/mu", "replace": "\\1\\n\\2"}
        ]
    },
    {
        "type": "ollama",
        "url": "https://user:pass@ai.example.com/api",
        "model": "qwen3.5:cloud",
        "capabilities": ["translate:*"],
        "concurrency": 4
    }
]
```

The first backend serves anything in the `default` / `search:*` / `article-*` / `vyhledavani` / `workflow` family and gets post-processed to clean up model quirks. The second backend is dedicated to translation jobs (matched by capabilities like `translate:en-cs` or `translate:*`) and is allowed up to 4 concurrent in-flight requests.

## Programmatic Backend Access

You can also build an `AiBackend` directly and pass it to the service:

```php
use Zolinga\AI\Types\AiBackend;

$backend = new AiBackend([
    'url' => 'https://user:pass@ai.example.com/api',
    'model' => 'gemma3:27b',
    'capabilities' => ['default'],
]);

$api->ai->prompt($backend, 'Hello.');
```

This is useful in tests or when the backend is discovered dynamically.

## How `options` Are Applied

- `options` defined on a backend are merged into the request to the backend. They are **not** merged with the global `default` backend's options — each backend is self-contained.
- When you call `$api->ai->prompt()` and pass an `options` array, that array is merged on top of the backend's configured `options` first, and then the configured backend values take precedence for matching keys.
- For async pipelines (`promptAsync()`), request-level `options` are merged with per-step `options`, and that result is then passed to the backend together with the configured backend `options`.
