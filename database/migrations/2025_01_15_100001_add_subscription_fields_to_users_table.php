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
        Schema::table('users', function (Blueprint $table) {
            // stripe_customer_id already exists in users table
            // Add subscription status fields
            $table->string('subscription_status')->nullable()->after('stripe_customer_id'); // active, canceled, past_due, etc.
            $table->timestamp('subscription_ends_at')->nullable()->after('subscription_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['subscription_status', 'subscription_ends_at']);
        });
    }
};


