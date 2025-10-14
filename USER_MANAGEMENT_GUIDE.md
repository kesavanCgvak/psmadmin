# User Management System Guide

## Overview
The User Management System provides comprehensive CRUD operations for managing users in the admin panel. It includes user creation, editing, viewing, deletion, and status management with full relationship support.

## Features

### Core Functionality
- **Full CRUD Operations**: Create, Read, Update, Delete users
- **Profile Management**: Complete user profile information management
- **Role Management**: User roles (User, Admin, Super Admin)
- **Status Management**: Verify/unverify users, grant/revoke admin privileges
- **Company Association**: Link users to companies
- **Profile Pictures**: Upload and manage user profile pictures
- **DataTables Integration**: Advanced listing with search, sort, and pagination

### User Information Fields

#### Basic User Information
- **Username**: Unique identifier
- **Email**: User's email address
- **Password**: Secure password with confirmation
- **Account Type**: Individual or Company
- **Role**: User, Admin, or Super Admin
- **Company**: Optional company association
- **Status**: Verified/Unverified, Admin/User

#### Profile Information
- **Full Name**: Complete name
- **Mobile**: Phone number
- **Birthday**: Date of birth
- **Address**: Full address
- **City, State, Country**: Location details
- **Zip Code**: Postal code
- **Profile Picture**: User avatar image

## File Structure

### Controller
```
app/Http/Controllers/Admin/UserManagementController.php
```

### Views
```
resources/views/admin/users/
├── index.blade.php      # Users listing with DataTables
├── create.blade.php     # Create new user form
├── edit.blade.php       # Edit user form
└── show.blade.php       # User details view
```

### Routes
```
Route::resource('users', UserManagementController::class);
Route::post('/users/{user}/toggle-verification', 'toggleVerification');
Route::post('/users/{user}/toggle-admin', 'toggleAdmin');
```

## Usage Guide

### Accessing User Management
1. Navigate to the admin panel
2. Click on "USER MANAGEMENT" in the sidebar
3. Select "All Users" to access the user listing

### Creating a New User
1. Click "Add New User" button on the users listing page
2. Fill in the required fields:
   - Username (must be unique)
   - Email (must be unique)
   - Password and confirmation
   - Account type (Individual/Company)
   - Role (User/Admin/Super Admin)
3. Optionally fill in profile information
4. Upload a profile picture if desired
5. Click "Create User"

### Editing a User
1. From the users listing, click the edit (pencil) icon
2. Modify any user information or profile details
3. Leave password blank to keep current password
4. Click "Update User" to save changes

### Viewing User Details
1. Click the view (eye) icon from the users listing
2. View comprehensive user information including:
   - Profile details
   - Account status
   - Activity information
   - Quick action buttons

### Managing User Status
#### Verification Status
- Click the verification status button to toggle between verified/unverified
- Verified users have full access to system features
- Unverified users have limited access

#### Admin Privileges
- Click the admin status button to grant/revoke admin privileges
- Admin users can access admin panel features
- Super Admin users cannot have admin privileges revoked

### Deleting a User
1. Click the delete (trash) icon from the users listing
2. Confirm the deletion in the popup dialog
3. **Warning**: This action cannot be undone and will delete all associated data

## DataTables Features

### Advanced Filtering
- **Search**: Global search across all columns
- **Column Search**: Individual column filtering
- **Sort**: Click column headers to sort
- **Pagination**: Navigate through large datasets

### Export Options
- **CSV Export**: Download user data as CSV
- **Excel Export**: Export to Excel format
- **PDF Export**: Generate PDF reports

### Responsive Design
- Mobile-friendly interface
- Adaptive column display
- Touch-friendly controls

## Security Features

### Access Control
- Admin-only access to user management
- Role-based permissions
- Secure password handling

### Data Validation
- Server-side validation for all inputs
- Unique constraint enforcement
- File upload security

### Audit Trail
- User creation timestamps
- Last updated tracking
- Activity logging

## Relationships

### User Relationships
- **Profile**: One-to-one relationship with UserProfile
- **Company**: Belongs-to relationship with Company
- **Rental Jobs**: One-to-many relationship with RentalJob
- **Supply Jobs**: One-to-many relationship with SupplyJob
- **Comments**: One-to-many relationship with RentalJobComment

### Profile Relationships
- **User**: Belongs-to relationship with User
- **Company**: Through user relationship

## API Endpoints

### Standard CRUD Routes
```
GET    /admin/users           # List users
GET    /admin/users/create    # Show create form
POST   /admin/users           # Store new user
GET    /admin/users/{user}    # Show user details
GET    /admin/users/{user}/edit # Show edit form
PUT    /admin/users/{user}    # Update user
DELETE /admin/users/{user}    # Delete user
```

### Status Management Routes
```
POST /admin/users/{user}/toggle-verification # Toggle verification status
POST /admin/users/{user}/toggle-admin        # Toggle admin privileges
```

## Best Practices

### User Creation
- Always provide a strong password
- Verify user email addresses
- Assign appropriate roles
- Complete profile information when possible

### Security
- Regularly review user permissions
- Monitor admin user accounts
- Remove inactive users
- Keep user data up to date

### Data Management
- Use DataTables features for efficient data browsing
- Export data for backup purposes
- Regular cleanup of unused accounts

## Troubleshooting

### Common Issues
1. **Duplicate Username/Email**: Ensure uniqueness constraints
2. **File Upload Errors**: Check file size and format restrictions
3. **Permission Errors**: Verify user has admin access
4. **Relationship Errors**: Ensure related records exist

### Error Messages
- Validation errors are displayed inline with form fields
- Success messages appear at the top of pages
- Error logs are available in Laravel logs

## Future Enhancements

### Planned Features
- Bulk user operations
- Advanced user filtering
- User activity monitoring
- Automated user cleanup
- User import/export tools
- Advanced reporting features

### Integration Opportunities
- Email notifications for user status changes
- Audit logging for all user operations
- Integration with external user directories
- Advanced role-based access control

## Support

For technical support or feature requests related to the User Management System, please refer to the main project documentation or contact the development team.
