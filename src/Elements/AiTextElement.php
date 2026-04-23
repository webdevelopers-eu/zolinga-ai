<?php

namespace Zolinga\AI\Elements;

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
* Attributes:
* - ai: Optional. The AI backend to use. Default is "default".
* - uuid: Optional. The unique identifier of the article. If not provided, hash of the prompt will be used.
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
        
        $api->cmsParser->parse($event->input, true);

        $list = iterator_to_array($event->inputXPath->query(".//step|.//qc", $event->input));

        if (!count($list)) { // no subelements supported
            $list = [$event->input];
        }

        $list = array_map(fn($step) => [
            "prompt" => $this->extractPrompt($step),
            "type" => $step->localName
        ], $list);

        $ai = $event->input->getAttribute("ai") ?: "default";
        $uuid = $this->resolveUuid($event->input, $ai, 
            json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($event->input->hasAttribute('print-only')) {
            $this->print($event->output, implode("\n\n", array_map(
                fn($item) => "==={$item['type']}===\n{$item['prompt']}", $list
            )));
            $event->setStatus(ContentElementEvent::STATUS_OK, "Print-only article $uuid rendered.");
            return;
        }
            
        $event->output->nodeValue = "";
        $this->handleArticleResponse($event, $uuid, $ai, $list);
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

        return $contentDom->textContent;
    }

    /**
     * Resolve UUID from explicit attribute or generate from prompt fingerprint.
     */
    private function resolveUuid(\DOMElement $input, string $ai, string $prompt): string
    {
        if ($input->getAttribute("uuid")) {
            return $input->getAttribute("uuid");
        }

        $fingerprint = "$ai:$prompt";
        $fingerprint = preg_replace('/\s+/', ' ', trim($fingerprint));
        return 'ai:article:' . substr(sha1($fingerprint), 0, 12);
    }

    /**
     * Render existing article or queue generation, responding with appropriate HTTP status.
     */
    private function handleArticleResponse(ContentElementEvent $event, string $uuid, string $ai, array $list): void
    {
        global $api;

        $article = AiTextModel::getTextModel($uuid);
        $allowedIps = $event->input->getAttribute("allow-generate-from") ?: null;
        $allowed = !$allowedIps || $api->network->matchCidr($_SERVER['REMOTE_ADDR'], explode(',', $allowedIps));
        $regenerate = isset($_GET['regenerate']);

        if ($regenerate && $allowed) {
            $api->db->query('DELETE FROM aiTexts WHERE id = ? LIMIT 1', $article?->id); // allow regeneration by deleting old article    
            $article = null; // force regeneration below
        }

        if ($article) {
            $this->renderArticle($event->input, $event->output, $article);             
            $event->setStatus(ContentElementEvent::STATUS_OK, "Article $uuid rendered.");
        } elseif ($allowed) {
            $this->displayError($event->output, "⚠️ " . dgettext("zolinga-ai", "The server is busy. Please try again later."));
            $removeInvalidLinks = $event->input->getAttribute("remove-invalid-links") === "true";
            $this->generateArticle($uuid, $ai, $list, $removeInvalidLinks, $event->input->getAttribute("tag") ?: null);
            $event->setStatus(ContentElementEvent::STATUS_OK, "Article $uuid not available at this time.");
            header("Retry-After: 86400");
            http_response_code(StatusEnum::SERVICE_UNAVAILABLE->value);                
        } else {
            $this->displayError($event->output, "⚠️ " . dgettext("zolinga-ai", "The article was not found.")." (Your IP is {$_SERVER['REMOTE_ADDR']})");
            $event->setStatus(ContentElementEvent::STATUS_OK, "Article $uuid not found and generation not allowed.");
            http_response_code(StatusEnum::GONE->value);
        }
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
        $doc = new \DOMDocument();
        $doc->loadXML($article->contents);
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
