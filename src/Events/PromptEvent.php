<?php

namespace Zolinga\AI\Events;

use ArrayAccess;
use ArrayObject;
use Zolinga\System\Events\RequestResponseEvent;
use Zolinga\System\Types\OriginEnum;

/**
 * AI event class that represents a prompt and a response.
 *
 * @author Daniel Sevcik <sevcik@zolinga.ort>
 * @date 2025-02-07
 */
class PromptEvent extends RequestResponseEvent {
    private const REQUEST_DEFAULTS = [
        'ai' => 'default',
        'prompt' => '',

    ];

    private const REQUEST_REQUIRED = [
        'ai',
        'prompt',
    ];

    /**
     * PromptEvent constructor.
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
                throw new \Exception("Missing required parameter '$key' in PromptEvent request.");
            }
        }

        if (!is_array($api->config['ai']['backends'][$request['ai']])) {
            throw new \Exception("AI backend '{$request['ai']}' not found in configuration key '.config.ai.backends.{$request['ai']}'.");
        }
    }
}