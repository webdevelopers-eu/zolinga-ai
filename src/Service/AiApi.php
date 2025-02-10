<?php

namespace Zolinga\AI\Service;

use DOMDocument;
use Parsedown;
use Zolinga\AI\Enum\AiBackendEnum;
use Zolinga\AI\Events\PromptEvent;
use Zolinga\System\Events\ServiceInterface;

/**
 * AI API service. 
 * 
 * Provides methods to interact with the AI model.
 * 
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2025-02-07
 */
class AiApi implements ServiceInterface
{
    public function __construct()
    {
    }

    /**
     * Sends a prompt to the AI model and handles the response in async way.
     * 
     * Request is accepted and processed later. When finished the supplied callback event is triggered
     * and the response is set to the event object's $event->response->data property.
     * 
     * Example usage:
     *  $api->ai->promptAsync(new PromptEvent(
     *      "my-response-process", 
     *      request: [
     *        'backend' => 'default',
     *        'model' => 'my-model',
     *        'prompt' => 'Hello, how are you?'
     *      ], 
     *      response: [
     *        "myId" => 123,
     *        // "data" => [...] will be set by the system and will contain the AI response.
     *      ]
     * ));
     * 
     * When AI processes the request the PromptEvent will have the response data set in $event->response['data']
     * and the event will be dispatched. Your listener is expected to listen for the event with the same type
     * as the one you supplied to the \Zolinga\AI\Events\PromptEvent constructor. 
     * 
     * You can set your own meta data into $event->response, those will be preserved and dispatched with the event.
     *
     * @param PromptEvent $event The event to handle the AI response.
     * @param array $options Optional parameters to customize the prompt.
     * @throws \Exception If the request cannot be processed.
     * 
     * @return string The request ID - technically it returns $event->uuid
     */
    public function promptAsync(PromptEvent $event): string
    {
        global $api;

        if ($this->isPromptAsyncQueued($event->uuid)) {
            throw new \Exception("The prompt with UUID '{$event->uuid}' is already queued.", 1223);
        }

        $lastInsertId = $api->db->query("INSERT INTO aiRequests (created, uuid, uuidHash, promptEvent) VALUES (?, ?, UNHEX(SHA1(?)), ?)",
            time(),
            $event->uuid,
            $event->uuid,
            json_encode($event)
        );

        if (!$lastInsertId) {
            throw new \Exception("Failed to insert AI request into database.", 1224);
        }

        $api->log->info("ai", "Prompt with UUID '{$event->uuid}' queued for processing.");
        return $event->uuid;
    }

    /**
     * Checks if the prompt with the given UUID is already queued for processing.
     * 
     * @param string $uuid The UUID of the prompt.
     * @return bool True if the prompt is queued, false otherwise.
     */
    public function isPromptAsyncQueued(string $uuid): bool
    {
        global $api;

        $id = $api->db->query("SELECT id FROM aiRequests WHERE uuidHash = UNHEX(SHA1(?))", $uuid)['id'];
        return $id ? true : false;
    }

   /**
    * Sends a request to the AI backend with the provided prompt and model.
    * 
    * Decodes the JSON response and stores it in the event's response data.
    *
    * IMPORTANT: This is a blocking call and should be used in async context only.
    *
    * Example:
    * $response = $api->ai->prompt('default', 'deepseek-r1:8b', 'Is the labrador blue? Set `answer` prop to true if yes.', {
    *     [
    *       "type" => "object", 
    *       "properties" => [
    *           "answer" => ["type" => "boolean"],
    *           "explanation" => ["type" => "string"]
    *       ], 
    *       "required" => ["answer", "explanation"]
    *     ]
    * });
    *
    * @param string $backend The backend to use as defined in the configuration.
    * @param string $prompt The prompt to send.
    * @param array|null $format Expected output format specified as JSON schema or "json" or null. See Oolama API documentation.
    * @return array|string The response from the AI model - if the $format is set to "json" or JSON schema, the response is decoded array, otherwise it is a string.
    */
    public function prompt(string $backend, string $prompt, ?array $format = null): array|string
    {
        global $api;

        if (!is_array($api->config['ai']['backends'][$backend])) {
            throw new \Exception("Unknown AI backend: $backend, check that the configuration key .ai.backends.$backend exists in your Zolinga configuration.", 1222);
        }
        $config = array_merge(
            $api->config['ai']['backends']['default'], 
            $api->config['ai']['backends'][$backend]
        );
        $model = $config['model'];
        $uri = $config['uri'];
        $url = rtrim($uri, '/') . '/api/chat';
        $urlSafe = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
        
        $user = parse_url($uri, PHP_URL_USER);
        $pass = parse_url($uri, PHP_URL_PASS);
        
        $basicAuth = $user && $pass ? base64_encode("$user:$pass") : null;
        $request = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'stream' => false,
            'options' => ['temperature' => 0],
        ];

        if ($format !== null) {
            $request['format'] = $format;
        }
       
        $timer = microtime(true);
        $api->log->info('ai', "Ollama request to $urlSafe using model {$model}: ".substr($prompt, 0, 100)."...");
        $response = file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' =>
                "Content-Type: application/json; charset=utf-8\r\n" .
                ($basicAuth ? "Authorization: Basic $basicAuth\r\n" : '') .
                "User-Agent: ZolingaAI/1.0\r\n" .
                "Accept: application/json\r\n" .
                "Accept-Charset: utf-8\r\n",
                'content' => json_encode($request, JSON_UNESCAPED_UNICODE),
                'timeout' => 28800, // 28800s = 8 hours
            ],
        ]));
        $api->log->info('ai', "Ollama request took " . round(microtime(true) - $timer, 2) . "s.");
        
        $raw = json_decode($response, true, 512, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $answer = $raw['message']['content'] 
            or throw new \Exception("Unexpected answer from the model: $response", 1225);

        if ($format !== null) { // then it is serialized json
            $answer = json_decode($answer, true, 512, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                or throw new \Exception("Failed to decode the model response: $answer", 1226);
        }

        return $answer;
    }

    /**
     * Converts the markdown text to DOM.
     *
     * @param string $markdown
     * @return DOMDocument
     */
    public function convertMarkdownToDOM(string $markdown): DOMDocument
    {
        
        $parser = new Parsedown();
        $contents = $parser->text($markdown);

        // to XML
        $doc = new \DOMDocument("1.0", "UTF-8");
        $doc->formatOutput = false;
        $doc->substituteEntities = false;
        $doc->strictErrorChecking = false;
        $doc->recover = true;
        $doc->resolveExternals = false;
        $doc->validateOnParse = false;
        $doc->xmlStandalone = true;
        $doc->loadHTML("<!DOCTYPE html>
            <html>
            <head><meta charset=\"utf-8\"></head>
            <body>
                <section>" . $contents . "</section>
            </body>
            </html>",  LIBXML_NOERROR | LIBXML_NONET | LIBXML_NOWARNING);
        return $doc;
    }
}
