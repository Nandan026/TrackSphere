<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\CourierPartnerModel;
use App\Models\RateCardModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Rate cards (route path: rate-cards).
 * Route permission: billing.manage
 */
class RateCardsController extends MasterDataController
{
    protected string $module = 'rates';

    private const PATH = 'rate-cards';

    public function index(): string
    {
        [$q, $status] = $this->listFilters();

        $courierId = $this->input('courier', 'get');
        $courierId = ctype_digit($courierId) ? (int) $courierId : 0;

        $model = new RateCardModel();
        $model->forOrg()
            ->select('rate_cards.*, cp.name AS courier_name')
            ->join('courier_partners cp', 'cp.id = rate_cards.courier_partner_id', 'left')
            ->search($q, ['rate_cards.service_name', 'cp.name', 'rate_cards.origin_postal_prefix', 'rate_cards.destination_postal_prefix'])
            ->withStatus($status);

        if ($courierId > 0) {
            $model->where('rate_cards.courier_partner_id', $courierId);
        }

        $model->orderBy('rate_cards.id', 'DESC');

        $rates = $model->paginate($this->perPage);

        return $this->renderPage('rate_cards/index', [
            'title'     => 'Rate Cards',
            'rates'     => $rates,
            'pager'     => $model->pager,
            'q'         => $q,
            'status'    => $status,
            'courierId' => $courierId,
            'couriers'  => $this->courierOptions(),
            'today'     => gmdate('Y-m-d'),
        ]);
    }

