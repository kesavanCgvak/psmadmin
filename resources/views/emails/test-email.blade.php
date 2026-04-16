<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Test Email - Mail Configuration Test</title>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <h1 style="color: #ffffff; margin: 0; font-size: 24px;">Test Email</h1>
            </td>
        </tr>

        <!-- Body -->
        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8; margin-top: 0;">Mail Configuration Test</h2>

                <p style="font-size: 16px; line-height: 1.6;">
                    This is a test email to verify that your mail configuration is working correctly.
                </p>

                <div style="background-color: #e8f4f8; padding: 15px; border-left: 4px solid #1a73e8; margin: 20px 0;">
                    <p style="margin: 0; font-size: 14px; color: #555;">
                        <strong>✓ If you receive this email, your SMTP/mail settings are configured correctly!</strong>
                    </p>
                </div>

                <!-- Test Information -->
                <h3 style="color: #1a73e8; margin-top: 30px;">Test Information</h3>
                <table cellpadding="8" cellspacing="0" style="margin: 15px 0; font-size: 14px; width: 100%; border-collapse: collapse;">
                    <tr style="background-color: #f9f9f9;">
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold; width: 40%;">Recipient:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $test_email }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Sent At:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $sent_at }}</td>
                    </tr>
                    <tr style="background-color: #f9f9f9;">
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Mail Driver:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $mail_config['driver'] ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">From Address:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $mail_config['from_address'] ?? 'N/A' }}</td>
                    </tr>
                    <tr style="background-color: #f9f9f9;">
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">From Name:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $mail_config['from_name'] ?? 'N/A' }}</td>
                    </tr>
                </table>

                <p style="margin-top: 25px; font-size: 14px; color: #666; line-height: 1.6;">
                    This test email was sent to verify your mail configuration. If you received this email,
                    it means your SMTP or mail service settings are working correctly.
                </p>

                <div style="background-color: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;">
                    <p style="margin: 0; font-size: 13px; color: #856404;">
                        <strong>Note:</strong> This is a test email sent from the mail test endpoint. 
                        This endpoint should only be used for testing and debugging purposes.
                    </p>
                </div>

                <p style="font-size: 13px; color: #666; line-height: 1.6; margin-top: 25px;">
                    <strong>Pro Subrental Marketplace</strong> - Mail Test System
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
