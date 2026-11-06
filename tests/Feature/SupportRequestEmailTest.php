<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\IssueType;
use App\Models\SupportRequest;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SupportRequestEmailTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'mail.from.address' => 'noreply@prosubmarket.com',
            'mail.from.name' => 'Pro Subrental Marketplace',
            'mail.support_inbox' => 'k7.cgvak@gmail.com',
        ]);
        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');
    }

    public function test_support_request_is_sent_from_the_application_address_with_the_user_as_reply_to(): void
    {
        $user = $this->user('k7.cgvak+cg@gmail.com', 'Casey Grant');
        $issueType = IssueType::create([
            'name' => 'Account Connection to a Company',
            'is_active' => true,
        ]);

        $response = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/support-request', [
            'email' => 'k7.cgvak+cg@gmail.com',
            'description' => 'Please connect this account to the company.',
            'issue_type_id' => $issueType->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $stored = SupportRequest::query()->first();
        $this->assertNotNull($stored);
        $this->assertSame('k7.cgvak+cg@gmail.com', $stored->email);
        $this->assertSame('Account Connection to a Company', $stored->subject);

        $messages = $this->transportMessages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('noreply@prosubmarket.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('Pro Subrental Marketplace', $email->getFrom()[0]->getName());
        $this->assertSame('k7.cgvak@gmail.com', $email->getTo()[0]->getAddress());
        $this->assertSame('k7.cgvak+cg@gmail.com', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('Casey Grant', $email->getReplyTo()[0]->getName());
        $this->assertSame('New Support Request: Account Connection to a Company', $email->getSubject());
        $this->assertStringContainsString('Please connect this account to the company.', (string) $email->getHtmlBody());
        $this->assertStringContainsString('Account Connection to a Company', (string) $email->getHtmlBody());
        $this->assertStringNotContainsString('k7.cgvak+cg@gmail.com', (string) $email->getFrom()[0]->getAddress());

        $log = EmailLog::query()->where('to_email', 'k7.cgvak@gmail.com')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, EmailLog::query()->where('to_email', 'k7.cgvak@gmail.com')->count());
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertNull($log->failure_reason);
        $this->assertSame('noreply@prosubmarket.com', $log->from_email);
        $this->assertSame('New Support Request: Account Connection to a Company', $log->subject);
    }

    public function test_support_request_smtp_failure_is_logged_as_failed(): void
    {
        Mail::mailer()->setSymfonyTransport(new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('550 This server is unable to send email from this domain: gmail.com');
            }

            public function __toString(): string
            {
                return 'smtp-reject';
            }
        });

        $user = $this->user('k7.cgvak+cg@gmail.com', 'Casey Grant');
        $issueType = IssueType::create([
            'name' => 'Account Connection to a Company',
            'is_active' => true,
        ]);

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/support-request', [
            'email' => 'k7.cgvak+cg@gmail.com',
            'description' => 'Please connect this account to the company.',
            'issue_type_id' => $issueType->id,
        ])->assertCreated();

        $this->assertSame(1, SupportRequest::query()->count());
        $this->assertSame('k7.cgvak+cg@gmail.com', SupportRequest::query()->value('email'));

        $log = EmailLog::query()->where('to_email', 'k7.cgvak@gmail.com')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, EmailLog::query()->where('to_email', 'k7.cgvak@gmail.com')->count());
        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('550 This server is unable to send email from this domain: gmail.com', (string) $log->failure_reason);
        $this->assertSame('noreply@prosubmarket.com', $log->from_email);
        $this->assertNotSame(EmailLog::STATUS_SENT, $log->status);
    }

    private function user(string $email, string $name): User
    {
        $user = User::create([
            'account_type' => 'provider',
            'username' => 'support_'.uniqid(),
            'email' => $email,
            'password' => 'password',
            'is_admin' => 0,
            'role' => 'user',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'full_name' => $name,
        ]);

        return $user->fresh('profile');
    }

    /**
     * @return list<SentMessage>
     */
    private function transportMessages(): array
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertTrue(method_exists($transport, 'messages'));

        $messages = $transport->messages();

        return is_array($messages) ? $messages : $messages->all();
    }
}
