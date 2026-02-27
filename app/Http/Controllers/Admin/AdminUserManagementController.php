<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewAdminUserCreated;

class AdminUserManagementController extends Controller
{
    /**
     * Check if the current user is a Super Admin
     */
    protected function isSuperAdmin()
    {
        $user = auth()->user();
        return $user && (
            $user->role === 'super_admin' ||
            $user->email === 'kesavan@cgvak.com' ||
            $user->profile?->email === 'kesavan@cgvak.com'
        );
    }

    /**
     * Display a listing of admin users (Super Admins only).
     */
    public function index()
    {
        // Display only Super Admins
        $adminUsers = User::with(['profile', 'company'])
            ->where('role', 'super_admin')
            ->orderBy('created_at', 'desc')
            ->get();

        $isSuperAdmin = $this->isSuperAdmin();

        return view('admin.admin-users.index', compact('adminUsers', 'isSuperAdmin'));
    }

    /**
     * Show the form for creating a new admin user.
     */
    public function create()
    {
        // Only Super Admin can create
        if (!$this->isSuperAdmin()) {
            return redirect()->route('admin.admin-users.index')
                ->with('error', 'Only Super Admin can create admin users.');
        }

        return view('admin.admin-users.create');
    }

    /**
     * Store a newly created admin user in storage.
     */
    public function store(Request $request)
    {
        // Only Super Admin can create
        if (!$this->isSuperAdmin()) {
            return redirect()->route('admin.admin-users.index')
                ->with('error', 'Only Super Admin can create admin users.');
        }

        // Check if email is already used by another Super Admin
        $existingSuperAdmin = User::where('role', 'super_admin')
            ->whereHas('profile', function($query) use ($request) {
                $query->where('email', $request->email);
            })
            ->first();

        if ($existingSuperAdmin) {
            return redirect()->back()
                ->withErrors(['email' => 'This email is already used by another Super Admin.'])
                ->withInput();
        }

        $validator = Validator::make($request->all(), [
            'username' => 'nullable|string|max:255|unique:users,username',
            'email' => 'required|email|max:255',
            'full_name' => 'required|string|max:255',
            'mobile' => 'nullable|string|max:20',
            'role' => 'required|in:super_admin',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            // Generate a secure random password
            $password = Str::random(12) . rand(10, 99) . '!@';

            // Generate username from email if not provided
            $username = $request->username;
            if (empty($username)) {
                // Use email as username (or part of it)
                $username = explode('@', $request->email)[0];

                // Ensure uniqueness
                $baseUsername = $username;
                $counter = 1;
                while (User::where('username', $username)->exists()) {
                    $username = $baseUsername . $counter;
                    $counter++;
                }
            }

            // Create the user
            $user = User::create([
                'username' => $username,
                'email' => $request->email, // Store email in users table too for login
                'password' => Hash::make($password),
                'role' => $request->role,
                'is_admin' => true,
                'email_verified' => true,
                'email_verified_at' => now(),
                'account_type' => 'admin',
            ]);

            // Create the user profile
            UserProfile::create([
                'user_id' => $user->id,
                'email' => $request->email,
                'full_name' => $request->full_name,
                'mobile' => $request->mobile,
            ]);

            // Send email notification
            try {
                Mail::to($request->email)->send(new NewAdminUserCreated($user, $password));
            } catch (\Exception $e) {
                // Log the error but don't fail the user creation
                \Log::error('Failed to send admin user creation email: ' . $e->getMessage());
            }

            return redirect()->route('admin.admin-users.index')
                ->with('success', "Admin user created successfully. Login credentials have been sent to {$request->email}.");
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to create admin user: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Display the specified admin user.
     */
    public function show(User $adminUser)
    {
        $adminUser->load(['profile', 'company']);
        $isSuperAdmin = $this->isSuperAdmin();

        return view('admin.admin-users.show', compact('adminUser', 'isSuperAdmin'));
    }

    /**
     * Show the form for editing the specified admin user.
     */
    public function edit(User $adminUser)
    {
        // Only Super Admin can edit
        if (!$this->isSuperAdmin()) {
            return redirect()->route('admin.admin-users.index')
                ->with('error', 'Only Super Admin can edit admin users.');
        }

        $adminUser->load('profile');

        return view('admin.admin-users.edit', compact('adminUser'));
    }

    /**
     * Update the specified admin user in storage.
     */
    public function update(Request $request, User $adminUser)
    {
        // Only Super Admin can update
        if (!$this->isSuperAdmin()) {
            return redirect()->route('admin.admin-users.index')
                ->with('error', 'Only Super Admin can edit admin users.');
        }

        // Check if email is already used by another Super Admin
        $existingSuperAdmin = User::where('role', 'super_admin')
            ->where('id', '!=', $adminUser->id)
            ->whereHas('profile', function($query) use ($request) {
                $query->where('email', $request->email);
            })
            ->first();

        if ($existingSuperAdmin) {
            return redirect()->back()
                ->withErrors(['email' => 'This email is already used by another Super Admin.'])
                ->withInput();
        }

        $validator = Validator::make($request->all(), [
            'username' => 'nullable|string|max:255|unique:users,username,' . $adminUser->id,
            'email' => 'required|email|max:255',
            'full_name' => 'required|string|max:255',
            'mobile' => 'nullable|string|max:20',
            'role' => 'required|in:super_admin',
            'is_blocked' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            // Generate username from email if not provided
            $username = $request->username;
            if (empty($username)) {
                $username = explode('@', $request->email)[0];

                // Ensure uniqueness (excluding current user)
                $baseUsername = $username;
                $counter = 1;
                while (User::where('username', $username)->where('id', '!=', $adminUser->id)->exists()) {
                    $username = $baseUsername . $counter;
                    $counter++;
                }
            }

            // Update user
            $adminUser->update([
                'username' => $username,
                'email' => $request->email, // Update email in users table for login
                'role' => $request->role,
                'is_blocked' => $request->has('is_blocked') ? $request->is_blocked : false,
            ]);

            // Update profile
            if ($adminUser->profile) {
                $adminUser->profile->update([
                    'email' => $request->email,
                    'full_name' => $request->full_name,
                    'mobile' => $request->mobile,
                ]);
            } else {
                UserProfile::create([
                    'user_id' => $adminUser->id,
                    'email' => $request->email,
                    'full_name' => $request->full_name,
                    'mobile' => $request->mobile,
                ]);
            }

            return redirect()->route('admin.admin-users.index')
                ->with('success', 'Admin user updated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to update admin user: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Remove the specified admin user from storage (soft delete/deactivate).
     */
    public function destroy(User $adminUser)
    {
        // Only Super Admin can delete
        if (!$this->isSuperAdmin()) {
            return redirect()->route('admin.admin-users.index')
                ->with('error', 'Only Super Admin can delete admin users.');
        }

        // Prevent deletion of the super admin themselves
        if ($adminUser->id === auth()->id()) {
            return redirect()->route('admin.admin-users.index')
                ->with('error', 'You cannot delete your own account.');
        }

        // Prevent deletion of kesavan@cgvak.com
        if ($adminUser->profile?->email === 'kesavan@cgvak.com' || $adminUser->email === 'kesavan@cgvak.com') {
            return redirect()->route('admin.admin-users.index')
                ->with('error', 'Cannot delete the primary Super Admin account.');
        }

        try {
            // Soft delete by blocking the user instead of hard delete
            $adminUser->update(['is_blocked' => true]);

            return redirect()->route('admin.admin-users.index')
                ->with('success', 'Admin user has been deactivated successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.admin-users.index')
                ->with('error', 'Failed to delete admin user: ' . $e->getMessage());
        }
    }

    /**
     * Reactivate a blocked admin user.
     */
    public function reactivate(User $adminUser)
    {
        // Only Super Admin can reactivate
        if (!$this->isSuperAdmin()) {
            return redirect()->route('admin.admin-users.index')
                ->with('error', 'Only Super Admin can reactivate admin users.');
        }

        try {
            $adminUser->update(['is_blocked' => false]);

            return redirect()->route('admin.admin-users.index')
                ->with('success', 'Admin user has been reactivated successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.admin-users.index')
                ->with('error', 'Failed to reactivate admin user: ' . $e->getMessage());
        }
    }

    /**
     * Reset password for an admin user.
     */
    public function resetPassword(User $adminUser)
    {
        // Only Super Admin can reset passwords
        if (!$this->isSuperAdmin()) {
            return redirect()->route('admin.admin-users.index')
                ->with('error', 'Only Super Admin can reset passwords.');
        }

        try {
            // Generate a new secure password
            $password = Str::random(12) . rand(10, 99) . '!@';

            // Update the password
            $adminUser->update([
                'password' => Hash::make($password),
            ]);

            // Send email with new password
            try {
                Mail::to($adminUser->profile->email)->send(new NewAdminUserCreated($adminUser, $password, true));
            } catch (\Exception $e) {
                \Log::error('Failed to send password reset email: ' . $e->getMessage());
            }

            return redirect()->route('admin.admin-users.show', $adminUser)
                ->with('success', "Password has been reset. New credentials have been sent to {$adminUser->profile->email}.");
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to reset password: ' . $e->getMessage());
        }
    }
}

