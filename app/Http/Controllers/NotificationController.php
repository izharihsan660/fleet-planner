<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarkNotificationReadRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', [
            'notifications' => NotificationResource::collection(
                $request->user()->appNotifications()->latest()->paginate(25)->withQueryString()
            ),
        ]);
    }

    public function markAsRead(MarkNotificationReadRequest $request, Notification $notification): RedirectResponse
    {
        Gate::authorize('update', $notification);

        $notification->markAsRead();

        $redirectTo = $request->validated('redirect_to') ?? ($notification->data['url'] ?? route('dashboard'));

        return redirect($redirectTo);
    }
}
