<?php

namespace Zolinga\AI\Elements;

use Exception;
use Parsedown;
use Zolinga\AI\Events\AiEvent;
use Zolinga\AI\Model\AiTextModel;
use Zolinga\Cms\Events\ContentElementEvent;
use Zolinga\System\Events\ListenerInterface;
use Zolinga\System\Types\OriginEnum;
use Zolinga\System\Types\StatusEnum;

/**
* Processes CMS generative article content. 
* 
* Supports two modes:
* 1. Simple: <ai-text ai="default">Write about X.</ai-text>
* 2. Pipeline: <ai-text ai="default"><step>Write draft.</step><qc>- No links.</qc><step>Refine: {{input}}</step></ai-text>
*
* In pipeline mode, <step> and <qc> elements are processed in order.
* Each <step> generates content (subsequent steps receive previous output via {{input}}).
* Each <qc> validates the current output against its criteria. On QC failure the pipeline retries up to 3 times.
*
* The variable {{random|n[|charset[|separator]]}} will be replaced with a random string of length n to increase variability and avoid duplicate content.
*
* Attributes:
* - ai: Optional. The AI backend to use. Default is "default".
* - uuid: Required. The unique identifier of the article. An exception is thrown if omitted.
* - remove-invalid-links: Optional. If set to "true", invalid links in the generated article will be removed.
* - allow-generate-from: Optional. Comma-separated IP/CIDR list that may trigger generation.
*
* @author Daniel Sevcik <sevcik@webdevelopers.eu>
* @date 2025-02-07
*/
class AiTextElement implements ListenerInterface
{
    public function __construct() {}
    
    /**
    * This method is called when the <ai-text> element is processed.
    * See more in wiki.
    *
    * @param ContentElementEvent $event
    * @return void
    */
    public function onAiTextElement(ContentElementEvent $event): void
    {
        global $api;
        
        $ai = $event->input->getAttribute("ai") ?: "default";
        $allowedIps = $event->input->getAttribute("allow-generate-from") ?: null;
        $printOnly = $event->input->hasAttribute('print-only');
        $uuid = $event->input->getAttribute("uuid") 
            or throw new Exception("AiTextElement requires a 'uuid' attribute.");

        $article = AiTextModel::getTextModel($uuid);
        if (!$printOnly && $article) { // article already exists, render it
            $this->renderArticle($event->input, $event->output, $article);
            $event->setStatus(ContentElementEvent::STATUS_OK, "Article $uuid rendered.");
            return;
        }

        $canGenerate = !$allowedIps || $api->network->matchCidr($_SERVER['REMOTE_ADDR'], explode(',', $allowedIps));
        $forceGenerate = isset($_GET['regenerate']);
        
        if (!$canGenerate) {
            $this->displayError($event->output, "⚠️ " . dgettext("zolinga-ai", "The article was not found.")." (Your IP is {$_SERVER['REMOTE_ADDR']})");
            $event->setStatus(ContentElementEvent::STATUS_OK, "Article $uuid not found and generation not allowed.");
            http_response_code(StatusEnum::GONE->value);
            return;
        }

        // This step can be expensive, do it after all the checks to avoid unnecessary processing
        $api->cmsParser->parse($event->input, true);

        $list = $this->extractSteps($event);

        if ($printOnly) {
            $this->printOnlyAndRespond($event, $list);
        } else {            
            $this->generateArticleAndRespond($event, $uuid, $ai, $list, $forceGenerate);
        }
    }

    private function printOnlyAndRespond(ContentElementEvent $event, array $list): void
    {
        $this->print($event->output, implode("\n\n", array_map(
            fn($item) => "==={$item['type']}===\n{$item['prompt']}", $list
        )));
        $event->setStatus(ContentElementEvent::STATUS_OK, "Print-only article rendered.");
    }


    private function extractSteps(ContentElementEvent $event): array
    {
        $list = iterator_to_array($event->inputXPath->query(".//step|.//qc", $event->input));

        if (!count($list)) { // no subelements supported
            $list = [$event->input];
        }

        $list = array_map(fn($step) => [
            "prompt" => $this->extractPrompt($step),
            "type" => $step->localName
        ], $list);

        return $list;
    }        

    /**
     * Extract text prompt from the input element, stripping scripts.
     */
    private function extractPrompt(\DOMElement $input): string
    {
        global $api;

        $contentDom = new \DOMDocument();
        $contentDom->appendChild($contentDom->importNode($input, true));

        $scripts = $contentDom->getElementsByTagName("script");
        for ($i = $scripts->length - 1; $i >= 0; $i--) {
            $scripts->item($i)?->parentNode->removeChild($scripts->item($i));
        }

        $prompt = $contentDom->textContent;

        // Replace {{random|n}} with random string of length n
        // Replace {{random|n|charset}} with random string of length n from charset
        // Replace {{random|n|charset|separator}} with random string of length n from charset separated by separator
        // Example: {{random|5}} -> "XJQPW", {{random|5|abc}} -> "baccb", {{random|5|abc|-}} -> "a-c-b", {{random|5||-}} -> "A-B-C-D-E"
        $prompt = preg_replace_callback('/{{random\|(?<matches>\d+)(?:\|(?<charset>[^}|]*)(?:\|(?<separator>[^}]*))?)?}}/u', function($matches) {
            $length = (int)$matches['matches'];
            $characters = $matches['charset'] ?? '' ?: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $separator = $matches['separator'] ?? '';
            $randomString = [];
            for ($i = 0; $i < $length; $i++) {
                $randomString[] = $characters[random_int(0, strlen($characters) - 1)];
            }
            return implode($separator, $randomString);
        }, $prompt);

        return $prompt;
    }

