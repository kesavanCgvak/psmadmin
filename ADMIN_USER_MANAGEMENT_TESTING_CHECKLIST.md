# Admin User Management - Testing Checklist

## 📋 Pre-Testing Setup

Before testing, ensure:
- [ ] Cache cleared: `php artisan config:clear` and `php artisan route:clear`
- [ ] Logged into admin panel
- [ ] Have access to test email account
- [ ] Database has test data
- [ ] Browser developer tools open (to check for errors)

---

## 🔐 Authentication & Authorization Tests

### As Super Admin (kesavan@cgvak.com)

#### Menu Access
- [ ] "Admin Users" menu item visible in sidebar
- [ ] Menu item has shield icon
- [ ] Menu item color is red
- [ ] Menu item is clickable

#### Page Access
- [ ] Can access `/admin/admin-users` (index)
- [ ] Can access `/admin/admin-users/create` (create form)
- [ ] Can access `/admin/admin-users/{id}` (show details)
- [ ] Can access `/admin/admin-users/{id}/edit` (edit form)

#### Button Visibility
- [ ] "Add New Admin User" button visible on index page
- [ ] "Edit" buttons visible on index page
- [ ] "Deactivate" buttons visible on index page
- [ ] Quick action buttons visible on details page

---

### As Regular Admin

#### Menu Access
- [ ] "Admin Users" menu item visible in sidebar
- [ ] Menu item has shield icon
- [ ] Menu item is clickable

#### Page Access
- [ ] Can access `/admin/admin-users` (index)
- [ ] Can access `/admin/admin-users/{id}` (show details)
- [ ] Cannot access `/admin/admin-users/create` (redirected with error)
- [ ] Cannot access `/admin/admin-users/{id}/edit` (redirected with error)

#### Button Visibility
- [ ] "Add New Admin User" button NOT visible
- [ ] "Edit" buttons NOT visible
- [ ] "Deactivate" buttons NOT visible
- [ ] Quick action buttons NOT visible on details page
- [ ] View-only notice displayed

#### Access Protection
- [ ] Accessing create URL directly → redirected to index
- [ ] Accessing edit URL directly → redirected to index
- [ ] Accessing delete endpoint → redirected to index
- [ ] Error message: "Only Super Admin can [action] admin users"

---

## 📊 Index Page Tests

### Display Tests
- [ ] Page loads without errors
- [ ] Page title is "Admin User Management"
- [ ] Card title is "All Admin Users"
- [ ] Table has correct headers (9 columns)
- [ ] Admin users are listed
- [ ] Data populates correctly

### DataTable Tests
- [ ] DataTable initializes
- [ ] Search box appears
- [ ] Search works (type username, results filter)
- [ ] Column sorting works (click headers)
- [ ] Pagination appears (if >10 users)
- [ ] Pagination works (navigate pages)
- [ ] Entries selector works (10/25/50/100)
- [ ] "Showing X to Y of Z entries" displays correctly

### Data Display Tests
- [ ] User IDs display correctly
- [ ] Usernames display correctly
- [ ] "Primary" badge shows for kesavan@cgvak.com
- [ ] Full names display correctly (or N/A if null)
- [ ] Emails display correctly (or N/A if null)
- [ ] Phone numbers display correctly (or N/A if null)
- [ ] Role badges display with correct colors:
  - [ ] Super Admin = red (badge-danger)
  - [ ] Admin = blue (badge-primary)
- [ ] Status badges display with correct colors:
  - [ ] Active = green (badge-success)
  - [ ] Blocked = red (badge-danger)
- [ ] Verified badge shows for verified users
- [ ] Created dates formatted correctly (M d, Y)
- [ ] Blocked users have red row background

### Action Button Tests
- [ ] View button (eye icon) appears for all users
- [ ] Edit button (pencil icon) appears (Super Admin only)
- [ ] Deactivate button (ban icon) appears for active users (Super Admin only)
- [ ] Reactivate button (check icon) appears for blocked users (Super Admin only)
- [ ] No deactivate button for kesavan@cgvak.com
- [ ] No deactivate button for current user's own account

### Responsive Tests
- [ ] Desktop (>1200px): All columns visible
- [ ] Tablet (768-1199px): Some columns collapse
- [ ] Mobile (<768px): Only essential columns visible
- [ ] Expand button (+) works on mobile
- [ ] Expanded row shows all data

