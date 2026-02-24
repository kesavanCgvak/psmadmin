<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Imported Products Added - Pro Subrental Marketplace</title>
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

        <!-- Title -->
        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8; margin-top: 0;">Product Import Summary</h2>

                <p>Hello Admin,<br>
                    A batch of products has been imported successfully into the Marketplace.</p>

                <!-- User details -->
                <table width="100%" cellpadding="8" cellspacing="0"
                    style="background: #f1f5fb; border-radius: 8px; margin-top: 15px;">

                    <h4 style="color: #1a73e8; margin-top: 0;">Imported By:</h4>

                    <tr>
                        <td><strong>Name:</strong></td>
                        <td>{{ $user_full_name }}</td>
                    </tr>

                    <tr>
                        <td><strong>Email:</strong></td>
                        <td>{{ $user_email }}</td>
                    </tr>

                    <tr>
                        <td><strong>Company:</strong></td>
                        <td>{{ $company_name }}</td>
                    </tr>

                </table>

                {!! $products_table_html !!}
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