    /**
     * Render existing article or queue generation, responding with appropriate HTTP status.
     */
    private function generateArticleAndRespond(ContentElementEvent $event, string $uuid, string $ai, array $list, bool $forceGenerate): void
    {
        global $api;

        if ($forceGenerate) {
            $api->db->query('DELETE FROM aiTexts WHERE uuid = ? LIMIT 1', $uuid); // allow regeneration by deleting old article    
        }

        $this->displayError($event->output, "⚠️ " . dgettext("zolinga-ai", "The article was not published yet. Try again later.")." (UUID: $uuid)");
        $removeInvalidLinks = $event->input->getAttribute("remove-invalid-links") === "true";
        $this->generateArticle($uuid, $ai, $list, $removeInvalidLinks, $event->input->getAttribute("tag") ?: null);
        $event->setStatus(ContentElementEvent::STATUS_OK, "The article was not published yet. Try again later.");
        header("Retry-After: 86400");
        http_response_code(StatusEnum::SERVICE_UNAVAILABLE->value);                
    }

    private function print(\DOMDocumentFragment $frag, string $text): void
    {
        $pre = $frag->ownerDocument->createElement("pre");
        $pre->setAttribute("class", "zolinga-text print-only");
        $pre->appendChild(new \DOMText($text));
        $frag->appendChild($pre);
    }

    private function renderArticle(\DOMElement $input, \DOMDocumentFragment $frag, AiTextModel $article): void
    {
        global $api;

        $doc = new \DOMDocument();
        if (!@$doc->loadXML($article->contents)) { 
            $api->log->error("ai", "Failed to parse article content as XML: " . libxml_get_last_error()->message);
        }
        $body = $doc->getElementsByTagName('article')->item(0);

        $rootTagName = $input->getAttribute('element') ?: 'article';
        if ($rootTagName !== 'article') {
            $newRoot = $doc->createElement($rootTagName);
            $newRoot->append(...$body->childNodes);
            $doc->documentElement->replaceWith($newRoot);
            $body = $newRoot;
        }

        if (!$body instanceof \DOMElement) {
            throw new \Exception("Invalid article format: $article");
        }

        // Copy all attributes from the original <ai-text> element
        // except for "ai" and "uuid"
        foreach ($input->attributes as $attr) {
            // Only select attributes - and all data-*
            if (in_array(explode('-', $attr->name, 2)[0], ["class", "style", "id", "data"])) {
                $body->setAttribute($attr->name, $attr->value);
            }
        }

        $body->setAttribute('data-tag', $article->tag);
        $body->setAttribute("data-text-id", $article->id);
        $frag->appendChild($frag->ownerDocument->importNode($body, true));
    }
    
    private function displayError(\DOMDocumentFragment $frag, string $message): void
    {
        $errorMsgElement = $frag->ownerDocument->createElement("article");
        $frag->appendChild($errorMsgElement);
        $errorMsgElement->setAttribute("data-text-id", "");
        $errorMsgElement->setAttribute("class", "zolinga-text warning");
        $errorMsgElement->appendChild(new \DOMText(dgettext("zolinga-ai", $message)));
    }
    
    /**
    * Query the AI model to generate the article.
    * 
    * The request will be queued and after processing the response will be dispatched as an event
    * and processed by the $this->onGenerateArticle() method.
    *
    * @param string $uuid The unique identifier of the article.
    * @param string $ai The backend to use.
    * @param array $list The list of steps and QC checks to process. Each item has 'prompt' and 'type' keys.
    * @param bool $removeInvalidLinks Whether to validate links in the article. If invalid link is found, it will be removed.
    * @param string|null $tag An optional tag to associate with the article. Can be used for categorization or later retrieval. Will be stored in DB column 'tag'.
    * @return void
    */
    private function generateArticle(string $uuid, string $ai, array $list, bool $removeInvalidLinks = false, ?string $tag = null): void
    {
        global $api;
        
        if ($api->ai->isPromptAsyncQueued($uuid) && !isset($_GET['regenerate'])) {
            return;
        }
        
        $event = new AiEvent("ai:article:generated", OriginEnum::INTERNAL, [
            'ai' => $ai,
            'tag' => $tag,
            'prompt' => $list,
            // Make the articles maximally variable so they are not
            // treated as duplicates by search engines.
            'options' => [
                'temperature' => 0.9,
                'repeat_penalty' => 1.3,
                'presence_penalty' => 0.6
            ],
            'triggerURL' => $api->url->getCurrentUrl(),
            'removeInvalidLinks' => $removeInvalidLinks
        ]);
        $event->uuid = $uuid;
        
        $api->ai->promptAsync($event);
    }
    
    /**
    * This method is called when the AI model generates the article.
    * 
    * The response is processed and HTML-formatted article is saved to the database.
    * 
    * The expectation is that the input is in Markdown format.
    *
    * @param AiEvent $event
    * @return void
    */
    public function onGenerateArticle(AiEvent $event): void
    {
        global $api;
        
        $uuid = $event->uuid;
        $contents = $event->response['data'];
        $tag = $event->request['tag'] ?? null;
        $triggerURL = $event->request['triggerURL'] ?? null;
        $removeInvalidLinks = $event->request['removeInvalidLinks'] ?? false;
        
        $article = AiTextModel::getTextModel($uuid) ?: AiTextModel::createTextModel($uuid, $contents, $triggerURL, $tag);
        $article->setContents($contents, $removeInvalidLinks); // this setter converts Markdown to HTML

        $article->save();
        
        $event->setStatus(AiEvent::STATUS_OK, "Article saved.");
    }
}
