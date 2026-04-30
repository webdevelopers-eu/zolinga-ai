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
 * - 'priority' (float): Processing priority between 0 and 1 (exclusive). Higher = processed first. Default: 0.5.
 * - Any custom keys you add to request[] are preserved through serialization.
 *
 * Response keys:
 * - 'data' (string|array): The AI-generated content — set by the system after processing.
 *   String for plain text, array if format was set.
 * - Any custom keys you add to response[] are preserved and available in your callback listener.
 *   Pre-fill response with identifiers (e.g. record IDs, field names, entity types) that your
 *   callback needs to process the result — they survive the async round-trip unchanged.
 *
 * Usage:
 * ```php
 * $event = new AiEvent(
 *     'my-unique-id', // required — duplicate UUIDs are silently ignored
 *     "my:callback:event",
 *     OriginEnum::INTERNAL,
 *     [
 *         'ai' => 'default',
 *         'prompt' => 'Write about Zolinga.',
 *     ],
 *     [
 *         'myId' => 123, // custom data preserved for your callback
 *     ],
 * );
 * $api->ai->promptAsync($event);
 * ```
 *
 * @author Daniel Sevcik <sevcik@zolinga.net>
 * @date 2025-02-07
 */
class AiEvent extends RequestResponseEvent {
    public ?string $uuid {
        set {
            if (empty($value)) {
                throw new \Exception("UUID cannot be empty.");
            }
            $this->uuid = $value;
        }
    }

    private const REQUEST_DEFAULTS = [
        'ai' => 'default',
        'prompt' => [],
        'format' => null,
        'priority' => 0.5,
        'options' => [],
    ];

    private const REQUEST_REQUIRED = [
        'ai',
        'prompt',
    ];

    /**
     * AiEvent constructor.
     *
     * @param string $uuid Unique identifier for deduplication. Duplicate UUIDs are silently ignored by promptAsync().
     * @param string $type The type of the event.
     * @param OriginEnum $origin The origin of the event, defaults to OriginEnum::INTERNAL.
     * @param ArrayAccess|array $request The request data, defaults to a new ArrayObject. Required keys: ai, prompt.
     * @param ArrayAccess|array $response The response data, defaults to a new ArrayObject.
     */
    public function __construct(
        string $uuid,
        string $type,
        OriginEnum $origin = OriginEnum::INTERNAL,
        ArrayAccess|array $request = new ArrayObject,
        ArrayAccess|array $response = new ArrayObject,
    ) {
        global $api;

        $this->uuid = $uuid;
        
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

        $priority = $request['priority'] ?? 0.5;
        if (!is_numeric($priority) || $priority <= 0 || $priority >= 1) {
            throw new \Exception("Priority must be a float between 0 and 1 (exclusive), got '{$priority}'.");
        }

        if (!is_array($api->config['ai']['backends'][$request['ai']])) {
            throw new \Exception("AI backend '{$request['ai']}' not found in configuration key '.config.ai.backends.{$request['ai']}'.");
        }

        // Check there are no unknown keys in the request that might indicate a typo or misunderstanding of the API.
        $allowedKeys = array_merge(array_keys(self::REQUEST_DEFAULTS), self::REQUEST_REQUIRED);
        foreach ($request as $key => $value) {
            if (!in_array($key, $allowedKeys)) {
                $api->log->warning('ai', "Unknown parameter '$key' in AiEvent request. Allowed keys are: " . implode(', ', $allowedKeys) . ". This parameter will be preserved in the request and available in your callback, but double-check for typos or misunderstandings of the API.");
            } elseif (empty($value) && in_array($key, self::REQUEST_REQUIRED)) {
                throw new \Exception("Required parameter '$key' cannot be empty in AiEvent request.");
            }
        }
    }

    public static function fromArray(array $data): static
    {
        $event = new static(
            $data['uuid'],
            $data['type'],
            OriginEnum::tryFrom($data['origin']),
            new ArrayObject($data['request']),
            new ArrayObject($data['response']),
        );
        if ($data['status']) {
            $event->setStatus($data['status'], $data['message']);
        }
        return $event;
    }
}