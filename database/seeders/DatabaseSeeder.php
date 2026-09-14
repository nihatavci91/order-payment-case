<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Demo data must never reach a production database.
        if (! app()->environment('local', 'testing')) {
            return;
        }
        $this->call(DemoDataSeeder::class);
    }
}
