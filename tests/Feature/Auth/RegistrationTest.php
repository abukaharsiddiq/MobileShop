<?php

use App\Models\User;

// Public registration bondho: notun user shudhu admin (Staff menu) ba `app:create-admin` diye
test('registration screen is not available', function () {
    $this->get('/register')->assertNotFound();
});

test('nobody can register through a public form', function () {
    $this->post('/register', [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'phone' => '01799999999',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::where('email', 'intruder@example.com')->exists())->toBeFalse();
});