---

## ➕ Create Page Tests (Super Admin Only)

### Page Load
- [ ] Page loads without errors
- [ ] Page title is "Create New Admin User"
- [ ] Card title is "Admin User Information"
- [ ] Form displays correctly
- [ ] Information sidebar displays

### Form Fields
- [ ] Username field present (required)
- [ ] Full Name field present (required)
- [ ] Email field present (required)
- [ ] Phone field present (optional)
- [ ] Role dropdown present (required)
- [ ] Role options: "Admin" and "Super Admin"
- [ ] All fields have labels
- [ ] Required fields marked with red asterisk (*)
- [ ] Helper text displays below fields

### Validation Tests

#### Empty Form Submission
- [ ] Submit empty form
- [ ] Validation errors appear at top
- [ ] Individual field errors appear below fields
- [ ] Form retains focus on first error
- [ ] No data saved to database

#### Username Validation
- [ ] Empty username → "Username is required" error
- [ ] Duplicate username → "Username has already been taken" error
- [ ] Valid username → No error

#### Email Validation
- [ ] Empty email → "Email is required" error
- [ ] Invalid format (test@) → "Invalid email format" error
- [ ] Duplicate email → "Email has already been taken" error
- [ ] Valid email → No error

#### Full Name Validation
- [ ] Empty full name → "Full name is required" error
- [ ] Valid name → No error

#### Role Validation
- [ ] Empty role → "Role is required" error
- [ ] Valid role selection → No error

### Success Flow
- [ ] Fill all required fields with valid data
- [ ] Click "Create Admin User"
- [ ] Success message appears
- [ ] Redirected to index page
- [ ] New user appears in list
- [ ] Database record created (check users table)
- [ ] Database profile created (check user_profiles table)
- [ ] Password is hashed in database (not plain text)
- [ ] email_verified = true
- [ ] email_verified_at set to current time
- [ ] is_admin = true

### Email Notification
- [ ] Email sent to new admin's email address
- [ ] Email subject: "Welcome to PSM Admin Panel"
- [ ] Email contains username
- [ ] Email contains generated password
- [ ] Email contains admin panel URL
- [ ] Email contains role information
- [ ] Email is HTML formatted
- [ ] Email is mobile responsive
- [ ] Email sent even if delivery fails (user created anyway)

### UI Elements
- [ ] Information sidebar displays security info
- [ ] Information sidebar displays email notification info
- [ ] Information sidebar displays role explanations
- [ ] Auto-generation notice displays
- [ ] "Create Admin User" button works
- [ ] "Cancel" button redirects to index

---

## ✏️ Edit Page Tests (Super Admin Only)

### Page Load
- [ ] Page loads without errors
- [ ] Page title is "Edit Admin User"
- [ ] Card title shows "Edit: [username]"
- [ ] Form pre-filled with current data
- [ ] Account information sidebar displays

### Pre-filled Data
- [ ] Username field has current username
- [ ] Full Name field has current full name
- [ ] Email field has current email
- [ ] Phone field has current phone (or empty)
- [ ] Role dropdown has current role selected
- [ ] Block checkbox matches current blocked status

### Validation Tests

#### Username Update
- [ ] Change to duplicate username → error
- [ ] Change to valid new username → success
- [ ] Keep same username → success

#### Email Update
- [ ] Change to duplicate email → error
- [ ] Change to invalid format → error
- [ ] Change to valid new email → success
- [ ] Keep same email → success

#### Role Update
- [ ] Change Admin to Super Admin → success
- [ ] Change Super Admin to Admin → success
- [ ] Cannot change kesavan@cgvak.com role (disabled)

#### Block Status
- [ ] Check block checkbox → user blocked on update
- [ ] Uncheck block checkbox → user unblocked on update
- [ ] Cannot block kesavan@cgvak.com (disabled)

### Success Flow
- [ ] Update user details
- [ ] Click "Update Admin User"
- [ ] Success message appears
- [ ] Redirected to index page
- [ ] Changes reflected in list
- [ ] Database updated (check users table)
- [ ] Profile updated (check user_profiles table)

### Protected Account Tests
- [ ] Editing kesavan@cgvak.com:
  - [ ] Role dropdown disabled
  - [ ] Block checkbox disabled
  - [ ] Hidden inputs preserve original values
  - [ ] Warning message displayed

