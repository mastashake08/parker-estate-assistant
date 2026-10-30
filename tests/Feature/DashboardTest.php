<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
test('users can use the app without verifying their email', function () {
    Http::fake(['*/api/tags' => Http::response(['models' => []])]);
    $this->actingAs(User::factory()->unverified()->create());

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('chat.index'))->assertOk();
});
