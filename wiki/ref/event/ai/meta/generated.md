## Description

Internal event fired when AI-generated metadata (title, description, TL;DR) is ready to be saved. The handler updates the corresponding `aiTexts` row with the structured metadata.

- **Event:** `ai:meta:generated`
- **Class:** `Zolinga\AI\Elements\AiTextElement`
- **Method:** `onGenerateMeta`
- **Origin:** `internal`
- **Event Type:** `\Zolinga\AI\Events\AiEvent`

## Parameters

The event carries the AI generation result:

| Property | Type | Description |
|---|---|---|
| `ai` | `string` | AI backend name used for metadata generation |
| `uuid` | `string` | Unique identifier for the article whose metadata was generated |
| `prompt` | `string` | The meta-prompt used for generation (from `data/meta-prompt.txt`) |
| `format` | `object` | JSON schema object requesting structured output with `title`, `description`, and `tldr` properties |

## Response Format

The AI response must be a JSON object with these fields:

| Field | Type | Description |
|---|---|---|
| `title` | `string` | Article title, clear and keyword-relevant |
| `description` | `string` | 110–160 character plain description |
| `tldr` | `string` | 4–16 sentence practical summary |

## Behavior

Validates the response contains all three required fields, updates the `aiTexts` row, and logs the result. If any field is missing, an exception is thrown.

## Trigger

This event is queued automatically by `AiTextElement::generateMeta()` when an `<ai-text>` element has `show-meta` set and the metadata is not yet available. It can also be triggered manually via `$api->ai->promptAsync()` with the appropriate `AiEvent` configuration.