### Quick Actions Sidebar
- [ ] Account information displays correctly
- [ ] User ID shown
- [ ] Account type shown
- [ ] Current status shown
- [ ] Email verified status shown
- [ ] Created date shown
- [ ] Updated date shown
- [ ] "Reset Password" button visible (not for kesavan@cgvak.com)
- [ ] "Reactivate" or "Deactivate" button visible
- [ ] "Edit Details" link works

### UI Elements
- [ ] "Update Admin User" button works
- [ ] "View Details" button navigates to show page
- [ ] "Back to List" button navigates to index
- [ ] Warning message about password reset displays

---

## 👁️ Show Page Tests (All Admins)

### Page Load
- [ ] Page loads without errors
- [ ] Page title is "Admin User Details"
- [ ] Card title shows username
- [ ] Role badge displays in header
- [ ] Primary badge shows for kesavan@cgvak.com

### Data Display
- [ ] User ID displays
- [ ] Username displays
- [ ] Full name displays (or N/A)
- [ ] Email displays and is clickable (mailto: link)
- [ ] Phone displays (or N/A)
- [ ] Role badge displays with correct color
- [ ] Account type badge displays
- [ ] Status badge displays (Active/Blocked)
- [ ] Email verified status displays
- [ ] Verification date displays (if verified)
- [ ] Created date displays (formatted)
- [ ] Updated date displays (formatted)

### Quick Actions Sidebar (Super Admin Only)
- [ ] "Reset Password" button visible (not for kesavan@cgvak.com)
- [ ] "Reactivate" or "Deactivate" button visible
- [ ] "Edit Details" button visible
- [ ] Protected account notice for kesavan@cgvak.com

### Permissions Card
- [ ] Card displays
- [ ] Correct permissions list for role:
  - [ ] Super Admin: Full list of permissions
  - [ ] Admin: Regular admin permissions
- [ ] "Cannot manage admin users" note for regular admins

### Account Summary Card
- [ ] Card displays
- [ ] Account age shows (human readable)
- [ ] Account status shows with correct icon
- [ ] Email status shows with correct icon

### Footer Buttons
- [ ] "Edit" button visible (Super Admin only)
- [ ] "Back to List" button works

### Success Messages
- [ ] Success message from password reset displays
- [ ] Success message from reactivate displays

---

## 🔑 Password Reset Tests (Super Admin Only)

### From Show Page
- [ ] Click "Reset Password" button
- [ ] Confirmation dialog appears: "Are you sure...?"
- [ ] Click OK → password reset
- [ ] Click Cancel → no action

### Success Flow
- [ ] Password reset successful
- [ ] Success message: "Password has been reset. New credentials sent to [email]"
- [ ] Database password updated (hash changed)
- [ ] Email sent to admin user
- [ ] Email subject: "Your Admin Panel Password Has Been Reset"
- [ ] Email contains new password
- [ ] Email contains username
- [ ] Email contains admin panel URL

### Protected Account
- [ ] Cannot reset kesavan@cgvak.com password
- [ ] Button not visible for kesavan@cgvak.com

### From Edit Page
- [ ] "Reset Password" button works
- [ ] Same behavior as show page

---

## 🚫 Deactivate Tests (Super Admin Only)

### From Index Page
- [ ] Click "Deactivate" button (ban icon)
- [ ] Confirmation dialog appears
- [ ] Click OK → user deactivated
- [ ] Click Cancel → no action

### Success Flow
- [ ] User deactivated successfully
- [ ] Success message: "Admin user has been deactivated successfully"
- [ ] Redirected to index page
- [ ] User row now has red background
- [ ] Status shows "Blocked" badge (red)
- [ ] Deactivate button replaced with Reactivate button
- [ ] Database updated (is_blocked = true)
- [ ] User cannot log in

### Protected Accounts
- [ ] Cannot deactivate kesavan@cgvak.com (no button)
- [ ] Cannot deactivate own account (no button)
- [ ] Attempting via direct URL → error message

### From Edit Page
- [ ] "Deactivate Account" button works
- [ ] Same behavior as index page

---

## ✅ Reactivate Tests (Super Admin Only)

