<?php

declare(strict_types=1);

namespace Zolinga\AI\Workflow;

use DOMDocument;
use DOMElement;
use DOMEntity;
use DOMXPath;

class WorkflowAtom
{
    private DOMXPath $xpath;
    private ?string $prompt = null;
    private array $vars = ["ai" => [], "download" => [], "local" => []];
    private array $validators = [];
    private static array $downloadCache = [];

    public function __construct(private DOMElement $atomElement) {
        $this->xpath = new DOMXPath($this->atomElement->ownerDocument);
        // xmlns="http://www.zolinga.org/ai/workflow"
        $this->xpath->registerNamespace('wf', 'http://www.zolinga.org/ai/workflow');

        // Get prompt <ai [prompt="..."]>[<prompt>...</prompt>]</ai>
        $this->prompt = $this->xpath->evaluate('string(./@prompt|./wf:prompt)', $this->atomElement) ?: null;

        $this->extractVariables();

        foreach($this->xpath->query('./wf:test', $this->atomElement) as $node) {
            /** @var \DOMElement $node */
            $this->validators[] = [
                "expect" => $node->getAttribute('expect') ?: 'yes',
                "text" => $node->textContent,
                "pattern" => $node->hasAttribute('pattern') ? $node->getAttribute('pattern') : null
            ];
        }
        // Sort regexp first
        usort($this->validators, fn($a, $b) =>
            ($a['pattern'] ? 1 : 0) <=> ($b['pattern'] ? 1 : 0)
        );
    }

    private function extractVariables(): void
    {
        // Variables <var name="..." [source="ai|download|..."] [pattern="..."] [value="..."]>[value]</var>
        // or <var name="..."><option [value="value"]>[value]</option>...</var>
        foreach ($this->xpath->query('./wf:var', $this->atomElement) as $node) {
            /** @var \DOMElement $node */
            $name = $node->getAttribute('name');
            $value = self::getElementValue($node);
            $source = $node->getAttribute('source') ?: 'local';

            switch ($source) {
                case 'ai':
                    $options = array_map(fn (DOMElement $node) => self::getElementValue($node),
                        iterator_to_array($this->xpath->query('./wf:option', $node)));
                    $this->vars['ai'][] = [
                        "name" => $name,
                        "pattern" => $node->getAttribute('pattern'),
                        "required" => $node->getAttribute('required') === 'yes',
                        "value" => $value,
                        "options" => $options
                    ];
                    break;
                case 'download':
                    $this->vars['download'][] = [
                        'name' => $name,
                        'value' => $value,
                        'postprocess' => $node->getAttribute('postprocess') ?: null,
                        'limit' => $node->hasAttribute('limit') ? (int)$node->getAttribute('limit') : null,
                    ];
                    break;
                case 'local':
                default: 
                    $this->vars[$source][$name] = $value;
            }
        }
    }

    private function download(string $url, array $data): string
    {
        global $api;

        try {
            $url = self::replaceVars($url, $data);
            $opts = [
                CURLOPT_HTTPHEADER => [
                    "accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7",
                    "cache-control: no-cache",
                    "pragma: no-cache",
                    "upgrade-insecure-requests: 1",
                ]
            ];
            self::$downloadCache[$url] ??= $api->downloader->download($url, curlOpts: $opts);
        } catch (\Exception $e) {
            $api->log->error('ai', "Failed to download URL: $url. Will use keyword '**unknown**'. Error: " . $e->getMessage());
            self::$downloadCache[$url] = "**unknown**";
        }

        return self::$downloadCache[$url];
    }

    private static function postprocess(string $text, ?string $postprocess, ?int $limit): string {
        global $api;

        $ret = match ($postprocess) {
            'html2md' => $api->convert->htmlToMarkdown($text),
            default => $text,
        };

        return $limit && $ret ? mb_substr($ret, 0, $limit) : $ret;
    }

    private function getJsonSchema(): array
    {
        $ret= [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => [],
            'properties' => [],
            'additionalProperties' => false,
        ];

        foreach($this->vars['ai'] as $i) {
            if ($i['required']) {
                $ret['required'][] = $i['name'];
            }
            $ret['properties'][$i['name']] = [
                'type' => 'string',
            ];
            if (count($i['options'])) {
                $ret['properties'][$i['name']]['enum'] = $i['options'];
            }
            if ($i['pattern']) {
                $ret['properties'][$i['name']]['pattern'] = $i['pattern'];
            }
        }

        return $ret;
    }

    private static function getElementValue(DOMElement $element): ?string {
        return $element->hasAttribute('value') ? $element->getAttribute('value') : 
                ($element->childNodes->length ? $element->textContent : null);
    }

