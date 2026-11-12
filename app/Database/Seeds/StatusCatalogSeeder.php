<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Services\Catalog\StatusCatalogService;
use CodeIgniter\Database\Seeder;

/**
 * Seeds the platform-wide status glossary (status_definitions) and dropdown
 * values (lookup_values).
 *
 * Safe to run repeatedly: rows are matched on (module, code) / (category, code);
 * missing rows are inserted and existing rows are left untouched so edits made
 * in the admin screens are never overwritten. Organization data is not touched.
 *
 *   php spark db:seed StatusCatalogSeeder
 */
class StatusCatalogSeeder extends Seeder
{
    /**
     * module => list of [code, label, meaning, badge_class, is_terminal]
     * (display order = order in this list).
     *
     * @var array<string, list<array{0: string, 1: string, 2: string, 3: string, 4: int}>>
     */
    private const STATUSES = [
        'shipments' => [
            ['draft', 'Draft', 'Saved but not yet released for shipping.', 'secondary', 0],
            ['ready_to_ship', 'Ready to Ship', 'Packed and ready; waiting for pickup booking.', 'primary', 0],
            ['pickup_scheduled', 'Pickup Scheduled', 'Courier pickup has been booked for a date/slot.', 'warning', 0],
            ['pickup_failed', 'Pickup Failed', 'Courier could not collect the parcel.', 'danger', 0],
            ['picked_up', 'Picked Up', 'Parcel collected from the sender.', 'info', 0],
            ['in_transit', 'In Transit', 'Moving through the courier network.', 'info', 0],
            ['reached_destination_hub', 'Reached Destination Hub', 'Arrived at the local hub near the receiver.', 'info', 0],
            ['out_for_delivery', 'Out for Delivery', 'With a delivery agent for last-mile delivery.', 'primary', 0],
            ['delivered', 'Delivered', 'Successfully handed to the receiver.', 'success', 1],
            ['delivery_failed', 'Delivery Failed', 'Attempted delivery but could not complete (creates NDR).', 'danger', 0],
            ['cancelled', 'Cancelled', 'Shipment cancelled before pickup/movement.', 'dark', 1],
            ['rto_initiated', 'RTO Initiated', 'Return-to-origin started after failed delivery.', 'danger', 0],
            ['rto_in_transit', 'RTO In Transit', 'Parcel returning to origin.', 'info', 0],
            ['rto_delivered', 'RTO Delivered', 'Returned parcel received at origin.', 'success', 1],
        ],
        'pickups' => [
            ['requested', 'Requested', 'A pickup has been requested but no courier slot is confirmed yet.', 'warning', 0],
            ['scheduled', 'Scheduled', 'A courier pickup is booked for a specific date and time slot.', 'info', 0],
            ['completed', 'Completed', 'The courier collected the parcel from the pickup location.', 'success', 1],
            ['cancelled', 'Cancelled', 'The pickup was cancelled and will not take place.', 'dark', 1],
            ['failed', 'Failed', 'The courier visited but could not collect the parcel; it needs rescheduling.', 'danger', 0],
        ],
        'delivery' => [
            ['assigned', 'Assigned', 'A delivery agent has been given the shipment but has not confirmed it yet.', 'info', 0],
            ['accepted', 'Accepted', 'The delivery agent confirmed the task and is responsible for the delivery.', 'primary', 0],
            ['completed', 'Completed', 'The agent finished the task with a delivered or failed outcome recorded.', 'success', 1],
            ['cancelled', 'Cancelled', 'The assignment was withdrawn before the agent completed it.', 'dark', 1],
        ],
        'ndr' => [
            ['open', 'Open', 'A delivery failed and the case is waiting for a decision on the next step.', 'warning', 0],
            ['resolved', 'Resolved', 'The case was handled by scheduling a re-attempt or starting return-to-origin.', 'success', 1],
            ['closed', 'Closed', 'The case is finished and needs no further follow-up.', 'secondary', 1],
        ],
        'returns' => [
            ['requested', 'Requested', 'The customer asked to return a delivered shipment; waiting for a decision.', 'warning', 0],
            ['approved', 'Approved', 'The return was accepted and the reverse shipment can be arranged.', 'info', 0],
            ['rejected', 'Rejected', 'The return request was declined and no reverse shipment will be created.', 'danger', 1],
            ['in_transit', 'In Transit', 'The returned parcel is on its way back to the warehouse.', 'info', 0],
            ['received', 'Received', 'The returned parcel has arrived and been accepted at the warehouse.', 'success', 1],
            ['cancelled', 'Cancelled', 'The return request was withdrawn before it was completed.', 'dark', 1],
        ],
        'invoices' => [
            ['draft', 'Draft', 'The invoice is being prepared and has not been shared with the customer.', 'secondary', 0],
            ['sent', 'Sent', 'The invoice was issued to the customer and is awaiting payment.', 'info', 0],
            ['partial', 'Partially Paid', 'Part of the amount has been received; a balance is still due.', 'warning', 0],
            ['paid', 'Paid', 'The full amount has been received and the balance is zero.', 'success', 1],
            ['cancelled', 'Cancelled', 'The invoice was voided and no payment is expected.', 'dark', 1],
            ['overdue', 'Overdue', 'The due date has passed and the invoice is still unpaid.', 'danger', 0],
        ],
        'cod_remittances' => [
            ['pending', 'Pending', 'Cash collected by the courier has been recorded but not yet paid out to you.', 'warning', 0],
            ['remitted', 'Remitted', 'The courier has paid out the money and the bank reference is recorded.', 'success', 1],
            ['cancelled', 'Cancelled', 'The remittance batch was cancelled and is not expected to be paid.', 'dark', 1],
        ],
        'record' => [
            ['active', 'Active', 'The record is in use and can be selected in new transactions.', 'success', 0],
            ['inactive', 'Inactive', 'The record is switched off; history is kept but it is hidden from new selections.', 'secondary', 0],
        ],
    ];

