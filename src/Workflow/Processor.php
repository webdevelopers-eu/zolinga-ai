<?php

declare(strict_types=1);

namespace Zolinga\AI\Workflow;

use DOMDocument;
use DOMElement;

class Processor
{
    public function __construct(private DOMDocument $workflow) {}


    public function process(array $data = []): array | string
    {
        $atom = new AtomProcessor($this->workflow->documentElement, $data);
        $return = $atom->process();
        return $return;
    }
}
