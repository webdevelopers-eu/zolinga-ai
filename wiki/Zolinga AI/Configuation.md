## Configuring AI Backends

Configure the AI backends in your Zolinga configuration. The module supports only the [Ollama backend API](https://ollama.com/download) at the moment. The `default` backend definition is equired. Incomplete backend definitions will inherit missing options from the `default` backend.

```json
   {
       "config": {
           "ai": {
               "backends": {
                   "default": {
                       "type": "ollama",
                       "url": "http://login:password@127.0.0.1:3000/api/",
                       "model": "deepseek-r1:8b",
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
- `replace` is an array of search and replace rules to apply to the generated content. The rules are applied in the order they are defined.
