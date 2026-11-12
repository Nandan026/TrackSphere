<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use CodeIgniter\Database\BaseConnection;

/**
 * Generates unique tracking numbers: TS{YYYY}{10 random A-Z0-9 chars}.
 */
class TrackingNumberService
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // no 0/O/1/I
    private const LENGTH   = 10;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public function generate(): string
    {
        $year = gmdate('Y');

        for ($i = 0; $i < 10; $i++) {
            $candidate = 'TS' . $year . $this->randomSuffix();

            $exists = $this->db->table('shipments')
                ->where('tracking_number', $candidate)
                ->countAllResults() > 0;

            if (! $exists) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate a unique tracking number.');
    }

    private function randomSuffix(): string
    {
        $max    = strlen(self::ALPHABET) - 1;
        $suffix = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $suffix .= self::ALPHABET[random_int(0, $max)];
        }

        return $suffix;
    }
}
