<?php

declare(strict_types=1);

namespace Zolinga\AI\Workflow;


class StringManipulator {

    /**
     * Postprocess text according to specified method and limit its length if needed.
     *
     * @param string|null $text The input text to be processed.
     * @param string|null $postprocess The postprocessing method to apply (e.g., 'html2md').
     * @param int|null $limit The maximum length of the output text. If null, no limit is applied.
     * @return string|null The processed text, or null if the input text was null.
     */
    public static function postprocess(?string $text, ?string $postprocess, ?int $limit): ?string {
        global $api;

        $ret = match ($postprocess) {
            'html2md' => $api->convert->htmlToMarkdown($text),
            default => $text,
        };

        return $limit && $ret ? mb_substr($ret, 0, $limit) : $ret;
    }

    /**
     * Convert specially formatted blocks in the text.
     * 
     * Supported formats:
     * - quoted-printable: Encodes the text in quoted-printable format.
     * - text-to-html: Converts plain text to HTML by escaping special characters and converting new
     * lines to <br> tags.
     * 
     * Multiple formats can be applied in sequence by separating them with '|'.
     * 
     * Example: <<<text-to-html|quoted-printable>>>Your text here<<<end>>>
     *
     * @param string $text
     * @return string
     */
    public static function replaceBlocksFormat(string $text): string {
        // Convert <<<...[|...]>>>...<<<end>>>
        foreach (['text-to-html', 'quoted-printable'] as $format) {
            do {
                $replaced = 0;
                $text = preg_replace_callback(
                    '/<<<(' . $format .')>>>(.*?)<<<end>>>/s',
                    function ($matches) use (&$replaced) {
                        $process = explode('|', $matches[1]);
                        $text = $matches[2];
                        $replaced++;

                        foreach ($process as $p) {
                            $text = match($p) {
                                'text-to-html' => nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                                'quoted-printable' => quoted_printable_encode($text),
                                default => throw new \InvalidArgumentException("Unknown block processing method: '$p'"),
                            };
                        }

                        return $text;
                    },
                    $text
                );
            } while ($replaced > 0);
        }

        return $text;
    }

    /**
     * Replace variables in the given string or array of strings with their corresponding values from the data array.
     * Supports nested variable replacement and a special 'rand' function for generating random strings.
     * 
     * Common synax: ${varName}
     * 
     * Syntax for 'rand': ${@rand|<length>[|<characters>]}
     * - <length>: Length of the random string to generate (required).
     * - <characters>: Optional string of characters to use for generating the random string. Defaults to alphanumeric characters if not provided.
     * Example: ${@rand|8} or ${@rand|10|ABCDEF}
     *
     * Syntax for time variables: ${@date|<format>[|<strtotime_string>]}
     * - <format>: Date format string compatible with PHP's date() function (required).
     * - <strtotime_string>: Optional string to modify the current time (e.g., '+1 day', 'next Monday'). Defaults to current time if not provided.
     * Example: ${@date|Y-m-d} or ${@date|Y-m-d|-1 hour}
     * 
     * Syntax for autocamel: ${@autocamel|<varName>}
     * - <varName>: Name of the variable whose value will be converted to CamelCase in case it is all lowercase.
     * 
     * Capturing into a variable: ${...}${>varName}
     * Will evaluate ${...}, print it and store it into 'varName' in the $data array.
     * Example: ${@rand|8}${>randomString} ${randomString} - will print twice the same random string.
     *
     * @param string|array|null $string The input string or array of strings containing variables to be replaced.
     * @param array $data An associative array where keys are variable names and values are their replacements.
     * @return string|array|null The input with variables replaced by their corresponding values, or null if input was null.
     */
    public static function replaceVars(string|array|null $string, array $data): string|array|null {
        if (is_array($string)) {
            return array_map(fn($s) => self::replaceVars($s ?? '', $data), $string);
        } elseif ($string === null) {
            return null;
        }

        $ret = $string;
        do {
            $input = $ret;
            $ret = preg_replace_callback(
                // Match outer ${...} but do not close it prematurely on nested ${...}
                '/ \$\{ ( (?: [^{}]+ | (?R) )+ )\} (?: \$\{> ([^}]+) \} )? /x',
                function ($matches) use (&$count, &$data, $ret) {
                    global $api;

                    $params = explode('|', $matches[1]);
                    $varName = array_shift($params);
                    $storeVar = $matches[2] ?? null;

                    if ($varName === '@autocamel') {
                        $ret = self::callAutoCamel($data[array_shift($params) ?? 'var'] ?? '');
                    } elseif ($varName === '@tee') {
                        $ret = $data[array_shift($params) ?? 'var'] = implode('|', $params);
                    } elseif ($varName === '@date') {
                        $ret = self::callTime(array_shift($params) ?? 'Y-m-d H:i:s', array_shift($params) ?? 'now');
                    } elseif ($varName === '@rand') {
                        $ret = self::callRand((int) (array_shift($params) ?? 8), implode('|', $params));
                    } elseif (isset($data[$varName])) {
                        $ret = $data[$varName];
                    } else {
                        $api->log->warning('ai', "Variable '{$varName}' not found in data. Text: \"$ret\". Supported vars: " . json_encode(array_keys($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $ret = $matches[0];
                    }

                    if ($storeVar) {
                        $data[$storeVar] = $ret;
                    }

                    return $ret;
                }, 
                $ret
            );
        } while ($input !== $ret); // recursive

        return $ret;
    }

    private static function callAutoCamel(string $text): string {
        // If contains uppercase letters, return as is
        if (preg_match('/[A-Z]/', $text)) {
            return $text;
        }

        $words = preg_split('/[\s_-]+/', $text);
        $camelCased = array_map(fn($w) => ucfirst(strtolower($w)), $words);
        return implode('', $camelCased);
    }

    private static function callRand(int $count, ?string $characters): string {
        if (strlen($characters) === 0) {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $count; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private static function callTime(string $format, ?string $strtotime): string {
        $time = strtotime($strtotime ?: 'now');
        if ($time === false) {
            throw new \InvalidArgumentException("Invalid strtotime string: '$strtotime'");
        }
        return date($format, $time);
    }

}