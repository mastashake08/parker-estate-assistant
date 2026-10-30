<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\UpdateToolRequest;
use App\Services\ToolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ToolController extends Controller
{
    public function __construct(protected ToolService $tools) {}

    /**
     * Show the built-in and MCP tools available to the assistant.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('assistant/Tools', $this->tools->catalog($request->user()));
    }

    /**
     * Switch a tool on or off.
     */
    public function update(UpdateToolRequest $request): RedirectResponse
    {
        $this->tools->setEnabled($request->user(), $request->validated('tool'), $request->boolean('enabled'));

        return to_route('tools.index');
    }
}
