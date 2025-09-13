<?php

declare(strict_types=1);

namespace Zolinga\AI\Workflow;

use DOMDocument;
use DOMElement;
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

        foreach($this->xpath->query('./wf:validate', $this->atomElement) as $node) {
            /** @var \DOMElement $node */
            $this->validators[] = $node->textContent;
        }
    }

    private function parseExtractVariables() {
        // Variables <var name="..." [generate="true"] [pattern="..."] [value="..."]>[value]</var>
        // or <var name="..."><option [value="value"]>[value]</option>...</var>
        foreach ($this->xpath->query('./wf:var', $this->atomElement) as $node) {
            /** @var \DOMElement $node */
            $name = $node->getAttribute('name');
            $value = $node->hasAttribute('value') ? $node->getAttribute('value') : 
                ($node->childNodes->length ? $node->textContent : null);
            
            if ($node->getAttribute('generate') === 'true') { // AI generated
                $options = array_map(fn (DOMElement $node) => 
                    $node->getAttribute('value') ? $node->childNodes->length : $node->textContent,
                    iterator_to_array($this->xpath->query('./wf:option', $node)));
                $this->generateVariables[] = [
                    "name" => $name,
                    "pattern" => $node->getAttribute('pattern'),
                    "required" => $node->getAttribute('required') === 'required',
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

    private static function replaceVars(string $string, array $data): string {
        return preg_replace_callback('/\$\{(\w+)\}/', fn($matches) =>
            $data[$matches[1]] ?? $matches[0], $string);
    }

    private function validate(array $data): bool {  
        foreach ($this->generateVariables as $i) {
            if ($i['required'] && empty($data[$i['name']])) {
                trigger_error("The required variable '{$i['name']}' is missing.", E_USER_WARNING);
                return false;
            }
            if ($i['pattern'] && !preg_match("/{$i['pattern']}/", $data[$i['name']])) {
                trigger_error("The variable '{$i['name']}' does not match the required pattern {$i['pattern']}: {$data[$i['name']]}", E_USER_WARNING);
                return false;
            }
        }

        foreach ($this->validators as $validateText) {
            // Generate new Atom processor
            $dom = new DOMDocument;
            $dom->loadXML('<ai xmlns="http://www.zolinga.org/ai/workflow">
                <var name="answer" generate="true" required="true">
                    <option value="yes"/>
                    <option value="no"/>
                </var>
            </ai>');

            $dom->documentElement->setAttribute('prompt', $this->replaceVars($validateText, $data));
            $atom = new AtomProcessor($dom->documentElement, $data);
            ["answer" => $answer] = $atom->process();

            if ($answer === 'no') return false;
        }
        return true;
    }

    public function process(): array
    {
        global $api;
        
        if ($this->prompt) {
            $maxAttempts = 5;
            do {
                $resp = $api->ai->prompt('workflow', self::replaceVars($this->prompt, $this->data), 
                    format: $this->getJsonSchema());

                $data = array_merge($this->data, $resp);
                $validated = $this->validate($data);
            } while (!$validated && $maxAttempts-- > 0);

            if (!$validated) {
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
