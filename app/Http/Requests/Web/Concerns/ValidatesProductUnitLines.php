<?php

namespace App\Http\Requests\Web\Concerns;

use Closure;

trait ValidatesProductUnitLines
{
    /**
     * Reject duplicate product/unit pairs within a set of line items.
     *
     * The same product may appear more than once as long as each line uses a
     * different unit.
     */
    protected function validateUniqueProductUnits(
        mixed $items,
        Closure $fail,
        string $translationKey,
        ?int $defaultUnitId = null,
    ): void {
        if (! is_array($items)) {
            return;
        }

        $seen = [];

        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            $unitId = $item['unit_id'] ?? $defaultUnitId;

            if ($productId === null || $unitId === null) {
                continue;
            }

            $key = $productId . ':' . $unitId;

            if (isset($seen[$key])) {
                $fail(__($translationKey));

                return;
            }

            $seen[$key] = true;
        }
    }
}
