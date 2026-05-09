<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class AdminDashboard extends Controller
{
    public function __construct(private readonly RevenueDashboard $revenueDashboard)
    {
    }

    public function stats(): JsonResponse
    {
        return $this->revenueDashboard->stats();
    }
}

