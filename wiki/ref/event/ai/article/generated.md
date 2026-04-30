## Description

Internal event fired when an AI-generated article is ready to be saved. The handler stores the generated content for later retrieval by the `<ai-text>` content element.

- **Event:** `ai:article:generated`
- **Class:** `Zolinga\AI\Elements\AiTextElement`
- **Method:** `onGenerateArticle`
- **Origin:** `internal`
- **Event Type:** `\Zolinga\AI\Events\AiEvent`

## Parameters

### Request

| Property | Type | Description |
|---|---|---|
| `ai` | `string` | AI backend name |
| `prompt` | `string\|array` | The prompt or pipeline steps used for generation |
| `tag` | `string\|null` | Optional tag for categorization or versioning |
| `options` | `array` | AI generation options (temperature, penalties, etc.) |

### Response

| Property | Type | Description |
|---|---|---|
| `data` | `string\|array` | The AI-generated content |
| `triggerURL` | `string|null` | URL that triggered the generation |
| `removeInvalidLinks` | `bool` | Whether invalid links were removed during generation |
| `generateMetaAI` | `string|null` | Backend to use for metadata generation, or `null` if not requested |

## Behavior

Processes the AI output and stores or updates the generated article content in the database for later rendering by the `<ai-text>` CMS element.

If `generateMetaAI` is set, the handler immediately queues a secondary `ai:meta:generated` event to generate title, description, and TL;DR metadata for the article.
