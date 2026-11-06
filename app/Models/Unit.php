<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Traits\HasUserstamps;

class Unit extends Model
{
    use LogsActivity;
    use HasUserstamps;

    protected $fillable = ['name', 'symbol'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable();
    }

    /**
     * Unit used by flows that only reference a product.
     */
    public static function defaultId(): int
    {
        $id = static::query()->where('is_default', true)->value('id')
            ?? static::query()->orderBy('id')->value('id');

        if ($id === null) {
            throw new \RuntimeException('No unit is configured. Create a unit before adding stock.', 422);
        }

        return (int) $id;
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }
}
