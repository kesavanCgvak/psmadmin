<?php

namespace App\Observers;

use App\Jobs\EvaluateTrialIncentiveJob;
use App\Models\Equipment;

class EquipmentObserver
{
    public function created(Equipment $equipment): void
    {
        $this->dispatchEvaluation($equipment);
    }

    public function updated(Equipment $equipment): void
    {
        if ($equipment->wasChanged(['product_id', 'rental_price', 'company_id'])) {
            $this->dispatchEvaluation($equipment);
        }
    }

    protected function dispatchEvaluation(Equipment $equipment): void
    {
        if (! $equipment->company_id) {
            return;
        }

        EvaluateTrialIncentiveJob::dispatch((int) $equipment->company_id);
    }
}
