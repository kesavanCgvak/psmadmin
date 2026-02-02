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
                <h2 style="color: #1a73e8; margin-top: 0;">New Quote Request from {{ $user_company}}</h2>

                <p>
                    Hello <strong>{{ $provider_contact_name ?? 'there' }}</strong>,<br><br>
                    You’ve received a new quote request from
                    <strong>{{ $user_company}}</strong> via
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

                @if (!empty($global_message))
                    <h3 style="color: #1a73e8;">Global Message</h3>
                    <p style="background: #f9f9f9; padding: 12px; border-left: 4px solid #1a73e8;">
                        {{ $global_message }}
                    </p>
                @endif

                @if(!empty($offer_requirements))
                    <h3 style="color: #1a73e8;">Offer Requirements</h3>
                    <p>{{ $offer_requirements }}</p>
                @endif

                @if(!empty($private_message))
                    <h3 style="color: #1a73e8;">Private Message</h3>
                    <p style="background: #f9f9f9; padding: 12px; border-left: 4px solid #1a73e8;">
                        {{ $private_message }}
                    </p>
                @endif

                @if(!empty($initial_offer))
                    <h3 style="color: #1a73e8;">Initital Offer Negotitaion</h3>
                    <p><b>Offer Price : </b>{{ $currency_symbol }}{{ $initial_offer }}</p>
                @endif


                <!-- Equipment List -->
                <h3 style="color: #1a73e8;">Requested Equipment</h3>
                <table width="100%" cellpadding="8" cellspacing="0"
                    style="border-collapse: collapse; margin-top: 10px; font-size: 14px;">
                    <thead style="background-color: #f0f0f0; border-bottom: 2px solid #ddd;">
                        <tr>
                            <th align="left">Equipment</th>
                            <th align="left">PSM Code</th>
                            <th align="left">Software Code</th>
                            <th align="left">Qty</th>
                            <th align="left">Price</th>
                            <th align="left">Total Price</th>
                        </tr>
                    </thead>
                    <tbody>
                    <tbody>
                        @php
                            $grandTotal = 0;
                        @endphp

                        @foreach($products as $product)
                            @php
                                $itemTotal = $product['total_price'] ?? 0;
                                $grandTotal += $itemTotal;
                            @endphp

                            <tr style="border-bottom: 1px solid #eee;">
                                <td>{{ $product['model'] ?? '-' }}</td>
                                <td>{{ $product['psm_code'] ?? '—' }}</td>
                                <td>{{ $product['software_code'] ?? '—' }}</td>
                                <td>{{ $product['requested_quantity'] ?? '-' }}</td>
                                <td>{{ $currency_symbol }}{{ number_format($product['price_per_unit'] ?? 0, 2) }}</td>
                                <td>{{ $currency_symbol }}{{ number_format($itemTotal, 2) }}</td>
                            </tr>
                        @endforeach

                        <!-- ✅ Grand Total Row -->
                        <tr style="border-top: 2px solid #ddd; background-color: #f9f9f9;">
                            <td colspan="5" align="right" style="font-weight: bold; padding-right: 10px;">Grand Total:
                            </td>
                            <td style="font-weight: bold;">{{ $currency_symbol }}{{ number_format($grandTotal, 2) }}</td>
                        </tr>
                    </tbody>
                </table>

                <p style="margin-top: 25px; font-size: 15px; line-height: 1.5;">
                    Please review the request and respond promptly to increase your chance of securing this rental
                    opportunity with <strong>{{ $user_company}}</strong>.
                </p>

                @if(!empty($is_similar_request))
                    <p style="margin-top: 15px; padding: 12px; background-color: #e8f4fd; border-left: 4px solid #1a73e8; font-size: 14px; line-height: 1.5;">
                        <strong>Note:</strong> The requester is also open to similar or equivalent products. Please contact the requester if you can offer suitable alternatives.
                    </p>
                @endif

                <p style="font-size: 13px; color: #666; line-height: 1.6;">
                    <strong>Pro Subrental Marketplace</strong> connects rental companies to help you maximize
                    equipment utilization and grow your network in the rental industry.
                </p>
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td style="background-color:#726d6c; padding: 18px; text-align:center; color:#ffffff; font-size: 13px;">
                &copy; {{ date('Y') }} Pro Subrental Marketplace. All rights reserved.
            </td>
        </tr>

    </table>
</body>

</html>