    /**
     * category => list of [code, label, meaning]. Codes that the application
     * already validates against (service/controller constants) keep the same
     * codes so existing data stays valid.
     *
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     */
    private const LOOKUPS = [
        'payment_mode' => [
            ['prepaid', 'Prepaid', 'The sender has already paid; nothing is collected from the receiver.'],
            ['cod', 'Cash on Delivery', 'The courier collects the shipment value from the receiver at delivery.'],
        ],
        'failure_reason' => [
            ['customer_unavailable', 'Customer unavailable', 'The receiver was not present when the agent arrived.'],
            ['phone_unreachable', 'Phone unreachable', 'The agent could not reach the receiver by phone.'],
            ['address_incorrect', 'Address incorrect / incomplete', 'The address is wrong, incomplete or could not be found.'],
            ['delivery_refused', 'Delivery refused', 'The receiver refused to accept the parcel.'],
            ['cod_not_ready', 'COD amount not ready', 'The receiver did not have the cash-on-delivery amount available.'],
            ['premises_closed', 'Premises closed', 'The home, shop or office was locked or closed at the time of delivery.'],
            ['area_inaccessible', 'Area inaccessible', 'The agent could not reach the location (restricted area, road closure, weather).'],
            ['reschedule_requested', 'Customer requested reschedule', 'The receiver asked for delivery on another day or time.'],
            ['other', 'Other', 'Any reason not covered above; add details in the remarks.'],
        ],
        'return_reason' => [
            ['damaged_item', 'Damaged item', 'The item arrived broken or damaged.'],
            ['wrong_item', 'Wrong item delivered', 'The customer received a different item than ordered.'],
            ['not_as_described', 'Not as described', 'The item does not match its description or photos.'],
            ['quality_issue', 'Quality issue', 'The item works poorly or is of lower quality than expected.'],
            ['size_fit_issue', 'Size / fit issue', 'The item does not fit or is the wrong size.'],
            ['no_longer_needed', 'No longer needed', 'The customer changed their mind about the purchase.'],
            ['other', 'Other', 'Any reason not covered above; add details in the request.'],
        ],
        'ndr_customer_response' => [
            ['reattempt', 'Wants a re-attempt', 'The customer asked for the delivery to be tried again.'],
            ['change_address', 'Provided a new address / landmark', 'The customer gave a corrected address or landmark for the next attempt.'],
            ['no_response', 'No response from customer', 'The customer could not be reached or did not reply.'],
            ['cancel_order', 'Cancelled the order', 'The customer no longer wants the order; the parcel goes back to the sender.'],
            ['refused', 'Refused the shipment', 'The customer declined to accept the shipment.'],
        ],
        'vehicle_type' => [
            ['bike', 'Bike', 'Motorbike used for quick last-mile deliveries.'],
            ['scooter', 'Scooter', 'Scooter used for small and medium parcels in the city.'],
            ['bicycle', 'Bicycle', 'Bicycle used for short, light deliveries.'],
            ['van', 'Van', 'Van for bulk or larger parcels.'],
            ['truck', 'Truck', 'Truck for heavy or long-haul loads.'],
            ['on_foot', 'On foot', 'The agent delivers on foot, for example inside a campus or market.'],
            ['other', 'Other', 'Any vehicle not listed.'],
        ],
        'verification_method' => [
            ['name', 'Receiver name', 'Delivery confirmed by recording the name of the person who received it.'],
            ['otp', 'OTP verified', 'The receiver shared a one-time password to confirm delivery.'],
            ['signature', 'Signature', 'The receiver signed to confirm delivery.'],
            ['photo', 'Photo of parcel', 'A photo of the delivered parcel was taken as proof.'],
        ],
        'receiver_relation' => [
            ['self', 'Self (addressee)', 'The parcel was handed to the person named on the shipment.'],
            ['family', 'Family member', 'Handed to someone from the receiver\'s family.'],
            ['neighbour', 'Neighbour', 'Handed to a neighbour on behalf of the receiver.'],
            ['security', 'Security / reception', 'Left with building security or the reception desk.'],
            ['colleague', 'Colleague', 'Handed to a co-worker at the delivery address.'],
            ['other', 'Other', 'Handed to someone else authorised to accept it.'],
        ],
        'integration_type' => [
            ['simulated', 'Simulated', 'A built-in demo courier; no live connection, ideal for testing.'],
            ['api', 'API', 'Connected to the courier through its live API.'],
            ['manual', 'Manual', 'Bookings and updates are entered by hand with no integration.'],
        ],
        'customer_type' => [
            ['business', 'Business', 'A company or organisation that ships regularly.'],
            ['individual', 'Individual', 'A private person shipping on their own behalf.'],
        ],
        'address_type' => [
            ['pickup', 'Pickup', 'Where the courier collects parcels from.'],
            ['delivery', 'Delivery', 'Where parcels are delivered to.'],
            ['billing', 'Billing', 'The address printed on invoices.'],
            ['warehouse', 'Warehouse', 'The address of a warehouse or hub.'],
            ['other', 'Other', 'Any address that does not fit the types above.'],
        ],
        'delivery_outcome' => [
            ['success', 'Success', 'The parcel was handed to the receiver.'],
            ['failed', 'Failed', 'The agent could not complete the delivery on this attempt.'],
        ],
    ];

