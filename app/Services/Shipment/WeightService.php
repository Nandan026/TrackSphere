<?php

declare(strict_types=1);

namespace App\Services\Shipment;

/**
 * Weight calculations. Dimensions in cm, weights in kg.
 */
class WeightService
{
    public const DEFAULT_DIVISOR = 5000;

    /**
     * Volumetric weight = (L x W x H) / divisor, rounded to 3 decimals.
     */
    public function volumetricWeight(float $length, float $width, float $height, int $divisor = self::DEFAULT_DIVISOR): float
    {
        if ($length <= 0 || $width <= 0 || $height <= 0) {
            return 0.0;
        }

        $divisor = $divisor > 0 ? $divisor : self::DEFAULT_DIVISOR;

        return round(($length * $width * $height) / $divisor, 3);
    }

    /**
     * Chargeable weight = max(actual, volumetric), rounded to 3 decimals.
     */
    public function chargeableWeight(float $actualWeight, float $volumetricWeight): float
    {
        return round(max($actualWeight, $volumetricWeight, 0.0), 3);
    }

    /**
     * Rounds up to the nearest slab (e.g. 0.5 kg) for rate lookups.
     */
    public function roundToSlab(float $weight, float $slab = 0.5): float
    {
        if ($slab <= 0) {
            return round($weight, 3);
        }

        return round(ceil($weight / $slab) * $slab, 3);
    }
}
