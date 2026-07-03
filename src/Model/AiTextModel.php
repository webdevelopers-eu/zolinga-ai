<?php

namespace Zolinga\AI\Model;

/**
 * AI article model.
 * 
 * Usage:
 * 
 * $article = AiTextModel::getTextModel($uuid) ?? AiTextModel::createTextModel($uuid, $markdown, $triggerURL, $tag);
 * $article->save();
 * 
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2025-02-07
 */
class AiTextModel implements \Stringable
{
    /**
     * The DB ID of the article.
     * 
     * @var int
     */
    readonly public int $id;

    /**
     * UUID of the article.
     *
     * @var string
     */
    readonly public string $uuid;

    /**
     * HTML contents of the article.
     *
     * @var string
     */
    public string $contents; 

    /**
     * Title of the article. Optional, can be null.
     */
    public ?string $title;

    /**
     * Description of the article. Optional, can be null.
     */
    public ?string $description;

    /**
     * TL;DR summary of the article. Optional, can be null.
     */
    public ?string $tldr;

    /**
     * What URL triggered the generation of this article. Optional, can be null if not applicable.
     */
    public private(set) ?string $triggerURL;

    /**
     * Optional tag associated with the article. Can be used for categorization or versioning.
     * 
     * This value is stored in the database and can be used for filtering or later retrieval of related articles.
     */
    public private(set) ?string $tag;

    /**
     * Timestamp of the last update to the article.
     */
    public private(set) int $updated;

    /**
     * Creates a new AI article model.
     * 
     * To create a new article use AiTextModel::createTextModel() method.
     * To get an existing article use AiTextModel::getTextModel() method.
     *
     * @param array $rowData The row data from the database.
     */
    public function __construct(array $rowData) 
    {
        $this->id = $rowData['id'];
        $this->uuid = $rowData['uuid'];
        $this->contents = $rowData['contents'];
        $this->title = $rowData['title'] ?? null;
        $this->description = $rowData['description'] ?? null;
        $this->tldr = $rowData['tldr'] ?? null;
        $this->triggerURL = $rowData['triggerURL'] ?? null;
        $this->tag = $rowData['tag'] ?? null;
        $this->updated = strtotime($rowData['updated']);
        // $contents = preg_replace('/<think>.*?<\/think>/', '', $this->contents);
    }
/**
     * Sets the article contents after converting Markdown to HTML.
     * 
     * Delegates to {@see markdownToHtml()} for conversion, then stores the result as $this->contents.
     * 
     * @param string $contents Markdown content.
     * @param bool $removeInvalidLinks If true, invalid or duplicate links are stripped.
     * @return void
     */
    public function setContentsMarkdown(string $contents, bool $removeInvalidLinks = false): void {
        $doc = self::markdownToHtml($contents, $removeInvalidLinks);
        $contents = $doc->saveXML();  
        $this->contents = $contents;
    }

    /**
     * Convert Markdown to a DOMDocument with an <article> root element.
     * 
     * Strips think tags, converts Markdown to HTML via Parsedown, wraps in an article element,
     * and optionally removes invalid/duplicate links.
     * 
     * @throws \Exception If the converted HTML does not contain an article element.
     * @param string $contents Markdown content.
     * @param bool $removeInvalidLinks If true, invalid or duplicate links are stripped.
     * @return \DOMDocument The DOMDocument with an article root element.
     */
    static public function markdownToHtml(string $contents, bool $removeInvalidLinks = false): \DOMDocument {
        global $api;

        $contents = trim(preg_replace('/<think>.*?<\/think>/s', '', $contents));

        // if ($format === ResponseTextFormat::MARKDOWN) { -- for now we support only MARKDOWN
        $doc = $api->ai->convertMarkdownToDOM($contents);
        $articleElement = $doc->getElementsByTagName('article')->item(0);
        if ($articleElement === null) {
            throw new \Exception("Failed to parse " . self::class . " content as XML: no <article> element found.\n\nArticle content: " . mb_substr($contents, 0, 200), 1227);
        }
        $articleElement->setAttribute('class', 'zolinga-text');

        if ($removeInvalidLinks) {
            $xpath = new \DOMXPath($doc);
            $dedupe = [];
            foreach ($xpath->query('//a[@href]') as $node) {
                /** @var \DOMElement $node */
                $href = $node->getAttribute('href');
                if (isset($dedupe[$href]) || !$api->url->isValidURL($href)) { // strip invalid links
                    $node->parentNode->insertBefore($doc->createComment(
                        "START: removed invalid or duplicate link: " . str_replace('--', '- -', $href)), $node);
                    while ($node->firstChild) {
                        $node->parentNode->insertBefore($node->firstChild, $node);
                    }
                    $node->parentNode->insertBefore($doc->createComment("END"), $node);
                    $node->parentNode->removeChild($node);
                } else {
                    $dedupe[$href] = true;
                }
            }
        }

        return $doc;
    }

