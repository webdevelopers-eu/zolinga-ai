<?php

namespace Zolinga\AI\Service;

use DOMDocument;
use Parsedown;
use Zolinga\AI\Enum\AiaiEnum;
use Zolinga\AI\Enum\AiTypeEnum;
use Zolinga\AI\Events\AiEvent;
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
    *  $api->ai->promptAsync(new AiEvent(
    *      "my-response-process", 
    *      request: [
    *        'ai' => 'default',
    *        'prompt' => 'Hello, how are you?'
    *      ], 
    *      response: [
    *        "myId" => 123,
    *        // "data" => [...] will be set by the system and will contain the AI response.
    *      ]
    * ));
    * 
    * When AI processes the request the AiEvent will have the response data set in $event->response['data']
    * and the event will be dispatched. Your listener is expected to listen for the event with the same type
    * as the one you supplied to the \Zolinga\AI\Events\AiEvent constructor. 
    * 
    * You can set your own meta data into $event->response, those will be preserved and dispatched with the event.
    *
    * @param AiEvent $event The event to handle the AI response.
    * @param array $options Optional parameters to customize the prompt.
    * @throws \Exception If the request cannot be processed.
    * 
    * @return string The request ID - technically it returns $event->uuid
    */
    public function promptAsync(AiEvent $event): string
    {
        global $api;
        
        if ($this->isPromptAsyncQueued($event->uuid)) {
            throw new \Exception("The prompt with UUID '{$event->uuid}' is already queued.", 1223);
        }
        
        $lastInsertId = $api->db->query("INSERT INTO aiEvents (created, uuid, uuidHash, aiEvent) VALUES (?, ?, UNHEX(SHA1(?)), ?)",
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
    
    $id = $api->db->query("SELECT id FROM aiEvents WHERE uuidHash = UNHEX(SHA1(?))", $uuid)['id'];
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
* @param string $ai The backend to use as defined in the configuration.
* @param string $prompt The prompt to send.
* @param array|null $format Expected output format specified as JSON schema or "json" or null. See Oolama API documentation.
* @param array|null $options Optional parameters to customize the prompt. E.g. "{num_ctx: 4096}". See Ollama options.
* @param int $retry The number of times to retry the request in case of failure.
* @return array|string The response from the AI model - if the $format is set to "json" or JSON schema, the response is decoded array, otherwise it is a string.
*/
public function prompt(string $ai, string $prompt, ?array $format = null, ?array $options = null, int $retry = 3): array|string
{
    while ($retry-- > 0) {
        try {
            return $this->processPrompt($ai, $prompt, $format, $options);
        } catch (\Exception $e) {
            trigger_error("Error processing prompt ($retry attempts left): " . $e->getMessage(), E_USER_WARNING);
        }
    }
    throw new \Exception("Failed to process the prompt after multiple attempts.", 1228);
}

private function processPrompt(string $ai, string $prompt, ?array $format = null, ?array $options = null): array|string
{
    global $api;
    
    $config = $this->getBackendConfig($ai);
    $model = $config['model'];
    $url = $config['url'];
    $url = rtrim($url, '/') . '/generate';
    
    $request = [
        'model' => $model,
        'prompt' => $prompt,
        'stream' => false,
        'system' => $api->config['ai']['systemPrompt'] ?: "You are a very capable content creator.",
    ];
    
    if ($format !== null) {
        $request['format'] = $format;
    }
    if ($options !== null) {
        $request['options'] = $options;
    }
    
    $data = $this->httpRequest($url, $request, $model);
    $answerRaw = $data['response'] 
    or throw new \Exception("Unexpected answer from the model: ".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1225);
    
    if ($format === null) { // then it is serialized json
        $answer = $answerRaw;
        foreach($config['replace'] ?: [] as ['search' => $search, 'replace' => $replace]) {
            $answer = preg_replace($search, $replace, $answer);
        }
    } else {
        $answer = json_decode($answerRaw, true, 512, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (!is_array($answer)) {
            throw new \Exception("Failed to decode the model response: " . json_encode($answerRaw), 1226);
        }
    }
    
    return $answer;
}

private function getBackendConfig(string $ai): array
{
    global $api;
    
    if (!is_array($api->config['ai']['backends'][$ai])) {
        throw new \Exception("Unknown AI backend: $ai, check that the configuration key .ai.backends.$ai exists in your Zolinga configuration.", 1222);
    }
    return array_merge(
        array("type" => AiTypeEnum::OLLAMA, "model" => "llama3.2:1b"),
        $api->config['ai']['backends']['default'], 
        $api->config['ai']['backends'][$ai]
    );
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
                <article>" . $contents . "</article>
            </body>
            </html>",  LIBXML_NOERROR | LIBXML_NONET | LIBXML_NOWARNING);
    return $doc;
}

private function httpRequest(string $url, array $request, string $model): array
{
    global $api;
    
    $urlSafe = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
    $user = parse_url($url, PHP_URL_USER);
    $pass = parse_url($url, PHP_URL_PASS);
    
    $basicAuth = $user && $pass ? base64_encode("$user:$pass") : null;
    $this->log($request, "Request to $urlSafe");
    $raw = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    
    $timer = microtime(true);
    $api->log->info('ai', "Asking $model at $urlSafe (".number_format(strlen($raw))." bytes)...");
    $response = file_get_contents($url, false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' =>
            "Content-Type: application/json; charset=utf-8\r\n" .
            ($basicAuth ? "Authorization: Basic $basicAuth\r\n" : '') .
            "User-Agent: ZolingaAI/1.0\r\n" .
            "Accept: application/json\r\n" .
            "Accept-Charset: utf-8\r\n",
            'content' => $raw,
            'timeout' => 28800, // 28800s = 8 hours
        ],
    ]));
    
    if (!$response) {
        // Generate curl reproducible command
        $curl = "curl -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' -H 'Accept-Charset: utf-8' -d ".escapeshellarg($raw)." '$url'";
        $api->log->error('ai', "Failed to get a response from the AI model. Try to run the following command in your terminal to reproduce the error: $curl");
        throw new \Exception("Failed to get a response from the AI model.", 1221);
    }
    
    $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR)
    or throw new \Exception("Failed to decode the response: $response", 1227);
    
    $promptSpeed = round($data['prompt_eval_count'] / $data['prompt_eval_duration'] * 1000000000);
    $responseSpeed = round($data['eval_count'] / $data['eval_duration'] * 1000000000);
    $stat=[
        "time " . round(microtime(true) - $timer, 2) . "s",
        "size " . number_format(strlen($response)) . " bytes",
        "response tokens {$data['eval_count']} [$responseSpeed tokens/s]",
        "prompt tokens {$data['prompt_eval_count']} [$promptSpeed tokens/s]",
    ];
    $api->log->info('ai', "Model $model responded: " . implode(", ", $stat));
    $this->log($data, "Response from $urlSafe (".number_format(strlen($response))." bytes)");
    
    return $data;
}

private function log(string|array $message, string $extraMessage): void
{
    global $api;
    
    if (!$api->config['ai']['log']) return;
    
    
    $print = '';
    if (is_array($message)) {
        if (isset($message['context'])) {
            $message['context'] = "...removed for the log..."; // very long list of numbers 
        }
        foreach ($message as $key => $value) {
            $valText = is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $print .= "🟤 $key: " . $valText . "\n";
        }
    } else {
        $print .= $message;
    }
    
    $separator = str_repeat('#', 80);
    file_put_contents(
        'private://zolinga-ai/ai.log', 
        '<<<START ' . date('Y-m-d H:i:s') . "\n$separator\n## $extraMessage\n$separator\n$print\n>>>END\n", 
        FILE_APPEND
    );
}
}
