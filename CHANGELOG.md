# Changelog

All notable changes to this module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0/).

## [Unreleased]

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
