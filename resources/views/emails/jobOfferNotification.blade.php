<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>New Job Offer - Pro Subrental Marketplace</title>
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
                <h2 style="color: #1a73e8; margin-top: 0;">New Job Offer Received</h2>

                <p>
                    Hello <strong>{{ $receiver_contact_name ?? 'there' }}</strong>,<br><br>
                    You’ve received a new job offer from <strong>{{ $sender_company_name }}</strong> via
                    <strong>Pro Subrental Marketplace</strong>.
                </p>

                <table cellpadding="6" cellspacing="0" style="font-size: 14px; margin: 10px 0 20px 0;">
                    <!-- <tr>
                        <td><strong>Offer Version:</strong></td>
                        <td>{{ $version }}</td>
                    </tr> -->
                    <tr>
                        <td><strong>Offer Price:</strong></td>
                        <td>{{ $currency ?? '' }}{{ number_format($total_price, 2) }}</td>
                    </tr>
                    <tr>
                        <td><strong>Status:</strong></td>
                        <td>{{ $status }}</td>
                    </tr>
                </table>

                @if(!empty($products))
                    <h3 style="color: #1a73e8;">Offered Equipment Details</h3>
                    <table width="100%" cellpadding="8" cellspacing="0"
                        style="border-collapse: collapse; margin-top: 10px; font-size: 14px;">
                        <thead style="background-color: #f0f0f0; border-bottom: 2px solid #ddd;">
                            <tr>
                                <th align="left">PSM Code</th>
                                <th align="left">Model</th>
                                <th align="left">Software Code</th>
                                <th align="left">Qty</th>
                                <th align="left">Price</th>
                                <th align="left">Total Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $grandTotal = 0; @endphp

                            @foreach($products as $product)
                                @php
                                    $qty = $product['quantity'] ?? 0;
                                    $price = $product['price'] ?? 0;
                                    $total = $qty * $price;
                                    $grandTotal += $total;
                                @endphp
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td>{{ $product['psm_code'] ?? '—' }}</td>
                                    <td>{{ $product['model'] ?? '-' }}</td>
                                    <td>{{ $product['software_code'] ?? '—' }}</td>
                                    <td>{{ $qty }}</td>
                                    <td>{{ $currency ?? '' }}{{ number_format($price, 2) }}</td>
                                    <td>{{ $currency ?? '' }}{{ number_format($total, 2) }}</td>
                                </tr>
                            @endforeach

                            <!-- Grand total row -->
                            <tr style="background-color: #f9f9f9; border-top: 2px solid #ddd; font-weight: bold;">
                                <td colspan="5" align="right">Total Amount:</td>
                                <td>{{ $currency ?? '' }}{{ number_format($grandTotal, 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                @endif

                <p style="margin-top: 25px; font-size: 15px; line-height: 1.5;">
                    Please review this offer and respond (Accept / Counter / Decline) in your dashboard to proceed with
                    the negotiation.
                </p>

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
