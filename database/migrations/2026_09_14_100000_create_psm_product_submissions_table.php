<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psm_product_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('submitted_by_user_id');
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('psm_code')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->string('webpage_url', 2048)->nullable();
            $table->decimal('replacement_price', 12, 2)->nullable();
            $table->decimal('height', 12, 2)->nullable();
            $table->decimal('width', 12, 2)->nullable();
            $table->decimal('length', 12, 2)->nullable();
            $table->decimal('weight', 12, 2)->nullable();
            $table->unsignedBigInteger('linear_unit_id')->nullable();
            $table->unsignedBigInteger('weight_unit_id')->nullable();
            $table->string('country_of_origin', 100)->nullable();
            $table->string('iso_code_2', 2)->nullable();
            $table->string('iso_code_3', 3)->nullable();
            $table->string('hsn_code', 20)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('admin_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('inventory_master_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('submitted_by_user_id', 'fk_psm_submissions_user')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('company_id', 'fk_psm_submissions_company')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('brand_id', 'fk_psm_submissions_brand')
                ->references('id')->on('brands')->nullOnDelete();
            $table->foreign('category_id', 'fk_psm_submissions_category')
                ->references('id')->on('categories')->nullOnDelete();
            $table->foreign('sub_category_id', 'fk_psm_submissions_sub_category')
                ->references('id')->on('sub_categories')->nullOnDelete();
            $table->foreign('linear_unit_id', 'fk_psm_submissions_linear_unit')
                ->references('id')->on('linear_units')->nullOnDelete();
            $table->foreign('weight_unit_id', 'fk_psm_submissions_weight_unit')
                ->references('id')->on('weight_units')->nullOnDelete();
            $table->foreign('reviewed_by', 'fk_psm_submissions_reviewer')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('inventory_master_id', 'fk_psm_submissions_master')
                ->references('id')->on('inventory_master')->nullOnDelete();

            $table->index('status', 'idx_psm_submissions_status');
            $table->index('company_id', 'idx_psm_submissions_company');
            $table->index('submitted_by_user_id', 'idx_psm_submissions_user');
            $table->index('created_at', 'idx_psm_submissions_created');
        });

        Schema::create('psm_product_submission_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('psm_product_submission_id');
            $table->string('image_path', 512);
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();

            $table->foreign('psm_product_submission_id', 'fk_psm_submission_images_submission')
                ->references('id')->on('psm_product_submissions')->cascadeOnDelete();
            $table->index('psm_product_submission_id', 'idx_psm_submission_images_submission');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psm_product_submission_images');
        Schema::dropIfExists('psm_product_submissions');
    }
};
