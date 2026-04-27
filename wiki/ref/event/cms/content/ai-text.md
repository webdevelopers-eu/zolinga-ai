## Syntax

```html
<ai-text [ai="{AI_BACKEND}"] uuid="{UUID}" [element="{ELEMENT_TYPE}"] [allow-generate-from="{IP_LIST}"] [remove-invalid-links="true"] [tag="{TAG}"] [other_attributes...]}>{PROMPT}</ai-text>
```

Example:

```html
<ai-text ai="default" uuid="example:zolinga-platform" remove-invalid-links="true">
    Write a blog post about the Zolinga platform.
</ai-text>
```

Example with prompt variation:

```html
<ai-text ai="default" uuid="campaign:summer-2026">
    Write a landing page headline for our summer launch.
    Prefer words built from these letters: {{random|8}}
</ai-text>
```

Example with custom character set:

```html
<ai-text ai="default" uuid="campaign:summer-2026-digits">
    Write three short promo codes inspired by this seed: {{random|6|0123456789}}
</ai-text>
```

Example with separator:

```html
<ai-text ai="default" uuid="campaign:summer-2026-spaced-seed">
    Write a headline inspired by this letter pattern: {{random|5||-}}
</ai-text>
```

Restricted generation (only your office IP and localhost can trigger generation):

```html
<ai-text ai="default" uuid="example:widget-desc" allow-generate-from="203.0.113.10,10.0.0.0/8">
    Write a product description for our new widget.
</ai-text>
```

## Attributes

- `ai`: The backend to use for generating the article. The backends are defined in your [configuration](:Zolinga Core:Configuration)'s key. Default: `default`.
- `uuid`: **(required)** The UUID of the article. If omitted, an error is thrown and the article will not be generated or rendered. AI generated content is stored in the database under the UUID. Therefore, if you want to display the same article multiple times, you should provide the same UUID.
- `element`: The HTML element type to use for the generated content. Default: `article`.
- `allow-generate-from`: A comma-separated list of IP addresses or CIDR ranges that are allowed to **trigger** AI content generation. Requests from IPs not on the list will receive a 404 response instead of queuing a generation job. Already-generated content is served to everyone regardless of this attribute. If the attribute is omitted, any visitor can trigger generation. Uses `$api->network->matchCidr()` for matching, so both IPv4 and IPv6 are supported. Example: `"109.164.101.75,10.0.0.0/8,2001:db8::/32"`.
- `remove-invalid-links`: If set to `true`, invalid links found in the generated article will be removed before the article is saved. Use this when you want link cleanup during article generation.
- `tag`: An optional tag to associate with the generated article. Stored in the database alongside the article and can be used for categorization, filtering, or later retrieval of related articles. The value is stored as-is in the DB `tag` column. Can be used as a version marker (e.g. `tag="2.0"`) to force regeneration by changing the UUID fingerprint indirectly, or simply to group articles by topic.
- `print-only`: If present, renders the prompt pipeline as plain text instead of generating or displaying an article. Useful for debugging prompts. Each step is shown as `===type===` followed by the prompt text.
- Output attributes copied from `<ai-text>`: Only `class`, `style`, `id`, and any `data-*` attributes are copied to the rendered output element.

## Regeneration

Append `?regenerate` to the page URL to force regeneration of an existing article. When the query parameter is present, the cached article is ignored and a new generation job is queued (the visitor sees the "server is busy" placeholder while it generates). The requester's IP must still be allowed by `allow-generate-from` (if set). The regenerated content replaces the previous version in the database.

Example: `https://example.com/my-page?regenerate`

## Nested Elements

The `<ai-text>` element can contain nested CMS content elements. Before the prompt is extracted and processed by the AI backend, any nested elements are first fully expanded by the CMS parser. The resulting plain text (after stripping scripts and markup) is then used as the prompt.

This allows you to dynamically construct prompts using other content elements:

