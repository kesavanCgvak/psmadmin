# Admin User Management - Quick Guide

## 🚀 Getting Started

### Access Admin User Management
1. Log into the PSM Admin Panel
2. Look for **"Admin Users"** in the sidebar (under User Management)
3. Click to view all admin users

---

## 👥 Who Can Do What?

### Super Admin (kesavan@cgvak.com)
✅ Create new admin users
✅ Edit admin user details  
✅ Deactivate/reactivate admin users
✅ Reset passwords
✅ View all admin users
✅ Full control

### Regular Admins
✅ View list of admin users
✅ View admin user details
❌ Cannot create admin users
❌ Cannot edit admin users
❌ Cannot delete admin users

---

## 📋 Common Tasks

### Creating a New Admin User (Super Admin Only)

1. Click **"Admin Users"** in sidebar
2. Click **"Add New Admin User"** button (top right)
3. Fill in the form:
   - **Username:** Unique login name (e.g., john_admin)
   - **Full Name:** User's full name (e.g., John Smith)
   - **Email:** Valid email address (credentials sent here)
   - **Phone:** Optional phone number
   - **Role:** Select Admin or Super Admin
4. Click **"Create Admin User"**
5. ✅ Done! The system will:
   - Generate a secure password
   - Create the account
   - Send welcome email with login credentials

**Email Example:**
```
To: newadmin@example.com
Subject: Welcome to PSM Admin Panel

Your login credentials:
- Admin Panel URL: https://yoursite.com/login
- Username: john_admin
- Password: aBcD1234eFgH56!@
```

---

### Editing an Admin User (Super Admin Only)

1. Go to **"Admin Users"** list
2. Click **Edit** button (pencil icon) on any admin user
3. Update details:
   - Username
   - Full Name
   - Email
   - Phone
   - Role (Admin or Super Admin)
   - Block status (checkbox)
4. Click **"Update Admin User"**
5. ✅ Done! Changes saved

**Note:** Cannot edit the primary Super Admin (kesavan@cgvak.com)

---

### Resetting a Password (Super Admin Only)

1. Go to admin user's details page
2. Click **"Reset Password"** button (yellow button in sidebar)
3. Confirm the action
4. ✅ Done! The system will:
   - Generate a new secure password
   - Send email with new credentials to the user

**When to use:**
- User forgot their password
- Security concern
- User requested password change

---

### Deactivating an Admin User (Super Admin Only)

1. Go to **"Admin Users"** list
2. Click **Deactivate** button (ban icon) on admin user
3. Confirm: "Are you sure you want to deactivate this admin user?"
4. ✅ Done! User is blocked and cannot log in

**What happens:**
- User's row turns red in the list
- Status shows "Blocked"
- User cannot log into the admin panel
- Account data is preserved
- Can be reactivated later

**Cannot deactivate:**
- The primary Super Admin (kesavan@cgvak.com)
- Your own account

---

### Reactivating a Blocked Admin User (Super Admin Only)

1. Go to **"Admin Users"** list
2. Find the blocked user (red row)
3. Click **Reactivate** button (green check icon)
4. ✅ Done! User can log in again

**What happens:**
- User's row returns to normal color
- Status shows "Active"
- User can log into the admin panel

---

### Viewing Admin User Details (All Admins)

1. Go to **"Admin Users"** list
2. Click **View** button (eye icon) on any admin user
3. See all details:
   - Username and Full Name
   - Email and Phone
   - Role and Status
   - Created and Updated dates
   - Account summary
   - Permissions

**Everyone can view details - no permissions needed**

---

## 🎨 Understanding the Interface

### List Page

**Table Columns:**
- **ID** - User ID number
- **Username** - Login name (+ "Primary" badge for kesavan@cgvak.com)
- **Full Name** - User's full name
- **Email** - Email address
- **Phone** - Phone number
- **Role** - Badge: Super Admin (red) or Admin (blue)
- **Status** - Active (green) or Blocked (red)
- **Created At** - When account was created
- **Actions** - View, Edit, Deactivate/Reactivate buttons

**Top Right:**
- "Add New Admin User" button (Super Admin only)

**Features:**
- Search box - type to find users
- Sort - click column headers
- Pagination - navigate through pages

---

### Details Page

**Main Card:**
- All user information
- Role badge and status
- Email verification status
- Account creation and update times

**Sidebar (Super Admin only):**
- Reset Password button
- Reactivate/Deactivate button
- Edit Details button

**Permission Card:**
- Shows what the admin user can do
- Different for Admin vs Super Admin

**Account Summary:**
- Account age
- Current status
- Email verification

---

### Create/Edit Form

**Required Fields (marked with *):**
- Username
- Full Name
- Email
- Role

**Optional Fields:**
- Phone

**Additional (Edit only):**
- Block checkbox

**Sidebar:**
- Security information
- Email notification details
- Role explanations

---

## 🔐 Security Features

### Automatic Password Generation
- **Length:** 12+ characters
- **Includes:** Letters, numbers, special characters
- **Example:** aBcD1234eFgH56!@
- **Sent via:** Email only (not displayed in UI)

