<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\DeliveryAgentModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Delivery agents (delivery_agents table).
 * Route permission: delivery.assign
 */
class AgentsController extends MasterDataController
{
    protected string $module = 'agents';

    private const PATH          = 'agents';
    private const VEHICLE_TYPES = ['Bike', 'Scooter', 'Bicycle', 'Van', 'Truck', 'On foot'];

    public function index(): string
    {
        [$q, $status] = $this->listFilters();

        $model = new DeliveryAgentModel();
        $model->forOrg()
            ->select('delivery_agents.*, u.email AS user_email')
            ->join('users u', 'u.id = delivery_agents.user_id', 'left')
            ->search($q, ['delivery_agents.full_name', 'delivery_agents.agent_code', 'delivery_agents.phone', 'delivery_agents.vehicle_number'])
            ->withStatus($status)
            ->orderBy('delivery_agents.id', 'DESC');

        $agents = $model->paginate($this->perPage);

        return $this->renderPage('agents/index', [
            'title'  => 'Delivery Agents',
            'agents' => $agents,
            'pager'  => $model->pager,
            'q'      => $q,
            'status' => $status,
        ]);
    }

    public function create(): string
    {
        return $this->renderPage('agents/form', $this->formData('Add Agent', 'create', []));
    }

    public function store(): RedirectResponse
    {
        $orgId = $this->orgId();
        $data  = $this->collect();
        $model = new DeliveryAgentModel();

        if (! $this->validateData($data, $this->rules(false))) {
            return $this->validationFailed();
        }

        if ($error = $this->linkErrors($data, null)) {
            return $this->backWithErrors($error);
        }

        if ($data['agent_code'] === null) {
            $data['agent_code'] = $this->generateCode($model, 'agent_code', 'AGT-');
        } elseif ($this->valueTaken($model, 'agent_code', $data['agent_code'])) {
            return $this->backWithErrors(['agent_code' => 'This agent code is already in use.']);
        }

        try {
            if (! $model->insert($data + ['organization_id' => $orgId])) {
                throw new \RuntimeException('Agent could not be saved.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Agent create failed: ' . $e->getMessage());

            return $this->backWithErrors('The agent could not be saved. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Agent "' . $data['full_name'] . '" created.');
    }

    public function edit(int $id): string
    {
        $agent = $this->ownedRow(new DeliveryAgentModel(), $id);

        return $this->renderPage('agents/form', $this->formData('Edit Agent', 'edit', $agent));
    }

    public function update(int $id): RedirectResponse
    {
        $this->ownedRow(new DeliveryAgentModel(), $id);
        $data  = $this->collect();
        $model = new DeliveryAgentModel();

        if (! $this->validateData($data, $this->rules(true))) {
            return $this->validationFailed();
        }

        if ($error = $this->linkErrors($data, $id)) {
            return $this->backWithErrors($error);
        }

        if ($this->valueTaken($model, 'agent_code', (string) $data['agent_code'], $id)) {
            return $this->backWithErrors(['agent_code' => 'This agent code is already in use.']);
        }

        try {
            if (! $model->update($id, $data)) {
                throw new \RuntimeException('Agent could not be updated.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Agent update failed: ' . $e->getMessage());

            return $this->backWithErrors('The agent could not be updated. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Agent "' . $data['full_name'] . '" updated.');
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->changeStatus(new DeliveryAgentModel(), $id, 'inactive', self::PATH, 'agent');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->changeStatus(new DeliveryAgentModel(), $id, 'active', self::PATH, 'agent');
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $agent
     *
     * @return array<string, mixed>
     */
    private function formData(string $title, string $mode, array $agent): array
    {
        $users = (new UserModel())
            ->forOrg()
            ->select('users.id, users.first_name, users.last_name, users.email')
            ->where('users.status', 'active')
            ->orderBy('users.first_name', 'ASC')
            ->findAll(500);

        return [
            'title'        => $title,
            'mode'         => $mode,
            'agent'        => $agent,
            'users'        => $users,
            // Vehicle type is stored as free text, so the datalist offers the labels.
            'vehicleTypes' => array_values(lookup_options('vehicle_type', array_combine(self::VEHICLE_TYPES, self::VEHICLE_TYPES))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $code   = strtoupper($this->input('agent_code'));
        $userId = $this->input('user_id');
        $plate  = $this->inputOrNull('vehicle_number');

        return [
            'agent_code'     => $code === '' ? null : $code,
            'full_name'      => $this->input('full_name'),
            'phone'          => $this->input('phone'),
            'vehicle_type'   => $this->inputOrNull('vehicle_type'),
            'vehicle_number' => $plate !== null ? strtoupper($plate) : null,
            'user_id'        => ctype_digit($userId) && $userId !== '0' ? (int) $userId : null,
            'status'         => $this->inputStatus(),
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    private function rules(bool $codeRequired): array
    {
        return [
            'agent_code'     => ['label' => 'Agent code', 'rules' => ($codeRequired ? 'required' : 'permit_empty') . '|max_length[50]|regex_match[/^[A-Z0-9_\-]+$/]'],
            'full_name'      => ['label' => 'Full name', 'rules' => 'required|min_length[2]|max_length[150]'],
            'phone'          => ['label' => 'Phone', 'rules' => 'required|max_length[30]|regex_match[/^[0-9+\-\s()]{6,30}$/]'],
            'vehicle_type'   => ['label' => 'Vehicle type', 'rules' => 'permit_empty|max_length[50]'],
            'vehicle_number' => ['label' => 'Vehicle number', 'rules' => 'permit_empty|max_length[30]'],
            'status'         => ['label' => 'Status', 'rules' => 'required|in_list[active,inactive]'],
        ];
    }

    /**
     * The linked login must be a user of this organization and not already
     * attached to another agent.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function linkErrors(array $data, ?int $ignoreAgentId): array
    {
        if ($data['user_id'] === null) {
            return [];
        }

        $user = (new UserModel())->findForOrg((int) $data['user_id']);
        if (! is_array($user)) {
            return ['user_id' => 'The selected user account is not available.'];
        }

        if ($this->valueTaken(new DeliveryAgentModel(), 'user_id', (string) $data['user_id'], $ignoreAgentId)) {
            return ['user_id' => 'That user account is already linked to another agent.'];
        }

        return [];
    }
}
