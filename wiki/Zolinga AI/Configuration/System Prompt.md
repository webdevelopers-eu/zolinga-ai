# System Prompt

The **system prompt** is the instruction sent to the model before the user prompt. It sets the model's persona, behavior, and constraints for every request.

## Resolution Order

When `$api->ai->prompt()` is called, the system prompt is resolved in `AiService::processPrompt()` using a 3-level fallback — the first non-empty value wins:

```php
'system' => $ai->systemPrompt ?: $api->config['ai']['systemPrompt'] ?: "You are a very capable content creator.",
```

| Priority | Source | Where to set it | Scope |
|----------|--------|-----------------|-------|
| 1 (highest) | Per-backend `systemPrompt` | `config/zolinga-ai/ai-backends.json` → backend's `"systemPrompt"` key | Single backend |
| 2 | Global `config['ai']['systemPrompt']` | `config/global.json` or `config/local.json` → `"ai": {"systemPrompt": "..."}` | All backends |
| 3 (fallback) | Hardcoded default | `"You are a very capable content creator."` (source code) | All backends |

## Setting a Global System Prompt

The module default is defined in `modules/zolinga-ai/zolinga.json`:

```json
"config": {
    "ai": {
        "log": false,
        "systemPrompt": "You are an exceptionally talented copywriter."
    }
}
```

To override it globally, add the `ai.systemPrompt` key to your project config:

**`config/global.json`** (shared across all environments):

```json
{
    "ai": {
        "systemPrompt": "You are a trademark monitoring expert."
    }
}
```

**`config/local.json`** (environment-specific, highest priority of the two):

```json
{
    "ai": {
        "systemPrompt": "You are a trademark monitoring expert for local dev."
    }
}
```

The merge order is: module default (`zolinga.json`) → `global.json` → `local.json`. The last non-empty value wins.

## Per-Backend Override

A single backend can override the global system prompt by setting `"systemPrompt"` in `config/zolinga-ai/ai-backends.json`:

```json
[
    {
        "type": "ollama",
        "url": "https://user:pass@ai.example.com/api",
        "model": "gemma3:27b",
        "capabilities": ["default", "search:*"],
        "systemPrompt": "You are a trademark search assistant. Be concise."
    },
    {
        "type": "ollama",
        "url": "https://user:pass@ai.example.com/api",
        "model": "qwen3.5:cloud",
        "capabilities": ["translate:*"]
    }
]
```

In this example, the first backend uses its own system prompt. The second backend has no `systemPrompt` key, so it falls through to the global `config.ai.systemPrompt`.

If `systemPrompt` is absent, `null`, or not a string, the per-backend override is skipped and the global config is used.

## JSON-Format Requests

When a `format` (JSON schema) is passed to `prompt()`, the service appends an instruction to the resolved system prompt:

```
Return only valid JSON format that exactly matches the schema. Do not add text, tabs, or comments. If you cannot comply, return an empty object.
```

This is hardcoded and cannot be configured. It is appended after the resolved system prompt, so both the global/per-backend prompt and this instruction are sent to the model.

## How It Flows

```
config/zolinga-ai/ai-backends.json (per-backend systemPrompt)
    │
    ├── modules/zolinga-ai/zolinga.json (module default: config.ai.systemPrompt)
    ├── config/global.json (global override: ai.systemPrompt)
    ├── config/local.json (local override: ai.systemPrompt)
    │
    ▼
AiBackend object (systemPrompt property or null)
    │
    ▼
AiService::processPrompt()
    Resolves: $ai->systemPrompt ?: $api->config['ai']['systemPrompt'] ?: hardcoded default
    Appends JSON instruction if format is set
    │
    ▼
HTTP POST to backend /generate
    Body: {"model": ..., "prompt": ..., "stream": false, "system": <resolved prompt>}
```

## Key Files

| File | Role |
|------|------|
| `modules/zolinga-ai/src/Service/AiService.php` | `processPrompt()` — resolves and sends the system prompt |
| `modules/zolinga-ai/src/Config/Backends/AiBackendConfig.php` | Parses per-backend `systemPrompt` from config |
| `modules/zolinga-ai/zolinga.json` | Module default `config.ai.systemPrompt` |
| `config/zolinga-ai/ai-backends.json` | Per-backend `systemPrompt` overrides |
| `config/global.json` | Global system prompt override |
| `config/local.json` | Local environment system prompt override |