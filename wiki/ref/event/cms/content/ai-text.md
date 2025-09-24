## Syntax

```html
<ai-text [ai="{AI_BACKEND}"] [uuid="{UUID}"] [element="{ELEMENT_TYPE}"] [other_attributes...]>{PROMPT}</ai-text>
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
- `element`: The HTML element type to use for the generated content. Default: `article`.
- Additional attributes: All other attributes (such as `class`, `style`, `data-*`, etc.) will be copied to the output element.

## Processing

The first time the `<ai-text>` element is rendered, the system queues request for backend to generate the article. In the meantime the element will display an error messsage that the server is busy and the user should try again later. Once the article is generated, the element will display the article content. 

## Output Structure

When the article is generated, the content will be wrapped in an `<article>` element (or the element specified by the `element` attribute). The output element will have:

- `data-text-id`: The unique identifier of the generated content
- All attributes from the original `<ai-text>` element except for `ai`, `uuid`, and `element`

Example output:
```html
<article data-text-id="ai:article:a1b2c3d4e5f6" class="my-custom-class">
  <h1>Generated Title</h1>
  <p>Generated content paragraph...</p>
</article>
```

## Background Processing

In order to generate the article on the background run the `./bin/zolinga ai:generate` command. You can run it regularly from the cron job to process all queued articles. The process will finish all queued articles and exit. Or you can run it with the `--loop` option to run it in the loop continuously.

To run the command in the loop, use the following command:
```bash
./bin/zolinga ai:generate --loop
```

To run the command one-time to process all queued articles and then exit, use the following command:
```bash
./bin/zolinga ai:generate
```