    private static function replaceVars(string|array $string, array $data): string|array {
        if (is_array($string)) {
            return array_map(fn($s) => self::replaceVars($s, $data), $string);
        }

        $ret = $string;
        do {
            $count = 0;
            $ret = preg_replace_callback(
                '/\$\{(\w+)\}/', 
                function ($matches) use (&$count, $data, $ret) {
                    global $api;

                    $varName = $matches[1];
                    if (isset($data[$varName])) {
                        $count++;
                        return $data[$varName];
                    } else {
                        $api->log->warning('ai', "Variable '{$varName}' not found in data. Text: \"$ret\". Supported vars: " . json_encode(array_keys($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        return $matches[0];
                    }
                }, 
                $ret
            );
        } while ($count > 0); // recursive

        return $ret;
    }

    private function test(array $data): bool {  
        global $api;
        foreach ($this->vars['ai'] as $i) {
            if ($i['required'] && empty($data[$i['name']])) {
                $api->log->warning('ai', "The required variable '{$i['name']}' is missing: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return false;
            }
            if ($i['pattern'] && !preg_match("/{$i['pattern']}/s", $data[$i['name']])) {
                $api->log->warning('ai', "The variable '{$i['name']}' does not match the required pattern {$i['pattern']}: {$data[$i['name']]}");
                return false;
            }
        }

        foreach ($this->validators as $validator) {
            $text = $this->replaceVars($validator['text'], $data);

            if ($validator['pattern']) {
                $testResult = preg_match("/{$validator['pattern']}/", $text) ? 'yes' : 'no';
                $debugInfo = "pattern {$validator['pattern']}";
                $testResultInfo = $testResult === 'yes' ? "matched" : "not matched";
            } else {
                // Generate new Atom processor
                $dom = new DOMDocument;
                $dom->loadXML('<ai xmlns="http://www.zolinga.org/ai/workflow">
                    <var name="answer" source="ai" required="yes">
                        <option value="yes"/>
                        <option value="no"/>
                    </var>
                    <var name="answerExplanation" source="ai" required="yes"/>
                </ai>');

                $dom->documentElement->setAttribute('prompt', $text);
                $atom = new WorkflowAtom($dom->documentElement, $data);
                ["answer" => $testResult, "answerExplanation" => $testResultInfo] = $atom->process();
                $debugInfo = "generated by AI";
            }

            if ($testResult !== $validator['expect']) {
                $api->log->warning('ai', "Validation failed expected {$validator['expect']} ($debugInfo $testResultInfo), got $testResult. Text: " . json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return false;
            }
        }
        return true;
    }

    private function parseReturnValue(?DOMElement $el, array $data): array|string {
        if (!$el) { // No <return> tag, return all data
            return $data;
        }

        $items = $this->xpath->query('./wf:item', $el);
        if ($items->length === 0) {
            return self::replaceVars($this->getElementValue($el), $data);
        } else {
            $ret = [];
            foreach ($items as $item) {
                /** @var \DOMElement $item */
                $name = $item->getAttribute('name') ?: count($ret);
                $ret[$name] = $this->parseReturnValue($item, $data);
            }
            return $ret;
        }
    }

    public function process(array $data = []): array|string
    {
        global $api;
        
        // Merge defined variables
        $data = self::replaceVars(array_merge($this->vars['local'], $data), $data);

        // Resolve downloads
        foreach ($this->vars['download'] as ['name' => $name, 'value' => $url, 'postprocess' => $postprocess, 'limit' => $limit]) {
            $data[$name] = self::postprocess($this->download($url, $data), $postprocess, $limit ?? null);
        }

        if ($this->prompt) {
            $maxAttempts = 5;
            do {
                $schema = $this->getJsonSchema();
                $api->log->info('ai', 'Prompting AI to generate ' . json_encode(array_keys($schema['properties'])));
                $prompt = self::replaceVars($this->prompt, $data);
                $resp = $api->ai->prompt('workflow', $prompt, format: $schema);

                // trigger_error("AI response: " . json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), E_USER_NOTICE);

                $data = array_merge($data, $resp);
                $testResult = $this->test($data);
                if (!$testResult) {
                    $api->log->warning('ai', 
                        "Validation failed, retrying... (attempts left: $maxAttempts). Response: " . 
                        json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . " " .
                        "Prompt: " . json_encode($prompt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );
                }   
            } while (!$testResult && $maxAttempts-- > 0);

            if (!$testResult) {
                throw new \RuntimeException('Failed to validate workflow data: ' . json_encode($data));
            }
        }

        // Process subelements <ai>
        foreach($this->xpath->query('./wf:ai', $this->atomElement) as $node) {
            /** @var \DOMElement $node */
            $atom = new WorkflowAtom($node);
            $data = $atom->process($data);
        }

        // Return
        $returnElement = $this->xpath->query('./wf:return[1]', $this->atomElement)->item(0) ?: null;
        $return = $this->parseReturnValue($returnElement, $data);

        return $return;
    }

}
