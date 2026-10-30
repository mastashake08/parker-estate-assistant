<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(protected DashboardService $dashboard) {}

    /**
     * Show the user's assistant at a glance: activity, setup and model status.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => $this->dashboard->stats($request->user()),
            'recentConversations' => $this->dashboard->recentConversations($request->user()),
            'model' => $this->dashboard->model(),
        ]);
    }
}