```html
<ai-text ai="default" uuid="example:summary">
    Write a summary for this post: <autoblog-post data-field="title" />
    Context: <my-data-element />
</ai-text>
```

Tip: this also works inside `<step>` blocks in a multi-step pipeline. A useful pattern is to place a content tag directly into a bullet list or instruction block so it conditionally injects extra prompt text.

Example using `<random-chooser>` to optionally include one instruction line:

```html
<ai-text ai="vyhledavani" uuid="vyhledavani:{{GET:search|Your Brand}}">
    <step>
        STRUCTURE:
        - # heading containing "{{GET:search|Your Brand}}".
        - The title must be at least 60 characters long.
        <random-chooser count="1">
            <span>- Provocative questions implying fear and concern are encouraged in the title. Not required though.</span>
            <span>- Avoid provocative questions, keep the title neutral and informative.</span>
        </random-chooser>
    </step>
</ai-text>
```

Example using `<random-chooser>` to shuffle plain text values and join them with a separator:

```html
<ai-text ai="default" uuid="demo:random-chooser-seed">
    <step>
        Prefer title words inspired by these numbers:
        <random-chooser count="3" text-separator=", " selector="./*/text()">
            <div>111</div>
            <div>222</div>
            <div>333</div>
            <div>444</div>
            <div>555</div>
        </random-chooser>
    </step>
</ai-text>
```

Practical notes:

- Nested content tags are expanded before prompt variables such as `{{random|n}}` are evaluated.
- Only the expanded text content becomes part of the prompt, so wrapper tags like `<span>` or `<div>` are not preserved as HTML instructions.
- This makes content tags useful for optional prompt lines, shuffled constraints, injected metadata, or dynamic snippets assembled from other CMS elements.

The expansion order is:
1. Nested elements inside `<ai-text>` are expanded first by the CMS parser.
2. Scripts are stripped from the expanded content.
3. Prompt variables such as `{{random|n}}`, `{{random|n|charset}}`, and `{{random|n|charset|separator}}` are expanded.
4. The `<ai-text>` element itself is then processed and replaced with the generated article.

## Prompt Variables

The extracted prompt supports this built-in variable:

- `{{random|n}}`: Replaced with a random string of length `n` using the default character set `ABCDEFGHIJKLMNOPQRSTUVWXYZ`.
- `{{random|n|charset}}`: Replaced with a random string of length `n` using the characters provided in `charset`.
- `{{random|n|charset|separator}}`: Replaced with a random string of length `n` using the characters provided in `charset`, joined with `separator` between each generated character.
- `{{random|n||separator}}`: Uses the default character set `ABCDEFGHIJKLMNOPQRSTUVWXYZ` and inserts `separator` between each generated character.

Use it when you want the prompt to vary between generations, for example to encourage different title shapes, keyword mixes, or phrasing.

Example:

```html
<ai-text ai="default" uuid="blog:trademark-monitoring">
    <step>
        Write a long-form article about trademark monitoring.
        Use these random letters as a title constraint: {{random|10}}
    </step>
</ai-text>
```

Example with a restricted alphabet:

```html
<ai-text ai="default" uuid="blog:coupon-seeds">
    <step>
        Generate a coupon seed using only A, B, C, 1, 2, and 3: {{random|12|ABC123}}
    </step>
</ai-text>
```

Example with separator:

```html
<ai-text ai="default" uuid="blog:spaced-initials">
    <step>
        Generate a mnemonic seed with dash-separated letters: {{random|5||-}}
    </step>
</ai-text>
```

**Important:**

- The `uuid` attribute is **mandatory**. If omitted, an error is thrown and the article will not be generated or rendered.
- Each `{{random|n}}`, `{{random|n|charset}}`, or `{{random|n|charset|separator}}` occurrence is expanded independently.

## Multi-Step Pipeline

The `<ai-text>` element supports a multi-step pipeline using `<step>` and `<qc>` child elements. This allows chaining AI operations where each step builds on the output of the previous one, with optional quality control checks in between.

