<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Public registration bondho, tai notun install e prothom admin ei command diye banate hobe
Artisan::command('app:create-admin', function () {
    $data = [
        'name'     => $this->ask('Name'),
        'email'    => $this->ask('Email'),
        'phone'    => $this->ask('Phone'),
        'password' => $this->secret('Password (minimum 8 characters)'),
    ];

    $validator = Validator::make($data, [
        'name'     => 'required|string|max:255',
        'email'    => 'required|email|unique:users,email',
        'phone'    => 'required|unique:users,phone',
        'password' => 'required|min:8',
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }
        return 1;
    }

    User::create([
        'name'     => $data['name'],
        'email'    => $data['email'],
        'phone'    => $data['phone'],
        'password' => Hash::make($data['password']),
        'role'     => 'admin',
        'status'   => 1,
    ]);

    $this->info('Admin user created.');
})->purpose('Create an admin user');
