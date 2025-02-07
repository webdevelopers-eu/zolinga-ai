<?php

namespace Zolinga\AI\Elements;

use Parsedown;
use Zolinga\AI\Events\PromptEvent;
use Zolinga\AI\Model\ArticleModel;
use Zolinga\Cms\Events\ContentElementEvent;
use Zolinga\System\Events\ListenerInterface;
use Zolinga\System\Types\OriginEnum;

/**
 * Processes CMS generative article content. 
 * 
 * Example: <ai-article backend="default" model="my-model">Hello, how are you?</ai-article>
 * 
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2025-02-07
 */
class ArticleElement implements ListenerInterface
{
    public function __construct() {}

    /**
     * This method is called when the <ai-article> element is processed.
     * See more in wiki.
     *
     * @param ContentElementEvent $event
     * @return void
     */
    public function onArticleElement(ContentElementEvent $event): void
    {
        global $api;

        $backend = $event->input->getAttribute("backend") ?: "default";
        $model = $event->input->getAttribute("model");
        $prompt = $event->input->textContent;

        // Erase the contents of the element to be safe
        $event->output->nodeValue = "";

        if ($event->input->getAttribute("id")) {
            $uuid = $event->input->getAttribute("id");
        } else {
            $fingerprint = "$backend:$model::$prompt";
            $fingerprint = preg_replace('/\s+/', ' ', trim($fingerprint));
            $uuid = 'ai:article:' . substr(sha1($fingerprint), 0, 12);
        }

        $article = ArticleModel::getArticle($uuid);
        if ($article) {
            $doc = new \DOMDocument();
            $doc->loadXML($article->contents);
            $body = $doc->getElementsByTagName('section')->item(0);
            $event->output->appendChild($event->output->ownerDocument->importNode($body, true));
            $event->setStatus(ContentElementEvent::STATUS_OK, "Article $uuid rendered.");
            if (isset($_REQUEST['regenerate'])) {
                $this->generateArticle($uuid, $backend, $model, $prompt);
            }
        } else {
            $errorMsgElement = $event->output->appendChild($event->output->ownerDocument->createElement("section"));
            $errorMsgElement->setAttribute("data-article-id", "");
            $errorMsgElement->setAttribute("class", "zolinga-article warning");
            $errorMsgElement->appendChild(new \DOMText(dgettext("zolinga-ai", "⚠️ The server is busy. Please try again later.")));
            $this->generateArticle($uuid, $backend, $model, $prompt);
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
     * @param mixed $model The model to use.
     * @param mixed $prompt The prompt to use.
     * @return void
     */
    private function generateArticle(string $uuid, $backend, $model, $prompt): void
    {
        global $api;

        if ($api->ai->isPromptQueued($uuid)) {
            return;
        }

        $event = new PromptEvent("ai:article:generated", OriginEnum::INTERNAL, [
            'backend' => $backend,
            'model' => $model,
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
        $contents = $event->response['data']['message']['content'];

        $article = ArticleModel::getArticle($uuid) ?: ArticleModel::createArticle($uuid, $contents);
        $article->contents = $contents;
        $article->save();

        $event->setStatus(PromptEvent::STATUS_OK, "Article saved.");
    }
}
