# Instructions

Inject custom instructions into the AI system prompt based on capability matching.

## What This Does

When `prompt()` is called with a capability (e.g. `"translate:*-cs"`, `"vyhledavani"`, `"workflow"`), the system automatically appends any matching instruction text to the system prompt. This lets you customize AI behavior per capability without modifying code.

## Configuration

Create `config/zolinga-ai/instructions.json`:

```json
[
    {
        "capabilities": ["translate:*-cs"],
        "instruction": "Always use formal Czech address forms."
    },
    {
        "capabilities": ["vyhledavani"],
        "instruction": "config://zolinga-ai/vyhledavani-instructions.md"
    }
]
```

### Schema

Each entry has two fields:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `capabilities` | array&lt;string&gt; | Yes | Capability tags/patterns this instruction applies to. Supports `fnmatch` wildcards (`*`, `?`). |
| `instruction` | string | Yes | The instruction text, OR a Zolinga FS URI pointing to a file whose contents are used as the instruction. |

### File-Link Instructions

Instead of inline text, the `instruction` field can point to a file using a Zolinga FS URI:

```json
{
    "capabilities": ["translate:*-cs"],
    "instruction": "config://zolinga-ai/translate-cs.md"
}
```

Supported schemes: `config://`, `private://`, `module://`, `public://`, `dist://`, `wiki://`. The file contents are read once at load time.

## How Matching Works

The same capability matching algorithm as `ai-backends.json` is used. An instruction block matches if **all** of its `capabilities` are satisfied by the requested capability. Multiple matching blocks are joined (newline-separated) and appended to the system prompt.

Wildcards work bidirectionally — either the requested capability or the configured capability may contain `*` or `?` patterns.

## Where Instructions Are Injected

Instructions are appended to the **system prompt** (not the user prompt). When structured output (JSON format) is requested, instructions are inserted **before** the JSON-format directive so the structured-output instruction remains last and most prominent.

## Examples

### Per-capability instructions

```json
[
    {
        "capabilities": ["article-title"],
        "instruction": "Keep titles under 60 characters. No clickbait."
    },
    {
        "capabilities": ["article-marketing"],
        "instruction": "Write in a professional but engaging tone. Target audience: IP professionals."
    }
]
```

### File-based instructions

```json
[
    {
        "capabilities": ["translate:*-cs"],
        "instruction": "private://zolinga-ai/instructions/translate-cs.txt"
    }
]
```