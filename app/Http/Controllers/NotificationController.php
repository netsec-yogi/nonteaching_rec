<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->notifications()->limit(50)->get(['id', 'data', 'read_at', 'created_at']));
    }

    public function markRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->when($request->id, fn ($q, $id) => $q->where('id', $id))->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
