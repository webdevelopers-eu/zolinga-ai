## Syntax

```html
<ai-article model="{MODEL}" [backend="{SOURCE}"]>{PROMPT}</ai-article>
```

Example:

```html
<ai-article model="deepseek-r1:8b" backend="default">
    Write a blog post about the Zolinga platform.
</ai-article>
```

## Attributes

- `model`: The model to use for generating the article. The model must be supported by your backend AI service.
- `backend`: The backend to use for generating the article. The backends are defined in your [configuration](:Zolinga Core:Configuration)'s key. Default: `default`.

## Backend Configuration

The backend configuration is defined in your [configuration](:Zolinga Core:Configuration). The configuration key is `ai.backends`. The configuration should be an associative array where the key is the backend name and the value is an array of configuration options for the backend.

Currently only Ollama backend API is supported. The example of the configuration of 2 backends `default` and `server2` is shown below:
```json
{
    "config": {
        "ai": {
            "backends": {
                "default": {
                    "type": "ollama",
                    "uri": "http://login:password@127.0.0.1:3000/"
                },
                "server2": {
                    "type": "ollama",
                    "uri": "http://login2:password2@nvidia-rack.local:3000/"
                }
            }
        }
    }
}
```

## Processing

The first time the `<ai-article>` element is rendered, the system queues request for backend to generate the article. In the meantime the element will display an error messsage that the server is busy and the user should try again later. Once the article is generated, the element will display the article content. 

In order to generate the article on the background run the `./bin/zolinga ai:generate` command. You can run it regularly from the cron job to process all queued articles. The process will finish all queued articles and exit. Or you can run it with the `--loop` option to run it in the loop continuously.

To run the command in the loop, use the following command:
```bash
./bin/zolinga ai:generate --loop
```

To run the command one-time to process all queued articles and then exit, use the following command:
```bash
./bin/zolinga ai:generate
```
