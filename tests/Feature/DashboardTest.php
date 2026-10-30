<?php

use App\Models\Document;
use App\Models\McpServer;
use App\Models\Skill;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['assistant.model' => 'llama3.2:3b']);

    Http::fake(['*/api/tags' => Http::response(['models' => [['name' => 'llama3.2:3b'], ['name' => 'llama3.2:1b']]])]);
});

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

test('the dashboard counts only the user\'s own conversations, documents, skills and servers', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $conversations = app(ConversationService::class);
    $conversations->start($user, 'Mine');
    $conversations->start($user, 'Also mine');
    $conversations->start($other, 'Theirs');
    Document::factory()->for($user)->create();
    Document::factory()->for($other)->create();
    Skill::factory()->for($user)->create();
    Skill::factory()->for($user)->create(['enabled' => false]);
    Skill::factory()->for($other)->create();
    McpServer::factory()->for($user)->create(['enabled' => false]);
    McpServer::factory()->for($other)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('stats', [
                'conversations' => 2,
                'documents' => 1,
                'skills' => ['enabled' => 1, 'total' => 2],
                'mcpServers' => ['enabled' => 0, 'total' => 1],
            ])
        );
});

test('the dashboard lists the five most recently active conversations', function () {
    $user = User::factory()->create();
    $conversations = app(ConversationService::class);

    foreach (range(1, 6) as $day) {
        $this->travelTo(now()->addDay());
        $conversations->start($user, "Conversation {$day}");
    }

    $conversations->start(User::factory()->create(), 'Someone else');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('recentConversations', 5)
            ->where('recentConversations.0.title', 'Conversation 6')
            ->where('recentConversations.4.title', 'Conversation 2')
        );
});

test('the dashboard shows that the default model is installed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('model', ['name' => 'llama3.2:3b', 'installed' => true, 'available' => ['llama3.2:3b', 'llama3.2:1b']])
        );
});

test('the dashboard shows when the default model is not installed', function () {
    config(['assistant.model' => 'llama3.1:8b']);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('model.installed', false)
        );
});

test('the dashboard still loads when Ollama cannot be reached', function () {
    Http::fake(['*/api/tags' => Http::failedConnection()]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('model', ['name' => 'llama3.2:3b', 'installed' => false, 'available' => []])
        );
});
