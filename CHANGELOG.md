# Changelog

All notable changes to this module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0/).

## [Unreleased]

### Changed
- `AiTextModel::createTextModel()` now accepts **Markdown** (not HTML) as its `$contents` parameter. The Markdown is converted to HTML via `AiTextModel::markdownToHtml()` before being inserted into the database. This prevents raw Markdown from persisting in the `aiTexts.contents` column if the conversion fails.
- New static method `AiTextModel::markdownToHtml()` — converts Markdown to a `DOMDocument` with an `<article>` root element. Extracted from `setContentsMarkdown()` for reuse.
- `AiTextElement::onGenerateArticle()` no longer calls `save()` after `createTextModel()` — the INSERT already stores converted HTML. `save()` is only called on the regeneration path (`setContentsMarkdown()`).

### Fixed
- Raw Markdown could end up in the `aiTexts.contents` database column when `setContentsMarkdown()` threw an exception after `createTextModel()` had already inserted the unconverted content. Now `createTextModel()` converts Markdown to HTML *before* the INSERT, so a conversion failure prevents the row from being created at all.

## [1.7.0] - 2026-06-22

### Added
- Optional `config://zolinga-ai/instructions.json` — an array of `{capabilities, instruction}` objects selected by capability matching (same algorithm as `ai-backends.json`). Matching instruction text is appended to the system prompt automatically on every `prompt()` call.
- The `instruction` field may be a Zolinga FS URI (e.g. `config://zolinga-ai/translate-cs.md`) pointing to a file whose contents are read at load time.

### Changed
- `AiService::prompt()` signature simplified: first parameter is now `string|array $capabilities` (the `AiBackend` instance union member was dead code — all callers pass strings).
- `AiEvent` request key `'ai'` renamed to `'capabilities'` to accurately describe what it holds. The `<ai-text>` CMS element attributes `ai` and `ai-meta` are renamed to `capabilities` and `capabilities-meta`. **Breaking change** for queued DB events and CMS page markup.
- `TranslateEvent` request key `'ai'` renamed to `'capabilities'` (zolinga-intl module).
- `AiBackend` moved from `Zolinga\AI\Types` to `Zolinga\AI\Config\Backends\AiBackendConfig`. `AiBackendReplace` moved to `Zolinga\AI\Config\Backends\AiBackendReplaceConfig`.
- Backend selection logic extracted from `AiService` into `Zolinga\AI\Config\Backends\AiBackendConfigManager`.
- Capability matching logic extracted into shared `Zolinga\AI\Config\AiCapabilityMatcher` (used by both backend and instruction selection).

### Fixed
- `AiService::selectBackendAI()` could return null, causing a latent TypeError in `processPrompt()`. Backend selection now throws `\Exception` on no match instead of returning null.

## [1.6.0] - 2026-06-02

### Changed
- AI backends are now configured as a **list** of objects with a `capabilities` array, selected by capability (with `fnmatch`-style wildcards) instead of by backend name. The default location is `config/zolinga-ai/ai-backends.json`. The legacy map-of-named-backends layout is no longer supported.
- `AiBackend` is no longer constructed with a name; its identity is derived from `<model>@<host>`. `capabilities` is required and any backend missing it fails to load.
- `$api->ai->prompt()` and `$api->ai->promptAsync()` now accept a string or array of required capabilities (or an `AiBackend` instance) and pick the most specific matching backend.
- `replace` rules are applied only to plain-text output; JSON-schema responses are no longer post-processed.
- `options` are no longer inherited from a `default` backend at the config level — each backend carries its own.

### Added
- `Zolinga\AI\Types\AiBackendReplace` value object encapsulating a single post-processing rule, with optional `description` for human-readable notes.
- `concurrency` per backend, implemented via the registry lock service, allowing multiple in-flight requests to the same URL.
- `systemPrompt` override per backend, falling back to the global `config.ai.systemPrompt`.

## [1.5] - 2026-04-29

### Added
- `<ai-text>` now supports `show-meta` and `ai-meta` attributes for automatic SEO metadata generation (title, description, TL;DR).
- New `ai:meta:generated` internal event fired when AI-generated metadata is ready to be saved.
- New `data/meta-prompt.txt` prompt template for structured metadata generation.
- `AiTextModel` gains `title`, `description`, and `tldr` fields with corresponding database columns.
- `generateMeta()` method in `AiTextElement` queues a secondary AI call to produce JSON-structured metadata.
- `renderMeta()` method injects `<meta name="title">`, `<meta name="description">`, and a `<details class="text-tldr">` element into the page when metadata is available.

### Changed
- `AiTextModel::setContents()` renamed to `setContentsMarkdown()` for clarity.
- `AiTextModel` properties `contents`, `title`, `description`, and `tldr` are now public instead of magic `__get`/`__set`.
- `onGenerateArticle()` now reads `uuid` from `$event->response['uuid']` with fallback to `$event->uuid`, and triggers meta generation immediately when `generateMetaAI` is set.
- `generateArticle()` passes `uuid` in the event request and prefixes the event UUID with `ai:text:` for easier tracking.
- `AiService::httpRequest()` now captures HTTP status codes from `$http_response_header` and includes the response body in error messages for better debugging of backend failures.

### Fixed
- `AiService::httpRequest()` no longer silently discards HTTP 4xx/5xx responses; it logs the status code and response body before throwing.
