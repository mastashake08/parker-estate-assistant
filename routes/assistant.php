<?php

use App\Http\Controllers\Assistant\ConversationApprovalController;
use App\Http\Controllers\Assistant\ConversationController;
use App\Http\Controllers\Assistant\ConversationMessageController;
use App\Http\Controllers\Assistant\McpServerController;
use App\Http\Controllers\Assistant\SkillController;
use App\Http\Controllers\Assistant\ToolController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('chat', [ConversationController::class, 'index'])->name('chat.index');
    Route::post('chat/messages', [ConversationMessageController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('chat.messages.store');
    Route::get('chat/{conversation}', [ConversationController::class, 'show'])->name('chat.show');
    Route::post('chat/{conversation}/approvals', [ConversationApprovalController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('chat.approvals.store');
    Route::delete('chat/{conversation}', [ConversationController::class, 'destroy'])->name('chat.destroy');

    Route::resource('settings/mcp-servers', McpServerController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('mcp-servers');
    Route::post('settings/mcp-servers/{mcp_server}/refresh', [McpServerController::class, 'refresh'])
        ->name('mcp-servers.refresh');

    Route::resource('settings/skills', SkillController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('skills');

    Route::get('settings/tools', [ToolController::class, 'index'])->name('tools.index');
    Route::put('settings/tools', [ToolController::class, 'update'])->name('tools.update');
});
