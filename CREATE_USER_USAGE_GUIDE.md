# Create User - Usage Guide

## Quick Start Guide

This guide will walk you through the enhanced Create User functionality with all its new features.

---

## 📍 Accessing the Create User Page

Navigate to: **Admin Panel → Users → Create New User**

Or directly: `http://yourapp.com/admin/users/create`

---

## 🎯 Step-by-Step Process

### Step 1: Select or Create Company

**Option A: Select Existing Company**
1. Click on the **Company** dropdown at the top of the form
2. Choose a company from the list
3. You'll see the company's location and phone format displayed below

**Option B: Create New Company**
1. Click the **"Add New Company"** button next to the dropdown
2. You'll be redirected to the Create Company page
3. Fill in all company information
4. Click **"Create Company"**
5. You'll be automatically redirected back to Create User
6. The new company will be pre-selected
7. Your previous form data will be restored

> **💡 Tip**: Always create/select the company first to see the correct phone number format!

---

### Step 2: Enter Username

1. Type your desired username in the **Username** field
2. Wait a moment (500ms) for the system to check availability
3. Look for the status indicator:
   - 🔄 **Checking...** - Validation in progress
   - ✅ **Username is available** - Good to go!
   - ❌ **Username is already taken** - Try another one

> **⚠️ Important**: Don't submit the form until you see the green checkmark!

---

### Step 3: Choose Account Type

Select the appropriate account type:

| Account Type | Description | Auto-Assigned Role | Permissions |
|-------------|-------------|-------------------|-------------|
| **Provider** | Company representative or service provider | Admin | Full access to company features |
| **User** | Regular user or customer | User | Standard user permissions |

> **Note**: The role is automatically assigned based on the account type. No need to select it separately!

---

### Step 4: Create Password

1. Enter your password in the **Password** field
2. Watch the **Password Strength** indicator update in real-time:
   - 🔴 **Weak** (< 40 points) - Add more complexity
   - 🟡 **Medium** (40-69 points) - Acceptable
   - 🟢 **Strong** (≥ 70 points) - Excellent!

**Strength Calculation:**
- ✓ Length ≥ 8 characters (+25 points)
- ✓ Lowercase letters (+25 points)
- ✓ Uppercase letters (+25 points)
- ✓ Numbers (+15 points)
- ✓ Special characters (+10 points)

3. Re-enter the password in **Confirm Password**
4. Look for the match indicator:
   - ✅ **Passwords match** - Perfect!
   - ❌ **Passwords do not match** - Try again

> **💡 Tip**: Aim for a "Strong" password for better security!

---

### Step 5: Fill Profile Information

#### Full Name *(Required)*
- Enter the user's complete name
- Example: "John Smith"

#### Email *(Required)*
- Enter a valid email address
- Must be unique (not already used)
- Example: "john.smith@example.com"

#### Mobile Number *(Required)*
- Enter the mobile number
- Follow the format shown below the field
- Format is based on the selected company's country
- Examples:
  - **USA**: +1 (555) 123-4567
  - **UK**: +44 2012 345678
  - **India**: +91 98765 43210

> **📱 Phone Format Helper**: The system automatically shows you the correct format based on your company's location!

#### Birthday *(Required)*
- Select the user's date of birth
- **Must be at least 18 years old**
- Date picker won't allow dates less than 18 years ago
- After selection, the system will show:
  - ✅ **Age: 25 years** - Valid
  - ❌ **Must be at least 18 years old** - Invalid

---

### Step 6: Optional Settings

#### Profile Picture
- Click **"Choose File"** to upload a profile picture
- Accepted formats: JPEG, PNG, JPG, GIF
- Maximum size: 2MB

#### Email Verified
- Check this box if the user's email is already verified
- Useful for administrative creation of pre-verified accounts

---

### Step 7: Submit

1. Review all fields for accuracy
2. Ensure all validations show green checkmarks
3. Click **"Create User"**
4. If validation fails, check the error messages
5. If successful, you'll be redirected to the Users list

---

## 🎨 Visual Indicators Guide

### Input Field States

| State | Visual | Meaning |
|-------|--------|---------|
| **Normal** | Gray border | No input yet or neutral |
| **Valid** | Green border + checkmark | Input is valid |
| **Invalid** | Red border + X | Input needs correction |
| **Checking** | Spinner icon | Validation in progress |

### Status Icons

| Icon | Meaning |
|------|---------|
| ℹ️ | Information or hint |
| ✅ | Success or valid |
| ❌ | Error or invalid |
| 🔄 | Loading or checking |
| 📱 | Phone-related info |

