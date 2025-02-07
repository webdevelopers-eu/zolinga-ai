<?php

// Run it as: bin/zolinga ai:generate --loop

namespace Zolinga\AI\Service;

use Zolinga\AI\Enum\PromptStatusEnum;
use Zolinga\AI\Events\PromptEvent;
use Zolinga\System\Events\CliRequestResponseEvent;
use Zolinga\System\Events\ListenerInterface;
use Zolinga\System\Events\RequestResponseEvent;

class AiGenerator implements ListenerInterface
{
    /**
     * Handles the generation process triggered by a CLI request.
     *
     * Process all queued AI prompts. If use specified --loop option on command line
     * it will keep processing prompts and watching for new ones indefinitely.
     * 
     * Example: ./bin/zolinga ai:generate --loop
     * 
     * Only one instance of this process can run at a time. If another instance is running
     * it will exit immediately.
     *
     * @param CliRequestResponseEvent $event The event object containing the CLI request and response.
     */
    public function onGenerate(CliRequestResponseEvent $event)
    {
        global $api;

        $loop = (bool) ($event->request['loop'] ?? false);

        if ($api->registry->acquireLock('ai:generate', 0)) {
            do {
                $this->processQueuedAll();
            } while ($loop && !sleep(5));

            $event->setStatus(RequestResponseEvent::STATUS_OK, "Prompts processed.");
            $api->registry->releaseLock('ai:generate');
        } else {
            $event->setStatus(RequestResponseEvent::STATUS_OK, "Another process is already running.");
        }
    }

    private function processQueuedAll(): void
    {
        global $api;
        do {
            $id = $api->db->query("SELECT id FROM aiRequests WHERE status = ? ORDER BY created ASC", PromptStatusEnum::QUEUED)['id'];
            if ($id) {
                // Try to lock the row by setting the status to 'processing'
                $count = $api->db->query("UPDATE aiRequests SET status = ? WHERE id = ? AND status = 'queued'", PromptStatusEnum::PROCESSING, $id);
                if ($count == 1) {
                    $this->processRequest($id);
                }
            }
        } while ($id);
    }

    private function processRequest(int $id): void
    {
        global $api;

        $row = $api->db->query("SELECT * FROM aiRequests WHERE id = ?", $id)->fetchAssoc();
        $eventData = json_decode($row['promptEvent'], true);
        $event = PromptEvent::fromArray($eventData);

        $this->queryOllama($event);

        $event->dispatch();

        $api->db->query("DELETE FROM aiRequests WHERE id = ?", $id);
    }

    /**
     * Sends a request to the Ollama AI backend with the provided prompt and model.
     * 
     * Decodes the JSON response and stores it in the event's response data.
     *
     * @param PromptEvent $event The event containing the request details.
     * @global object $api The global API object containing configuration and logging utilities.
     * @throws JsonException If the JSON decoding of the response fails.
     */
    private function queryOllama(PromptEvent $event): void
    {
        global $api;

        $type = $api->config['ai']['backends'][$event->request['backend']]['type']; // for now always 'ollama'
        $uri = $api->config['ai']['backends'][$event->request['backend']]['uri'];
        $prompt = $event->request['prompt'];
        $model = $event->request['model'];
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

        $timer = microtime(true);
        $api->log->info('ai', "Ollama request {$event->uuid} to $urlSafe using model {$model} .");
        $response = file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' =>
                "Content-Type: application/json; charset=utf-8\r\n" .
                    ($basicAuth ? "Authorization: Basic $basicAuth\r\n" : '') .
                    "User-Agent: Zolinga/1.0\r\n" .
                    "Accept: application/json\r\n" .
                    "Accept-Charset: utf-8\r\n",
                'content' => json_encode($request, JSON_UNESCAPED_UNICODE),
                'timeout' => 600, // 10 minutes
            ],
        ]));
        $api->log->info('ai', "Ollama request {$event->uuid} took " . round(microtime(true) - $timer, 2) . "s.");
        file_put_contents("private://zolinga-ai/ai-last-response.json", $response);

        $event->response['data'] = json_decode($response, true, 512, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
