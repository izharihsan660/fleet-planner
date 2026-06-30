<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarkNotificationReadRequest;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class NotificationController extends Controller
{
    public function markAsRead(MarkNotificationReadRequest $request, Notification $notification): RedirectResponse
    {
        Gate::authorize('update', $notification);

        $notification->markAsRead();

        $redirectTo = $request->validated('redirect_to') ?? ($notification->data['url'] ?? route('dashboard'));

        return redirect($redirectTo);
    }
}
