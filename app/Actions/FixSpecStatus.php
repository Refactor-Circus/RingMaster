<?php

namespace App\Actions;

use App\Enums\AttentionKind;
use App\Enums\AttentionStatus;
use App\Models\Activity;
use App\Models\AttentionItem;
use App\Models\Spec;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RefactorCircus\Ringside\Specs\SpecRepository;

/**
 * A spec whose meta.yml status is not one Ringside knows (a session wrote
 * `shaped`, say) matches no stage, so nothing would ever pick it up. It is
 * raised in the Inbox; your answer sets the status in meta.yml directly, with
 * no Claude session, and the job follows it from there.
 */
final class FixSpecStatus
{
    public const string KEY = 'status:';

    public const string SOURCE = 'status';

    /** A meta.yml that is not valid YAML: you fix the file, the next scan reads it. */
    public const string UNREADABLE = 'unreadable';

    public const string FIXED = 'I fixed the file';

    /** Answer => the status it sets. */
    public const array OPTIONS = [
        'Shape it again (ticketed)' => 'ticketed',
        'Finish shaping (shaping)' => 'shaping',
        'Review the written spec (written)' => 'written',
        'Build it (approved)' => 'approved',
        'Close it (closed)' => 'closed',
    ];

    public static function unknown(string $status): bool
    {
        return ! in_array($status, SpecRepository::STATUSES, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributes(Spec $spec, ?string $error = null): array
    {
        if ($spec->status === self::UNREADABLE) {
            return [
                'kind' => AttentionKind::Escalation,
                'question' => "This job's meta.yml is not valid YAML, so RingMaster cannot tell its stage and nothing picks it up. Fix {$spec->dir}/meta.yml; the next scan reads it again.",
                'options' => [self::FIXED],
                'recommendation' => null,
                'evidence' => (string) $error,
                'source' => self::SOURCE,
            ];
        }

        return [
            'kind' => AttentionKind::Escalation,
            'question' => "This job's meta.yml says `status: {$spec->status}`, which is not a Ringside status, so no stage picks it up. Where should it go from here?",
            'options' => array_keys(self::OPTIONS),
            'recommendation' => null,
            'evidence' => 'Ringside statuses: '.implode(', ', SpecRepository::STATUSES).'.',
            'source' => self::SOURCE,
        ];
    }

    /**
     * Sets the status you chose in meta.yml and closes the item.
     */
    public function apply(AttentionItem $item): void
    {
        if ($item->answer === self::FIXED) {
            $item->update(['status' => AttentionStatus::Closed, 'closed_at' => now()]);

            return;
        }

        $status = self::OPTIONS[$item->answer] ?? null;
        $item->loadMissing('spec.project');
        $path = $item->spec->project->path.'/'.$item->spec->dir.'/meta.yml';

        if ($status === null || ! is_file($path)) {
            return;
        }

        $meta = File::get($path);
        $meta = preg_match('/^status:.*$/m', $meta) === 1
            ? (string) preg_replace('/^status:.*$/m', "status: {$status}", $meta, 1)
            : rtrim($meta)."\nstatus: {$status}\n";
        File::put($path, $meta);

        $item->update(['status' => AttentionStatus::Closed, 'closed_at' => now()]);
        Activity::record('spec.status', 'Status set to '.$status.' in RingMaster: '.Str::limit($item->spec->key, 80), spec: $item->spec);
    }
}