### Protected Accounts
- **kesavan@cgvak.com** (Primary Super Admin):
  - Cannot be deleted
  - Cannot be blocked
  - Role cannot be changed
  - Password cannot be reset via UI
- **Your own account:**
  - Cannot delete yourself

### Soft Delete
- Deactivating users doesn't delete data
- Sets `is_blocked = true`
- User cannot log in
- Can be reactivated anytime
- All data preserved

---

## 📧 Email Notifications

### Welcome Email (New Admin)
**Sent to:** New admin user's email
**Subject:** Welcome to PSM Admin Panel
**Contains:**
- Greeting with full name
- Login credentials (username & password)
- Admin panel URL
- Role information
- Security instructions
- "Access Admin Panel" button

### Password Reset Email
**Sent to:** Admin user's email
**Subject:** Your Admin Panel Password Has Been Reset
**Contains:**
- Reset notification
- New login credentials
- Admin panel URL
- Security instructions
- "Access Admin Panel" button

**Note:** Emails are HTML formatted and mobile-friendly

---

## 🎯 Admin Roles Explained

### Admin
**Can:**
- Manage regular users
- Manage companies
- Manage products and equipment
- View rental and supply jobs
- Access reports and analytics

**Cannot:**
- Create/edit/delete admin users
- Access admin user management (view only)

### Super Admin
**Can:**
- Everything admins can do
- **PLUS:**
- Create new admin users
- Edit admin users
- Deactivate/reactivate admin users
- Reset admin passwords
- Full system control

**Special Status:** kesavan@cgvak.com is the primary Super Admin

---

## 💡 Tips & Best Practices

### When Creating Admin Users:
1. Use descriptive usernames (e.g., john_admin, mary_superadmin)
2. Use valid email addresses (credentials sent there)
3. Choose appropriate role (most should be Admin, not Super Admin)
4. Add phone number for contact purposes

### Password Management:
- Never share login credentials
- Tell new admins to change password on first login
- Use password reset if user forgets password
- Don't write passwords down

### Account Management:
- Deactivate (don't delete) users who leave
- Keep admin count reasonable
- Review admin list periodically
- Reactivate when needed

### Email Troubleshooting:
- Check spam folder if email not received
- Verify email address is correct
- Contact super admin if email not sent
- User is still created even if email fails

---

## ⚠️ Important Notes

### Things You Cannot Do:
- ❌ Delete the primary Super Admin (kesavan@cgvak.com)
- ❌ Delete your own account
- ❌ Change primary Super Admin's role
- ❌ Block the primary Super Admin
- ❌ Reset primary Super Admin's password (via UI)

### Things That Happen Automatically:
- ✅ Password generated on create
- ✅ Welcome email sent
- ✅ Account marked as verified
- ✅ Password reset email sent
- ✅ User logs show in system

### View-Only for Regular Admins:
- See everything but cannot change anything
- "Add New Admin User" button hidden
- Edit and Delete buttons hidden
- View-only notice displayed
- Redirected if trying to access restricted pages

---

## 🆘 Troubleshooting

### "Only Super Admin can [action] admin users"
**Problem:** You're trying to create/edit/delete as a regular admin
**Solution:** You need Super Admin access. Contact kesavan@cgvak.com

### Email Not Received
**Problem:** New admin didn't receive welcome email
**Solution:** 
1. Check spam folder
2. Verify email address is correct
3. Check with IT about email server
4. User can still log in (contact super admin for password)

### Cannot Edit Admin User
**Problem:** Edit button not visible or access denied
**Solution:** Only Super Admins can edit. Contact kesavan@cgvak.com

### Blocked User Cannot Log In
**Problem:** Admin user reports cannot log in
**Solution:** Check if account is blocked. Super Admin can reactivate.

### Forgot Password
**Problem:** Admin user forgot password
**Solution:** Super Admin can reset password (sends new password via email)

---

## 📞 Need Help?

### As Regular Admin:
Contact the Super Administrator (kesavan@cgvak.com) for:
- Creating new admin users
- Editing admin user details
- Resetting passwords
- Deactivating/reactivating users

### As Super Admin:
Refer to:
- ADMIN_USER_MANAGEMENT_IMPLEMENTATION.md - Full technical details
- ADMIN_USER_MANAGEMENT_TESTING_CHECKLIST.md - Testing guide
- This guide - Quick reference

---

## ✅ Quick Reference

| Action | Regular Admin | Super Admin |
|--------|--------------|-------------|
| View list | ✅ | ✅ |
| View details | ✅ | ✅ |
| Create user | ❌ | ✅ |
| Edit user | ❌ | ✅ |
| Delete user | ❌ | ✅ |
| Reset password | ❌ | ✅ |
| Reactivate user | ❌ | ✅ |

---

**That's it! You're ready to manage admin users effectively.** 🎉

*For technical details, see ADMIN_USER_MANAGEMENT_IMPLEMENTATION.md*

