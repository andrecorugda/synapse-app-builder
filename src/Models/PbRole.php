<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Models;

use Andre\AiPageBuilder\Support\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role for the BUILT app's end-users (distinct from the builder/admin who
 * uses the Filament panel). Roles carry permissions; `is_admin` roles bypass
 * every check.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_admin
 */
class PbRole extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_admin' => 'boolean',
    ];

    public function getConnectionName(): ?string
    {
        return Schema::connection();
    }

    public function getTable(): string
    {
        return Schema::table('roles');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<PbPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(PbPermission::class, 'role_id');
    }

    /**
     * @return HasMany<PbUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(PbUser::class, 'role_id');
    }
}
