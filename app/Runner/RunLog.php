<?php

namespace App\Runner;

/**
 * Reads a run's stream-json log: what Claude said, the tools it used, and
 * the final result with its cost.
 */
final class RunLog
{
    /**
     * @return list<array{type: string, text: string}>
     */
    public function entries(string $path, int $limit = 300): array
    {
        $entries = [];

        foreach ($this->events($path) as $event) {
            $type = $event['type'] ?? null;
            $message = is_array($event['message'] ?? null) ? $event['message'] : [];
            $content = is_array($message['content'] ?? null) ? $message['content'] : [];

            if ($type === 'assistant') {
                foreach ($content as $block) {
                    if (! is_array($block)) {
                        continue;
                    }

                    if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null) && trim($block['text']) !== '') {
                        $entries[] = ['type' => 'text', 'text' => trim($block['text'])];
                    } elseif (($block['type'] ?? null) === 'tool_use') {
                        $entries[] = ['type' => 'tool', 'text' => (string) ($block['name'] ?? 'tool').' '.self::brief($block['input'] ?? null)];
                    }
                }
            } elseif ($type === 'result') {
                $entries[] = ['type' => ($event['is_error'] ?? false) ? 'error' : 'result', 'text' => is_string($event['result'] ?? null) ? $event['result'] : (string) ($event['subtype'] ?? 'finished')];
            }
        }

        return array_slice($entries, -$limit);
    }

    /**
     * The final result event, or null while the run is still going.
     *
     * @return array{text: string, is_error: bool, cost_usd: ?float}|null
     */
    public function result(string $path): ?array
    {
        $result = null;

        foreach ($this->events($path) as $event) {
            if (($event['type'] ?? null) === 'result') {
                $result = [
                    'text' => is_string($event['result'] ?? null) ? $event['result'] : '',
                    'is_error' => (bool) ($event['is_error'] ?? false),
                    'cost_usd' => is_numeric($event['total_cost_usd'] ?? null) ? (float) $event['total_cost_usd'] : null,
                ];
            }
        }

        return $result;
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function events(string $path): iterable
    {
        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $event = json_decode($line, true);

            if (is_array($event)) {
                yield $event;
            }
        }

        fclose($handle);
    }

    private static function brief(mixed $input): string
    {
        if (! is_array($input)) {
            return '';
        }

        foreach (['command', 'file_path', 'pattern', 'skill', 'scriptPath', 'description', 'prompt'] as $key) {
            if (is_string($input[$key] ?? null)) {
                return mb_strimwidth(str_replace("\n", ' ', $input[$key]), 0, 160, '…');
            }
        }

        return mb_strimwidth((string) json_encode($input, JSON_UNESCAPED_SLASHES), 0, 160, '…');
    }
}
