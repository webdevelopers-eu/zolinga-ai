<?php

namespace Zolinga\AI\Elements;

use Parsedown;
use Zolinga\AI\Events\PromptEvent;
use Zolinga\AI\Model\AiTextModel;
use Zolinga\Cms\Events\ContentElementEvent;
use Zolinga\System\Events\ListenerInterface;
use Zolinga\System\Types\OriginEnum;

/**
 * Processes CMS generative article content. 
 * 
 * Example: <ai-text backend="default" model="my-model">Hello, how are you?</ai-text>
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

        $backend = $event->input->getAttribute("backend") ?: "default";
        $prompt = $event->input->textContent;

        // Erase the contents of the element to be safe
        $event->output->nodeValue = "";

        if ($event->input->getAttribute("uuid")) {
            $uuid = $event->input->getAttribute("uuid");
        } else {
            $fingerprint = "$backend:$prompt";
            $fingerprint = preg_replace('/\s+/', ' ', trim($fingerprint));
            $uuid = 'ai:article:' . substr(sha1($fingerprint), 0, 12);
        }

        $article = AiTextModel::getTextModel($uuid);
        if ($article) {
            $doc = new \DOMDocument();
            $doc->loadXML($article->contents);
            $body = $doc->getElementsByTagName('section')->item(0);
            if (!$body instanceof \DOMElement) {
                throw new \Exception("Invalid article format: $article");
            }
            $body->setAttribute("data-text-id", $article->id);
            $event->output->appendChild($event->output->ownerDocument->importNode($body, true));
            $event->setStatus(ContentElementEvent::STATUS_OK, "Article $uuid rendered.");
            // Selectively regenerate the article 
            if (ZOLINGA_DEBUG && isset($_REQUEST['regenerate'])) {
                if (intval($_REQUEST['regenerate'] ?: 0) == $article->id) {
                    $this->generateArticle($uuid, $backend, $prompt);
                }
            }
        } else {
            $errorMsgElement = $event->output->appendChild($event->output->ownerDocument->createElement("section"));
            $errorMsgElement->setAttribute("data-text-id", "");
            $errorMsgElement->setAttribute("class", "zolinga-text warning");
            $errorMsgElement->appendChild(new \DOMText(dgettext("zolinga-ai", "⚠️ The server is busy. Please try again later.")));
            $this->generateArticle($uuid, $backend, $prompt);
            $event->setStatus(ContentElementEvent::STATUS_OK, "Article $uuid not found.");
            // Throw HTTP error 503 with Retry-After: 600
            header("Retry-After: 600");
            http_response_code(503);
        }
    }

    /**
     * Query the AI model to generate the article.
     * 
     * The request will be queued and after processing the response will be dispatched as an event
     * and processed by the $this->onGenerateArticle() method.
     *
     * @param string $uuid The unique identifier of the article.
     * @param mixed $backend The backend to use.
     * @param mixed $prompt The prompt to use.
     * @return void
     */
    private function generateArticle(string $uuid, $backend, $prompt): void
    {
        global $api;

        if ($api->ai->isPromptAsyncQueued($uuid)) {
            return;
        }

        $event = new PromptEvent("ai:article:generated", OriginEnum::INTERNAL, [
            'backend' => $backend,
            'prompt' => $prompt,
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
     * @param PromptEvent $event
     * @return void
     */
    public function onGenerateArticle(PromptEvent $event): void
    {
        global $api;

        $uuid = $event->uuid;
        $contents = $event->response['data'];

        $article = AiTextModel::getTextModel($uuid) ?: AiTextModel::createTextModel($uuid, $contents);
        $article->contents = $contents;
        $article->save();

        $event->setStatus(PromptEvent::STATUS_OK, "Article saved.");
    }
}
