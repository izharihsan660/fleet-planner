<?php

namespace App\Http\Middleware;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email', 'email_verified_at', 'role', 'site_id'),
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
            'notifications' => fn () => $request->user() ? [
                'unread_count' => $request->user()->appNotifications()->whereNull('read_at')->count(),
                'latest' => NotificationResource::collection(
                    $request->user()->appNotifications()->latest()->limit(10)->get()
                )->resolve(),
            ] : [
                'unread_count' => 0,
                'latest' => [],
            ],
        ];
    }
}
