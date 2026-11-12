<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Entry point: `php spark db:seed DatabaseSeeder`.
 * Add further seeders here as modules grow.
 *
 * StatusCatalogSeeder (platform-wide statuses + lookups) runs first so demo
 * data can rely on it; it can also be run on its own:
 * `php spark db:seed StatusCatalogSeeder`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(StatusCatalogSeeder::class);
        $this->call(DemoSeeder::class);
    }
}
