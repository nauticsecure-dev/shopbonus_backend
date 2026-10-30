<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Production-safe by default. Local/testing installs demo users, shops and CMS.
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->call([
            DemoUsersAndShopsSeeder::class,
        ]);
    }
}
