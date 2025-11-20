<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
            'account_type' => 'nullable|in:Provider,User',
            'role' => 'required|in:admin,user',
            'email_verified' => 'boolean',
            'company_id' => 'required|exists:companies,id',
            'set_as_default_contact' => 'boolean',

            // Profile fields
            'full_name' => 'required|string|max:255',
            'mobile' => 'required|string|max:20',
            'birthday' => "required|date|before_or_equal:$eighteenYearsAgo",
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ], [
            'birthday.before_or_equal' => 'User must be at least 18 years old.',
            'account_type.in' => 'Account type must be either Provider or User.',
            'role.in' => 'Role must be either Admin or User.',
        ]);

        // Auto-assign account_type based on company if not provided
        $company = Company::find($request->company_id);
        $accountType = $request->account_type ?: $company->account_type;

        // Normalize account type to match validation requirements (Provider/User)
        if ($accountType) {
            $accountType = ucfirst(strtolower($accountType)); // Ensure first letter is capitalized
            // Validate the normalized value matches expected values
            if (!in_array($accountType, ['Provider', 'User'])) {
                $accountType = null; // Reset if invalid
            }
        }

        // Validate that account type is set
        if (!$accountType) {
            return redirect()->back()
                ->withErrors(['account_type' => 'Account type could not be determined. Please ensure the selected company has an account type.'])
                ->withInput();
        }

        // Create user
        $userData = [
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'account_type' => $accountType,
            'role' => $request->role,
            'is_admin' => $request->role === 'admin',
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

            // Store in public/images/profile_pictures directory
            $uploadPath = public_path('images/profile_pictures');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            // Move file to public/images/profile_pictures
            $file->move($uploadPath, $filename);

            // Store path relative to public directory
            $profileData['profile_picture'] = 'images/profile_pictures/' . $filename;
        }

        UserProfile::create($profileData);

        // Handle default contact assignment
        $setAsDefaultContact = $request->boolean('set_as_default_contact');
        $shouldSetAsDefault = false;

        // Case A: Company has NO default_contact_id
        // Automatically set the newly created user as default_contact_id (even if checkbox is not checked)
        if (empty($company->default_contact_id)) {
            $shouldSetAsDefault = true;
        }
        // Case B: Company already HAS a default_contact_id
        // Only update if checkbox IS checked
        elseif ($setAsDefaultContact) {
            $shouldSetAsDefault = true;
        }

        // Update company's default_contact_id if needed
        if ($shouldSetAsDefault) {
            $company->default_contact_id = $user->id;
            $company->save();
        }

        // Send emails to the newly created USER (not admin)
        try {
            // Send user credentials email to USER - ALL THE TIME (regardless of verification status)
            // Email is sent TO the user's email address ($request->email)
            Mail::send('emails.registrationSuccess', [
                'name' => $request->full_name,
                'email' => $request->email,
                'username' => $request->username,
                'password' => $request->password,
                'account_type' => $accountType,
                'login_url' => env('APP_URL'),
            ], function ($message) use ($request) {
                $message->to($request->email); // TO: User's email address
                $message->subject('Welcome to ProSub Marketplace - Account Created Successfully');
                $message->from(config('mail.from.address'), config('mail.from.name')); // FROM: System email
            });

            // Send verification email to USER - ONLY if Email Verified checkbox is NOT checked
            if (!$user->email_verified) {
                $token = Str::random(30);
                $user->update(['token' => $token]);

                Mail::send('emails.verificationEmail', [
                    'token' => $token,
                    'username' => $request->username
                ], function ($message) use ($request) {
                    $message->to($request->email); // TO: User's email address
                    $message->subject('Email Verification - ProSub Marketplace');
                    $message->from(config('mail.from.address'), config('mail.from.name')); // FROM: System email
                });
            }
        } catch (\Exception $e) {
            // Log email sending error but don't fail the user creation
            \Log::error('Failed to send email to user', [
                'user_id' => $user->id,
                'user_email' => $request->email, // User's email where email was supposed to be sent
                'error' => $e->getMessage()
            ]);
        }

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
                // Ensure uniqueness against user_profiles.email excluding current user's profile
                Rule::unique('user_profiles', 'email')->where(function ($query) use ($user) {
                    return $query->where('user_id', '!=', $user->id);
                })
            ],
            'password' => 'nullable|string|min:8|confirmed',
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

        // Update user (account type is derived from company and not editable here)
        $userData = [
            'username' => $request->username,
            'email' => $request->email,
            'role' => $request->role,
            'is_admin' => $request->boolean('is_admin'),
            'email_verified' => $request->boolean('email_verified'),
            'company_id' => $request->company_id,
        ];

        // If company is selected, derive account_type from the company; if none, clear it
        if ($request->filled('company_id')) {
            $companyForType = Company::find($request->company_id);
            if ($companyForType) {
                $userData['account_type'] = $companyForType->account_type;
            }
        } else {
            $userData['account_type'] = null;
        }

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
            'email' => $request->email,
            'mobile' => $request->mobile,
            'birthday' => $request->birthday,
        ];

        // Handle profile picture upload
        if ($request->hasFile('profile_picture')) {
            // Delete old profile picture
            if ($user->profile && $user->profile->profile_picture) {
                $oldPicturePath = public_path($user->profile->profile_picture);
                if (file_exists($oldPicturePath)) {
                    unlink($oldPicturePath);
                }
            }

            $file = $request->file('profile_picture');
            $filename = time() . '_' . $file->getClientOriginalName();

            // Store in public/images/profile_pictures directory
            $uploadPath = public_path('images/profile_pictures');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            // Move file to public/images/profile_pictures
            $file->move($uploadPath, $filename);

            // Store path relative to public directory
            $profileData['profile_picture'] = 'images/profile_pictures/' . $filename;
        }

        if ($user->profile) {
            $user->profile->update($profileData);
        } else {
            $profileData['user_id'] = $user->id;
            UserProfile::create($profileData);
        }

        // Preserve filter parameters from the request if they exist
        $filterParams = $request->only(['country', 'city', 'state', 'search', 'page']);
        $redirectUrl = route('admin.users.index');

        if (!empty(array_filter($filterParams))) {
            $redirectUrl .= '?' . http_build_query(array_filter($filterParams));
        }

        return redirect($redirectUrl)
            ->with('success', 'User updated successfully.');
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        // Prevent deleting super admins
        if ($user->role === 'super_admin') {
            return redirect()->route('admin.users.index')
                ->with('error', 'Super admin users cannot be deleted.');
        }

        // Prevent deleting own account
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'You cannot delete your own account.');
        }

        // Delete profile picture
        if ($user->profile && $user->profile->profile_picture) {
            $picturePath = public_path($user->profile->profile_picture);
            if (file_exists($picturePath)) {
                unlink($picturePath);
            }
        }

        // Delete user profile
        $user->profile()->delete();

        // Delete user
        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', 'User deleted successfully.');
    }

    /**
     * Bulk delete multiple users.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id'
        ]);

        $userIds = $request->user_ids;
        $deletedCount = 0;
        $errors = [];

        foreach ($userIds as $userId) {
            $user = User::find($userId);

            if (!$user) {
                continue;
            }

            // Prevent deleting super admins
            if ($user->role === 'super_admin') {
                $errors[] = "Cannot delete super admin: {$user->username}";
                continue;
            }

            // Prevent deleting own account
            if ($user->id === auth()->id()) {
                $errors[] = "Cannot delete your own account: {$user->username}";
                continue;
            }

            try {
                // Delete profile picture
                if ($user->profile && $user->profile->profile_picture) {
                    $picturePath = public_path($user->profile->profile_picture);
                    if (file_exists($picturePath)) {
                        unlink($picturePath);
                    }
                }

                // Delete user profile
                $user->profile()->delete();

                // Delete user
                $user->delete();
                $deletedCount++;
            } catch (\Exception $e) {
                $errors[] = "Failed to delete user: {$user->username} - " . $e->getMessage();
            }
        }

        if ($deletedCount > 0) {
            $message = "Successfully deleted {$deletedCount} user(s).";
            if (!empty($errors)) {
                $message .= " Errors: " . implode(', ', $errors);
            }

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'deleted_count' => $deletedCount,
                    'errors' => $errors
                ]);
            }

            return redirect()->route('admin.users.index')
                ->with('success', $message);
        } else {
            $message = 'No users were deleted. ' . (!empty($errors) ? implode(', ', $errors) : '');

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'deleted_count' => 0,
                    'errors' => $errors
                ]);
            }

            return redirect()->route('admin.users.index')
                ->with('error', $message);
        }
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
            'account_type' => $company->account_type,
        ]);
    }
}