---

## ⚠️ Common Validation Errors

### Username Issues
- **"Username is already taken"**
  - Solution: Try a different username
  
### Password Issues
- **"The password field is required"**
  - Solution: Enter a password (minimum 8 characters)
  
- **"The password confirmation does not match"**
  - Solution: Make sure both password fields match exactly

### Birthday Issues
- **"User must be at least 18 years old"**
  - Solution: Select a date that makes the user 18 or older

### Company Issues
- **"The company field is required"**
  - Solution: Select a company from the dropdown or create a new one

### Email Issues
- **"The email has already been taken"**
  - Solution: Use a different email address

---

## 💾 Form Data Persistence

### How It Works
When you click **"Add New Company"**, the system:
1. Saves your current form data to browser storage
2. Redirects you to Create Company page
3. After creating the company, brings you back
4. Automatically restores your saved data
5. Pre-selects the newly created company

### What Gets Saved
- ✓ Username
- ✓ Account Type
- ✓ Full Name
- ✓ Email
- ✓ Mobile Number
- ✓ Birthday
- ✓ Email Verified checkbox

### What Doesn't Get Saved
- ✗ Passwords (for security)
- ✗ Profile Picture (file uploads)

---

## 🔍 Validation Timing

| Field | When Validated | Type |
|-------|----------------|------|
| Username | As you type (500ms delay) | Real-time |
| Password | On every character | Real-time |
| Confirm Password | On every character | Real-time |
| Birthday | When date is selected | On change |
| Mobile Format | When company changes | On change |
| All Fields | On form submit | Server-side |

---

## 📊 Account Type Comparison

### Provider Account
- **Best for**: Company representatives, service providers
- **Permissions**: Admin access to company features
- **Can do**:
  - Manage company equipment
  - Manage other company users
  - Create and manage jobs
  - Update company information

### User Account
- **Best for**: Regular customers, end users
- **Permissions**: Standard user access
- **Can do**:
  - Browse and search equipment
  - Create rental requests
  - Manage own profile
  - Rate and review companies

---

## 🎯 Best Practices

### Username
- ✓ Use alphanumeric characters
- ✓ Keep it professional
- ✓ Make it memorable
- ✗ Don't use special characters if possible
- ✗ Don't use spaces

### Password
- ✓ Use a mix of uppercase and lowercase
- ✓ Include numbers and special characters
- ✓ Make it at least 12 characters for better security
- ✗ Don't use common words or patterns
- ✗ Don't reuse passwords from other sites

### Mobile Number
- ✓ Include country code
- ✓ Follow the format shown
- ✓ Use the actual mobile number
- ✗ Don't use landline numbers
- ✗ Don't use fake numbers

### Email
- ✓ Use a valid, working email
- ✓ Use professional email for providers
- ✓ Ensure the user has access to it
- ✗ Don't use temporary email services
- ✗ Don't use shared email addresses

---

## 🚨 Troubleshooting

### Problem: Username validation not working
**Solution**: Check your internet connection. The validation requires AJAX calls to the server.

### Problem: Form data not restored after creating company
**Solution**: Ensure your browser allows localStorage. Check browser privacy settings.

### Problem: Phone format not showing
**Solution**: Make sure the company has a valid country assigned. Update the company if needed.

### Problem: Can't select recent dates for birthday
**Solution**: This is intentional. Users must be at least 18 years old.

### Problem: Password strength stays at "Weak"
**Solution**: Add more variety to your password (uppercase, numbers, special characters).

---

## 🎓 Training Checklist

Use this checklist to ensure users understand the system:

- [ ] Can access the Create User page
- [ ] Understands how to select/create a company
- [ ] Knows how to check username availability
- [ ] Can create a strong password
- [ ] Understands Provider vs User account types
- [ ] Can fill in all required profile fields
- [ ] Understands the phone format requirements
- [ ] Can validate user is 18+ years old
- [ ] Knows what gets saved when navigating to Create Company
- [ ] Can successfully create a user

---

## 📞 Support

If you encounter issues not covered in this guide:

1. Check the browser console for JavaScript errors
2. Verify all required fields are filled
3. Ensure proper network connectivity
4. Clear browser cache and try again
5. Contact system administrator

---

## 📈 Success Metrics

You'll know you're successful when:
- ✅ All validation indicators show green checkmarks
- ✅ No red error messages appear
- ✅ Form submits without issues
- ✅ User appears in the Users list
- ✅ User can log in with created credentials

---

**Last Updated**: October 16, 2025  
**Version**: 1.0  
**Difficulty Level**: Intermediate

