## Configuring AI Backends

Configure the AI backends in your Zolinga configuration. The module supports only the [Ollama backend API](https://ollama.com/download) at the moment. The `default` backend definition is required. Incomplete backend definitions inherit missing top-level keys from the `default` backend.

```json
   {
       "config": {
           "ai": {
               "backends": {
                   "default": {
                       "type": "ollama",
                       "url": "http://login:password@127.0.0.1:3000/api/",
                       "model": "deepseek-r1:8b",
                       "think": false,
                       "options": {
                           "temperature": 0.2,
                           "num_ctx": 8192
                       },
                       "replace": [{"search": "/^<think>.*?<\\/think>/", "replace": ""}]
                   },
                   "fast": {
                        "model": "deepseek-r1:1.5b",
                   }
               }
           }
       }
   }
```

In the example above

- `type` is the backend type. Currently only `ollama` is supported.
- `url` is the base URL of the backend API endpoint.
- `model` is the model to use for generating the content.
- `think` enables or disables the model's extended thinking mode (maps to Ollama's `think` parameter). Default: `false`.
- `options` is an optional map of model request options forwarded as Ollama `options`, for example `temperature`, `repeat_penalty`, `presence_penalty`, or `num_ctx`.
- `replace` is an array of search and replace rules to apply to the generated content. The rules are applied in the order they are defined.

## How `options` are applied

- Backend-level `options` may be defined in both `default` and any named backend.
- Backend inheritance is only top-level. If a named backend defines its own `options` block, it replaces the `default.options` block rather than merging individual option keys.
- When calling `$api->ai->prompt()`, the method-level `options` argument is merged with the selected backend's configured `options`.
- For matching keys in synchronous `$api->ai->prompt()` calls, the configured backend values currently override the values passed in the method call.
- For async pipelines, request-level `options` are merged with per-step `options` first, and that result is then passed to the backend together with the configured backend `options`.
