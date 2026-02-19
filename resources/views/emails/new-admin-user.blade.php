<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isPasswordReset ? 'Password Reset' : 'Welcome to PSM Admin Panel' }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #007bff;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background-color: #f8f9fa;
            padding: 30px;
            border: 1px solid #dee2e6;
        }
        .credentials {
            background-color: white;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #007bff;
            border-radius: 4px;
        }
        .credential-row {
            margin: 10px 0;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 3px;
        }
        .credential-label {
            font-weight: bold;
            color: #495057;
            display: inline-block;
            width: 120px;
        }
        .credential-value {
            color: #007bff;
            font-family: monospace;
            font-size: 1.1em;
        }
        .button {
            display: inline-block;
            background-color: #007bff;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .button:hover {
            background-color: #0056b3;
        }
        .footer {
            background-color: #343a40;
            color: #fff;
            padding: 20px;
            text-align: center;
            font-size: 0.9em;
            border-radius: 0 0 5px 5px;
        }
        .warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .note {
            background-color: #d1ecf1;
            border-left: 4px solid #17a2b8;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $isPasswordReset ? 'Password Reset' : 'Welcome to PSM Admin Panel' }}</h1>
    </div>

    <div class="content">
        @if($isPasswordReset)
            <p>Hello <strong>{{ $user->profile->full_name ?? $user->username }}</strong>,</p>

            <p>Your admin panel password has been reset by the Super Administrator.</p>
        @else
            <p>Hello <strong>{{ $user->profile->full_name ?? $user->username }}</strong>,</p>

            <p>Welcome to the PSM Equipment Rental Admin Panel! Your administrator account has been created successfully.</p>

            <p>You have been granted <strong>{{ ucfirst(str_replace('_', ' ', $user->role)) }}</strong> access to the system.</p>
        @endif

        <div class="credentials">
            <h3 style="margin-top: 0;">Your Login Credentials</h3>

            <div class="credential-row">
                <span class="credential-label">Admin Panel URL:</span>
                <span class="credential-value">{{ $adminPanelUrl }}</span>
            </div>

            <div class="credential-row">
                <span class="credential-label">Email (for login):</span>
                <span class="credential-value">{{ $user->profile->email ?? $user->email }}</span>
            </div>

            <div class="credential-row">
                <span class="credential-label">Password:</span>
                <span class="credential-value">{{ $password }}</span>
            </div>
        </div>

        <div class="note">
            <strong>📌 Important:</strong> Use your <strong>email address</strong> to log in, not a username.
        </div>

        <div class="warning">
            <strong>⚠️ Important Security Notice:</strong>
            <ul>
                <li>Please change your password after your first login</li>
                <li>Do not share these credentials with anyone</li>
                <li>Keep this email secure or delete it after changing your password</li>
            </ul>
        </div>

        <div style="text-align: center; margin: 24px 0;">
            <a href="{{ $adminPanelUrl }}" style="display: inline-block; padding: 14px 28px; background-color: #e8d50b; color: #000000; font-size: 16px; font-weight: bold; text-decoration: none; border-radius: 6px;">Access Admin Panel</a>
        </div>

        <div class="note">
            <strong>📌 Note:</strong> If you have any questions or need assistance, please contact the Super Administrator.
        </div>

        @if(!$isPasswordReset)
            <h3>What You Can Do:</h3>
            <ul>
                <li>Manage user accounts and companies</li>
                <li>View and monitor rental jobs and supply offers</li>
                <li>Manage products, equipment, and inventory</li>
                <li>Access comprehensive reporting and analytics</li>
                @if($user->role === 'super_admin')
                    <li><strong>Full administrative control including managing other admin users</strong></li>
                @endif
            </ul>
        @endif
    </div>

    <div class="footer">
        <p>PSM Equipment Rental Platform - Admin Panel</p>
        <p style="margin: 5px 0;">This is an automated email. Please do not reply to this message.</p>
        <p style="margin: 5px 0; font-size: 0.85em;">© {{ date('Y') }} PSM. All rights reserved.</p>
    </div>
</body>
</html>

