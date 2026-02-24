<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Pro Subrental Marketplace - Rental Request Closed</title>
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
        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8; margin-top: 0;">New Job Offer Received</h2>

                <p>
                    Hello <strong>{{ $receiver_contact_name ?? 'there' }}</strong>,<br><br>
                    The rental request <strong>{{ $rental_job_name }}</strong> has been closed because the user has
                    fulfilled the required quantity ({{ $fulfilled_quantity }} units).
                </p>
                <p>No further offers or negotiations are required.</p>
            </td>
        </tr>
        <tr>
            <td>
                <p style="font-size: 13px; color: #666; line-height: 1.6;">
                    <strong>Pro Subrental Marketplace</strong> connects rental companies to help maximize equipment
                    utilization and simplify collaboration within the rental industry.
                </p>
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
