## Syntax

```html
<ai-text [ai="{AI_BACKEND}"] [uuid="{UUID}"]>{PROMPT}</ai-text>
```

Example:

```html
<ai-text ai="default">
    Write a blog post about the Zolinga platform.
</ai-text>
```

## Attributes

- `ai`: The backend to use for generating the article. The backends are defined in your [configuration](:Zolinga Core:Configuration)'s key. Default: `default`.
- `uuid`: The UUID of the article. If the UUID is not provided, the system will generate a new UUID hash for the article from the prompt, model, and backend. AI generated content is stored in the database under the UUID. Therefore, if you want to display the same article multiple times, you should provide the same UUID.

## Processing

The first time the `<ai-text>` element is rendered, the system queues request for backend to generate the article. In the meantime the element will display an error messsage that the server is busy and the user should try again later. Once the article is generated, the element will display the article content. 

In order to generate the article on the background run the `./bin/zolinga ai:generate` command. You can run it regularly from the cron job to process all queued articles. The process will finish all queued articles and exit. Or you can run it with the `--loop` option to run it in the loop continuously.

To run the command in the loop, use the following command:
```bash
./bin/zolinga ai:generate --loop
```

To run the command one-time to process all queued articles and then exit, use the following command:
```bash
./bin/zolinga ai:generate
```
