<?php

// Run it as: bin/zolinga ai:generate --loop

namespace Zolinga\AI\Service;

use Zolinga\AI\Enum\PromptStatusEnum;
use Zolinga\AI\Events\AiEvent;
use Zolinga\System\Events\CliRequestResponseEvent;
use Zolinga\System\Events\ListenerInterface;
use Zolinga\System\Events\RequestResponseEvent;
use Zolinga\AI\Exceptions\QcException;

class AiGenerator implements ListenerInterface
{
    private ?int $timeLimit = 0;
    private ?int $startTime = null;
    private bool $debug = false;

    /**
     * Handles the generation process triggered by a CLI request.
     *
     * It AI-generates contents all $api->ai->promptAsync() requests that are queued in the database.
     *
     * Process all queued AI prompts. If use specified --loop option on command line
     * it will keep processing prompts and watching for new ones indefinitely.
     * 
     * Example: ./bin/zolinga ai:generate --loop
     *
     * --loop
     *    If set, run indefinitely checking for new prompts every 5 seconds.
     *
     * --timeLimit=N
     *    If set, exit approximately after N minutes (will let last prompt finish).
     * 
     * --uuid=UUID
     *    If set, process only the prompt with the specified UUID and exit.
     * 
     * --debug
     *   If set, enables debug logging for the generation process.
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
        $this->timeLimit = $event->request['timeLimit'] ?? 0 ? 60 * (int) $event->request['timeLimit'] : null;
        $this->startTime = time();
        $this->debug = (bool) ($event->request['debug'] ?? false);

        if ($event->request['uuid'] ?? null) {
            // If a specific UUID is provided, process only that one
            $uuid = $event->request['uuid'];
            $id = $api->db->query("SELECT id FROM aiEvents WHERE uuid = ?", $uuid)['id'] ?? null;
            if ($id) {
                $this->processRequest($id);
                $event->setStatus(RequestResponseEvent::STATUS_OK, "Request with UUID {$uuid} processed.");
            } else {
                $event->setStatus(RequestResponseEvent::STATUS_NOT_FOUND, "No request found with UUID {$uuid}.");
            }
            return;
        }

        if ($api->registry->acquireLock('ai:generate', 0)) {
            do {
                $this->processQueuedAll();
            } while ($loop && !sleep(5) && !$this->isTimeOver());

            $event->setStatus(RequestResponseEvent::STATUS_OK, "Prompts processed.");
            $api->registry->releaseLock('ai:generate');
        } else {
            $event->setStatus(RequestResponseEvent::STATUS_OK, "Another process is already running.");
        }
    }

    private function isTimeOver(): bool
    {
        if ($this->timeLimit && $this->startTime) {
            return (time() - $this->startTime) >= $this->timeLimit;
        }
        return false;
    }

    private function processQueuedAll(): void
    {
        global $api;
        do {
            // Unblock stuck or crashed
            $api->db->query(
                "UPDATE aiEvents SET status = ?, start = NULL, priority = priority * 0.9 WHERE status = ? AND start < ?",
                PromptStatusEnum::QUEUED,
                PromptStatusEnum::PROCESSING,
                time() - 60 * 240
            );

            $id = $api->db->query(
                "SELECT id FROM aiEvents WHERE status = ? ORDER BY priority DESC, created DESC LIMIT 1",
                PromptStatusEnum::QUEUED
            )['id'];

            if (!$id) break;

            // Try to lock the row by setting the status to 'processing'
            $count = $api->db->query(
                "UPDATE aiEvents SET status = ?, start = ? WHERE id = ? AND status = 'queued'",
                PromptStatusEnum::PROCESSING,
                time(),
                $id
            );
            if ($count == 1) {
                $this->processRequest($id);
            }
        } while ($id && !$this->isTimeOver());
    }

    private function processRequest(int $id): void
    {
        global $api;

        $row = $api->db->query("SELECT * FROM aiEvents WHERE id = ?", $id)->fetchAssoc();
        $eventData = json_decode($row['aiEvent'], true);
        $event = AiEvent::fromArray($eventData);
        $retriesLeft = 3;

        do {
            $retry = false;
            try {
                $this->prompt($id, $event);
                $api->log->info('ai', "Dispatching $event...");
                $event->dispatch();
                $api->db->query("DELETE FROM aiEvents WHERE id = ?", $id);
            } catch (QcException $e) {
                $api->log->warning('ai', "QC check failed for request UUID \"{$event->uuid}\" (#{$id}). Retries left: {$retriesLeft}.");
                $retry = $retriesLeft-- > 0;
            } catch (\Throwable $e) {
                $api->log->error('ai', "Error processing request {$id}: {$e->getMessage()}, trace {$e->getTraceAsString()}");
                $api->db->query(
                    "UPDATE aiEvents SET status = ?, end = ?, priority = priority * 0.9 WHERE id = ?",
                    PromptStatusEnum::ERROR,
                    time(),
                    $id
                );
            }
        } while ($retry);
    }

    /**
     * Sends a request to the Ollama AI backend with the provided prompt and model.
     * 
     * Decodes the JSON response and stores it in the event's response data.
     *
     * @param int $id The ID of the request being processed.
     * @param AiEvent $event The event containing the request details.
     * @global object $api The global API object containing configuration and logging utilities.
     * @throws JsonException If the JSON decoding of the response fails.
     */
    private function prompt(int $id, AiEvent $event): void
    {
        global $api;

        $ai = $event->request['ai']; // for now always 'ollama'

        $promptList = $event->request['prompt'];
        if (is_string($promptList)) $promptList = [["prompt" => $promptList, "type" => "step"]];

        $format = $event->request['format'] ?: null;
        $options = $event->request['options'] ?: [];
        $api->log->info('ai', "💬 Processing request UUID \"{$event->uuid}\" (id#{$id}) with AI '{$ai}'");
        $timer = microtime(true);

        $response = '';
        $stepTotal = count($promptList);
        foreach (array_values($promptList) as $ord => $step) {
            $stepNum = $ord + 1;
            $stepOptions = array_merge($options, $step['options'] ?? []);
            $stepPrompt = str_replace("{{input}}", $response, $step['prompt']);
            $prefix = "👣 Pipeline[{$step['type']} #$stepNum/$stepTotal]";

            switch ($step['type'] ?? 'step') {
                case 'qc':
                    $answer = $this->runQcCheck($ai, $stepPrompt, $response, $stepOptions);
                    if ($answer['compliant']) {
                        $api->log->info('ai', "$prefix: QC check passed for request UUID \"{$event->uuid}\" (#{$id}).");
                    } else {
                        $api->log->info('ai', "$prefix: QC check failed for request UUID \"{$event->uuid}\" (#{$id}). Explanation: {$answer['explanation']}, Test: " . substr(json_encode($stepPrompt), 0, 100) . "...");
                        throw new QcException("$prefix: QC check failed: {$answer['explanation']}");
                    }
                    break;
                case 'step':
                default:
                    $response = $api->ai->prompt($ai, $stepPrompt, $format, $stepOptions, debug: $this->debug);
                    $api->log->info('ai', "$prefix: Step completed for request UUID \"{$event->uuid}\" (#{$id}). Response: " . substr(json_encode($response), 0, 100) . "...");
            }
        }

        $api->db->query("UPDATE aiEvents SET response = ? WHERE id = ?", json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $id);
        $event->response['data'] = $response;

        $elapsed = microtime(true) - $timer;
        $time = gmdate("i\m s\s", (int) $elapsed);
        $api->log->info('ai', "🏁 Finished processing request UUID \"{$event->uuid}\" (id#{$id}) in {$time}");
    }

    private function runQcCheck(string $ai, string $prompt, string $input, array $options = []): array
    {
        global $api;

        $format = [
            "type" => "object",
            "properties" => [
                "compliant" => [
                    "type" => "boolean"
                ],
                "explanation" => [
                    "type" => "string"
                ]
            ],
            "required" => ["compliant", "explanation"]
        ];

        $qcTemplate = file_get_contents('module://zolinga-ai/data/qc-prompt.txt');
        $qcPrompt = str_replace(
            ["{{test}}", "{{input}}"],
            [$prompt, $input],
            $qcTemplate
        );
        $qcResponse = $api->ai->prompt($ai, $qcPrompt, $format, $options);
        return $qcResponse;
    }
}
