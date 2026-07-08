<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', User::class);

        return Inertia::render('Users/Index', ['users' => User::query()->with('site:id,name')->latest()->paginate(25)->withQueryString()]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Users/Create', $this->formOptions());
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        User::create($request->validated());

        return redirect()->route('users.index');
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('Users/Edit', [...$this->formOptions(), 'managedUser' => $user->load('site:id,name')]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            $data = Arr::except($data, 'password');
        }

        $user->update($data);

        return redirect()->route('users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);
        $user->delete();

        return redirect()->route('users.index');
    }

    private function formOptions(): array
    {
        return [
            'sites' => Site::query()->orderBy('name')->get(['id', 'name', 'region']),
            'roles' => collect(UserRole::cases())->map(fn (UserRole $role): array => ['value' => $role->value, 'label' => $role->label()])->values(),
        ];
    }
}
