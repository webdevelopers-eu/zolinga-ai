<?php

namespace Zolinga\AI\Elements;

use Parsedown;
use Zolinga\AI\Events\AiEvent;
use Zolinga\AI\Model\AiTextModel;
use Zolinga\Cms\Events\ContentElementEvent;
use Zolinga\System\Events\ListenerInterface;
use Zolinga\System\Types\OriginEnum;

/**
* Processes CMS generative article content. 
* 
* Example: <ai-text ai="default" model="my-model" remove-invalid-links="true">Hello, how are you?</ai-text>
*
* The element will be replaced with the generated article. If the article is not yet generated,
* a placeholder message will be displayed and the article generation will be queued.
*
* Attributes:
* - ai: Optional. The AI backend to use. Default is "default".
* - uuid: Optional. The unique identifier of the article. If not provided, hash of the prompt will be used.
* - remove-invalid-links: Optional. If set to "true", invalid links in the generated article will be removed.
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
        $api->cmsParser->parse($event->input, true);

        $contentDom = new \DOMDocument();
        $contentDom->appendChild($contentDom->importNode($event->input, true));
        // Remove all scripts
        $scripts = $contentDom->getElementsByTagName("script");
        for ($i = $scripts->length - 1; $i >= 0; $i--) {
            $scripts->item($i)?->parentNode->removeChild($scripts->item($i));
        }
        $prompt = $contentDom->textContent;

        if ($event->input->hasAttribute('print-only')) {
            $pre = $event->output->ownerDocument->createElement("pre");
            $pre->appendChild($event->output->ownerDocument->createTextNode($prompt));
            $event->output->appendChild($pre);
            $event->setStatus(ContentElementEvent::STATUS_OK, "Printed prompt only.");
            return;
        }
        
        // Erase the contents of the element to be safe
        $event->output->nodeValue = "";
        
        if ($event->input->getAttribute("uuid")) {
            $uuid = $event->input->getAttribute("uuid");
        } else {
            $fingerprint = "$ai:$prompt";
            $fingerprint = preg_replace('/\s+/', ' ', trim($fingerprint));
            $uuid = 'ai:article:' . substr(sha1($fingerprint), 0, 12);
        }
        
        $article = AiTextModel::getTextModel($uuid);
        $allowedIps = $event->input->getAttribute("allow-generate-from") ?: null;
        if ($article) {
            $event->setStatus(ContentElementEvent::STATUS_OK, "Article $uuid rendered.");
            $this->renderArticle($event->input, $event->output, $article);             
        } elseif (!$allowedIps || $api->network->matchCidr($_SERVER['REMOTE_ADDR'], explode(',', $allowedIps))) {
            $this->displayError($event->output, "⚠️ " . dgettext("zolinga-ai", "The server is busy. Please try again later."));
            $removeInvalidLinks = $event->input->getAttribute("remove-invalid-links") === "true";
            $this->generateArticle($uuid, $ai, $prompt, $removeInvalidLinks);
            $event->setStatus(ContentElementEvent::STATUS_OK, "Article $uuid not found.");
            // Throw HTTP error 503 with Retry-After: 600
            header("Retry-After: 600");
            http_response_code(503);                
        } else {
            $this->displayError($event->output, "⚠️ " . dgettext("zolinga-ai", "The article was not found.")." (Your IP is {$_SERVER['REMOTE_ADDR']})");
            $event->setStatus(ContentElementEvent::STATUS_OK, "Article $uuid not found and generation not allowed.");
            http_response_code(410);
        }
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
            if (in_array($attr->name, ["ai", "element", "uuid"])) {
                continue;
            }
            $body->setAttribute($attr->name, $attr->value);
        }

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
    * @param string $prompt The prompt to use.
    * @param bool $removeInvalidLinks Whether to validate links in the article. If invalid link is found, it will be removed.
    * @return void
    */
    private function generateArticle(string $uuid, string $ai, string $prompt, bool $removeInvalidLinks = false): void
    {
        global $api;
        
        if ($api->ai->isPromptAsyncQueued($uuid)) {
            return;
        }
        
        $event = new AiEvent("ai:article:generated", OriginEnum::INTERNAL, [
            'ai' => $ai,
            'prompt' => $prompt,
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
        $triggerURL = $event->request['triggerURL'] ?? null;
        $removeInvalidLinks = $event->request['removeInvalidLinks'] ?? false;
        
        $article = AiTextModel::getTextModel($uuid) ?: AiTextModel::createTextModel($uuid, $contents, $triggerURL);
        $article->setContents($contents, $removeInvalidLinks); // this setter converts Markdown to HTML

        $article->save();
        
        $event->setStatus(AiEvent::STATUS_OK, "Article saved.");
    }
}