    /**
     * Saves the article to the database.
     *
     * @return void
     */
    public function save() {
        global $api;

        $api->db->query("
            UPDATE aiTexts 
            SET contents = ?, title = ?, description = ?, tldr = ?, triggerURL = ?, updated = UNIX_TIMESTAMP(), tag = ?
            WHERE uuidHash = UNHEX(SHA1(?))", 
            $this->contents, $this->title, $this->description, $this->tldr, $this->triggerURL, $this->tag, $this->uuid);
    }

    /**
     * Creates a new AI article.
     *
     * @param string $uuid The UUID of the article.
     * @param string $markdown Markdown content of the article. Converted to HTML before insertion.
     * @param string|null $triggerURL The trigger URL of the article.
     * @param string|null $tag An optional tag to associate with the article. Can be used for categorization or later retrieval. Will be stored in DB column 'tag'.
     * @param string|null $title Optional title.
     * @param string|null $description Optional description.
     * @param string|null $tldr Optional TL;DR summary.
     * @return AiTextModel The created article.
     */
    static public function createTextModel(string $uuid, string $markdown, ?string $triggerURL, ?string $tag = null, ?string $title = null, ?string $description = null, ?string $tldr = null): AiTextModel
    {
        global $api;

        $contents = self::markdownToHtml($markdown)->saveXML();

        $id = $api->db->query("
            INSERT INTO aiTexts (uuid, uuidHash, contents, title, description, tldr, triggerURL, tag, updated) 
            VALUES (?, UNHEX(SHA1(?)), ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE contents = VALUES(contents), title = VALUES(title), description = VALUES(description), tldr = VALUES(tldr), triggerURL = VALUES(triggerURL), tag = VALUES(tag), updated = VALUES(updated)
        ", $uuid, $uuid, $contents, $title, $description, $tldr, $triggerURL, $tag);

        if (!is_numeric($id)) {
            throw new \Exception("Failed to insert AI article uuid " . json_encode($uuid) . " into database.", 1225);
        }

        $model = self::getTextModel($uuid)
            ?? throw new \Exception("Failed to retrieve the created AI article #id by its uuid " . json_encode($uuid) ." from database.", 1226);

        // Fire ai:text:generated event
        $event = new \Zolinga\System\Events\RequestEvent(
            'ai:text:generated',
            \Zolinga\System\Types\OriginEnum::INTERNAL,
            new \ArrayObject(['id' => $model->id, 'uuid' => $uuid, 'tag' => $tag, 'triggerURL' => $triggerURL])
        );
        $api->log->info('ai', "Dispatching event $event for generated AI text model with id #{$model->id} and uuid \"{$model->uuid}\"...");
        $api->dispatchEvent($event);

        return $model;
    }

    /**
     * Gets the article by UUID.
     *
     * @param string $uuid The UUID of the article.
     * @return AiTextModel|null The article or null if not found.
     */
    static public function getTextModel(string $uuid): ?AiTextModel
    {
        global $api;

        $articleData = $api->db->query("SELECT * FROM aiTexts WHERE uuidHash = UNHEX(SHA1(?))", $uuid)->fetchAssoc();
        if (!$articleData) {
            return null;
        }

        return new AiTextModel($articleData);
    }

    public function __toString(): string 
    {
        return __CLASS__ . "[$this->id, $this->uuid]";
    }
}
