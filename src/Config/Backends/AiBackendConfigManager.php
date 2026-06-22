<?php

declare(strict_types=1);

namespace Zolinga\AI\Config\Backends;

/**
 * Loads and manages AI backend configurations from
 * `config://zolinga-ai/ai-backends.json`.
 *
 * Selects the single best-matching backend for a given set of capabilities
 * (highest specificity score wins).
 *
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2026-06-22
 */
class AiBackendConfigManager
{
    /** @var AiBackendConfig[] */
    private array $backends;

    /** @var array<string, AiBackendConfig> Cache keyed by sorted capability string. */
    private array $selectionCache = [];

    public function __construct()
    {
        $this->backends = $this->loadBackends();
    }

    /**
     * Load all backends from the config file.
     *
     * @return AiBackendConfig[]
     */
    private function loadBackends(): array
    {
        global $api;

        $backends = [];
        $configUri = 'config://zolinga-ai/ai-backends.json';

        if (!file_exists($configUri)) {
            $api->log->warning('ai', "AI backends configuration file '$configUri' does not exist. No AI backends will be available. Please create the file with your backend configurations. See the documentation for details.");
            return $backends;
        }

        $config = json_decode(file_get_contents($configUri), true)
            or throw new \Exception("Failed to decode AI backends configuration file '$configUri': " . json_last_error_msg(), 1223);

        foreach ($config as $aiConfig) {
            try {
                $backends[] = new AiBackendConfig($aiConfig);
            } catch (\Exception $e) {
                $api->log->error('ai', "Failed to initialize AI backend '{$aiConfig['model']}': " . $e->getMessage());
            }
        }
        return $backends;
    }

    /**
     * Select the single best-matching backend (highest specificity score).
     *
     * @param string|array $capabilities Required capability or array of required capabilities.
     * @return AiBackendConfig The best-matching backend.
     * @throws \Exception If no backend matches the required capabilities.
     */
    public function select(string|array $capabilities): AiBackendConfig
    {
        global $api;

        $id = $this->capabilityToString($capabilities);
        if (isset($this->selectionCache[$id])) {
            return $this->selectionCache[$id];
        }

        $selected = null;
        $lastScore = -1;
        foreach ($this->backends as $backend) {
            $score = $backend->hasCapabilities($capabilities);
            if (is_int($score) && $score > $lastScore) {
                $selected = $backend;
                $lastScore = $score;
            }
        }

        if (!$selected) {
            throw new \Exception("No AI backend matches the required capabilities: $id", 1229);
        }

        $api->log->info('ai', "📌 Selected AI backend '$selected->name' for capabilities: $id (specificity: $lastScore)");
        $this->selectionCache[$id] = $selected;
        return $selected;
    }

    /**
     * Convert capabilities to a stable cache key string.
     *
     * @param string|array $capabilities
     * @return string
     */
    private function capabilityToString(string|array $capabilities): string
    {
        $arr = is_array($capabilities) ? $capabilities : [$capabilities];
        sort($arr);
        return implode(" + ", $arr);
    }
}