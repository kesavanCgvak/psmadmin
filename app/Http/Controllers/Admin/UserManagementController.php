<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

class UserManagementController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index()
    {
        $users = User::with(['profile', 'company'])
            ->where('role', '!=', 'super_admin')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.users.index', compact('users'));
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(Request $request)
    {
        $companies = Company::orderBy('name')->get();
        $selectedCompanyId = $request->query('company_id');

        return view('admin.users.create', compact('companies', 'selectedCompanyId'));
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        // Validate birthday to ensure user is at least 18 years old
        $eighteenYearsAgo = now()->subYears(18)->format('Y-m-d');

        $request->validate([
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|email|max:255|unique:user_profiles,email',
            'password' => 'required|string|min:8|confirmed',
            'account_type' => 'required|in:provider,user',
            'email_verified' => 'boolean',
            'company_id' => 'required|exists:companies,id',

            // Profile fields
            'full_name' => 'required|string|max:255',
            'mobile' => 'required|string|max:20',
            'birthday' => "required|date|before_or_equal:$eighteenYearsAgo",
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ], [
            'birthday.before_or_equal' => 'User must be at least 18 years old.',
            'account_type.in' => 'Account type must be either Provider or User.',
        ]);

        // Determine role based on account_type
        $role = $request->account_type === 'provider' ? 'admin' : 'user';

        // Create user
        $userData = [
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'account_type' => $request->account_type,
            'role' => $role,
            'is_admin' => $request->account_type === 'provider' ? true : false,
            'email_verified' => $request->boolean('email_verified'),
            'company_id' => $request->company_id,
        ];

        // Set email_verified_at timestamp if user is verified
        if ($request->boolean('email_verified')) {
            $userData['email_verified_at'] = now();
        }

        $user = User::create($userData);

        // Create user profile
        $profileData = [
            'user_id' => $user->id,
            'full_name' => $request->full_name,
            'email' => $request->email,
            'mobile' => $request->mobile,
            'birthday' => $request->birthday,
        ];

        // Handle profile picture upload
        if ($request->hasFile('profile_picture')) {
            $file = $request->file('profile_picture');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('profile_pictures', $filename, 'public');
            $profileData['profile_picture'] = $path;
        }

        UserProfile::create($profileData);

        return redirect()->route('admin.users.index')
            ->with('success', 'User created successfully.');
    }

    /**
     * Display the specified user.
     */
    public function show(User $user)
    {
        $user->load(['profile', 'company']);
        return view('admin.users.show', compact('user'));
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user)
    {
        $companies = Company::orderBy('name')->get();
        $user->load(['profile', 'company']);
        return view('admin.users.edit', compact('user', 'companies'));
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        $request->validate([
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users')->ignore($user->id)
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id)
            ],
            'password' => 'nullable|string|min:8|confirmed',
            'account_type' => 'required|in:individual,company,provider',
            'role' => 'required|in:user,admin,super_admin',
            'is_admin' => 'boolean',
            'email_verified' => 'boolean',
            'company_id' => 'nullable|exists:companies,id',

            // Profile fields
            'full_name' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:20',
            'birthday' => 'nullable|date',
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        // Update user
        $userData = [
            'username' => $request->username,
            'email' => $request->email,
            'account_type' => $request->account_type,
            'role' => $request->role,
            'is_admin' => $request->boolean('is_admin'),
            'email_verified' => $request->boolean('email_verified'),
            'company_id' => $request->company_id,
        ];

        if ($request->filled('password')) {
            $userData['password'] = Hash::make($request->password);
        }

        // Update email_verified_at timestamp
        if ($request->boolean('email_verified')) {
            $userData['email_verified_at'] = now();
        } else {
            $userData['email_verified_at'] = null;
        }

        $user->update($userData);

        // Update or create user profile
        $profileData = [
            'full_name' => $request->full_name,
            'mobile' => $request->mobile,
            'birthday' => $request->birthday,
        ];

        // Handle profile picture upload
        if ($request->hasFile('profile_picture')) {
            // Delete old profile picture
            if ($user->profile && $user->profile->profile_picture) {
                Storage::disk('public')->delete($user->profile->profile_picture);
            }

            $file = $request->file('profile_picture');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('profile_pictures', $filename, 'public');
            $profileData['profile_picture'] = $path;
        }

        if ($user->profile) {
            $user->profile->update($profileData);
        } else {
            $profileData['user_id'] = $user->id;
            UserProfile::create($profileData);
        }

        return redirect()->route('admin.users.index')
            ->with('success', 'User updated successfully.');
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        // Delete profile picture
        if ($user->profile && $user->profile->profile_picture) {
            Storage::disk('public')->delete($user->profile->profile_picture);
        }

        // Delete user profile
        $user->profile()->delete();

        // Delete user
        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', 'User deleted successfully.');
    }

    /**
     * Toggle user verification status.
     */
    public function toggleVerification(User $user)
    {
        $isVerified = !$user->email_verified;
        $updateData = ['email_verified' => $isVerified];

        // Update email_verified_at timestamp
        if ($isVerified) {
            $updateData['email_verified_at'] = now();
        } else {
            $updateData['email_verified_at'] = null;
        }

        $user->update($updateData);

        $status = $isVerified ? 'verified' : 'unverified';
        return redirect()->back()
            ->with('success', "User {$status} successfully.");
    }

    /**
     * Toggle user admin status.
     */
    public function toggleAdmin(User $user)
    {
        $user->update(['is_admin' => !$user->is_admin]);

        $status = $user->is_admin ? 'granted admin privileges' : 'revoked admin privileges';
        return redirect()->back()
            ->with('success', "User {$status} successfully.");
    }

    /**
     * Check if username is available (AJAX endpoint).
     */
    public function checkUsername(Request $request)
    {
        $username = $request->query('username');
        $userId = $request->query('user_id'); // For edit mode

        if (empty($username)) {
            return response()->json([
                'available' => false,
                'message' => 'Username is required.'
            ]);
        }

        $query = User::where('username', $username);

        // Exclude current user if editing
        if ($userId) {
            $query->where('id', '!=', $userId);
        }

        $exists = $query->exists();

        return response()->json([
            'available' => !$exists,
            'message' => $exists ? 'Username is already taken.' : 'Username is available.'
        ]);
    }

    /**
     * Get phone format information for a company (AJAX endpoint).
     */
    public function getPhoneFormat(Company $company)
    {
        $company->load(['country', 'state']);

        $phoneFormat = '';
        $countryCode = '';

        if ($company->country) {
            $countryName = $company->country->name;
            $countryCode = $company->country->phone_code ?? '';

            // Basic phone format patterns by country (can be expanded)
            $phoneFormats = [
                'United States' => '+1 (###) ###-####',
                'Canada' => '+1 (###) ###-####',
                'United Kingdom' => '+44 #### ######',
                'Australia' => '+61 # #### ####',
                'Germany' => '+49 ### #######',
                'France' => '+33 # ## ## ## ##',
                'India' => '+91 ##### #####',
                'China' => '+86 ### #### ####',
                'Japan' => '+81 ##-####-####',
                'Brazil' => '+55 (##) #####-####',
            ];

            $phoneFormat = $phoneFormats[$countryName] ?? "+$countryCode ###########";
        }

        return response()->json([
            'country' => $company->country ? $company->country->name : null,
            'state' => $company->state ? $company->state->name : null,
            'phone_format' => $phoneFormat,
            'country_code' => $countryCode,
        ]);
    }
}
