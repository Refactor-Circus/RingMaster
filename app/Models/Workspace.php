<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder RingMaster scans for packages.
 *
 * @property int $id
 * @property string $path
 * @property string $name
 * @property bool $from_config
 * @property bool $enabled
 * @property string|null $error
 * @property CarbonImmutable|null $scanned_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
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
