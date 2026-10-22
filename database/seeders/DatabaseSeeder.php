<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Reference data only. Demo accounts and applications: db:seed --class=DemoSeeder */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
    }
}
