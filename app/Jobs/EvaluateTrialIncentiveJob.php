<?php

namespace App\Jobs;

use App\Services\TrialIncentiveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateTrialIncentiveJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 30;

    public function __construct(
        public int $companyId
    ) {}

    public function uniqueId(): string
    {
        return 'trial-incentive-company-'.$this->companyId;
    }

    public function handle(TrialIncentiveService $trialIncentiveService): void
    {
        $trialIncentiveService->evaluateAndApply($this->companyId);
    }
}
