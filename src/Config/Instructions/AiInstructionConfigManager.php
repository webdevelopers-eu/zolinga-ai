<?php

declare(strict_types=1);

namespace Zolinga\AI\Config\Instructions;

/**
 * Loads and manages instruction blocks from
 * `config://zolinga-ai/instructions.json`.
 *
 * Instructions are optional — if the config file is missing, the manager
 * silently operates with an empty list (no warning).
 *
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2026-06-22
 */
class AiInstructionConfigManager
{
    /** @var AiInstructionConfig[] */
    private array $instructions;

    public function __construct()
    {
        $this->instructions = $this->loadInstructions();
    }

    /**
     * Load all instruction blocks from the config file.
     *
     * If the file is missing, returns an empty array (instructions are optional).
     * Malformed entries are logged and skipped.
     *
     * @return AiInstructionConfig[]
     */
    private function loadInstructions(): array
    {
        global $api;

        $instructions = [];
        $configUri = 'config://zolinga-ai/instructions.json';

        if (!file_exists($configUri)) {
            return $instructions; // silent — instructions are optional
        }

        $config = json_decode(file_get_contents($configUri), true);
        if (!is_array($config)) {
            $api->log->error('ai', "Failed to decode instructions configuration file '$configUri': " . json_last_error_msg());
            return $instructions;
        }

        foreach ($config as $entry) {
            try {
                $instructions[] = new AiInstructionConfig($entry);
            } catch (\Exception $e) {
                $api->log->error('ai', "Failed to load instruction config: " . $e->getMessage());
            }
        }
        return $instructions;
    }

    /**
     * Return all InstructionConfigs whose capabilities match.
     *
     * @param string|array $capabilities Required capability or array of required capabilities.
     * @return AiInstructionConfig[]
     */
    public function findAll(string|array $capabilities): array
    {
        $matches = [];
        foreach ($this->instructions as $instruction) {
            if ($instruction->hasCapabilities($capabilities) !== false) {
                $matches[] = $instruction;
            }
        }
        return $matches;
    }

    /**
     * Convenience: join all matching instructions into one string (newline-separated).
     *
     * @param string|array $capabilities
     * @return string Joined instruction text, or '' if no matches.
     */
    public function getInstructions(string|array $capabilities): string
    {
        $matches = $this->findAll($capabilities);
        return implode("\n\n", array_map(fn(AiInstructionConfig $i) => $i->instruction, $matches));
    }
}