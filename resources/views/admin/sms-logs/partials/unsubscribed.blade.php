@if($smsLog->isRecipientUnsubscribed())
    @php
        $twilioBody = $smsLog->provider_response['body'] ?? $smsLog->provider_response;
        $twilioMessage = is_array($twilioBody) ? ($twilioBody['message'] ?? null) : null;
    @endphp
    <div class="sms-unsubscribed-failure mt-1">
        <div>Failure reason: Recipient unsubscribed</div>
        <div>Twilio error code: 21610</div>
        @if($twilioMessage)
            <div>Twilio error message: {{ $twilioMessage }}</div>
        @endif
    </div>
@endif
