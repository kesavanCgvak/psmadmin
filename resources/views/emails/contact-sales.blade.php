<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>New Contact Sales Inquiry - Pro Subrental Marketplace</title>
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
                <h2 style="color: #1a73e8; margin-top: 0;">New Contact Sales Inquiry</h2>

                <p>
                    A new contact sales inquiry has been submitted through the Pro Subrental Marketplace platform.
                </p>

                <!-- Contact Information -->
                <h3 style="color: #1a73e8; margin-top: 30px;">Contact Information</h3>
                <table cellpadding="8" cellspacing="0" style="margin: 15px 0; font-size: 14px; width: 100%; border-collapse: collapse;">
                    <tr style="background-color: #f9f9f9;">
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold; width: 40%;">Name:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $name }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Email Address:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $email }}</td>
                    </tr>
                    <tr style="background-color: #f9f9f9;">
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Phone Number:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $phone_number }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Submitted At:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $submitted_at }}</td>
                    </tr>
                </table>

                <!-- Description -->
                <h3 style="color: #1a73e8; margin-top: 30px;">Message / Questions</h3>
                <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #1a73e8; margin: 15px 0; white-space: pre-wrap; word-wrap: break-word;">
                    {{ $description }}
                </div>

                <p style="margin-top: 25px; font-size: 14px; color: #666; line-height: 1.6;">
                    Please review this inquiry and respond to the contact at <strong>{{ $email }}</strong> as soon as possible.
                </p>

                <p style="font-size: 13px; color: #666; line-height: 1.6;">
                    <strong>Pro Subrental Marketplace</strong> - Sales Team
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
