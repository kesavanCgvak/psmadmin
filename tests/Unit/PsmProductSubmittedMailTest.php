<?php

namespace Tests\Unit;

use App\Models\PsmProductSubmission;
use App\Notifications\PsmProductSubmitted;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

class PsmProductSubmittedMailTest extends TestCase
{
    public function test_review_and_logo_urls_use_public_admin_host_not_local_app_url(): void
    {
        config([
            'app.url' => 'http://psmadmin.test',
            'mail.admin.panel_url' => 'https://prosubmarket.cgstagingsite.com/login',
            'mail.logo_url' => null,
            'mail.from.address' => 'noreply@prosubmarket.com',
            'mail.from.name' => 'Pro Subrental Marketplace',
        ]);

        $submission = new PsmProductSubmission(['name' => 'Shure SM58']);
        $submission->id = 7;
        $submission->setRelation('submitter', null);
        $submission->setRelation('company', null);

        $mail = (new PsmProductSubmitted($submission))->toMail(new AnonymousNotifiable);

        $this->assertSame(
            'https://prosubmarket.cgstagingsite.com/admin/psm-product-submissions/7',
            $mail->viewData['review_url']
        );
        $this->assertSame(
            'https://prosubmarket.cgstagingsite.com/images/logo-white.png',
            $mail->viewData['logo_url']
        );
    }
}
