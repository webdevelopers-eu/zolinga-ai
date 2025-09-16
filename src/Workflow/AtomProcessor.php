<?php

declare(strict_types=1);

namespace Zolinga\AI\Workflow;

use DOMDocument;
use DOMElement;
use DOMEntity;
use DOMXPath;

class AtomProcessor
{
    private DOMXPath $xpath;
    private ?string $prompt = null;
    private array $generateVariables = [];
    private array $validators = [];

    public function __construct(private DOMElement $atomElement, private array $data) {
        $this->xpath = new DOMXPath($this->atomElement->ownerDocument);
        // xmlns="http://www.zolinga.org/ai/workflow"
        $this->xpath->registerNamespace('wf', 'http://www.zolinga.org/ai/workflow');

        // Get prompt <ai [prompt="..."]>[<prompt>...</prompt>]</ai>
        $this->prompt = $this->xpath->evaluate('string(./@prompt|./wf:prompt)', $this->atomElement) ?: null;

        $this->parseExtractVariables();

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

    private function parseExtractVariables() {
        // Variables <var name="..." [generate="true"] [pattern="..."] [value="..."]>[value]</var>
        // or <var name="..."><option [value="value"]>[value]</option>...</var>
        foreach ($this->xpath->query('./wf:var', $this->atomElement) as $node) {
            /** @var \DOMElement $node */
            $name = $node->getAttribute('name');
            $value = self::getElementValue($node);
            
            if ($node->getAttribute('generate') === 'yes') { // AI generated
                $options = array_map(fn (DOMElement $node) => self::getElementValue($node),
                    iterator_to_array($this->xpath->query('./wf:option', $node)));

                $this->generateVariables[] = [
                    "name" => $name,
                    "pattern" => $node->getAttribute('pattern'),
                    "required" => $node->getAttribute('required') === 'yes',
                    "value" => $value,
                    "options" => $options
                ];
            } else {
                $this->data[$name] = $value;
            }
        }
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

        foreach($this->generateVariables as $i) {
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

    private static function replaceVars(string $string, array $data): string {
        $ret = $string;

        do {
            $ret = preg_replace_callback('/\$\{(\w+)\}/', fn($matches) =>
                $data[$matches[1]] ?? $matches[0], $string, count: $count);
        } while ($count); // recusive

        return $ret;
    }

    private function test(array $data): bool {  
        foreach ($this->generateVariables as $i) {
            if ($i['required'] && empty($data[$i['name']])) {
                trigger_error("The required variable '{$i['name']}' is missing: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), E_USER_WARNING);
                return false;
            }
            if ($i['pattern'] && !preg_match("/{$i['pattern']}/s", $data[$i['name']])) {
                trigger_error("The variable '{$i['name']}' does not match the required pattern {$i['pattern']}: {$data[$i['name']]}", E_USER_WARNING);
                return false;
            }
        }

        foreach ($this->validators as $validator) {
            $text = $this->replaceVars($validator['text'], $data);

            if ($validator['pattern']) {
                $testResult = preg_match("/{$validator['pattern']}/", $text) ? 'yes' : 'no';
            } else {
                // Generate new Atom processor
                $dom = new DOMDocument;
                $dom->loadXML('<ai xmlns="http://www.zolinga.org/ai/workflow">
                    <var name="answer" generate="yes" required="yes">
                        <option value="yes"/>
                        <option value="no"/>
                    </var>
                </ai>');

                $dom->documentElement->setAttribute('prompt', $text);
                $atom = new AtomProcessor($dom->documentElement, $data);
                ["answer" => $testResult] = $atom->process();
            }

            if ($testResult !== $validator['expect']) {
                return false;
            }
        }
        return true;
    }

    public function process(): array
    {
        global $api;
        
        if ($this->prompt) {
            $maxAttempts = 5;
            do {
                $schema = $this->getJsonSchema();
                $api->log->info('ai', 'Prompting AI to generate ' . implode(', ', array_keys($schema['properties'])));
                $resp = $api->ai->prompt(
                    'workflow', 
                    self::replaceVars($this->prompt, $this->data), 
                    format: $schema
                );

                $data = array_merge($this->data, $resp);
                $testResult = $this->test($data);
                if (!$testResult) {
                    trigger_error("Validation failed, retrying... (attempts left: $maxAttempts)", E_USER_WARNING);
                }   
            } while (!$testResult && $maxAttempts-- > 0);

            if (!$testResult) {
                throw new \RuntimeException('Failed to validate workflow data: ' . json_encode($data));
            }
        } else {
            $data = $this->data;
        }

        // Process subelements <ai>
        foreach($this->xpath->query('./wf:ai', $this->atomElement) as $node) {
            /** @var \DOMElement $node */
            $atom = new AtomProcessor($node, $data);
            $data = $atom->process();
        }

        return $data;
    }

}