### From Index Page
- [ ] Find blocked user (red row)
- [ ] Click "Reactivate" button (check icon)
- [ ] No confirmation dialog (direct action)

### Success Flow
- [ ] User reactivated successfully
- [ ] Success message: "Admin user has been reactivated successfully"
- [ ] Redirected to index page
- [ ] User row returns to normal color
- [ ] Status shows "Active" badge (green)
- [ ] Reactivate button replaced with Deactivate button
- [ ] Database updated (is_blocked = false)
- [ ] User can log in

### From Edit Page
- [ ] "Reactivate Account" button works
- [ ] Same behavior as index page

---

## 📧 Email Tests

### Welcome Email (New Admin)
- [ ] Email sends within 1 minute of creation
- [ ] Received in inbox (not spam)
- [ ] Subject correct: "Welcome to PSM Admin Panel"
- [ ] Greeting uses full name
- [ ] Username displays correctly
- [ ] Password displays correctly
- [ ] Admin panel URL correct and clickable
- [ ] Role information correct
- [ ] Security warning present
- [ ] "Access Admin Panel" button works
- [ ] Permissions list correct for role
- [ ] Footer present with branding
- [ ] HTML renders correctly
- [ ] Mobile responsive (test on mobile device)
- [ ] No broken images
- [ ] All links work

### Password Reset Email
- [ ] Email sends within 1 minute of reset
- [ ] Received in inbox (not spam)
- [ ] Subject correct: "Your Admin Panel Password Has Been Reset"
- [ ] Reset notification present
- [ ] Username displays correctly
- [ ] New password displays correctly
- [ ] Admin panel URL correct and clickable
- [ ] Security warning present
- [ ] "Access Admin Panel" button works
- [ ] Footer present with branding
- [ ] HTML renders correctly
- [ ] Mobile responsive

### Email Failure Handling
- [ ] User created even if email fails to send
- [ ] Error logged to laravel.log
- [ ] No error shown to admin creating user
- [ ] Success message still displays

---

## 🔒 Security Tests

### Password Generation
- [ ] Password is at least 12 characters
- [ ] Password contains letters
- [ ] Password contains numbers
- [ ] Password contains special characters
- [ ] Password is different each time
- [ ] Password is hashed in database (bcrypt)
- [ ] Password not displayed anywhere after creation
- [ ] Password only sent via email

### Authorization
- [ ] Regular admin blocked from create page
- [ ] Regular admin blocked from edit page
- [ ] Regular admin blocked from delete action
- [ ] Regular admin blocked from reset password
- [ ] Regular admin blocked from reactivate
- [ ] Accessing protected URLs directly → redirected
- [ ] Error messages display on unauthorized access

### Protected Accounts
- [ ] kesavan@cgvak.com cannot be deleted
- [ ] kesavan@cgvak.com cannot be blocked
- [ ] kesavan@cgvak.com role cannot be changed
- [ ] Super Admin cannot delete own account
- [ ] Attempting protected actions → error message

### Input Validation
- [ ] SQL injection attempts blocked
- [ ] XSS attempts sanitized
- [ ] Invalid email formats rejected
- [ ] Duplicate usernames rejected
- [ ] Duplicate emails rejected
- [ ] Required fields enforced
- [ ] Max lengths enforced

### Session & Auth
- [ ] Must be logged in to access any page
- [ ] Must be verified to access any page
- [ ] Logout → redirected to login
- [ ] Login as different user → correct permissions

---

## 🎨 UI/UX Tests

### Visual Design
- [ ] Matches AdminLTE theme
- [ ] Consistent with other admin pages
- [ ] Color scheme correct (red for danger, blue for primary, etc.)
- [ ] Icons display correctly (Font Awesome)
- [ ] Badges render correctly
- [ ] Cards have proper styling
- [ ] Tables bordered and striped
- [ ] Buttons have correct colors

### Responsive Design
- [ ] Desktop (1920x1080): Full layout
- [ ] Laptop (1366x768): Adjusted layout
- [ ] Tablet Portrait (768x1024): Stacked columns
- [ ] Mobile (375x667): Single column, collapsible table
- [ ] No horizontal scrolling
- [ ] Touch targets large enough on mobile
- [ ] Text readable on all screen sizes

