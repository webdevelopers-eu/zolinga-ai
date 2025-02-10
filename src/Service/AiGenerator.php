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
                $count = $api->db->query(
                    "UPDATE aiRequests SET status = ?, reqStart = ? WHERE id = ? AND status = 'queued'", 
                    PromptStatusEnum::PROCESSING, 
                    time(),
                    $id
                );
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
        
        try {
            $this->prompt($id, $event);
            $event->dispatch();
            $api->db->query("DELETE FROM aiRequests WHERE id = ?", $id);
        } catch (\Throwable $e) {
            $api->log->error('ai', "Error processing request {$id}: {$e->getMessage()}, trace {$e->getTraceAsString()}");
            $api->db->query(
                "UPDATE aiRequests SET status = ?, reqEnd = ? WHERE id = ?", 
                PromptStatusEnum::ERROR, 
                time(),
                $id
            );
        }
    }
    
    /**
    * Sends a request to the Ollama AI backend with the provided prompt and model.
    * 
    * Decodes the JSON response and stores it in the event's response data.
    *
    * @param int $id The ID of the request being processed.
    * @param PromptEvent $event The event containing the request details.
    * @global object $api The global API object containing configuration and logging utilities.
    * @throws JsonException If the JSON decoding of the response fails.
    */
    private function prompt(int $id, PromptEvent $event): void
    {
        global $api;
        
        $ai = $event->request['ai']; // for now always 'ollama'
        $prompt = $event->request['prompt'];

        $response = $api->ai->prompt($ai, $prompt);
        $api->db->query("UPDATE aiRequests SET response = ? WHERE id = ?", json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $id);
        $event->response['data'] = $response;
    }
}
