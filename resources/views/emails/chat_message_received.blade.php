<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>New chat message - Pro Subrental Marketplace</title>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 25px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">

        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="Pro Subrental Marketplace"
                    style="max-width: 200px; height: auto;">
            </td>
        </tr>

        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8; margin-top: 0;">You have a new message</h2>

                <p>
                    Hello @if (!empty($recipient_name))<strong>{{ $recipient_name }}</strong>@endif,<br><br>
                    You have a new message from <strong>{{ $sender_name }}</strong>
                    at <strong>{{ $sender_company_name }}</strong> via
                    <strong>Pro Subrental Marketplace</strong>.
                </p>

                <table width="100%" cellpadding="12" cellspacing="0"
                    style="background: #f1f5fb; border-radius: 8px; margin: 20px 0;">
                    <tr>
                        <td style="font-size: 15px; line-height: 1.5; color: #333;">
                            &ldquo;{{ $preview }}&rdquo;
                        </td>
                    </tr>
                </table>

                <p style="text-align: center; margin: 28px 0;">
                    <a href="{{ $conversation_url }}"
                        style="background-color: #1a73e8; color: #ffffff; text-decoration: none; padding: 12px 22px; border-radius: 4px; font-size: 15px; display: inline-block;">
                        Open Conversation
                    </a>
                </p>

                <p style="font-size: 13px; color: #666; line-height: 1.6;">
                    You received this email because you have chat email notifications enabled and were offline when this
                    message arrived. You can change this in Chat notification settings.
                </p>
            </td>
        </tr>

        <tr>
            <td style="background-color:#726d6c; padding: 18px; text-align:center; color:#ffffff; font-size: 13px;">
                &copy; {{ $current_year }} Pro Subrental Marketplace. All rights reserved.
            </td>
        </tr>
    </table>
</body>

</html>
