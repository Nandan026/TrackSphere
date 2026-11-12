<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\PickupLocationModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Pickup locations (route path: pickup-locations).
 * Route permission: pickups.manage
 */
class PickupLocationsController extends MasterDataController
{
    protected string $module = 'pickup_locations';

    private const PATH = 'pickup-locations';

    public function index(): string
    {
        [$q, $status] = $this->listFilters();

        $model = new PickupLocationModel();
        $model->forOrg()
            ->withAddress()
            ->search($q, ['pickup_locations.name', 'pickup_locations.location_code', 'a.city', 'a.postal_code', 'a.contact_name'])
            ->withStatus($status)
            ->orderBy('pickup_locations.is_default', 'DESC')
            ->orderBy('pickup_locations.id', 'DESC');

        $locations = $model->paginate($this->perPage);

        return $this->renderPage('pickup_locations/index', [
            'title'     => 'Pickup Locations',
            'locations' => $locations,
            'pager'     => $model->pager,
            'q'         => $q,
            'status'    => $status,
        ]);
    }

    public function create(): string
    {
        return $this->renderPage('pickup_locations/form', [
            'title'          => 'Add Pickup Location',
            'mode'           => 'create',
            'location'       => [],
            'addr'           => [],
            'hours'          => ['weekdays' => '', 'sunday' => ''],
            'addressOptions' => $this->addressOptions(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $orgId = $this->orgId();
        $data  = $this->collect();
        $model = new PickupLocationModel();

        if (! $this->validateData($data + ['hours_weekdays' => $this->input('hours_weekdays'), 'hours_sunday' => $this->input('hours_sunday')], $this->rules(false))) {
            return $this->validationFailed();
        }

        if ($data['location_code'] === null) {
            $data['location_code'] = $this->generateCode($model, 'location_code', 'PL-');
        } elseif ($this->valueTaken($model, 'location_code', $data['location_code'])) {
            return $this->backWithErrors(['location_code' => 'This location code is already in use.']);
        }

        $db = $this->db();
        $db->transBegin();

        try {
            [$addressId, $errors] = $this->resolveAddress($orgId, 'pickup', null);
            if ($addressId === null) {
                $db->transRollback();

                return $this->backWithErrors($errors);
            }

            if ($data['is_default'] === 1) {
                $this->clearDefault($orgId, null);
            }

            if (! $model->insert($data + ['organization_id' => $orgId, 'address_id' => $addressId])) {
                throw new \RuntimeException('Pickup location could not be saved.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Pickup location create failed: ' . $e->getMessage());

            return $this->backWithErrors('The pickup location could not be saved. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Pickup location "' . $data['name'] . '" created.');
    }

    public function edit(int $id): string
    {
        $location = $this->ownedRow(new PickupLocationModel(), $id);
        $address  = $this->findAddress((int) $location['address_id']) ?? [];

        return $this->renderPage('pickup_locations/form', [
            'title'          => 'Edit Pickup Location',
            'mode'           => 'edit',
            'location'       => $location,
            'addr'           => $address,
            'hours'          => $this->decodeHours($location['operating_hours'] ?? null),
            'addressOptions' => $this->addressOptions(),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $orgId    = $this->orgId();
        $location = $this->ownedRow(new PickupLocationModel(), $id);
        $data     = $this->collect();
        $model    = new PickupLocationModel();

        if (! $this->validateData($data + ['hours_weekdays' => $this->input('hours_weekdays'), 'hours_sunday' => $this->input('hours_sunday')], $this->rules(true))) {
            return $this->validationFailed();
        }

        if ($this->valueTaken($model, 'location_code', (string) $data['location_code'], $id)) {
            return $this->backWithErrors(['location_code' => 'This location code is already in use.']);
        }

        $db = $this->db();
        $db->transBegin();

        try {
            [$addressId, $errors] = $this->resolveAddress($orgId, 'pickup', $location);
            if ($addressId === null) {
                $db->transRollback();

                return $this->backWithErrors($errors);
            }

            if ($data['is_default'] === 1) {
                $this->clearDefault($orgId, $id);
            }

            if (! $model->update($id, $data + ['address_id' => $addressId])) {
                throw new \RuntimeException('Pickup location could not be updated.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Pickup location update failed: ' . $e->getMessage());

            return $this->backWithErrors('The pickup location could not be updated. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Pickup location "' . $data['name'] . '" updated.');
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->changeStatus(new PickupLocationModel(), $id, 'inactive', self::PATH, 'pickup location');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->changeStatus(new PickupLocationModel(), $id, 'active', self::PATH, 'pickup location');
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $code = strtoupper($this->input('location_code'));

        $email = $this->inputOrNull('contact_email');

        return [
            'name'            => $this->input('name'),
            'location_code'   => $code === '' ? null : $code,
            'contact_email'   => $email !== null ? strtolower($email) : null,
            'operating_hours' => $this->encodeHours(),
            'is_default'      => $this->inputBool('is_default'),
            'status'          => $this->inputStatus(),
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    private function rules(bool $codeRequired): array
    {
        return [
            'name'           => ['label' => 'Name', 'rules' => 'required|min_length[2]|max_length[150]'],
            'location_code'  => ['label' => 'Location code', 'rules' => ($codeRequired ? 'required' : 'permit_empty') . '|max_length[50]|regex_match[/^[A-Z0-9_\-]+$/]'],
            'contact_email'  => ['label' => 'Contact email', 'rules' => 'permit_empty|valid_email|max_length[190]'],
            'hours_weekdays' => ['label' => 'Mon-Sat hours', 'rules' => 'permit_empty|max_length[30]'],
            'hours_sunday'   => ['label' => 'Sunday hours', 'rules' => 'permit_empty|max_length[30]'],
            'status'         => ['label' => 'Status', 'rules' => 'required|in_list[active,inactive]'],
        ];
    }

    private function encodeHours(): ?string
    {
        $weekdays = $this->input('hours_weekdays');
        $sunday   = $this->input('hours_sunday');

        if ($weekdays === '' && $sunday === '') {
            return null;
        }

        return (string) json_encode([
            'mon-sat' => $weekdays !== '' ? $weekdays : 'closed',
            'sun'     => $sunday !== '' ? $sunday : 'closed',
        ]);
    }

    /**
     * @param mixed $json
     *
     * @return array{weekdays: string, sunday: string}
     */
    private function decodeHours($json): array
    {
        $hours = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return [
            'weekdays' => is_array($hours) ? (string) ($hours['mon-sat'] ?? '') : '',
            'sunday'   => is_array($hours) ? (string) ($hours['sun'] ?? '') : '',
        ];
    }

    /** Only one default pickup location per organization. */
    private function clearDefault(int $orgId, ?int $exceptId): void
    {
        $builder = $this->db()->table('pickup_locations')->where('organization_id', $orgId)->where('is_default', 1);

        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        $builder->update(['is_default' => 0]);
    }
}
