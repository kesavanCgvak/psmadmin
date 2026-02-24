<!DOCTYPE html
    PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Welcome to ProSub Marketplace</title>
    <style type="text/css">
        body {
            margin: 0;
            padding: 0;
            background-color: #f6f6f6;
            font-family: Arial, sans-serif;
        }

        table {
            border-collapse: collapse;
        }

        td {
            padding: 0;
            text-align: center;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            margin-top: 20px;
            background-color: #e8d50b;
            color: #000 !important;
            font-size: 16px;
            font-weight: bold;
            text-decoration: none;
            border-radius: 6px;
        }
    </style>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">
        <!-- Header with Logo -->
        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="prosubmarket.com"
                    style="max-width: 200px; height: auto; display: block; margin: 0 auto;">
            </td>
        </tr>
        <tr>
            <td align="center" bgcolor="#ffffff">
                <h1 style="color: #333333;">Welcome to ProSub Marketplace!</h1>
            </td>
        </tr>
        <tr>
            <td bgcolor="#ffffff" style="padding: 20px 30px 40px 30px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                    <tr>
                        <td style="color: #555555; font-size: 16px; line-height: 24px; text-align: left;">
                            <p><b>Dear {{ $name }},</b></p>
                            <p><b>Welcome to Pro Subrental Marketplace (PSM)!</b></p>
                            <p><b>Your account has been successfully created on our platform.</b></p>

                            <p><strong>Account Details:</strong></p>
                            <ul style="color: #555555; font-size: 14px; line-height: 22px;">
                                <li><strong>Name:</strong> {{ $name }}</li>
                                <li><strong>Email:</strong> {{ $email }}</li>
                                <li><strong>Username:</strong> {{ $username }}</li>
                                <li><strong>Password:</strong> {{ $password }}</li>
                                <li><strong>Account Type:</strong> {{ $account_type }}</li>
                            </ul>
                            <p style="color: #d9534f; font-size: 14px; font-weight: bold;">
                                <strong>Important:</strong> Please save your login credentials and change your password after first login for security purposes.
                            </p>

                            <p><b>We are simplifying the live events subrental process.</b></p>
                            <p>From now on, whenever your company needs extra equipment for a job, simply launch PSM,
                                search for what you need, and in seconds you'll get a list of rental companies who stock
                                what you're looking for in the places where you need it.</p>
                            <p>Feel free to contact suppliers directly, or save time by creating a list of gear and
                                sending quote requests with just one click.</p>
                            <p><b>No commissions and no hidden charges!</b></p>
                            <p>At PSM, our goal is to create new revenue streams by introducing you to new people in the
                                industry looking for the equipment sitting idle in your warehouse.</p>
                            <p><b>You can now log in to your account:</b></p>
                            <p style="text-align: center; margin: 24px 0;">
                                <a href="{{ $login_url }}" style="display: inline-block; padding: 14px 28px; background-color: #e8d50b; color: #000000; font-size: 16px; font-weight: bold; text-decoration: none; border-radius: 6px;">Login to Your Account</a>
                            </p>
                            <p>If the button above doesn't work, copy and paste this link into your browser:</p>
                            <p style="word-break: break-all; font-size: 14px; color: #0066cc;">
                                {{ $login_url }}
                            </p>
                            <p>Best regards,<br>Pro Subrental Marketplace Team</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <!-- Footer -->
        <tr>
            <td style="background-color:#726d6c; padding: 18px; text-align:center; color:#ffffff; font-size: 13px;">
                &copy; {{ $current_year }} Pro Subrental Marketplace. All rights reserved.
            </td>
        </tr>
    </table>
</body>

</html>
