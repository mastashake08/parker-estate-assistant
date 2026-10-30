<?php

use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $this->get(route('skills.index'))->assertRedirect(route('login'));
});

test('users only see their own skills', function () {
    $user = User::factory()->create();
    $skill = Skill::factory()->for($user)->create();
    Skill::factory()->create();

    $this->actingAs($user)
        ->get(route('skills.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('assistant/Skills')
            ->has('skills', 1)
            ->where('skills.0.id', $skill->id)
        );
});

test('a skill can be created', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('skills.store'), [
            'name' => 'property-taxes',
            'description' => 'Use when answering questions about property tax bills.',
            'instructions' => 'Always check the county assessor records first.',
        ])
        ->assertRedirect(route('skills.index'));

    $skill = $user->skills()->sole();

    expect($skill->name)->toBe('property-taxes')
        ->and($skill->instructions)->toBe('Always check the county assessor records first.')
        ->and($skill->enabled)->toBeTrue();
});

test('name, description and instructions are required', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('skills.store'), [])
        ->assertSessionHasErrors([
            'name' => 'The name field is required.',
            'description' => 'The description field is required.',
            'instructions' => 'The instructions field is required.',
        ]);
});

test('skill names must be kebab-case slugs', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('skills.store'), [
            'name' => 'Property Taxes!',
            'description' => 'Taxes.',
            'instructions' => 'Do things.',
        ])
        ->assertSessionHasErrors(['name' => 'The name field must only contain letters, numbers, dashes, and underscores.']);
});

test('skill names are unique per user', function () {
    $user = User::factory()->create();
    Skill::factory()->for($user)->create(['name' => 'taxes']);

    $this->actingAs($user)
        ->post(route('skills.store'), ['name' => 'taxes', 'description' => 'x', 'instructions' => 'y'])
        ->assertSessionHasErrors(['name' => 'The name has already been taken.']);
});

test('a skill can be updated', function () {
    $user = User::factory()->create();
    $skill = Skill::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('skills.update', $skill), [
            'name' => $skill->name,
            'description' => 'Updated description.',
            'instructions' => 'Updated instructions.',
            'enabled' => false,
        ])
        ->assertRedirect(route('skills.index'));

    $skill->refresh();

    expect($skill->description)->toBe('Updated description.')
        ->and($skill->enabled)->toBeFalse();
});

test("users cannot update another user's skill", function () {
    $skill = Skill::factory()->create();

    $this->actingAs(User::factory()->create())
        ->put(route('skills.update', $skill), ['name' => 'mine', 'description' => 'x', 'instructions' => 'y'])
        ->assertNotFound();
});

test('a skill can be deleted', function () {
    $user = User::factory()->create();
    $skill = Skill::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('skills.destroy', $skill))
        ->assertRedirect(route('skills.index'));

    $this->assertModelMissing($skill);
});

test("users cannot delete another user's skill", function () {
    $skill = Skill::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('skills.destroy', $skill))
        ->assertNotFound();

    $this->assertModelExists($skill);
});
