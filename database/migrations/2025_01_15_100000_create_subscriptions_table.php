<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            
            // User relationship (can be provider or regular user)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            
            // Account type
            $table->enum('account_type', ['provider', 'user'])->default('user');
            
            // Stripe related
            $table->string('stripe_subscription_id')->unique();
            $table->string('stripe_customer_id');
            $table->string('stripe_price_id');
            $table->string('stripe_status'); // active, canceled, past_due, unpaid, trialing, etc.
            
            // Subscription details
            $table->string('plan_name'); // e.g., "Provider Plan", "User Plan"
            $table->string('plan_type')->default('default'); // e.g., "default"
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('interval')->default('month'); // month, year
            
            // Dates
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable(); // For canceled subscriptions
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            
            $table->timestamps();
            
            $table->index(['user_id', 'stripe_status']);
            $table->index('stripe_customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

