<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RefactorCircus\Ringside\Project as RingsideProject;

/**
 * A package (or a workspace root) found in a workspace.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $path
 * @property string $folder
 * @property string $name
 * @property bool $is_workspace
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable(['workspace_id', 'path', 'folder', 'name', 'is_workspace'])]
class Project extends Model
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<Spec, $this>
     */
    public function specs(): HasMany
    {
        return $this->hasMany(Spec::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Projects at a path or inside it: a session in a workspace root sees
     * every package in it. No path means every project.
     *
     * @param  Builder<Project>  $query
     */
    public function scopeWithin(Builder $query, ?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $path = rtrim(realpath($path) ?: $path, '/');

        // "!" escapes LIKE wildcards in folder names on every database driver.
        $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $path).'/%';

        $query->where(fn (Builder $query) => $query->where('path', $path)->orWhereRaw("path like ? escape '!'", [$like]));
    }

    public function ringside(): RingsideProject
    {
        return new RingsideProject($this->path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_workspace' => 'boolean',
        ];
    }
}
