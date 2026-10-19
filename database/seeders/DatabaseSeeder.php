<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $username = (string) config('flowhub.username');
        $password = (string) config('flowhub.password');

        User::query()->updateOrCreate(
            ['username' => $username],
            [
                'name' => $username,
                'email' => strtolower($username).'@flowhub.local',
                'password' => $password,
            ],
        );
    }
};
