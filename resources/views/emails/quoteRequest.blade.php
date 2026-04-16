<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>New Quote Request - Pro Subrental Marketplace</title>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="Pro Subrental Marketplace"
                    style="max-width: 200px; height: auto; display: block; margin: 0 auto;">
            </td>
        </tr>

        <!-- Body -->
        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8; margin-top: 0;">New Quote Request from {{ $user_company }}</h2>

                <p>
                    Hello <strong>{{ $provider_contact_name }}</strong>,<br><br>
                    You've received a new quote request from
                    <strong>{{ $user_company }}</strong> via
                    <strong>Pro Subrental Marketplace</strong>.
                </p>

                <!-- User Contact Info -->
                <table cellpadding="5" cellspacing="0" style="margin: 15px 0; font-size: 14px;">
                    <tr>
                        <td><strong>Contact Person:</strong></td>
                        <td>{{ $user_name }}</td>
                    </tr>
                    <tr>
                        <td><strong>Email:</strong></td>
                        <td>{{ $user_email }}</td>
                    </tr>
                    <tr>
                        <td><strong>Phone:</strong></td>
                        <td>{{ $user_mobile }}</td>
                    </tr>
                    <tr>
                        <td><strong>Company:</strong></td>
                        <td>{{ $user_company }}</td>
                    </tr>
                </table>

                <!-- Rental Details -->
                <h3 style="color: #1a73e8; margin-top: 30px;">Rental Details</h3>
                <p style="margin-bottom: 10px;">
                    <strong>Rental Name:</strong> {{ $rental_name }}<br>
                    <strong>Rental Dates:</strong> {{ $from_date }} to {{ $to_date }}<br>
                    <strong>Delivery Address:</strong> {{ $delivery_address }}
                </p>

                {!! $global_message_section !!}

                {!! $offer_requirements_section !!}

                {!! $private_message_section !!}

                {!! $initial_offer_section !!}

                {!! $products_table_html !!}

                <p style="margin-top: 25px; font-size: 15px; line-height: 1.5;">
                    Please review the request and respond promptly to increase your chance of securing this rental
                    opportunity with <strong>{{ $user_company }}</strong>.
                </p>

                {!! $similar_request_note !!}

                <p style="font-size: 13px; color: #666; line-height: 1.6;">
                    <strong>Pro Subrental Marketplace</strong> connects rental companies to help you maximize
                    equipment utilization and grow your network in the rental industry.
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
