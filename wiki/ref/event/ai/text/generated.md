# ai:text:generated

Fired after a new AI text is created and inserted into the `aiTexts` database table via `AiTextModel::createTextModel()`.

## Origin

`internal`

## Event Class

`\Zolinga\System\Events\RequestEvent`

## Request Fields

| Field        | Type          | Description                                                        |
|--------------|---------------|--------------------------------------------------------------------|
| `id`         | `int`         | Database ID of the newly created `aiTexts` row.                    |
| `uuid`       | `string`      | UUID identifying the text (e.g. `vyhledavani:my-trademark`).       |
| `tag`        | `string|null` | Optional tag for categorization or versioning.                     |
| `triggerURL` | `string|null` | URL that triggered the text generation, if applicable.             |

## Emitter

`Zolinga\AI\Model\AiTextModel::createTextModel()`

## Usage Example

```json
{
    "listen": [
        {
            "event": "ai:text:generated",
            "class": "MyModule\\Listeners\\MyListener",
            "method": "onTextGenerated",
            "origin": ["internal"]
        }
    ]
}
```

```php
public function onTextGenerated(RequestEvent $event): void
{
    $uuid = $event->request['uuid'] ?? null;
    $tag = $event->request['tag'] ?? null;
    $triggerURL = $event->request['triggerURL'] ?? null;
    // ...
}
```