### Forms
- [ ] Labels clearly visible
- [ ] Required fields marked
- [ ] Placeholders helpful
- [ ] Error messages clear
- [ ] Success messages visible
- [ ] Input fields proper size
- [ ] Dropdowns work on mobile
- [ ] Checkboxes clickable

### DataTable
- [ ] Responsive plugin works
- [ ] Controls accessible on mobile
- [ ] Search box usable
- [ ] Pagination clickable
- [ ] No layout breaks

### Messages
- [ ] Success alerts green with check icon
- [ ] Error alerts red with ban icon
- [ ] Messages dismissible (X button)
- [ ] Messages auto-dismiss (if configured)
- [ ] Messages clearly readable

---

## 🐛 Error Handling Tests

### Form Errors
- [ ] Empty required fields → validation error
- [ ] Invalid email format → validation error
- [ ] Duplicate username → validation error
- [ ] Duplicate email → validation error
- [ ] Error summary at top of form
- [ ] Individual field errors below fields
- [ ] Form retains input values
- [ ] First error field focused

### Authorization Errors
- [ ] Unauthorized access → redirect to index
- [ ] Error message displays
- [ ] User not stuck in redirect loop

### Database Errors
- [ ] Connection error → graceful failure
- [ ] Constraint violation → error message
- [ ] Database down → error page

### 404 Errors
- [ ] Non-existent user ID → 404 page
- [ ] Invalid route → 404 page

---

## 🔄 Edge Cases

### Data Edge Cases
- [ ] User with no profile → displays N/A
- [ ] User with no email → displays N/A
- [ ] User with no phone → displays N/A
- [ ] User with long name → truncates or wraps
- [ ] User with special characters in name → displays correctly
- [ ] Empty list (no admin users) → "No data available" message

### Action Edge Cases
- [ ] Rapid clicks on create → only one user created
- [ ] Rapid clicks on delete → only deactivated once
- [ ] Create user with email that was just deleted → works
- [ ] Edit user to own username → works (no duplicate error)
- [ ] Edit user to own email → works (no duplicate error)

### Permission Edge Cases
- [ ] Super Admin logged in on two tabs → both work
- [ ] Regular admin promoted to Super Admin → sees new buttons after refresh
- [ ] Super Admin demoted to Admin → buttons hidden after refresh

---

## ⚡ Performance Tests

### Page Load Times
- [ ] Index page loads in <2 seconds
- [ ] Create page loads in <2 seconds
- [ ] Edit page loads in <2 seconds
- [ ] Show page loads in <2 seconds

### DataTable Performance
- [ ] 10 users: Fast
- [ ] 100 users: Acceptable
- [ ] 1000 users: Still functional (pagination helps)

### Email Sending
- [ ] Email queued (doesn't block page load)
- [ ] Multiple emails can be sent
- [ ] Email failures don't break user creation

---

## ✅ Final Verification

### Production Readiness
- [ ] No console errors (browser console)
- [ ] No PHP errors (laravel.log)
- [ ] No linter errors
- [ ] Routes registered correctly
- [ ] Menu item appears
- [ ] All features work as expected
- [ ] Documentation complete
- [ ] Testing checklist completed

### User Acceptance
- [ ] Super Admin can perform all CRUD operations
- [ ] Regular Admin can view but not edit
- [ ] Emails are received
- [ ] Passwords work for login
- [ ] UI is intuitive
- [ ] No confusing error messages
- [ ] Help text is clear

---

## 📝 Test Results

**Date Tested:** _________________

**Tested By:** _________________

**Browser:** _________________

**Results:** 
- [ ] All tests passed
- [ ] Some tests failed (list below)
- [ ] Major issues found (list below)

**Issues Found:**
1. _______________________________________________
2. _______________________________________________
3. _______________________________________________

**Notes:**
_______________________________________________
_______________________________________________
_______________________________________________

---

## ✨ Success Criteria

Testing is successful when:

✅ All Super Admin functions work
✅ Regular Admin correctly restricted
✅ Emails send and are received
✅ Passwords generate and work
✅ Forms validate correctly
✅ Protected accounts cannot be deleted
✅ UI is responsive on all devices
✅ No PHP or JavaScript errors
✅ DataTables function properly
✅ Success/error messages display
✅ Authorization enforced
✅ All edge cases handled

---

**Testing Complete!** 🎉

*If all checks pass, the feature is ready for production deployment.*

