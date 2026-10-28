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
     * refused lists the calls the allowlist denied, as "Bash: <command>" or the tool name.
     *
     * @return array{text: string, is_error: bool, cost_usd: ?float, subtype: string, refused: list<string>}|null
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
                    'subtype' => is_string($event['subtype'] ?? null) ? $event['subtype'] : '',
                    'refused' => self::refused($event['permission_denials'] ?? null),
                ];
            }
        }

        return $result;
    }

    /**
     * The allowlist entry that would let a refused call through, or why none
     * should: a compound or absolute-path command is split or rewritten, never allowed.
     */
    public static function suggestion(string $refused): string
    {
        if (! str_starts_with($refused, 'Bash: ')) {
            return $refused;
        }

        $command = trim(substr($refused, 6));

        return match (true) {
            (bool) preg_match('/&&|\|\||;|\||\$\(|`/', $command) => 'compound command: split it into one call each',
            str_starts_with($command, '/') || str_starts_with($command, '~') => 'absolute path: call it through composer exec or a relative path',
            default => 'Bash('.strtok($command, " \t").' *)',
        };
    }

    /**
     * @return list<string>
     */
    private static function refused(mixed $denials): array
    {
        if (! is_array($denials)) {
            return [];
        }

        $refused = [];

        foreach ($denials as $denial) {
            if (! is_array($denial)) {
                continue;
            }

            $tool = is_string($denial['tool_name'] ?? null) ? $denial['tool_name'] : 'tool';
            $input = is_array($denial['tool_input'] ?? null) ? $denial['tool_input'] : [];
            $refused[] = $tool === 'Bash' && is_string($input['command'] ?? null)
                ? 'Bash: '.mb_strimwidth(str_replace("\n", ' ', $input['command']), 0, 300, '…')
                : $tool;
        }

        return $refused;
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
