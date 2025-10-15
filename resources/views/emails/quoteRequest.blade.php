<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Quote Request</title>
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

        <!-- Body -->
        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8; margin-top: 0;">New Quote Request</h2>

                <p><strong>{{ $company_name }}</strong> has requested a quote for equipment via <strong>Pro Subrental
                        Marketplace</strong>.</p>

                <p style="margin-bottom: 20px;">
                    <strong>Email:</strong> {{ $email }}<br>
                    <strong>Phone:</strong> {{ $mobile }}
                </p>

                <h3 style="color: #1a73e8;">Rental Details</h3>
                <p>
                    <strong>Rental Name:</strong> {{ $rental_name }}<br>
                    <strong>Rental Dates:</strong> {{ $from_date }} - {{ $to_date }}<br>
                    <strong>Delivery Address:</strong> {{ $delivery_address }}
                </p>

                @if(!empty($offer_requirements))
                    <h3 style="color: #1a73e8;">Offer Requirements</h3>
                    <p>{{ $offer_requirements }}</p>
                @endif

                @if(!empty($private_message))
                    <h3 style="color: #1a73e8;">Private Message</h3>
                    <p style="background: #f9f9f9; padding: 12px; border-left: 4px solid #1a73e8;">{{ $private_message }}
                    </p>
                @endif

                <h3 style="color: #1a73e8;">Equipment Requested</h3>
                <table width="100%" cellpadding="8" cellspacing="0"
                    style="border-collapse: collapse; margin-top: 10px; font-size: 14px;">
                    <thead style="background-color: #f0f0f0; border-bottom: 2px solid #ddd;">
                        <tr>
                            <th align="left">PSM Code</th>
                            <th align="left">Qty</th>
                            <th align="left">Equipment</th>
                            <th align="left">Software Code</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products as $product)
                            <tr style="border-bottom: 1px solid #eee;">
                                <td>{{ $product['psm_code'] ?? '—' }}</td>
                                <td>{{ $product['requested_quantity'] ?? '-' }}</td>
                                <td>{{ $product['model'] ?? '-' }}</td>
                                <td>{{ $product['software_code'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p style="margin-top: 25px; font-size: 15px; line-height: 1.5;">
                    Good luck with your rental! The sooner you reply to <strong>{{ $company_name }}</strong>’s request,
                    the more likely you are to secure the rental.
                </p>

                <p style="font-size: 13px; color: #666; line-height: 1.6;">
                    Our mission at <strong>Pro Subrental Marketplace</strong> is to help you maximize your equipment
                    investments
                    and connect with new partners across the rental industry.
                </p>
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td style="background-color:#726d6c; padding: 18px; text-align:center; color:#ffffff; font-size: 13px;">
                &copy; 2025 Pro Subrental Marketplace. All rights reserved.
            </td>
        </tr>

    </table>

</body>

</html>
