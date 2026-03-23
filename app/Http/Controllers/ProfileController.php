<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $user->email = $validated['email'];
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();

        if ($user->profile) {
            $firstName = $validated['first_name'] ?? null;
            $lastName = $validated['last_name'] ?? null;
            if ($firstName === null && $lastName === null && !empty($validated['name'] ?? '')) {
                $parts = preg_split('/\s+/', trim($validated['name']), 2);
                $firstName = $parts[0] ?? null;
                $lastName = $parts[1] ?? null;
            }
            if ($firstName !== null || $lastName !== null) {
                $user->profile->update([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'full_name' => trim(($firstName ?? '') . ' ' . ($lastName ?? '')),
                ]);
            }
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
