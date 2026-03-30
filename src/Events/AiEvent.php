<?php

namespace Zolinga\AI\Events;

use ArrayAccess;
use ArrayObject;
use Zolinga\System\Events\RequestResponseEvent;
use Zolinga\System\Types\OriginEnum;

/**
 * AI event class that represents a prompt request and its response.
 * 
 * Used with `$api->ai->promptAsync()` to queue async AI generation.
 * The event is serialized to DB, later deserialized and processed by `bin/zolinga ai:generate`.
 * After processing, the event is dispatched so your listener receives the result.
 *
 * Request keys:
 * - 'ai' (string, required): Backend name as defined in config `ai.backends.*`. Default: "default".
 * - 'prompt' (string|array, required): Either a plain prompt string or an array of pipeline steps.
 *    Each step: ['prompt' => string, 'type' => 'step'|'qc']. See <ai-text> pipeline docs.
 * - 'format' (array|null): JSON Schema for structured output, or null for plain text. Default: null.
 * - 'removeInvalidLinks' (bool): Strip invalid links from generated HTML. Default: false.
 * - Any custom keys you add to request[] are preserved through serialization.
 *
 * Response keys (set after processing):
 * - 'data' (string|array): The AI-generated content. String for plain text, array if format was set.
 * - Any custom keys you add to response[] are preserved and available in your callback listener.
 *
 * Usage:
 * ```php
 * $event = new AiEvent("my:callback:event", OriginEnum::INTERNAL, [
 *     'ai' => 'default',
 *     'prompt' => 'Write about Zolinga.',
 * ], [
 *     'myId' => 123, // custom data preserved for your callback
 * ]);
 * $event->uuid = 'my-unique-id'; // optional, auto-generated if not set
 * $api->ai->promptAsync($event);
 * ```
 *
 * @author Daniel Sevcik <sevcik@zolinga.net>
 * @date 2025-02-07
 */
class AiEvent extends RequestResponseEvent {
    private const REQUEST_DEFAULTS = [
        'ai' => 'default',
        'prompt' => [],
        'format' => null,
        'removeInvalidLinks' => false,
    ];

    private const REQUEST_REQUIRED = [
        'ai',
        'prompt',
    ];

    /**
     * AiEvent constructor.
     *
     * @param string $type The type of the event.
     * @param OriginEnum $origin The origin of the event, defaults to OriginEnum::INTERNAL.
     * @param ArrayAccess|array $request The request data, defaults to a new ArrayObject. Required keys: ai, prompt.
     * @param ArrayAccess|array $response The response data, defaults to a new ArrayObject.
     */
    public function __construct(string $type, OriginEnum $origin = OriginEnum::INTERNAL, ArrayAccess|array $request = new ArrayObject, ArrayAccess|array $response = new ArrayObject) {
        global $api;
        
        $request = array_merge(self::REQUEST_DEFAULTS, (array) $request);
        $this->validateRequest($request);

        parent::__construct($type, $origin, $request, $response);
    }

    // In future the validation should be offloaded to a separate per-backend classes
    // if we are going to have other backends then Ollama.
    private function validateRequest(array $request): void {
        global $api;

        foreach (self::REQUEST_REQUIRED as $key) {
            if (!isset($request[$key]) || empty($request[$key])) {
                throw new \Exception("Missing required parameter '$key' in AiEvent request.");
            }
        }

        if ($request['format'] !== null && $request['format'] !== 'json' && !is_array($request['format'])) {
            throw new \Exception("Invalid format '{$request['format']}' in AiEvent request. See Oolama API documentation.");
        }

        if (!is_array($api->config['ai']['backends'][$request['ai']])) {
            throw new \Exception("AI backend '{$request['ai']}' not found in configuration key '.config.ai.backends.{$request['ai']}'.");
        }
    }
}