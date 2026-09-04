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
        Schema::create('company_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_link_id')->constrained('referral_links')->cascadeOnDelete();
            $table->foreignId('referrer_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('referred_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('referrer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('registered');
            $table->timestamps();

            // A referred company may only have one referral relationship.
            $table->unique('referred_company_id');
            $table->index('referrer_company_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_referrals');
    }
};