### Step Element

`<step>` defines an AI generation step. All steps except the first can reference the output from the previous step using the `{{input}}` variable.

### QC Element

`<qc>` defines a quality control check. The text content of `<qc>` lists the criteria that the current output must satisfy. If the QC check fails, the entire pipeline is restarted from the beginning (up to 3 retries).

### Processing Rules

- `<step>` and `<qc>` can appear in any order and at any nesting depth (only their text content matters, surrounding tags are ignored).
- Steps and QC checks are processed in document order.
- The first `<step>` generates initial content from its prompt.
- Subsequent `<step>` elements receive the previous output via `{{input}}`.
- A `<qc>` element validates the current output against its criteria.
- If any `<qc>` check fails, the whole pipeline restarts (max 3 retries total). If all retries fail, the generation fails.
- If no `<step>` or `<qc>` elements are found, `<ai-text>` falls back to the simple single-prompt mode.

### Example

```html
<ai-text ai="default" uuid="blog:trademark-monitoring-eu" remove-invalid-links="true">
    <step>
        Write a 500-word blog post about trademark monitoring in the EU.
    </step>
    <qc>
        - It must not contain any external links.
        - It must not contain any personal names.
        - It must be at least 400 words long.
    </qc>
    <step>
        Take the following article and add a compelling introduction
        and a call-to-action conclusion:

        {{input}}
    </step>
</ai-text>
```

In this example:
1. The first `<step>` generates the initial blog post.
2. The `<qc>` check validates the post has no links, no names, and meets the length requirement.
3. If QC passes, the second `<step>` refines the article by adding an intro and CTA using `{{input}}` to reference the validated output.
4. If QC fails, the entire pipeline restarts from step 1 (up to 3 times).

## Processing

The first time the `<ai-text>` element is rendered, the system queues a request for the backend to generate the article. In the meantime the element will display a message that the server is busy and the user should try again later (HTTP 503 with `Retry-After: 600`). Once the article is generated, the element will display the article content.

When `allow-generate-from` is set, only requests originating from the listed IPs or CIDR ranges can trigger a new generation job. Any other visitor whose request arrives before the article exists will receive a 404 response. This prevents arbitrary public visitors from flooding the generation queue — useful for AI-generated pages that are crawled or indexed by search engines before your content is ready. Once the article has been generated it is served to all visitors normally.

If `remove-invalid-links="true"` is set, link validation and cleanup happens during article generation before the generated content is stored.

## Output Structure

When the article is generated, the content will be wrapped in an `<article>` element (or the element specified by the `element` attribute). The output element will have:

- `data-text-id`: The database ID of the generated text record
- `data-tag`: The stored article tag value
- Copied attributes from `<ai-text>` limited to `class`, `style`, `id`, and any `data-*` attributes

Example output:
```html
<article data-text-id="123" data-tag="2.0" class="my-custom-class" data-analytics="ai-generated">
  <h1>Generated Title</h1>
  <p>Generated content paragraph...</p>
</article>
```

## Background Processing

In order to generate the article on the background run the `./bin/zolinga ai:generate` command. You can run it regularly from the cron job to process all queued articles. The process will finish all queued articles and exit. Or you can run it with the `--loop` option to run it in the loop continuously.

If you need detailed debug logs for background generation, either run `./bin/zolinga ai:generate --debug` for one debug session or enable the global config setting `config.ai.log` to log every run into `data/zolinga-ai/ai.log`.

To run the command in the loop, use the following command:
```bash
./bin/zolinga ai:generate --loop
```

To run the command one-time to process all queued articles and then exit, use the following command:
```bash
./bin/zolinga ai:generate
```

To process a single article by UUID:
```bash
./bin/zolinga ai:generate --uuid=ai:article:a1b2c3d4e5f6
```

See [:ref:event:ai:generate](:ref:event:ai:generate) for full parameter reference.
