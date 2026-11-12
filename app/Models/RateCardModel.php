<?php

declare(strict_types=1);

namespace App\Models;

class RateCardModel extends BaseModel
{
    protected $table         = 'rate_cards';
    protected $primaryKey    = 'id';
    protected $updatedField  = '';
    protected $allowedFields = [
        'organization_id', 'courier_partner_id', 'service_name', 'origin_postal_prefix',
        'destination_postal_prefix', 'min_weight_kg', 'max_weight_kg', 'base_charge',
        'per_kg_charge', 'cod_charge', 'fuel_surcharge_percent', 'effective_from',
        'effective_to', 'status',
    ];

    /**
     * Active rate cards effective on a date that fit the weight and postal prefixes.
     * NULL prefixes / max weight act as wildcards.
     *
     * @return $this
     */
    public function applicable(string $originPostal, string $destinationPostal, float $weightKg, ?string $date = null)
    {
        $date ??= gmdate('Y-m-d');

        $this->where('rate_cards.status', 'active')
            ->where('rate_cards.effective_from <=', $date)
            ->groupStart()
                ->where('rate_cards.effective_to', null)
                ->orWhere('rate_cards.effective_to >=', $date)
            ->groupEnd()
            ->where('rate_cards.min_weight_kg <=', $weightKg)
            ->groupStart()
                ->where('rate_cards.max_weight_kg', null)
                ->orWhere('rate_cards.max_weight_kg >=', $weightKg)
            ->groupEnd()
            ->groupStart()
                ->where('rate_cards.origin_postal_prefix', null)
                ->orWhere('rate_cards.origin_postal_prefix', '')
                ->orWhere($this->db->escape($originPostal) . " LIKE CONCAT(rate_cards.origin_postal_prefix, '%')", null, false)
            ->groupEnd()
            ->groupStart()
                ->where('rate_cards.destination_postal_prefix', null)
                ->orWhere('rate_cards.destination_postal_prefix', '')
                ->orWhere($this->db->escape($destinationPostal) . " LIKE CONCAT(rate_cards.destination_postal_prefix, '%')", null, false)
            ->groupEnd();

        return $this;
    }
}
