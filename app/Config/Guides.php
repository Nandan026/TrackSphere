<?php

declare(strict_types=1);

namespace Config;

use App\Services\Catalog\StatusCatalogService;
use CodeIgniter\Config\BaseConfig;

/**
 * In-app help content shown on each module page.
 * Structure: module key => [title, summary, steps[], tips[]]
 *
 * Usage: config('Guides')->guides['shipments'] or config('Guides')->get('shipments').
 */
class Guides extends BaseConfig
{
    /**
     * @var array<string, array{title: string, summary: string, steps: list<string>, tips: list<string>}>
     */
    public array $guides = [
        'dashboard' => [
            'title'   => 'Dashboard',
            'summary' => 'A live snapshot of your logistics operation: shipments by status, today\'s pickups and deliveries, exceptions and COD collections.',
            'steps'   => [
                'Review the summary cards to see shipments created, in transit, out for delivery and delivered.',
                'Open a status card to jump to the filtered shipment list.',
                'Check the attention panel for failed deliveries, open NDR cases and overdue pickups.',
                'Use the recent activity feed to follow the latest shipment events.',
            ],
            'tips' => [
                'Cards you cannot access are hidden based on your role permissions.',
                'All times are shown in your organization\'s timezone.',
            ],
        ],
        'customers' => [
            'title'   => 'Customers',
            'summary' => 'Maintain the businesses and individuals you ship for, along with their contact details and saved addresses.',
            'steps'   => [
                'Click "Add Customer" and enter the name, phone and (optionally) email and GSTIN.',
                'Save the customer, then add one or more pickup/delivery addresses.',
                'Mark a default address to pre-fill it when creating shipments.',
                'Use search and status filters to find a customer; edit or deactivate as needed.',
            ],
            'tips' => [
                'Customer codes are unique per organization and cannot be reused.',
                'Deactivate instead of deleting so past shipments and invoices stay intact.',
            ],
        ],
        'pickups' => [
            'title'   => 'Pickups',
            'summary' => 'Manage pickup locations and schedule courier pickups for shipments that are ready to ship.',
            'steps'   => [
                'Add a pickup location with a complete address and contact person.',
                'Open a shipment that is Ready to Ship and choose "Schedule Pickup".',
                'Select the pickup location, date and time slot, then confirm.',
                'After collection, mark the pickup completed to move the shipment to Picked Up.',
            ],
            'tips' => [
                'Mark the most-used location as default to speed up booking.',
                'If the courier cannot collect, record a failed pickup and reschedule.',
            ],
        ],
        'pickup_locations' => [
            'title'   => 'Pickup Locations',
            'summary' => 'Register the places where couriers collect your shipments, with a contact, address and operating hours.',
            'steps'   => [
                'Click "Add Pickup Location" and enter a name and (optionally) a unique location code.',
                'Choose an existing address, or fill in the address fields to create a new one.',
                'Add the contact email and operating hours so couriers know when to arrive.',
                'Tick "default" on the location you use most; it is pre-selected when scheduling pickups.',
            ],
            'tips' => [
                'Only one location can be the default at a time.',
                'Changing the address of a location saves a new address record, so past shipments keep their original address.',
                'Deactivate a location instead of deleting it; it stays in history but is hidden from new pickups.',
            ],
        ],
        'warehouses' => [
            'title'   => 'Warehouses',
            'summary' => 'Keep a register of the warehouses and hubs that hold or dispatch your goods.',
            'steps'   => [
                'Click "Add Warehouse" and enter a name and unique warehouse code.',
                'Attach a full address including postal code.',
                'Set the status to Active so it can be used in operations.',
            ],
            'tips' => [
                'Use short, meaningful codes such as BLR-01 or DEL-HUB.',
                'Inactive warehouses stay in history but are hidden from new selections.',
            ],
        ],
        'couriers' => [
            'title'   => 'Courier Partners',
            'summary' => 'Configure the courier companies that carry your shipments and what services they support.',
            'steps'   => [
                'Add a courier with a name and unique code.',
                'Choose the integration type (simulated or API) and set COD / reverse-pickup support.',
                'Add rate cards for the courier under Rates.',
                'Keep the status Active to make the courier available when booking.',
            ],
            'tips' => [
                'Simulated couriers are ideal for demos and testing without live credentials.',
                'Never share API credentials; they are stored in the courier configuration.',
            ],
        ],
        'agents' => [
            'title'   => 'Delivery Agents',
            'summary' => 'Register last-mile delivery agents, their vehicles and availability.',
            'steps'   => [
                'Click "Add Agent" and enter name, phone and a unique agent code.',
                'Optionally link the agent to a user account so they can sign in.',
                'Record vehicle type and number for accountability.',
                'Set status to Inactive when an agent is unavailable.',
            ],
            'tips' => [
                'Only active agents appear in the delivery assignment list.',
                'A linked user account lets the agent update delivery outcomes themselves.',
            ],
        ],
        'packages' => [
            'title'   => 'Packages',
            'summary' => 'Define standard package types with dimensions and default weight to speed up shipment creation and weight calculation.',
            'steps'   => [
                'Add a package with a name, code and length, width and height in cm.',
                'Enter the default weight in kg.',
                'Select the package when creating a shipment to auto-fill dimensions.',
            ],
            'tips' => [
                'Volumetric weight = (L x W x H) / 5000. Billing uses the higher of actual and volumetric weight.',
                'Keep package codes short and unique, e.g. BOX-S, BOX-M.',
            ],
        ],
        'rates' => [
            'title'   => 'Rates & Quotes',
            'summary' => 'Maintain courier rate cards and generate shipping quotes based on weight, route and payment mode.',
            'steps'   => [
                'Create a rate card: pick a courier, weight band, base charge and per-kg charge.',
                'Optionally restrict by origin/destination postal prefix and set validity dates.',
                'Add COD charge and fuel surcharge percentage where applicable.',
                'Use the quote calculator to compare couriers for a given route and weight.',
            ],
            'tips' => [
                'Leave prefixes empty to make a rate card apply to all routes.',
                'Expire old rate cards with an end date rather than deleting them.',
            ],
        ],
        'shipments' => [
            'title'   => 'Shipments',
            'summary' => 'Create, track and manage shipments through their full lifecycle from draft to delivery or return.',
            'steps'   => [
                'Click "New Shipment" and select the customer, sender and receiver addresses.',
                'Add items, package details and weight; the chargeable weight is calculated automatically.',
                'Choose payment mode (prepaid or COD) and courier, then save as Draft.',
                'Mark the shipment Ready to Ship, then schedule a pickup.',
                'Update status as it moves: Picked Up, In Transit, Reached Hub, Out for Delivery, Delivered.',
            ],
            'tips' => [
                'Status changes follow a strict workflow; invalid jumps are rejected.',
                'Only Draft or Ready to Ship shipments can be cancelled.',
                'Share the tracking number with the receiver for public tracking.',
            ],
        ],
        'delivery' => [
            'title'   => 'Delivery',
            'summary' => 'Assign shipments to delivery agents and record delivery attempts and proof of delivery.',
            'steps'   => [
                'Open shipments that are Out for Delivery or at the destination hub.',
                'Assign an available delivery agent.',
                'Record the outcome of each attempt: delivered or failed with a reason.',
                'For successful deliveries, capture receiver name and proof (OTP, photo or signature).',
            ],
            'tips' => [
                'A failed attempt can be retried or escalated to RTO.',
                'Proof of delivery cannot be replaced once saved.',
            ],
        ],
        'ndr' => [
            'title'   => 'Non-Delivery Reports',
            'summary' => 'Resolve shipments that could not be delivered by contacting the customer and deciding the next action.',
            'steps'   => [
                'Review open NDR cases and the reason for the failed attempt.',
                'Record the customer response (reattempt, change address, cancel).',
                'Choose a resolution and, for reattempt, set the next attempt date.',
                'Close the case once resolved so the shipment can proceed.',
            ],
            'tips' => [
                'Act within 24 hours to reduce RTO rates.',
                'Unresolved cases after repeated attempts should move to return-to-origin.',
            ],
        ],
        'returns' => [
            'title'   => 'Returns',
            'summary' => 'Manage customer return requests and return-to-origin (RTO) shipments.',
            'steps'   => [
                'Create a return request against a delivered shipment with a reason.',
                'Approve or reject the request.',
                'For approved returns, create a reverse shipment and schedule pickup.',
                'Mark the return received once it reaches your warehouse.',
            ],
            'tips' => [
                'Only couriers that support reverse pickup can be used for returns.',
                'Keep the reason details clear; they help reduce future returns.',
            ],
        ],
        'billing' => [
            'title'   => 'Billing & COD',
            'summary' => 'Generate invoices for shipping services and reconcile cash-on-delivery remittances from couriers.',
            'steps'   => [
                'Create an invoice for a customer and add shipments as line items.',
                'Review subtotal, tax and discount, then issue the invoice.',
                'Record payments to update the paid and balance amounts.',
                'Create COD remittances for a period and mark them remitted with the bank reference.',
            ],
            'tips' => [
                'Invoice numbers are generated automatically and are unique per organization.',
                'Net COD remittance = total COD collected - deductions.',
            ],
        ],
        'invoices' => [
            'title'   => 'Invoices',
            'summary' => 'Bill customers for shipping services: add line items (optionally linked to shipments), apply tax and discount, then track the invoice until it is paid.',
            'steps'   => [
                'Click "New invoice", choose the customer and set the invoice and due dates.',
                'Add line items: description, quantity, unit price and tax %. Pick a shipment to pre-fill its freight charge.',
                'Review the live subtotal, tax, discount and total, then save the invoice as a draft.',
                'Open the invoice and mark it as sent once it has been shared with the customer.',
                'Mark it as paid when the full amount is received; the balance drops to zero.',
            ],
            'tips' => [
                'Total = subtotal + tax - discount. Tax is calculated per line.',
                'A shipment can only appear on one invoice, and only for its own customer.',
                'Invoice numbers (INV-YYYYMM-0001) are generated automatically.',
            ],
        ],
        'cod_remittances' => [
            'title'   => 'COD Remittances',
            'summary' => 'Reconcile the cash-on-delivery money couriers collect on your behalf: record a batch per courier and period, then mark it remitted when the money arrives.',
            'steps'   => [
                'Click "New remittance", select the courier and the period.',
                'Use "Calculate from delivered shipments" to see the COD collected in that period, or type the amounts yourself.',
                'Enter any deductions (courier fees). Net remittance = total COD - deductions.',
                'Save the batch as Pending, then open "Mark remitted" and add the bank / UTR reference when the courier pays.',
            ],
            'tips' => [
                'Periods for the same courier cannot overlap, which prevents remitting the same shipments twice.',
                'Only delivered COD shipments count as collected cash.',
                'Keep the reference number so payments can be matched with your bank statement.',
            ],
        ],
        'reports' => [
            'title'   => 'Reports',
            'summary' => 'Analyse shipment volumes, delivery performance, NDR and RTO rates, and COD collections.',
            'steps'   => [
                'Choose a report type from the list.',
                'Set the date range and any filters such as courier or status.',
                'Review the summary and detailed table.',
                'Export the result to CSV for further analysis.',
            ],
            'tips' => [
                'Dates use your organization timezone.',
                'Narrow the date range for faster results on large datasets.',
            ],
        ],
        'users' => [
            'title'   => 'Users & Roles',
            'summary' => 'Manage who can sign in to your organization and what each role is allowed to do.',
            'steps'   => [
                'Click "Add User" and enter name, email and a temporary password.',
                'Assign a role; permissions come from the role.',
                'Edit a role to adjust its permissions by module.',
                'Deactivate users who leave instead of deleting them.',
            ],
            'tips' => [
                'Grant the minimum permissions needed for the job.',
                'Ask users to change their temporary password after first login.',
            ],
        ],
        'tracking' => [
            'title'   => 'Tracking',
            'summary' => 'Look up a shipment by tracking number and view its complete event timeline.',
            'steps'   => [
                'Enter the tracking number (for example TS2026XXXXXXXXXX).',
                'Review the current status and expected delivery date.',
                'Read the timeline from newest to oldest for location and status details.',
            ],
            'tips' => [
                'Tracking numbers are not case sensitive.',
                'Events can take a few minutes to appear after a courier scan.',
            ],
        ],
        'status_definitions' => [
            'title'   => 'Status Glossary',
            'summary' => 'The plain-English meaning of every status used across TrackSphere. These definitions feed the "Status meanings" section of each module guide and the colour of status badges.',
            'steps'   => [
                'Use the module filter to see one group of statuses, for example shipments or invoices.',
                'Click "Add Status" to define a new code with a label, a meaning and a badge colour.',
                'Edit a status to improve its meaning or change its colour; the code itself is fixed.',
                'Deactivate a status to hide it from guides; records that already use it still show their label.',
            ],
            'tips' => [
                'Definitions are platform-wide: a change applies to every organization.',
                'Adding a status here documents it only; workflow rules are still enforced by the application.',
                'Mark a status as final ("terminal") when nothing happens after it, such as Delivered or Cancelled.',
                'Badge colours use Bootstrap names: success, info, primary, warning, danger, secondary, dark.',
            ],
        ],
        'lookup_values' => [
            'title'   => 'Lookup Values',
            'summary' => 'The options offered in dropdowns, such as delivery failure reasons, return reasons and vehicle types, grouped by category.',
            'steps'   => [
                'Use the category filter to open one list, for example failure_reason.',
                'Click "Add Value" to add a new option with a code, label and optional explanation.',
                'Use the sort order to control the order options appear in dropdowns.',
                'Deactivate a value to remove it from dropdowns without losing history.',
            ],
            'tips' => [
                'Lookup values are platform-wide: a change applies to every organization.',
                'Codes are stored on records and cannot be changed after creation; edit the label instead.',
                'If a category has no active values, the application falls back to its built-in defaults.',
            ],
        ],
    ];

    /**
     * Returns the guide for a module. When status definitions exist for the
     * module they are attached as 'statuses' (list of code, label, meaning,
     * badge_class, group) so the guide can explain each status.
     *
     * @return array{title: string, summary: string, steps: list<string>, tips: list<string>, statuses: list<array{code: string, label: string, meaning: string, badge_class: string, group: string}>}|null
     */
    public function get(string $module): ?array
    {
        // Rate cards share the "rates" guide.
        $key   = $module === 'rate_cards' ? 'rates' : $module;
        $guide = $this->guides[$key] ?? null;

        if ($guide === null) {
            return null;
        }

        try {
            $guide['statuses'] = (new StatusCatalogService())->forGuide($module);
        } catch (\Throwable $e) {
            log_message('warning', 'Guide statuses unavailable for ' . $module . ': ' . $e->getMessage());
            $guide['statuses'] = [];
        }

        return $guide;
    }
}