    public function run(): void
    {
        $now        = date('Y-m-d H:i:s');
        $statusNew  = 0;
        $lookupNew  = 0;
        $statusTbl  = $this->db->table('status_definitions');
        $lookupTbl  = $this->db->table('lookup_values');

        $this->db->transStart();

        foreach (self::STATUSES as $module => $rows) {
            foreach ($rows as $i => [$code, $label, $meaning, $badge, $terminal]) {
                $exists = $this->db->table('status_definitions')
                    ->where('module', $module)->where('code', $code)->countAllResults() > 0;

                if ($exists) {
                    continue;
                }

                $statusTbl->insert([
                    'module'      => $module,
                    'code'        => $code,
                    'label'       => $label,
                    'meaning'     => $meaning,
                    'badge_class' => $badge,
                    'sort_order'  => ($i + 1) * 10,
                    'is_terminal' => $terminal,
                    'status'      => 'active',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
                $statusNew++;
            }
        }

        foreach (self::LOOKUPS as $category => $rows) {
            foreach ($rows as $i => [$code, $label, $meaning]) {
                $exists = $this->db->table('lookup_values')
                    ->where('category', $category)->where('code', $code)->countAllResults() > 0;

                if ($exists) {
                    continue;
                }

                $lookupTbl->insert([
                    'category'   => $category,
                    'code'       => $code,
                    'label'      => $label,
                    'meaning'    => $meaning,
                    'sort_order' => ($i + 1) * 10,
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $lookupNew++;
            }
        }

        $this->db->transComplete();

        (new StatusCatalogService())->clearCache();

        if (defined('SPARKED') || PHP_SAPI === 'cli') {
            \CodeIgniter\CLI\CLI::write(
                'StatusCatalogSeeder: inserted ' . $statusNew . ' status definition(s) and ' . $lookupNew . ' lookup value(s).',
                'green'
            );
        }
    }
}
