<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Activitylog\Models\Activity;

class ActivityLog extends Activity
{
    protected $casts = [
        'properties'        => 'collection',
        'attribute_changes' => 'array',
    ];
}
