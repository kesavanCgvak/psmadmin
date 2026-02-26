<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>New Registration - Pro Subrental Marketplace</title>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 25px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="Pro Subrental Marketplace"
                    style="max-width: 200px; height: auto;">
            </td>
        </tr>

        <!-- Body -->
        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8; margin-top: 0;">New User Registered</h2>
                <p>Hello there,<br>A new User has been registered to the Marketplace.</p>
                <table width="100%" cellpadding="8" cellspacing="0"
                    style="background: #f1f5fb; border-radius: 8px; margin-top: 20px;">

                    <tr>
                        <td><strong>Company Name:</strong></td>
                        <td>{{ $company_name }}</td>
                    </tr>
                    <tr>
                        <td><strong>Account Type:</strong></td>
                        <td>{{ $account_type }}</td>
                    </tr>
                    <tr>
                        <td><strong>User Name:</strong></td>
                        <td>{{ $username }}</td>
                    </tr>
                    <tr>
                        <td><strong>User Email:</strong></td>
                        <td>{{ $email }}</td>
                    </tr>
                    <tr>
                        <td><strong>Region:</strong></td>
                        <td>{{ $region_name }}</td>
                    </tr>
                    <tr>
                        <td><strong>Country:</strong></td>
                        <td>{{ $country_name }}</td>
                    </tr>
                    <tr>
                        <td><strong>State/Province:</strong></td>
                        <td>{{ $state_name }}</td>
                    </tr>
                    <tr>
                        <td><strong>City:</strong></td>
                        <td>{{ $city_name }}</td>
                    </tr>
                    <tr>
                        <td><strong>Contact Phone:</strong></td>
                        <td>{{ $mobile }}</td>
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
