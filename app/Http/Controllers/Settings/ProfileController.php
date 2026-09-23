<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Access\DeleteUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            Role::lockSuperAdmin();
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $wasDemoAdmin = $user->isDemoAdministrator();
            $user->fill($request->validated());

            if ($user->isDirty('email')) {
                if ($user->isLastSuperAdmin() || $wasDemoAdmin) {
                    throw ValidationException::withMessages(['email' => 'Сначала назначьте другого администратора. Email демо-аккаунта изменить нельзя.']);
                }
                $user->email_verified_at = null;
            }

            $user->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        app(DeleteUser::class)->handle($user, $user, ownProfile: true);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
