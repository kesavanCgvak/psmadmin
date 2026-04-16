# Create User - Developer Reference

## Quick Reference for Developers

This document provides technical details for developers who need to maintain, debug, or extend the Create User functionality.

---

## 📁 File Structure

```
app/Http/Controllers/Admin/
├── UserManagementController.php      (Main user CRUD + AJAX endpoints)
└── CompanyManagementController.php   (Company CRUD + redirect logic)

routes/
└── web.php                            (Route definitions)

resources/views/admin/
├── users/
│   └── create.blade.php              (User creation form with JS)
└── companies/
    └── create.blade.php              (Company creation form)

app/Models/
├── User.php                          (User model)
├── UserProfile.php                   (UserProfile model)
├── Company.php                       (Company model)
└── Country.php                       (Country model with phone_code)
```

---

## 🔌 API Endpoints

### Username Availability Check
```http
GET /admin/ajax/check-username?username={value}&user_id={id}
```

**Parameters:**
- `username` (required): Username to check
- `user_id` (optional): Current user ID for edit mode

**Response:**
```json
{
    "available": true,
    "message": "Username is available."
}
```

**Controller Method:**
```php
UserManagementController::checkUsername(Request $request)
```

---

### Phone Format Retrieval
```http
GET /admin/ajax/company/{company_id}/phone-format
```

**Parameters:**
- `company_id` (required): Company ID (route parameter)

**Response:**
```json
{
    "country": "United States",
    "state": "California",
    "phone_format": "+1 (###) ###-####",
    "country_code": "1"
}
```

**Controller Method:**
```php
UserManagementController::getPhoneFormat(Company $company)
```

---

## 🎯 Validation Rules

### Backend Validation (Laravel)

```php
// UserManagementController::store()
[
    'username' => 'required|string|max:255|unique:users',
    'email' => 'required|email|max:255|unique:user_profiles,email',
    'password' => 'required|string|min:8|confirmed',
    'account_type' => 'required|in:provider,user',
    'company_id' => 'required|exists:companies,id',
    'full_name' => 'required|string|max:255',
    'mobile' => 'required|string|max:20',
    'birthday' => "required|date|before_or_equal:{$eighteenYearsAgo}",
    'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
]
```

### Custom Validation Messages

```php
[
    'birthday.before_or_equal' => 'User must be at least 18 years old.',
    'account_type.in' => 'Account type must be either Provider or User.',
]
```

---

## 🔄 Data Flow

### Creating User with Existing Company

```
User clicks "Create User"
    ↓
Loads Create User Form
    ↓
Selects Company from Dropdown
    ↓
AJAX: Fetch phone format
    ↓
Fills user information
    ↓
AJAX: Check username availability
    ↓
Real-time password strength validation
    ↓
Submits form
    ↓
Server-side validation
    ↓
Create User record
    ↓
Create UserProfile record
    ↓
Redirect to Users Index
```

### Creating User with New Company

```
User clicks "Create User"
    ↓
Fills partial user information
    ↓
Clicks "Add New Company"
    ↓
JavaScript: Save form to localStorage
    ↓
Redirect to Create Company (with flag)
    ↓
User fills company information
    ↓
Submits company form
    ↓
Server: Create Company
    ↓
Check return_to_user_create flag
    ↓
Redirect to Create User with company_id
    ↓
JavaScript: Restore form from localStorage
    ↓
Company pre-selected in dropdown
    ↓
User completes remaining fields
    ↓
Submits form
    ↓
Create User + UserProfile
    ↓
Redirect to Users Index
```

---

## 💾 LocalStorage Schema

### Stored Data
```javascript
{
    username: "string",
    account_type: "provider|user",
    full_name: "string",
    email: "string",
    mobile: "string",
    birthday: "YYYY-MM-DD",
    email_verified: boolean
}
```

### Storage Key
```javascript
localStorage.key = 'userFormData'
```

### Storage Operations
```javascript
// Save
localStorage.setItem('userFormData', JSON.stringify(formData));

// Retrieve
const savedData = localStorage.getItem('userFormData');
const formData = JSON.parse(savedData);

// Clear
localStorage.removeItem('userFormData');
```

---

## 🎨 Frontend JavaScript Functions

### Main Functions (create.blade.php)

#### Save Form Data
```javascript
function saveFormData() {
    formData = {
        username: $('#username').val(),
        account_type: $('#account_type').val(),
        full_name: $('#full_name').val(),
        email: $('#email').val(),
        mobile: $('#mobile').val(),
        birthday: $('#birthday').val(),
        email_verified: $('#email_verified').is(':checked')
    };
    localStorage.setItem('userFormData', JSON.stringify(formData));
}
```

