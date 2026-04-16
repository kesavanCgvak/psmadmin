<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Rental Job Cancelled - Pro Subrental Marketplace</title>
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
            <td style="padding: 30px; color: #333; font-size: 15px;">
                <p>Hi <strong>{{ $receiver->contact_name }}</strong>,</p>

                <p>The following rental job has updated basic details:</p>

                <table width="100%" cellpadding="6" style="background:#f1f1f1; border-radius:8px;">
                    <tr>
                        <td><strong>Rental Job:</strong></td>
                        <td>{{ $rentalJob->name }}</td>
                    </tr>
                    <tr>
                        <td><strong>Delivery Address:</strong></td>
                        <td>{{ $rentalJob->delivery_address }}</td>
                    </tr>
                    <tr>
                        <td><strong>From Date:</strong></td>
                        <td>{{ $rentalJob->from_date }}</td>
                    </tr>
                    <tr>
                        <td><strong>To Date:</strong></td>
                        <td>{{ $rentalJob->to_date }}</td>
                    </tr>
                </table>
                <br>
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
