<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Services\Auth\AuthService;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Demo data for the "Demo Logistics Pvt Ltd" organization (code DEMO).
 *
 * - Runs inside a single transaction (all or nothing).
 * - Re-runnable: any previous DEMO organization (and the demo users) are
 *   removed first, in foreign-key-safe order, so the seed can be repeated.
 * - Requires the `permissions` table to be populated (done by the schema).
 * - All timestamps are stored in UTC.
 *
 * Login password for every demo user: Admin@123
 */
class DemoSeeder extends Seeder
{
    public const ORG_CODE      = 'DEMO';
    public const DEMO_PASSWORD = 'Admin@123';

    /** @var list<string> */
    private const DEMO_EMAILS = [
        'admin@demo.tracksphere.local',
        'ops@demo.tracksphere.local',
        'dispatch@demo.tracksphere.local',
        'agent@demo.tracksphere.local',
        'merchant@demo.tracksphere.local',
    ];

    /**
     * Role definitions. 'permissions' holds fnmatch() patterns matched against
     * permission codes in the permissions table ('*' = everything). Anything
     * not matched by a non-admin role is therefore admin-only by default.
     *
     * @var array<string, array{name: string, description: string, permissions: list<string>}>
     */
    private const ROLES = [
        'org_admin' => [
            'name'        => 'Organization Admin',
            'description' => 'Full access to everything in the organization.',
            'permissions' => ['*'],
        ],
        'operations_manager' => [
            'name'        => 'Operations Manager',
            'description' => 'Runs shipments, exceptions, couriers, billing and reports.',
            'permissions' => [
                'dashboard.*', 'customers.*', 'shipments.*', 'pickups.*', 'delivery.*',
                'ndr.*', 'returns.*', 'reports.*', 'billing.*',
            ],
        ],
        'dispatcher' => [
            'name'        => 'Dispatcher',
            'description' => 'Schedules pickups and assigns deliveries.',
            'permissions' => [
                'dashboard.view', 'shipments.view', 'shipments.update', 'pickups.*',
                'delivery.*', 'ndr.*',
            ],
        ],
        'delivery_agent' => [
            'name'        => 'Delivery Agent',
            'description' => 'Sees assigned shipments and records delivery outcomes.',
            'permissions' => ['dashboard.view', 'shipments.view', 'delivery.update_status'],
        ],
        'merchant' => [
            'name'        => 'Merchant',
            'description' => 'Creates and tracks own shipments and requests returns.',
            'permissions' => ['dashboard.view', 'shipments.view', 'shipments.create', 'returns.*'],
        ],
        'support_executive' => [
            'name'        => 'Support Executive',
            'description' => 'Handles tracking issues, NDR and customer support.',
            'permissions' => ['dashboard.view', 'customers.*', 'shipments.view', 'ndr.*', 'returns.*'],
        ],
    ];

    private DateTimeImmutable $now;
    private int $orgId = 0;

    /** @var array<string, int> */
    private array $users = [];

