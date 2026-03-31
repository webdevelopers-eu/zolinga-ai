## Description

AI service providing prompt queuing, AI backend communication, and content generation.

- **Service:** `$api->ai`
- **Class:** `Zolinga\AI\Service\AiApi`
- **Module:** zolinga-ai
- **Event:** `system:service:ai`

## Usage

```php
// Queue an async AI prompt
$api->ai->promptAsync($event);

// Process queued prompts via CLI
// bin/zolinga ai:generate
```
