<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