    public function create(): string
    {
        return $this->renderPage('rate_cards/form', [
            'title'    => 'Add Rate Card',
            'mode'     => 'create',
            'rate'     => [],
            'couriers' => $this->courierOptions(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $orgId = $this->orgId();
        $data  = $this->collect();

        if (! $this->validateData($data, $this->rules())) {
            return $this->validationFailed();
        }

        if ($error = $this->extraErrors($data)) {
            return $this->backWithErrors($error);
        }

        try {
            if (! (new RateCardModel())->insert($data + ['organization_id' => $orgId])) {
                throw new \RuntimeException('Rate card could not be saved.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Rate card create failed: ' . $e->getMessage());

            return $this->backWithErrors('The rate card could not be saved. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Rate card "' . $data['service_name'] . '" created.');
    }

    public function edit(int $id): string
    {
        return $this->renderPage('rate_cards/form', [
            'title'    => 'Edit Rate Card',
            'mode'     => 'edit',
            'rate'     => $this->ownedRow(new RateCardModel(), $id),
            'couriers' => $this->courierOptions(),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $this->ownedRow(new RateCardModel(), $id);
        $data = $this->collect();

        if (! $this->validateData($data, $this->rules())) {
            return $this->validationFailed();
        }

        if ($error = $this->extraErrors($data)) {
            return $this->backWithErrors($error);
        }

        try {
            if (! (new RateCardModel())->update($id, $data)) {
                throw new \RuntimeException('Rate card could not be updated.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Rate card update failed: ' . $e->getMessage());

            return $this->backWithErrors('The rate card could not be updated. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Rate card "' . $data['service_name'] . '" updated.');
    }

    /** Rate cards are never removed: deactivating keeps old quotes explainable. */
    public function delete(int $id): RedirectResponse
    {
        return $this->changeStatus(new RateCardModel(), $id, 'inactive', self::PATH, 'rate card');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->changeStatus(new RateCardModel(), $id, 'active', self::PATH, 'rate card');
    }

    // ------------------------------------------------------------------

    /**
     * Couriers selectable on a rate card: the organization's own plus platform couriers.
     *
     * @return list<array<string, mixed>>
     */
    private function courierOptions(): array
    {
        return (new CourierPartnerModel())
            ->forOrg()
            ->select('courier_partners.id, courier_partners.name, courier_partners.code, courier_partners.status')
            ->orderBy('courier_partners.name', 'ASC')
            ->findAll(300);
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $courier = $this->input('courier_partner_id');
        $origin  = $this->inputOrNull('origin_postal_prefix');
        $dest    = $this->inputOrNull('destination_postal_prefix');

        return [
            'courier_partner_id'        => ctype_digit($courier) && $courier !== '0' ? (int) $courier : null,
            'service_name'              => $this->input('service_name'),
            'origin_postal_prefix'      => $origin !== null ? strtoupper($origin) : null,
            'destination_postal_prefix' => $dest !== null ? strtoupper($dest) : null,
            'min_weight_kg'             => $this->input('min_weight_kg') === '' ? '0' : $this->input('min_weight_kg'),
            'max_weight_kg'             => $this->inputOrNull('max_weight_kg'),
            'base_charge'               => $this->input('base_charge') === '' ? '0' : $this->input('base_charge'),
            'per_kg_charge'             => $this->input('per_kg_charge') === '' ? '0' : $this->input('per_kg_charge'),
            'cod_charge'                => $this->input('cod_charge') === '' ? '0' : $this->input('cod_charge'),
            'fuel_surcharge_percent'    => $this->input('fuel_surcharge_percent') === '' ? '0' : $this->input('fuel_surcharge_percent'),
            'effective_from'            => $this->input('effective_from'),
            'effective_to'              => $this->inputOrNull('effective_to'),
            'status'                    => $this->inputStatus(),
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    private function rules(): array
    {
        $money = 'numeric|greater_than_equal_to[0]|less_than_equal_to[9999999999]';

        return [
            'courier_partner_id'        => ['label' => 'Courier', 'rules' => 'permit_empty|is_natural_no_zero'],
            'service_name'              => ['label' => 'Service name', 'rules' => 'required|min_length[2]|max_length[100]'],
            'origin_postal_prefix'      => ['label' => 'Origin postal prefix', 'rules' => 'permit_empty|max_length[10]|alpha_numeric'],
            'destination_postal_prefix' => ['label' => 'Destination postal prefix', 'rules' => 'permit_empty|max_length[10]|alpha_numeric'],
            'min_weight_kg'             => ['label' => 'Min weight (kg)', 'rules' => $money],
            'max_weight_kg'             => ['label' => 'Max weight (kg)', 'rules' => 'permit_empty|numeric|greater_than[0]|less_than_equal_to[9999999999]'],
            'base_charge'               => ['label' => 'Base charge', 'rules' => $money],
            'per_kg_charge'             => ['label' => 'Per-kg charge', 'rules' => $money],
            'cod_charge'                => ['label' => 'COD charge', 'rules' => $money],
            'fuel_surcharge_percent'    => ['label' => 'Fuel surcharge %', 'rules' => 'numeric|greater_than_equal_to[0]|less_than_equal_to[100]'],
            'effective_from'            => ['label' => 'Effective from', 'rules' => 'required|valid_date[Y-m-d]'],
            'effective_to'              => ['label' => 'Effective to', 'rules' => 'permit_empty|valid_date[Y-m-d]'],
            'status'                    => ['label' => 'Status', 'rules' => 'required|in_list[active,inactive]'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function extraErrors(array $data): array
    {
        $errors = [];

        if ($data['courier_partner_id'] !== null
            && ! is_array((new CourierPartnerModel())->findForOrg((int) $data['courier_partner_id']))) {
            $errors['courier_partner_id'] = 'The selected courier is not available.';
        }

        if ($data['max_weight_kg'] !== null && (float) $data['max_weight_kg'] < (float) $data['min_weight_kg']) {
            $errors['max_weight_kg'] = 'Max weight must be greater than or equal to min weight.';
        }

        if ($data['effective_to'] !== null && $data['effective_to'] < $data['effective_from']) {
            $errors['effective_to'] = 'Effective to date cannot be before the effective from date.';
        }

        return $errors;
    }
}
