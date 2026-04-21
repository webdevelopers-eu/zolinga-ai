<?php

declare(strict_types=1);

namespace Zolinga\AI\Types;

use Zolinga\AI\Enum\AiTypeEnum;

/**
 * Represents a configured AI backend with all its options.
 *
 * Usage:
 *   $backend = new AiBackend('default');
 *   echo $backend; // "🤖default"
 *   $backend->acquireLock();
 *   // ... do work ...
 *   $backend->releaseLock();
 *
 * @property-read string $name The backend config name (e.g. "default", "vyhledavani").
 * @property-read AiTypeEnum $type Backend type.
 * @property-read string $url API URL.
 * @property-read string $model Model identifier.
 * @property-read AiBackendReplace[] $replace Response post-processing replacements.
 * @property-read ?string $systemPrompt System prompt override or null/false.
 * @property-read ?bool $think Whether to enable thinking mode.
 * @property-read int $concurrency Max concurrent requests to this backend URL.
 */
class AiBackend
{
    public readonly string $name;
    public readonly AiTypeEnum $type;
    public readonly string $url;
    public readonly string $model;
    /** @var AiBackendReplace[] */
    public readonly array $replace;
    public readonly ?string $systemPrompt;
    public readonly ?bool $think;
    public readonly ?array $options;
    public readonly int $concurrency;

    /** @var array<string, string> Shared across all AiBackend instances: url => lockId */
    private static array $locks = [];

    public function __construct(string $name)
    {
        global $api;

        $this->name = $name;

        if (!is_array($api->config['ai']['backends'][$name] ?? null)) {
            throw new \Exception("Unknown AI backend: $name, check that the configuration key .ai.backends.$name exists in your Zolinga configuration.", 1222);
        }

        $config = array_merge(
            ['type' => 'ollama', 'model' => 'llama3.2:1b', 'concurrency' => 1],
            $api->config['ai']['backends']['default'] ?? [],
            $api->config['ai']['backends'][$name]
        );

        $this->type = $config['type'] instanceof AiTypeEnum
            ? $config['type']
            : AiTypeEnum::from($config['type']);
        $this->url = $config['url'];
        $this->model = $config['model'];
        $this->systemPrompt = is_string($config['systemPrompt'] ?? null) ? $config['systemPrompt'] : null;
        $this->think = isset($config['think']) ? (bool) $config['think'] : null;
        $this->options = is_array($config['options'] ?? null) ? $config['options'] : null;
        $this->concurrency = max(1, (int) ($config['concurrency'] ?? 1));

        $replaceRaw = $config['replace'] ?? [];
        $this->replace = array_map(
            fn(array $r) => new AiBackendReplace($r),
            is_array($replaceRaw) ? $replaceRaw : []
        );
    }

    public function __toString(): string
    {
        return "🤖{$this->name}";
    }

    /**
     * Get the server identifier for locking (hostname from URL).
     *
     * @return string
     */
    private function getServerId(): string
    {
        return parse_url($this->url, PHP_URL_HOST);
    }

    /**
     * Acquire a concurrency lock for this backend's URL.
     * Cycles through slots 0..concurrency-1 every second,
     * logging a message every 60 seconds.
     *
     * @param ?int $timeout Max seconds to wait. 0 = no wait (instant), null = wait forever.
     * @return bool True if lock acquired, false if timed out.
     */
    public function acquireLock(?int $timeout = null): bool
    {
        global $api;

        $serverId = $this->getServerId();

        // Already locked by us (same process) — return immediately
        if (isset(self::$locks[$serverId])) {
            return true;
        }

        $deadline = ($timeout !== null && $timeout > 0) ? time() + $timeout : null;
        $lastLog = 0;
        while (true) {
            for ($slot = 0; $slot < $this->concurrency; $slot++) {
                $lockId = "ai::slot:{$slot}:{$serverId}";
                $result = $api->registry->acquireLock($lockId, 0);
                if ($result !== false) {
                    self::$locks[$serverId] = $lockId;
                    // $api->log->info('ai', "$this acquired concurrency slot #$slot (concurrency {$this->concurrency}) on $serverId");
                    return true;
                }
            }

            // timeout=0: no wait, fail immediately
            if ($timeout === 0) {
                return false;
            }

            // Timed out?
            if ($deadline !== null && time() >= $deadline) {
                return false;
            }

            // All slots busy — log every 60s, sleep 1s
            $now = time();
            if ($now - $lastLog >= 60) {
                $api->log->info('ai', "$this waiting for a free concurrency slot on $serverId ({$this->concurrency} slots busy)...");
                $lastLog = $now;
            }
            sleep(1);
        }
    }

    /**
     * Apply all configured regex replacements to the given text.
     *
     * @param string $text Raw model response text.
     * @return string Text after all replacements.
     */
    public function replaceText(string $text): string
    {
        foreach ($this->replace as $rule) {
            $text = $rule->replaceText($text);
        }
        return $text;
    }

    /**
     * Release the concurrency lock for this backend's URL.
     *
     * @return void
     * @throws \Exception If no lock was held.
     */
    public function releaseLock(): void
    {
        global $api;

        $serverId = $this->getServerId();

        if (!isset(self::$locks[$serverId])) {
            throw new \Exception("$this attempted to release a lock on $serverId that was never acquired.", 1240);
        }

        $lockId = self::$locks[$serverId];
        $api->registry->releaseLock($lockId);
        unset(self::$locks[$serverId]);
        // $api->log->info('ai', "$this released concurrency lock on $serverId");
    }
}
