<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Default: minimal demo company + admin + portal customer for Render / local testing.
     */
    public function run(): void
    {
        $this->call([
            DemoSeedSeeder::class,
        ]);
    }
}
