<?php

namespace Zolinga\AI\Model;

use Parsedown;
use PhpParser\Node\Expr\Cast\String_;
use Zolinga\AI\Enum\ResponseTextFormat;

/**
 * AI article model.
 * 
 * Usage:
 * 
 * $article = AiTextModel::getTextModel($uuid) ?? AiTextModel::createTextModel($uuid, $contents);
 * $article->contents = "<h1>My new article</h1>";
 * $article->save();
 * 
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2025-02-07
 */
class AiTextModel
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
    private string $contents;

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
        $contents = preg_replace('/<think>.*?<\/think>/', '', $this->contents);
    }

    public function __get(string $name) {
        switch ($name) {
            case 'contents':
                return $this->contents;
            default:
                throw new \Exception("Property '$name' does not exist.", 1224);
        }
    }

    public function __set(string $name, $value) {
        switch ($name) {
            case 'contents':
                $this->setContents($value);
                break;
            default:
                throw new \Exception("Property '$name' does not exist.", 1224);
        }
    }

    /**
     * Sets the article contents after converting it to HTML.
     * 
     * @param string $contents The article contents.
     * @param ResponseTextFormat $format The format of the contents.
     * @return void
     */
    public function setContents(string $contents, ResponseTextFormat $format = ResponseTextFormat::MARKDOWN) {
        global $api;

        $contents = trim(preg_replace('/<think>.*?<\/think>/s', '', $contents));

        // if ($format === ResponseTextFormat::MARKDOWN) { -- for now we support only MARKDOWN
        $doc = $api->ai->convertMarkdownToDOM($contents);
        $articleElement = $doc->getElementsByTagName('article')->item(0);
        $articleElement->setAttribute('class', 'zolinga-text');
        $articleElement->setAttribute('data-text-id', $this->id);
        $contents = $doc->saveXML();  

        $this->contents = $contents;
    }

    /**
     * Saves the article to the database.
     *
     * @return void
     */
    public function save() {
        global $api;

        $api->db->query("UPDATE aiTexts SET contents = ? WHERE uuidHash = UNHEX(SHA1(?))", $this->contents, $this->uuid);
    }

    /**
     * Creates a new AI article.
     *
     * @param string $uuid The UUID of the article.
     * @param string $contents The contents of the article.
     * @return AiTextModel The created article.
     */
    static public function createTextModel(string $uuid, string $contents): AiTextModel
    {
        global $api;

        $id = $api->db->query("INSERT INTO aiTexts (uuid, uuidHash, contents) VALUES (?, UNHEX(SHA1(?)), ?)", $uuid, $uuid, $contents);
        if (!$id) {
            throw new \Exception("Failed to insert AI article into database.", 1225);
        }

        return self::getTextModel($uuid);
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
