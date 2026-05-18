<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Open API access request - Pro Subrental Marketplace</title>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">

        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="Pro Subrental Marketplace"
                    style="max-width: 200px; height: auto; display: block; margin: 0 auto;">
            </td>
        </tr>

        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8; margin-top: 0;">Open API access request</h2>

                <p>
                    A provider user has requested Open API / partner API key access for their company. Review the company in admin and enable <strong>Open API Access</strong> if appropriate.
                </p>

                <h3 style="color: #1a73e8; margin-top: 30px;">Company</h3>
                <table cellpadding="8" cellspacing="0" style="margin: 15px 0; font-size: 14px; width: 100%; border-collapse: collapse;">
                    <tr style="background-color: #f9f9f9;">
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold; width: 40%;">Company ID:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $company_id }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Company name:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $company_name }}</td>
                    </tr>
                    <tr style="background-color: #f9f9f9;">
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Open API currently enabled:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $is_open_api_enabled ? 'Yes' : 'No' }}</td>
                    </tr>
                </table>

                <h3 style="color: #1a73e8; margin-top: 30px;">Requesting user</h3>
                <table cellpadding="8" cellspacing="0" style="margin: 15px 0; font-size: 14px; width: 100%; border-collapse: collapse;">
                    <tr style="background-color: #f9f9f9;">
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold; width: 40%;">User ID:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $user_id }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Username:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $username }}</td>
                    </tr>
                    <tr style="background-color: #f9f9f9;">
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Name:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $full_name }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Email:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $user_email }}</td>
                    </tr>
                    <tr style="background-color: #f9f9f9;">
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Mobile:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $mobile }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Submitted at:</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $submitted_at }}</td>
                    </tr>
                </table>

                <h3 style="color: #1a73e8; margin-top: 30px;">Message from user</h3>
                <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #1a73e8; margin: 15px 0; white-space: pre-wrap; word-wrap: break-word;">
                    {{ $message }}
                </div>

                <p style="margin-top: 25px; font-size: 14px; color: #666; line-height: 1.6;">
                    Admin: open the company in <strong>Companies</strong> and set <strong>Open API Access</strong> to Enabled if you approve this request.
                </p>
            </td>
        </tr>
    </table>
</body>

</html>