#### Restore Form Data
```javascript
function restoreFormData() {
    const savedData = localStorage.getItem('userFormData');
    if (savedData) {
        formData = JSON.parse(savedData);
        // Populate form fields
    }
}
```

#### Username Validation
```javascript
// Debounced AJAX call (500ms delay)
$('#username').on('input', function() {
    // Clear previous timeout
    // Set new timeout
    // Make AJAX request
    // Update UI based on response
});
```

#### Password Strength
```javascript
$('#password').on('input', function() {
    const password = $(this).val();
    let strength = 0;
    
    // Calculate strength (0-100)
    if (password.length >= 8) strength += 25;
    if (password.match(/[a-z]/)) strength += 25;
    if (password.match(/[A-Z]/)) strength += 25;
    if (password.match(/[0-9]/)) strength += 15;
    if (password.match(/[^a-zA-Z0-9]/)) strength += 10;
    
    // Update UI
});
```

---

## 🗄️ Database Schema

### Users Table
```sql
users
├── id (PK)
├── username (unique)
├── email
├── password (hashed)
├── account_type (enum: provider, user)
├── role (auto-set: admin, user)
├── is_admin (boolean)
├── company_id (FK)
├── email_verified (boolean)
├── email_verified_at (datetime)
└── timestamps
```

### User Profiles Table
```sql
user_profiles
├── id (PK)
├── user_id (FK, unique)
├── full_name
├── email (unique)
├── mobile
├── birthday
├── profile_picture (path)
└── timestamps
```

### Companies Table
```sql
companies
├── id (PK)
├── name
├── country_id (FK)
├── state_id (FK)
└── ... (other fields)
```

### Countries Table
```sql
countries
├── id (PK)
├── name
├── iso_code
├── phone_code (used for format)
└── ... (other fields)
```

---

## 🔧 Backend Logic

### Role Assignment Logic

```php
// In UserManagementController::store()
$role = $request->account_type === 'provider' ? 'admin' : 'user';
$is_admin = $request->account_type === 'provider' ? true : false;
```

### Age Validation Logic

```php
// Calculate date 18 years ago
$eighteenYearsAgo = now()->subYears(18)->format('Y-m-d');

// Validation rule
'birthday' => "required|date|before_or_equal:$eighteenYearsAgo"
```

### Company Redirect Logic

```php
// In CompanyManagementController::store()
if ($request->input('return_to_user_create')) {
    return redirect()
        ->route('admin.users.create', ['company_id' => $company->id])
        ->with('success', 'Company created successfully.');
}
```

---

## 🎯 Phone Format Mapping

### Hardcoded Country Formats

```php
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

// Fallback format
$phoneFormat = $phoneFormats[$countryName] ?? "+$countryCode ###########";
```

### Adding New Country Formats

To add a new country format:
1. Open `UserManagementController.php`
2. Locate the `getPhoneFormat()` method
3. Add entry to `$phoneFormats` array:
```php
'Country Name' => '+XX #### ######',
```

---

## 🐛 Debugging Tips

### Username Validation Not Working

**Check:**
1. Browser console for JavaScript errors
2. Network tab for AJAX request/response
3. Route is properly defined in `web.php`
4. Controller method exists and is public

**Debug Code:**
```javascript
// Add to AJAX success handler
console.log('Username check response:', response);
```

### Form Data Not Persisting

**Check:**
1. Browser allows localStorage
2. No errors in console
3. Data is being saved before redirect
4. Data is being restored after redirect

**Debug Code:**
```javascript
// After saving
console.log('Saved data:', localStorage.getItem('userFormData'));

// After restoring
console.log('Restored data:', formData);
```

### Phone Format Not Displaying

**Check:**
1. Company has valid country_id
2. Country has phone_code in database
3. AJAX request completes successfully
4. Response contains expected data

**Debug Code:**
```javascript
// In AJAX success handler
console.log('Phone format response:', response);
```

---

## 🔒 Security Considerations

### CSRF Protection
```php
@csrf  // Always include in forms
```

### Password Hashing
```php
Hash::make($request->password)  // Never store plain passwords
```

### Input Sanitization
```php
// Laravel automatically sanitizes
// Additional validation through rules
```

### SQL Injection Prevention
```php
// Use Eloquent ORM
User::where('username', $username)->exists();  // Safe

// Avoid raw queries
DB::raw("SELECT * FROM users WHERE username = '$username'");  // Unsafe
```

---

## 📈 Performance Optimization

### AJAX Debouncing
```javascript
let usernameCheckTimeout;

// Clear previous timeout
clearTimeout(usernameCheckTimeout);

// Set new timeout (500ms)
usernameCheckTimeout = setTimeout(function() {
    // Make AJAX call
}, 500);
```

