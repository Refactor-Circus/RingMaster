<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A folder RingMaster scans for packages.
 *
 * @property int $id
 * @property string $path
 * @property string $name
 * @property bool $from_config
 * @property bool $enabled
 * @property string|null $error
 * @property Carbon|null $scanned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['path', 'name', 'from_config', 'enabled', 'error', 'scanned_at'])]
class Workspace extends Model
{
    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_config' => 'boolean',
            'enabled' => 'boolean',
            'scanned_at' => 'datetime',
        ];
    }
}
