<?php

namespace XLaravel\Payline\Routing;

use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Models\CommissionRate;

class GatewayRouter
{
    private string $model;

    public function __construct()
    {
        $this->model = config('payline.models.commission_rate', CommissionRate::class);
    }

    /**
     * Returns all gateways sorted ascending by commission rate for the given card profile
     * and installment count: ['hoppa' => 2.0300, 'qnb' => 2.9200]
     *
     * Match priority: card_family + card_type exact match > partial > wildcard (null)
     */
    public function rankedFor(CardProfile $profile, int $installments = 1): array
    {
        $model = $this->model;

        $rates = $model::query()
            ->where(fn ($q) => $q->where('card_family', $profile->family)->orWhereNull('card_family'))
            ->where(fn ($q) => $q->where('card_type', $profile->type?->value)->orWhereNull('card_type'))
            ->where('installments', $installments)
            ->orderByRaw('(card_family IS NOT NULL) + (card_type IS NOT NULL) DESC')
            ->orderBy('rate')
            ->get();

        $result = [];
        foreach ($rates as $rate) {
            if (!isset($result[$rate->gateway])) {
                $result[$rate->gateway] = (float) $rate->rate;
            }
        }

        asort($result);
        return $result;
    }

    /**
     * Returns the name of the gateway with the lowest commission rate.
     * Returns null if no matching record exists in the database.
     */
    public function cheapestFor(CardProfile $profile, int $installments = 1): ?string
    {
        $ranked = $this->rankedFor($profile, $installments);
        return array_key_first($ranked) ?: null;
    }
}
