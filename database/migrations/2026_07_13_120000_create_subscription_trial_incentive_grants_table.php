<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_trial_incentive_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('milestone_products');
            $table->unsignedTinyInteger('bonus_months');
            $table->unsignedInteger('product_count_at_grant');
            $table->timestamp('granted_at');
            $table->timestamps();

            $table->unique(['subscription_id', 'milestone_products'], 'sub_trial_incentive_grant_unique');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_trial_incentive_grants');
    }
};
