<?php

namespace Tests\Unit;

use App\Models\EmailLog;
use App\Notifications\PsmProductSubmitted;
use Tests\TestCase;

class EmailLogTypeTest extends TestCase
{
    public function test_psm_product_submission_type_is_inferred_from_subject(): void
    {
        $this->assertSame(
            EmailLog::TYPE_PSM_PRODUCT_SUBMISSION,
            EmailLog::inferEmailType('New PSM Product Submitted for Review')
        );
    }

    public function test_psm_product_submission_type_is_inferred_from_notification_class(): void
    {
        $this->assertSame(
            EmailLog::TYPE_PSM_PRODUCT_SUBMISSION,
            EmailLog::inferEmailType(null, PsmProductSubmitted::class)
        );
    }
}