    public function run(): void
    {
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $permissions = $this->db->table('permissions')->select('id, code')->get()->getResultArray();
        if ($permissions === []) {
            throw new RuntimeException('The permissions table is empty. Import database/tracksphere_schema.sql first.');
        }

        $this->db->transBegin();

        try {
            $this->clearExisting();
            $this->orgId = $this->createOrganization();
            $roles       = $this->createRoles($permissions);
            $this->createUsers($roles);

            $customers  = $this->createCustomers();
            $locations  = $this->createLocations($customers);
            $couriers   = $this->createCouriers();
            $agents     = $this->createAgents();
            $packages   = $this->createPackages();
            $this->createRateCards($couriers);
            $shipments  = $this->createShipments($customers, $locations, $couriers, $packages);
            $this->createOperations($shipments, $locations, $agents, $couriers);
            $this->createInvoice($customers, $shipments);

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        $this->report();
    }

    // ------------------------------------------------------------------
    // Cleanup
    // ------------------------------------------------------------------

    private function clearExisting(): void
    {
        $org = $this->db->table('organizations')->where('code', self::ORG_CODE)->get()->getRowArray();

        if ($org !== null) {
            $id = (int) $org['id'];

            // Children before parents so RESTRICT foreign keys never block.
            $statements = [
                'DELETE FROM return_requests WHERE organization_id = ?',
                'DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE organization_id = ?)',
                'DELETE FROM invoices WHERE organization_id = ?',
                'DELETE FROM cod_remittances WHERE organization_id = ?',
                'DELETE FROM ndr_cases WHERE organization_id = ?',
                'DELETE FROM notifications WHERE organization_id = ?',
                'DELETE FROM delivery_assignments WHERE organization_id = ?',
                'DELETE FROM pickup_requests WHERE organization_id = ?',
                'DELETE FROM shipments WHERE organization_id = ?', // cascades events, items, attempts, PODs, docs
                'DELETE FROM shipping_quotes WHERE organization_id = ?',
                'DELETE FROM rate_cards WHERE organization_id = ?',
                'DELETE FROM packages WHERE organization_id = ?',
                'DELETE FROM delivery_agents WHERE organization_id = ?',
                'DELETE FROM courier_partners WHERE organization_id = ?',
                'DELETE FROM warehouses WHERE organization_id = ?',
                'DELETE FROM pickup_locations WHERE organization_id = ?',
                'DELETE FROM addresses WHERE organization_id = ?',
                'DELETE FROM customers WHERE organization_id = ?',
                'DELETE FROM users WHERE organization_id = ?',
                'DELETE FROM roles WHERE organization_id = ?', // cascades role_permissions
                'DELETE FROM organizations WHERE id = ?',
            ];

            foreach ($statements as $sql) {
                $this->db->query($sql, [$id]);
            }
        }

        // Demo e-mails are globally unique; clear any left over outside the org.
        $this->db->table('users')->whereIn('email', self::DEMO_EMAILS)->delete();
    }

    // ------------------------------------------------------------------
    // Organization, roles, users
    // ------------------------------------------------------------------

    private function createOrganization(): int
    {
        return $this->insert('organizations', [
            'name'       => 'Demo Logistics Pvt Ltd',
            'code'       => self::ORG_CODE,
            'legal_name' => 'Demo Logistics Private Limited',
            'email'      => 'hello@demo.tracksphere.local',
            'phone'      => '+91-22-4000-1000',
            'gstin'      => '27AABCD1234E1Z5',
            'status'     => 'active',
            'timezone'   => 'Asia/Kolkata',
            'created_at' => $this->ts(),
        ]);
    }

    /**
     * @param list<array{id: int|string, code: string}> $permissions
     *
     * @return array<string, int> role code => role id
     */
    private function createRoles(array $permissions): array
    {
        $roleIds = [];

        foreach (self::ROLES as $code => $def) {
            $roleId = $this->insert('roles', [
                'organization_id' => $this->orgId,
                'name'            => $def['name'],
                'code'            => $code,
                'description'     => $def['description'],
                'is_system'       => 1,
                'created_at'      => $this->ts(),
            ]);
            $roleIds[$code] = $roleId;

            $rows = [];
            foreach ($permissions as $permission) {
                foreach ($def['permissions'] as $pattern) {
                    if ($pattern === '*' || fnmatch($pattern, (string) $permission['code'])) {
                        $rows[] = [
                            'role_id'       => $roleId,
                            'permission_id' => (int) $permission['id'],
                            'created_at'    => $this->ts(),
                        ];
                        break;
                    }
                }
            }

            if ($rows !== []) {
                $this->db->table('role_permissions')->insertBatch($rows);
            }
        }

        return $roleIds;
    }

    /**
     * @param array<string, int> $roles
     */
    private function createUsers(array $roles): void
    {
        $auth = new AuthService($this->db);

        $defs = [
            'admin'    => ['admin@demo.tracksphere.local', 'Aarav', 'Mehta', '+91-98200-00001', 'org_admin'],
            'ops'      => ['ops@demo.tracksphere.local', 'Neha', 'Kulkarni', '+91-98200-00002', 'operations_manager'],
            'dispatch' => ['dispatch@demo.tracksphere.local', 'Rohan', 'Desai', '+91-98200-00003', 'dispatcher'],
            'agent'    => ['agent@demo.tracksphere.local', 'Ravi', 'Kumar', '+91-98200-00004', 'delivery_agent'],
            'merchant' => ['merchant@demo.tracksphere.local', 'Priya', 'Nair', '+91-98200-00005', 'merchant'],
        ];

        foreach ($defs as $key => [$email, $first, $last, $phone, $role]) {
            $this->users[$key] = $this->insert('users', [
                'organization_id'   => $this->orgId,
                'role_id'           => $roles[$role],
                'first_name'        => $first,
                'last_name'         => $last,
                'email'             => $email,
                'phone'             => $phone,
                'password_hash'     => $auth->hashPassword(self::DEMO_PASSWORD),
                'status'            => 'active',
                'email_verified_at' => $this->ts(),
                'created_at'        => $this->ts(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Master data
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{id: int, name: string, address_ids: list<int>}>
     */
    private function createCustomers(): array
    {
        $customers = [];

        $defs = [
            'acme' => [
                'customer_code' => 'CUST-0001', 'customer_type' => 'business', 'name' => 'Acme Retail Pvt Ltd',
                'contact_person' => 'Kiran Shah', 'email' => 'orders@acme-retail.example', 'phone' => '+91-98765-43210',
                'gstin' => '27AAACA1234F1Z9',
                'addresses' => [
                    ['billing', 'Kiran Shah', '+91-98765-43210', '14 Linking Road, Bandra West', 'Opposite Gold\'s Gym', 'Mumbai', 'Mumbai Suburban', 'Maharashtra', '400050', 1],
                    ['pickup', 'Store Manager', '+91-98765-43211', 'Shop 7, Phoenix Mills, Lower Parel', null, 'Mumbai', 'Mumbai City', 'Maharashtra', '400013', 0],
                ],
            ],
            'priya' => [
                'customer_code' => 'CUST-0002', 'customer_type' => 'individual', 'name' => 'Sneha Iyer',
                'contact_person' => 'Sneha Iyer', 'email' => 'sneha.iyer@example.com', 'phone' => '+91-91234-56789',
                'gstin' => null,
                'addresses' => [
                    ['home', 'Sneha Iyer', '+91-91234-56789', '22 MG Road, Camp', 'Near Starbucks', 'Pune', 'Pune', 'Maharashtra', '411001', 1],
                    ['other', 'Sneha Iyer', '+91-91234-56789', 'B-304 Kalyani Nagar Heights', null, 'Pune', 'Pune', 'Maharashtra', '411014', 0],
                ],
            ],
        ];

        foreach ($defs as $key => $def) {
            $customerId = $this->insert('customers', [
                'organization_id' => $this->orgId,
                'customer_code'   => $def['customer_code'],
                'customer_type'   => $def['customer_type'],
                'name'            => $def['name'],
                'contact_person'  => $def['contact_person'],
                'email'           => $def['email'],
                'phone'           => $def['phone'],
                'gstin'           => $def['gstin'],
                'status'          => 'active',
                'created_at'      => $this->ts(),
            ]);

            $addressIds = [];
            foreach ($def['addresses'] as [$type, $contact, $phone, $line1, $line2, $city, $district, $state, $postal, $default]) {
                $addressIds[] = $this->createAddress($customerId, $type, $contact, $phone, $line1, $line2, $city, $district, $state, $postal, (bool) $default);
            }

            $customers[$key] = ['id' => $customerId, 'name' => $def['name'], 'address_ids' => $addressIds];
        }

        return $customers;
    }

    /**
     * Pickup locations and warehouse (each owns an address row).
     *
     * @param array<string, array{id: int, name: string, address_ids: list<int>}> $customers
     *
     * @return array{pickups: list<array{id: int, address_id: int, city: string, postal_code: string}>, warehouse_id: int, receivers: list<int>}
     */
    private function createLocations(array $customers): array
    {
        $pickups = [];

        $pickupDefs = [
            ['HQ-MUM', 'Mumbai Head Office Dock', 'Operations Desk', '+91-22-4000-1001', '101 Andheri Kurla Road, Sakinaka', 'Mumbai', 'Mumbai Suburban', 'Maharashtra', '400072', true],
            ['PUN-01', 'Pune Pickup Hub', 'Hub Supervisor', '+91-20-4000-2002', 'Plot 18, Hinjewadi Phase 1', 'Pune', 'Pune', 'Maharashtra', '411057', false],
        ];

        foreach ($pickupDefs as [$code, $name, $contact, $phone, $line1, $city, $district, $state, $postal, $default]) {
            $addressId = $this->createAddress(null, 'pickup', $contact, $phone, $line1, null, $city, $district, $state, $postal, $default);

            $pickupId = $this->insert('pickup_locations', [
                'organization_id' => $this->orgId,
                'address_id'      => $addressId,
                'name'            => $name,
                'location_code'   => $code,
                'contact_email'   => strtolower($code) . '@demo.tracksphere.local',
                'operating_hours' => json_encode(['mon-sat' => '09:00-19:00', 'sun' => 'closed']),
                'is_default'      => $default ? 1 : 0,
                'status'          => 'active',
                'created_at'      => $this->ts(),
            ]);

            $pickups[] = ['id' => $pickupId, 'address_id' => $addressId, 'city' => $city, 'postal_code' => $postal];
        }

        $warehouseAddress = $this->createAddress(
            null, 'warehouse', 'Warehouse Manager', '+91-2522-400-300',
            'Gala 5, Om Logistics Park, Bhiwandi', null, 'Bhiwandi', 'Thane', 'Maharashtra', '421302', false
        );
        $warehouseId = $this->insert('warehouses', [
            'organization_id' => $this->orgId,
            'address_id'      => $warehouseAddress,
            'name'            => 'Bhiwandi Central Warehouse',
            'warehouse_code'  => 'WH-BHI-01',
            'status'          => 'active',
            'created_at'      => $this->ts(),
        ]);

        // Receiver addresses for the demo shipments (one per shipment, spread across India).
        $receiverDefs = [
            ['Rahul Verma', '+91-98100-11111', 'D-45 Connaught Place', 'New Delhi', 'New Delhi', 'Delhi', '110001'],
            ['Anita Rao', '+91-98450-22222', '88 Indiranagar 100ft Road', 'Bengaluru', 'Bengaluru Urban', 'Karnataka', '560038'],
            ['Imran Qureshi', '+91-98480-33333', '12-3-45 Banjara Hills Road No. 3', 'Hyderabad', 'Hyderabad', 'Telangana', '500034'],
            ['Lakshmi Narayanan', '+91-98400-44444', '7 Anna Salai, T Nagar', 'Chennai', 'Chennai', 'Tamil Nadu', '600017'],
            ['Debashis Ghosh', '+91-98300-55555', '21 Park Street', 'Kolkata', 'Kolkata', 'West Bengal', '700016'],
            ['Hetal Patel', '+91-98250-66666', '5 CG Road, Navrangpura', 'Ahmedabad', 'Ahmedabad', 'Gujarat', '380009'],
            ['Meera Joshi', '+91-98290-77777', '33 MI Road', 'Jaipur', 'Jaipur', 'Rajasthan', '302001'],
            ['Suresh Yadav', '+91-98390-88888', '9 Hazratganj', 'Lucknow', 'Lucknow', 'Uttar Pradesh', '226001'],
        ];

        $receivers = [];
        foreach ($receiverDefs as [$contact, $phone, $line1, $city, $district, $state, $postal]) {
            $receivers[] = $this->createAddress(null, 'delivery', $contact, $phone, $line1, null, $city, $district, $state, $postal, false);
        }

        return ['pickups' => $pickups, 'warehouse_id' => $warehouseId, 'receivers' => $receivers];
    }

    /**
     * @return array<string, array{id: int, name: string, supports_cod: bool, base: float, per_kg: float, cod: float, fuel: float}>
     */
    private function createCouriers(): array
    {
        $couriers = [];

        $defs = [
            'express' => [
                'name' => 'Demo Express', 'code' => 'DEMOEXP', 'cod' => 1, 'reverse' => 1,
                'base' => 45.00, 'per_kg' => 28.00, 'cod_charge' => 30.00, 'fuel' => 6.00,
            ],
            'swift' => [
                'name' => 'Swift Parcel (Simulated)', 'code' => 'SWIFT', 'cod' => 0, 'reverse' => 0,
                'base' => 38.00, 'per_kg' => 24.00, 'cod_charge' => 0.00, 'fuel' => 4.50,
            ],
        ];

        foreach ($defs as $key => $d) {
            $id = $this->insert('courier_partners', [
                'organization_id'  => $this->orgId,
                'name'             => $d['name'],
                'code'             => $d['code'],
                'integration_type' => 'simulated',
                'api_base_url'     => null,
                'supports_cod'     => $d['cod'],
                'supports_reverse' => $d['reverse'],
                'status'           => 'active',
                'configuration'    => json_encode(['mode' => 'simulated', 'sla_days' => $key === 'express' ? 3 : 5]),
                'created_at'       => $this->ts(),
            ]);

            $couriers[$key] = [
                'id' => $id, 'name' => $d['name'], 'supports_cod' => (bool) $d['cod'],
                'base' => $d['base'], 'per_kg' => $d['per_kg'], 'cod' => $d['cod_charge'], 'fuel' => $d['fuel'],
            ];
        }

        return $couriers;
    }

    /**
     * @return array<string, int>
     */
    private function createAgents(): array
    {
        $agents = [];

        $defs = [
            'ravi'   => ['AGT-001', 'Ravi Kumar', '+91-98200-00004', 'bike', 'MH-02-AB-1234', $this->users['agent']],
            'sunil'  => ['AGT-002', 'Sunil Yadav', '+91-98200-00006', 'van', 'MH-04-CD-5678', null],
        ];

        foreach ($defs as $key => [$code, $name, $phone, $vehicle, $number, $userId]) {
            $agents[$key] = $this->insert('delivery_agents', [
                'organization_id' => $this->orgId,
                'user_id'         => $userId,
                'agent_code'      => $code,
                'full_name'       => $name,
                'phone'           => $phone,
                'vehicle_type'    => $vehicle,
                'vehicle_number'  => $number,
                'status'          => 'active',
                'created_at'      => $this->ts(),
            ]);
        }

        return $agents;
    }

    /**
     * @return array<string, array{id: int, length: float, width: float, height: float, weight: float}>
     */
    private function createPackages(): array
    {
        $packages = [];

        $defs = [
            'small'  => ['Small Box', 'BOX-S', 20.0, 15.0, 10.0, 0.500],
            'medium' => ['Medium Box', 'BOX-M', 35.0, 25.0, 20.0, 2.000],
        ];

        foreach ($defs as $key => [$name, $code, $l, $w, $h, $weight]) {
            $id = $this->insert('packages', [
                'organization_id'   => $this->orgId,
                'package_name'      => $name,
                'package_code'      => $code,
                'length_cm'         => $l,
                'width_cm'          => $w,
                'height_cm'         => $h,
                'default_weight_kg' => $weight,
                'status'            => 'active',
                'created_at'        => $this->ts(),
            ]);

            $packages[$key] = ['id' => $id, 'length' => $l, 'width' => $w, 'height' => $h, 'weight' => $weight];
        }

        return $packages;
    }

    /**
     * @param array<string, array{id: int, name: string, supports_cod: bool, base: float, per_kg: float, cod: float, fuel: float}> $couriers
     */
    private function createRateCards(array $couriers): void
    {
        $from = $this->now->modify('-60 days')->format('Y-m-d');

        $defs = [
            ['express', 'Express Standard', 0.0, 5.0],
            ['swift', 'Swift Economy', 0.0, 10.0],
        ];

        foreach ($defs as [$key, $service, $min, $max]) {
            $this->insert('rate_cards', [
                'organization_id'          => $this->orgId,
                'courier_partner_id'       => $couriers[$key]['id'],
                'service_name'             => $service,
                'origin_postal_prefix'     => null,
                'destination_postal_prefix' => null,
                'min_weight_kg'            => $min,
                'max_weight_kg'            => $max,
                'base_charge'              => $couriers[$key]['base'],
                'per_kg_charge'            => $couriers[$key]['per_kg'],
                'cod_charge'               => $couriers[$key]['cod'],
                'fuel_surcharge_percent'   => $couriers[$key]['fuel'],
                'effective_from'           => $from,
                'effective_to'             => null,
                'status'                   => 'active',
                'created_at'               => $this->ts(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Shipments
    // ------------------------------------------------------------------

    /**
     * Forward flow used to build event timelines.
     *
     * @var array<string, list<string>>
     */
    private const FLOWS = [
        'draft'            => ['draft'],
        'ready_to_ship'    => ['draft', 'ready_to_ship'],
        'pickup_scheduled' => ['draft', 'ready_to_ship', 'pickup_scheduled'],
        'in_transit'       => ['draft', 'ready_to_ship', 'pickup_scheduled', 'picked_up', 'in_transit'],
        'out_for_delivery' => ['draft', 'ready_to_ship', 'pickup_scheduled', 'picked_up', 'in_transit', 'reached_destination_hub', 'out_for_delivery'],
        'delivered'        => ['draft', 'ready_to_ship', 'pickup_scheduled', 'picked_up', 'in_transit', 'reached_destination_hub', 'out_for_delivery', 'delivered'],
        'delivery_failed'  => ['draft', 'ready_to_ship', 'pickup_scheduled', 'picked_up', 'in_transit', 'reached_destination_hub', 'out_for_delivery', 'delivery_failed'],
        'cancelled'        => ['draft', 'cancelled'],
    ];

    /**
     * @param array<string, array{id: int, name: string, address_ids: list<int>}> $customers
     * @param array{pickups: list<array{id: int, address_id: int, city: string, postal_code: string}>, warehouse_id: int, receivers: list<int>} $locations
     * @param array<string, array{id: int, name: string, supports_cod: bool, base: float, per_kg: float, cod: float, fuel: float}> $couriers
     * @param array<string, array{id: int, length: float, width: float, height: float, weight: float}> $packages
     *
     * @return array<string, array{id: int, status: string, tracking: string, customer: string, courier: string, receiver_address_id: int, charge: float, created: DateTimeImmutable, last_event_at: DateTimeImmutable, pickup: int}>
     */
    private function createShipments(array $customers, array $locations, array $couriers, array $packages): array
    {
        // key => [status, customer, pickup idx, courier, package, actual kg, payment, cod amount, days ago, declared, items]
        $defs = [
            's1' => ['draft', 'acme', 0, null, 'small', 0.4, 'prepaid', 0, 0.2, 1499.00, [['Cotton T-Shirt', 'TSH-001', 2, 499.50, '6109'], ['Canvas Cap', 'CAP-010', 1, 500.00, '6505']]],
            's2' => ['ready_to_ship', 'acme', 0, 'express', 'medium', 1.8, 'cod', 3299.00, 1, 3299.00, [['Bluetooth Speaker', 'SPK-220', 1, 2299.00, '8518'], ['Speaker Case', 'SPK-CASE', 1, 1000.00, '4202']]],
            's3' => ['pickup_scheduled', 'priya', 1, 'swift', 'small', 0.7, 'prepaid', 0, 2, 2450.00, [['Handmade Ceramic Mug Set', 'MUG-SET4', 1, 2450.00, '6912']]],
            's4' => ['in_transit', 'acme', 0, 'express', 'medium', 2.4, 'prepaid', 0, 3, 5600.00, [['Running Shoes', 'SHOE-RUN-9', 2, 2800.00, '6404']]],
            's5' => ['out_for_delivery', 'acme', 0, 'express', 'small', 0.9, 'cod', 1899.00, 4, 1899.00, [['Denim Jacket', 'JKT-DNM-L', 1, 1899.00, '6201']]],
            's6' => ['delivered', 'acme', 0, 'express', 'medium', 3.1, 'cod', 4750.00, 6, 4750.00, [['Mixer Grinder', 'MXG-750', 1, 3950.00, '8509'], ['Extra Jar', 'MXG-JAR', 1, 800.00, '8509']]],
            's7' => ['delivery_failed', 'priya', 1, 'swift', 'small', 1.1, 'prepaid', 0, 5, 3200.00, [['Yoga Mat Premium', 'YOGA-MAT', 2, 1600.00, '9506']]],
            's8' => ['cancelled', 'priya', 1, null, 'small', 0.5, 'prepaid', 0, 3, 999.00, [['Scented Candle Pack', 'CDL-PK6', 1, 999.00, '3406']]],
        ];

        $creatorByStatus = ['draft' => 'merchant', 'ready_to_ship' => 'ops', 'pickup_scheduled' => 'dispatch'];
        $shipments       = [];
        $receiverIndex   = 0;
        $serial          = 1;

        foreach ($defs as $key => [$status, $customerKey, $pickupIdx, $courierKey, $packageKey, $actual, $payment, $cod, $daysAgo, $declared, $items]) {
            $created = $this->now->modify('-' . (int) round($daysAgo * 24) . ' hours');
            $pickup  = $locations['pickups'][$pickupIdx];
            $package = $packages[$packageKey];
            $courier = $courierKey !== null ? $couriers[$courierKey] : null;

            $volumetric  = round(($package['length'] * $package['width'] * $package['height']) / 5000, 3);
            $chargeable  = max($actual, $volumetric);
            $freight     = 0.0;
            if ($courier !== null) {
                $freight = ($courier['base'] + $courier['per_kg'] * $chargeable) * (1 + $courier['fuel'] / 100);
                if ($payment === 'cod') {
                    $freight += $courier['cod'];
                }
            }
            $freight = round($freight, 2);

            $quoteId = null;
            if ($key === 's2' && $courier !== null) {
                $quoteId = $this->insert('shipping_quotes', [
                    'organization_id'         => $this->orgId,
                    'customer_id'             => $customers[$customerKey]['id'],
                    'courier_partner_id'      => $courier['id'],
                    'origin_postal_code'      => $pickup['postal_code'],
                    'destination_postal_code' => '500034',
                    'actual_weight_kg'        => $actual,
                    'volumetric_weight_kg'    => $volumetric,
                    'chargeable_weight_kg'    => $chargeable,
                    'freight_amount'          => $freight,
                    'cod_amount'              => $cod,
                    'tax_amount'              => round($freight * 0.18, 2),
                    'total_amount'            => round($freight * 1.18, 2),
                    'currency'                => 'INR',
                    'expires_at'              => $this->ts($created->modify('+7 days')),
                    'created_at'              => $this->ts($created),
                ]);
            }

            $events = $this->buildEvents($status, $created, $locations, $pickup, $receiverIndex);
            $last   = end($events);

            $tracking = 'TS' . $this->now->format('Y') . 'DEMO' . sprintf('%06d', $serial++);

            $shippedAt = null;
            $deliveredAt = null;
            $cancelledAt = null;
            foreach ($events as $event) {
                if ($event['status_code'] === 'picked_up') {
                    $shippedAt = $event['occurred_at'];
                } elseif ($event['status_code'] === 'delivered') {
                    $deliveredAt = $event['occurred_at'];
                } elseif ($event['status_code'] === 'cancelled') {
                    $cancelledAt = $event['occurred_at'];
                }
            }

            $creator = $this->users[$creatorByStatus[$status] ?? 'ops'];

            $shipmentId = $this->insert('shipments', [
                'organization_id'         => $this->orgId,
                'customer_id'             => $customers[$customerKey]['id'],
                'pickup_location_id'      => $pickup['id'],
                'sender_address_id'       => $pickup['address_id'],
                'receiver_address_id'     => $locations['receivers'][$receiverIndex],
                'courier_partner_id'      => $courier['id'] ?? null,
                'quote_id'                => $quoteId,
                'tracking_number'         => $tracking,
                'courier_tracking_number' => in_array($status, ['draft', 'ready_to_ship', 'cancelled'], true)
                    ? null
                    : strtoupper(($courierKey ?? 'DEMO')) . sprintf('%08d', 7000000 + $serial * 13),
                'shipment_type'           => 'forward',
                'payment_mode'            => $payment,
                'current_status'          => $status,
                'total_packages'          => 1,
                'actual_weight_kg'        => $actual,
                'volumetric_weight_kg'    => $volumetric,
                'chargeable_weight_kg'    => $chargeable,
                'declared_value'          => $declared,
                'shipping_charge'         => $freight,
                'cod_amount'              => $cod,
                'currency'                => 'INR',
                'special_instructions'    => $key === 's2' ? 'Fragile - handle with care.' : null,
                'expected_delivery_at'    => $this->ts($created->modify('+5 days')),
                'shipped_at'              => $shippedAt,
                'delivered_at'            => $deliveredAt,
                'cancelled_at'            => $cancelledAt,
                'created_by'              => $creator,
                'created_at'              => $this->ts($created),
            ]);

            foreach ($items as [$itemName, $sku, $qty, $unit, $hsn]) {
                $this->insert('shipment_items', [
                    'shipment_id' => $shipmentId,
                    'item_name'   => $itemName,
                    'sku'         => $sku,
                    'quantity'    => $qty,
                    'unit_value'  => $unit,
                    'total_value' => round($qty * $unit, 2),
                    'hsn_code'    => $hsn,
                    'created_at'  => $this->ts($created),
                ]);
            }

            foreach ($events as $event) {
                $this->insert('shipment_events', $event + ['shipment_id' => $shipmentId, 'created_at' => $event['occurred_at']]);
            }

            $shipments[$key] = [
                'id'                  => $shipmentId,
                'status'              => $status,
                'tracking'            => $tracking,
                'customer'            => $customerKey,
                'courier'             => $courierKey ?? '',
                'receiver_address_id' => $locations['receivers'][$receiverIndex],
                'charge'              => $freight,
                'created'             => $created,
                'last_event_at'       => new DateTimeImmutable($last['occurred_at'], new DateTimeZone('UTC')),
                'pickup'              => $pickupIdx,
            ];

            $receiverIndex++;
        }

        return $shipments;
    }

    /**
     * Builds the append-only event timeline for a shipment up to its status.
     *
     * @param array{pickups: list<array{id: int, address_id: int, city: string, postal_code: string}>, warehouse_id: int, receivers: list<int>} $locations
     * @param array{id: int, address_id: int, city: string, postal_code: string} $pickup
     *
     * @return list<array<string, mixed>>
     */
    private function buildEvents(string $status, DateTimeImmutable $created, array $locations, array $pickup, int $receiverIndex): array
    {
        $destinationCities = ['New Delhi', 'Bengaluru', 'Hyderabad', 'Chennai', 'Kolkata', 'Ahmedabad', 'Jaipur', 'Lucknow'];
        $destination       = $destinationCities[$receiverIndex] ?? 'Destination';

        $catalog = [
            'draft'                   => ['Shipment created', 'Shipment draft created in TrackSphere.', $pickup['city'], 'system', 'merchant'],
            'ready_to_ship'           => ['Ready to ship', 'Shipment packed and ready for pickup.', $pickup['city'], 'system', 'ops'],
            'pickup_scheduled'        => ['Pickup scheduled', 'Courier pickup has been scheduled.', $pickup['city'], 'system', 'dispatch'],
            'picked_up'               => ['Picked up', 'Shipment collected from the sender.', $pickup['city'], 'courier', null],
            'in_transit'              => ['In transit', 'Shipment is on its way to the destination city.', 'Bhiwandi Sorting Hub', 'courier', null],
            'reached_destination_hub' => ['Reached destination hub', 'Shipment arrived at the destination hub.', $destination . ' Hub', 'courier', null],
            'out_for_delivery'        => ['Out for delivery', 'Delivery agent is on the way to the receiver.', $destination, 'agent', 'agent'],
            'delivered'               => ['Delivered', 'Shipment delivered and handed over to the receiver.', $destination, 'agent', 'agent'],
            'delivery_failed'         => ['Delivery attempt failed', 'Receiver was not available at the delivery address.', $destination, 'agent', 'agent'],
            'cancelled'               => ['Shipment cancelled', 'Shipment cancelled before pickup.', $pickup['city'], 'system', 'ops'],
        ];

        $flow   = self::FLOWS[$status];
        $count  = count($flow);
        $events = [];

        // Spread events between creation and (now - 30 min) so nothing is in the future.
        $span = max(0, $this->now->getTimestamp() - 1800 - $created->getTimestamp());
        $step = $count > 1 ? (int) floor($span / ($count - 1)) : 0;
        $step = min($step, 10 * 3600); // never wider than 10h between scans

        foreach ($flow as $i => $code) {
            [$title, $description, $location, $source, $userKey] = $catalog[$code];

            $events[] = [
                'status_code'         => $code,
                'title'               => $title,
                'description'         => $description,
                'event_location'      => $location,
                'event_source'        => $source,
                'source_reference'    => null,
                'visible_to_customer' => in_array($code, ['draft', 'cancelled'], true) ? 0 : 1,
                'occurred_at'         => $this->ts($created->modify('+' . ($i * $step) . ' seconds')),
                'created_by'          => $userKey !== null ? $this->users[$userKey] : null,
            ];
        }

        return $events;
    }

    // ------------------------------------------------------------------
    // Pickups, delivery, NDR, returns
    // ------------------------------------------------------------------

    /**
     * @param array<string, array<string, mixed>> $shipments
     * @param array{pickups: list<array{id: int, address_id: int, city: string, postal_code: string}>, warehouse_id: int, receivers: list<int>} $locations
     * @param array<string, int> $agents
     * @param array<string, array<string, mixed>> $couriers
     */
    private function createOperations(array $shipments, array $locations, array $agents, array $couriers): void
    {
        // --- Pickup requests -------------------------------------------------
        $this->insert('pickup_requests', [
            'organization_id'    => $this->orgId,
            'shipment_id'        => $shipments['s3']['id'],
            'pickup_location_id' => $locations['pickups'][$shipments['s3']['pickup']]['id'],
            'requested_date'     => $this->now->modify('+1 day')->format('Y-m-d'),
            'time_slot'          => '10:00-13:00',
            'status'             => 'scheduled',
            'courier_reference'  => 'PKP-SWIFT-' . sprintf('%05d', 4821),
            'remarks'            => 'Call the hub supervisor on arrival.',
            'requested_by'       => $this->users['dispatch'],
            'created_at'         => $this->ts($shipments['s3']['last_event_at']),
        ]);

        $completedAt = $this->ts($shipments['s4']['created']->modify('+10 hours'));
        $this->insert('pickup_requests', [
            'organization_id'    => $this->orgId,
            'shipment_id'        => $shipments['s4']['id'],
            'pickup_location_id' => $locations['pickups'][$shipments['s4']['pickup']]['id'],
            'requested_date'     => $shipments['s4']['created']->modify('+1 day')->format('Y-m-d'),
            'time_slot'          => '14:00-17:00',
            'status'             => 'completed',
            'courier_reference'  => 'PKP-DEMOEXP-' . sprintf('%05d', 1177),
            'remarks'            => null,
            'requested_by'       => $this->users['dispatch'],
            'completed_at'       => $completedAt,
            'created_at'         => $this->ts($shipments['s4']['created']->modify('+2 hours')),
        ]);

        // --- Delivery assignments + attempts ---------------------------------
        // s5: out for delivery, currently with Ravi (linked to the agent user).
        $this->insert('delivery_assignments', [
            'organization_id'   => $this->orgId,
            'shipment_id'       => $shipments['s5']['id'],
            'delivery_agent_id' => $agents['ravi'],
            'assigned_by'       => $this->users['dispatch'],
            'assigned_at'       => $this->ts($shipments['s5']['last_event_at']),
            'accepted_at'       => $this->ts($shipments['s5']['last_event_at']->modify('+15 minutes')),
            'status'            => 'accepted',
            'notes'             => 'COD shipment - collect INR 1,899 in cash or UPI.',
        ]);

        // s6: delivered by Ravi, with attempt and proof of delivery.
        $deliveredAt = $shipments['s6']['last_event_at'];
        $assign6     = $this->insert('delivery_assignments', [
            'organization_id'   => $this->orgId,
            'shipment_id'       => $shipments['s6']['id'],
            'delivery_agent_id' => $agents['ravi'],
            'assigned_by'       => $this->users['dispatch'],
            'assigned_at'       => $this->ts($deliveredAt->modify('-3 hours')),
            'accepted_at'       => $this->ts($deliveredAt->modify('-2 hours 45 minutes')),
            'completed_at'      => $this->ts($deliveredAt),
            'status'            => 'completed',
            'notes'             => null,
        ]);
        $attempt6 = $this->insert('delivery_attempts', [
            'shipment_id'            => $shipments['s6']['id'],
            'delivery_assignment_id' => $assign6,
            'attempt_number'         => 1,
            'outcome'                => 'delivered',
            'failure_reason'         => null,
            'remarks'                => 'Handed over to the receiver. COD collected.',
            'attempted_at'           => $this->ts($deliveredAt),
            'latitude'               => 28.6329000,
            'longitude'              => 77.2195000,
            'created_at'             => $this->ts($deliveredAt),
        ]);
        $this->insert('proof_of_deliveries', [
            'shipment_id'            => $shipments['s6']['id'],
            'delivery_attempt_id'    => $attempt6,
            'received_by'            => 'Rahul Verma',
            'receiver_relation'      => 'self',
            'verification_method'    => 'otp',
            'verification_reference' => '482915',
            'notes'                  => 'Simulated OTP verified.',
            'delivered_at'           => $this->ts($deliveredAt),
            'created_at'             => $this->ts($deliveredAt),
        ]);

        // s7: failed attempt by Sunil -> open NDR case.
        $failedAt = $shipments['s7']['last_event_at'];
        $assign7  = $this->insert('delivery_assignments', [
            'organization_id'   => $this->orgId,
            'shipment_id'       => $shipments['s7']['id'],
            'delivery_agent_id' => $agents['sunil'],
            'assigned_by'       => $this->users['dispatch'],
            'assigned_at'       => $this->ts($failedAt->modify('-3 hours')),
            'accepted_at'       => $this->ts($failedAt->modify('-2 hours 50 minutes')),
            'completed_at'      => $this->ts($failedAt),
            'status'            => 'failed',
            'notes'             => null,
        ]);
        $attempt7 = $this->insert('delivery_attempts', [
            'shipment_id'            => $shipments['s7']['id'],
            'delivery_assignment_id' => $assign7,
            'attempt_number'         => 1,
            'outcome'                => 'failed',
            'failure_reason'         => 'customer_unavailable',
            'remarks'                => 'Door locked, phone not reachable after 3 calls.',
            'attempted_at'           => $this->ts($failedAt),
            'latitude'               => 23.0300000,
            'longitude'              => 72.5600000,
            'created_at'             => $this->ts($failedAt),
        ]);

        $this->insert('ndr_cases', [
            'organization_id'     => $this->orgId,
            'shipment_id'         => $shipments['s7']['id'],
            'delivery_attempt_id' => $attempt7,
            'case_number'         => 'NDR-DEMO-0001',
            'reason_code'         => 'customer_unavailable',
            'customer_response'   => null,
            'resolution'          => null,
            'next_attempt_at'     => null,
            'status'              => 'open',
            'remarks'             => 'Awaiting customer callback to confirm re-attempt slot.',
            'created_at'          => $this->ts($failedAt->modify('+10 minutes')),
        ]);

        // --- Return request on the delivered shipment -----------------------
        $this->insert('return_requests', [
            'organization_id' => $this->orgId,
            'shipment_id'     => $shipments['s6']['id'],
            'return_number'   => 'RET-DEMO-0001',
            'reason_code'     => 'damaged_item',
            'reason_details'  => 'Mixer jar arrived with a hairline crack.',
            'status'          => 'requested',
            'requested_by'    => $this->users['merchant'],
            'requested_at'    => $this->ts($deliveredAt->modify('+1 day')),
        ]);
    }

    /**
     * @param array<string, array{id: int, name: string, address_ids: list<int>}> $customers
     * @param array<string, array<string, mixed>> $shipments
     */
    private function createInvoice(array $customers, array $shipments): void
    {
        $lines = [];
        foreach (['s4', 's5', 's6'] as $key) {
            $lines[] = [
                'shipment_id' => $shipments[$key]['id'],
                'description' => 'Shipping charge - ' . $shipments[$key]['tracking'],
                'quantity'    => 1,
                'unit_price'  => $shipments[$key]['charge'],
                'tax_percent' => 18.00,
            ];
        }

        $subtotal = 0.0;
        $tax      = 0.0;
        foreach ($lines as $i => $line) {
            $net                    = round($line['quantity'] * $line['unit_price'], 2);
            $lines[$i]['line_total'] = $net;
            $subtotal              += $net;
            $tax                   += round($net * $line['tax_percent'] / 100, 2);
        }

        $subtotal = round($subtotal, 2);
        $tax      = round($tax, 2);
        $discount = 0.0;
        $total    = round($subtotal + $tax - $discount, 2);

        $invoiceId = $this->insert('invoices', [
            'organization_id' => $this->orgId,
            'customer_id'     => $customers['acme']['id'],
            'invoice_number'  => 'INV-DEMO-0001',
            'invoice_date'    => $this->now->format('Y-m-d'),
            'due_date'        => $this->now->modify('+15 days')->format('Y-m-d'),
            'subtotal'        => $subtotal,
            'tax_amount'      => $tax,
            'discount_amount' => $discount,
            'total_amount'    => $total,
            'paid_amount'     => 0,
            'balance_amount'  => $total,
            'status'          => 'draft',
            'created_by'      => $this->users['admin'],
            'created_at'      => $this->ts(),
        ]);

        foreach ($lines as $line) {
            $this->insert('invoice_items', $line + ['invoice_id' => $invoiceId, 'created_at' => $this->ts()]);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createAddress(
        ?int $customerId,
        string $type,
        string $contact,
        string $phone,
        string $line1,
        ?string $line2,
        string $city,
        string $district,
        string $state,
        string $postal,
        bool $default
    ): int {
        return $this->insert('addresses', [
            'organization_id' => $this->orgId,
            'customer_id'     => $customerId,
            'address_type'    => $type,
            'contact_name'    => $contact,
            'contact_phone'   => $phone,
            'address_line1'   => $line1,
            'address_line2'   => $line2,
            'city'            => $city,
            'district'        => $district,
            'state'           => $state,
            'postal_code'     => $postal,
            'country_code'    => 'IN',
            'is_default'      => $default ? 1 : 0,
            'created_at'      => $this->ts(),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(string $table, array $row): int
    {
        $this->db->table($table)->insert($row);

        return (int) $this->db->insertID();
    }

    /** UTC "Y-m-d H:i:s" for a moment (defaults to now). */
    private function ts(?DateTimeImmutable $moment = null): string
    {
        return ($moment ?? $this->now)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function report(): void
    {
        $lines = [
            'Demo organization seeded: Demo Logistics Pvt Ltd (' . self::ORG_CODE . ')',
            'Login password for all demo users: ' . self::DEMO_PASSWORD,
        ];
        foreach (self::DEMO_EMAILS as $email) {
            $lines[] = '  - ' . $email;
        }

        foreach ($lines as $line) {
            if (is_cli()) {
                CLI::write($line, 'green');
            }
        }
    }
}
