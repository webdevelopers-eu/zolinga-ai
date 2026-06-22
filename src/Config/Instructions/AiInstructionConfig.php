<?php

declare(strict_types=1);

namespace Zolinga\AI\Config\Instructions;

use Zolinga\AI\Config\AbstractConfigItem;

/**
 * Represents a single instruction block from `config://zolinga-ai/instructions.json`.
 *
 * The `instruction` field may be inline text or a Zolinga FS URI
 * (e.g. `config://zolinga-ai/translate-cs.md`) pointing to a file whose
 * contents are read at construction time.
 *
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2026-06-22
 */
class AiInstructionConfig extends AbstractConfigItem
{
    /** @var string The resolved instruction text (inline or read from file). */
    public readonly string $instruction;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $instruction = $config['instruction']
            or throw new \InvalidArgumentException("Missing 'instruction' in instruction config.");

        // If the instruction is a Zolinga FS URI (private://, config://, etc.),
        // read the file contents via the registered stream wrapper.
        global $api;
        if ($api->fs->isZolingaUri($instruction)) {
            $resolved = file_get_contents($instruction);
            if ($resolved === false) {
                throw new \InvalidArgumentException("Failed to read instruction file '{$config['instruction']}'.");
            }
            $instruction = $resolved;
        }

        $this->instruction = $instruction;
    }
}