### Database Queries
```php
// Eager loading
$companies = Company::with(['country', 'state'])->get();

// Avoid N+1 queries
$company->load(['country', 'state']);
```

---

## 🧪 Testing Scenarios

### Unit Tests

```php
// Test username uniqueness
public function test_username_must_be_unique()
{
    User::create(['username' => 'testuser']);
    
    $response = $this->post('/admin/users', [
        'username' => 'testuser',
        // ... other fields
    ]);
    
    $response->assertSessionHasErrors('username');
}

// Test age validation
public function test_user_must_be_18_years_old()
{
    $response = $this->post('/admin/users', [
        'birthday' => now()->subYears(17)->format('Y-m-d'),
        // ... other fields
    ]);
    
    $response->assertSessionHasErrors('birthday');
}
```

### Feature Tests

```php
public function test_can_create_user_with_provider_account()
{
    $company = Company::factory()->create();
    
    $response = $this->post('/admin/users', [
        'username' => 'provider1',
        'account_type' => 'provider',
        'company_id' => $company->id,
        // ... other fields
    ]);
    
    $this->assertDatabaseHas('users', [
        'username' => 'provider1',
        'role' => 'admin',
        'is_admin' => true,
    ]);
}
```

### JavaScript Tests (Example with Jest)

```javascript
describe('Password Strength Calculator', () => {
    test('weak password returns < 40', () => {
        const strength = calculatePasswordStrength('weak');
        expect(strength).toBeLessThan(40);
    });
    
    test('strong password returns >= 70', () => {
        const strength = calculatePasswordStrength('StrongP@ss123');
        expect(strength).toBeGreaterThanOrEqual(70);
    });
});
```

---

## 🔄 Extension Points

### Adding New Validation

**Backend (Controller):**
```php
$request->validate([
    'new_field' => 'required|custom_rule',
]);
```

**Frontend (JavaScript):**
```javascript
$('#new_field').on('input', function() {
    // Custom validation logic
    // Update UI accordingly
});
```

### Adding New Account Type

1. Update validation rule:
```php
'account_type' => 'required|in:provider,user,new_type',
```

2. Update role logic:
```php
$role = match($request->account_type) {
    'provider' => 'admin',
    'new_type' => 'moderator',
    default => 'user'
};
```

3. Update frontend dropdown:
```html
<option value="new_type">New Type</option>
```

### Adding New Phone Format

```php
// In getPhoneFormat() method
$phoneFormats = [
    'New Country' => '+XX (###) ### ####',
    // ... existing formats
];
```

---

## 📝 Code Patterns

### Controller Response Pattern
```php
// Success redirect
return redirect()->route('route.name')
    ->with('success', 'Message here');

// Error redirect
return redirect()->back()
    ->withErrors($validator)
    ->withInput();

// JSON response
return response()->json([
    'key' => 'value',
    'status' => 'success'
]);
```

### JavaScript AJAX Pattern
```javascript
$.ajax({
    url: "{{ route('route.name') }}",
    method: 'GET|POST',
    data: { key: value },
    success: function(response) {
        // Handle success
    },
    error: function(xhr) {
        // Handle error
    }
});
```

---

## 🆘 Common Issues & Solutions

| Issue | Cause | Solution |
|-------|-------|----------|
| 500 Error on submit | Server-side validation failing | Check error logs, fix validation |
| AJAX not working | Route not defined | Add route to web.php |
| Form data lost | localStorage disabled | Check browser settings |
| Phone format blank | Country has no phone_code | Update country record |
| Username always "taken" | Query logic error | Check where clause in controller |

---

## 📚 Related Documentation

- [Laravel Validation](https://laravel.com/docs/validation)
- [jQuery AJAX](https://api.jquery.com/jquery.ajax/)
- [AdminLTE Components](https://adminlte.io/docs)
- [Bootstrap Forms](https://getbootstrap.com/docs/4.6/components/forms/)

---

## 🔍 Code Review Checklist

When reviewing changes to this feature:

- [ ] CSRF token included in forms
- [ ] All inputs properly validated (backend)
- [ ] SQL injection prevention (use Eloquent)
- [ ] XSS prevention (use Blade {{ }} syntax)
- [ ] Password hashing applied
- [ ] Error messages are user-friendly
- [ ] Success messages are clear
- [ ] AJAX endpoints return proper status codes
- [ ] JavaScript doesn't block UI
- [ ] LocalStorage usage is appropriate
- [ ] Browser compatibility considered
- [ ] Responsive design maintained
- [ ] Accessibility standards met

---

**Maintained By**: Development Team  
**Last Updated**: October 16, 2025  
**Version**: 1.0  
**Status**: Production Ready

