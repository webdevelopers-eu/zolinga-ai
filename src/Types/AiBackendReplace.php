<?php

declare(strict_types=1);

namespace Zolinga\AI\Types;

/**
 * Represents a single regex replacement rule for AI backend response post-processing.
 *
 * @property-read string $search Regex pattern to search for.
 * @property-read string $replace Replacement string.
 * @property-read ?string $description Optional human-readable description.
 */
class AiBackendReplace
{
    public readonly string $search;
    public readonly string $replace;
    public readonly ?string $description;

    public function __construct(array $data)
    {
        $this->search = $data['search'] ?? throw new \InvalidArgumentException("Missing 'search' in replace rule.");
        $this->replace = $data['replace'] ?? '';
        $this->description = $data['description'] ?? null;
    }

    /**
     * Apply this replacement to the given text.
     *
     * @param string $text
     * @return string|null The result or null on failure.
     */
    public function apply(string $text): ?string
    {
        return preg_replace($this->search, $this->replace, $text);
    }

    /**
     * Apply this replacement to the text, logging errors on failure.
     *
     * @param string $text Input text.
     * @return string Resulting text (unchanged on failure).
     */
    public function replaceText(string $text): string
    {
        global $api;

        $newText = $this->apply($text);
        if ($newText !== null && json_encode($newText) !== false) {
            return $newText;
        } elseif ($newText !== null) {
            $api->log->error('ai', "The regex replacement produced an unserializable result. Search: " . json_encode($this->search, JSON_UNESCAPED_SLASHES) .
                " Replace: " . json_encode($this->replace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
                " Result was: " . json_encode($newText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $error = preg_last_error() !== PREG_NO_ERROR ? preg_last_error_msg() : "unknown error";
            $api->log->error('ai', "Failed to apply regex replacement on the model response. Search: " . json_encode($this->search, JSON_UNESCAPED_SLASHES) .
                " Replace: " . json_encode($this->replace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
                " Response was: " . json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
                " Error: " . $error);
        }

        return $text;
    }
}
