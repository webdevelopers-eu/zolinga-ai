<?php

declare(strict_types=1);

namespace Zolinga\AI\Config;

/**
 * Abstract base for config items parsed from JSON arrays that carry a
 * `capabilities` array. Provides shared capability-matching via
 * {@see AiCapabilityMatcher}.
 *
 * Subclasses: {@see \Zolinga\AI\Config\Backends\AiBackendConfig},
 * {@see \Zolinga\AI\Config\Instructions\AiInstructionConfig}.
 *
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2026-06-22
 */
abstract class AbstractConfigItem
{
    /** @var array<string> Capability tags/patterns for this config item. */
    public readonly array $capabilities;

    public function __construct(array $config)
    {
        $this->capabilities = $config['capabilities']
            or throw new \InvalidArgumentException("Missing 'capabilities' in config item.");
    }

    /**
     * Check if this item matches the given capability or capabilities.
     *
     * @param string|array $capabilities Required capability or array of required capabilities.
     * @return false|int false: no match, int: specificity score (higher = more specific).
     */
    public function hasCapabilities(string|array $capabilities): false|int
    {
        return AiCapabilityMatcher::match($capabilities, $this->capabilities);
    }
}