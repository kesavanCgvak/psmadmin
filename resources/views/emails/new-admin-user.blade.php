<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }}</title>
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
        .credentials-title {
            margin-top: 0;
        }
        .cta-wrap {
            text-align: center;
            margin: 24px 0;
        }
        .button-cta {
            display: inline-block;
            padding: 14px 28px;
            background-color: #e8d50b;
            color: #000000;
            font-size: 16px;
            font-weight: bold;
            text-decoration: none;
            border-radius: 6px;
        }
        .button-cta:hover {
            background-color: #d4c009;
        }
        .footer p {
            margin: 5px 0;
        }
        .footer .footer-small {
            font-size: 0.85em;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $heading }}</h1>
    </div>

    <div class="content">
        <p>Hello <strong>{{ $greeting_name }}</strong>,</p>

        <p>{{ $body_message }}</p>
        @if(!empty($role_line))
            <p>{!! $role_line !!}</p>
        @endif

        <div class="credentials">
            <h3 class="credentials-title">Your Login Credentials</h3>

            <div class="credential-row">
                <span class="credential-label">Admin Panel URL:</span>
                <span class="credential-value">{{ $adminPanelUrl }}</span>
            </div>

            <div class="credential-row">
                <span class="credential-label">Email (for login):</span>
                <span class="credential-value">{{ $user_email }}</span>
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

        <div class="cta-wrap">
            <a href="{{ $adminPanelUrl }}" class="button-cta">Access Admin Panel</a>
        </div>

        <div class="note">
            <strong>📌 Note:</strong> If you have any questions or need assistance, please contact the Super Administrator.
        </div>

        @if(!empty($capabilities_section))
            {!! $capabilities_section !!}
        @endif
    </div>

    <div class="footer">
        <p>PSM Equipment Rental Platform - Admin Panel</p>
        <p>This is an automated email. Please do not reply to this message.</p>
        <p class="footer-small">© {{ $current_year }} PSM. All rights reserved.</p>
    </div>
</body>
</html>

