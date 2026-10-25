<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Breeze er profile page ei app e nei; nijer password bodlano "Change Password" page theke
test('change password page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.change.password'))->assertOk();
});

test('password can be changed with the current password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.change.password'))
        ->post(route('profile.change.password.updated'), [
            'current_password' => 'password',
            'new_password' => 'new-password-123',
            'confirm_password' => 'new-password-123',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.change.password'));

    expect(Hash::check('new-password-123', $user->refresh()->password))->toBeTrue();
});

test('wrong current password is rejected and new password is not flashed back', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.change.password'))
        ->post(route('profile.change.password.updated'), [
            'current_password' => 'wrong-password',
            'new_password' => 'new-password-123',
            'confirm_password' => 'new-password-123',
        ])
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    expect(session()->getOldInput())->not->toHaveKeys(['current_password', 'new_password', 'confirm_password']);
});

test('password is stored as a hash, never as plain text', function () {
    $user = User::factory()->create(['password' => 'plain-secret-1']);

    expect($user->getRawOriginal('password'))->not->toBe('plain-secret-1');
    expect(Hash::check('plain-secret-1', $user->refresh()->password))->toBeTrue();
});
