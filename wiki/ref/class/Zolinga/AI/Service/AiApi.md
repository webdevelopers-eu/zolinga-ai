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

Example of calling non-blocking AI API:

```php
$aiEvent = new AiEvent("my-callback-event", OriginEnum::INTERNAL, [
    'ai' => 'default',
    'prompt' => 'Write a blog reasonably long post about the Zolinga platform.',
    'format' => null
]);
$api->ai->promptAsync($aiEvent);
```

When the AI generates the article, it will trigger the specified `my-callback-event` event.
You should have your [event listener](:Zolinga Core:Events and Listeners) for the `my-callback-event` event to handle the generated article.

## Configuration

For details refer to the [Zolinga AI Configuration](:Zolinga AI:Configuation) documentation.