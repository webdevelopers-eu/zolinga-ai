<?php

namespace Zolinga\AI\Model;

use Parsedown;
use Zolinga\AI\Enum\ResponseTextFormat;

/**
 * AI article model.
 * 
 * Usage:
 * 
 * $article = ArticleModel::getArticle($uuid) ?? ArticleModel::createArticle($uuid, $contents);
 * $article->contents = "<h1>My new article</h1>";
 * $article->save();
 * 
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2025-02-07
 */
class ArticleModel
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
     * To create a new article use ArticleModel::createArticle() method.
     * To get an existing article use ArticleModel::getArticle() method.
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
        $contents = trim(preg_replace('/<think>.*?<\/think>/s', '', $contents));

        if ($format === ResponseTextFormat::MARKDOWN) {
            $parser = new Parsedown();
            $contents = $parser->text($contents);
        }

        // to XML
        $doc = new \DOMDocument("1.0", "UTF-8");
        $doc->formatOutput = false;
        $doc->substituteEntities = false;
        $doc->strictErrorChecking = false;
        $doc->recover = true;
        $doc->resolveExternals = false;
        $doc->validateOnParse = false;
        $doc->xmlStandalone = true;
        $doc->loadHTML("<!DOCTYPE html>\n<html>
            <head><meta charset=\"utf-8\"></head>
            <body>
                <section class=\"zolinga-article\" data-article-id=\"{$article->id}\">\n" . $contents . "</section>
            </body></html>",  LIBXML_NOERROR | LIBXML_NONET | LIBXML_NOWARNING);
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

        $api->db->query("UPDATE aiArticles SET contents = ? WHERE uuidHash = UNHEX(SHA1(?))", $this->contents, $this->uuid);
    }

    /**
     * Creates a new AI article.
     *
     * @param string $uuid The UUID of the article.
     * @param string $contents The contents of the article.
     * @return ArticleModel The created article.
     */
    static public function createArticle(string $uuid, string $contents): ArticleModel
    {
        global $api;

        $id = $api->db->query("INSERT INTO aiArticles (uuid, uuidHash, contents) VALUES (?, UNHEX(SHA1(?)), ?)", $uuid, $uuid, $contents);
        if (!$id) {
            throw new \Exception("Failed to insert AI article into database.", 1225);
        }

        return self::getArticle($uuid);
    }

    /**
     * Gets the article by UUID.
     *
     * @param string $uuid The UUID of the article.
     * @return ArticleModel|null The article or null if not found.
     */
    static public function getArticle(string $uuid): ?ArticleModel
    {
        global $api;

        $articleData = $api->db->query("SELECT * FROM aiArticles WHERE uuidHash = UNHEX(SHA1(?))", $uuid)->fetchAssoc();
        if (!$articleData) {
            return null;
        }

        return new ArticleModel($articleData);
    }
}