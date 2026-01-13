<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('category')->index(); // e.g., 'regional', 'pricing'
            $table->string('label'); // Display name
            $table->text('value')->nullable(); // Configuration value
            $table->text('description')->nullable(); // Help text
            $table->string('type')->default('select'); // text, select, etc.
            $table->text('options')->nullable(); // JSON for select options
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Insert default configurations
        DB::table('system_configurations')->insert([
            [
                'key' => 'date_format',
                'category' => 'regional',
                'label' => 'Date Format',
                'value' => 'MM/DD/YYYY',
                'description' => 'Default date format for the system',
                'type' => 'select',
                'options' => json_encode([
                    'MM/DD/YYYY' => 'MM/DD/YYYY',
                    'DD/MM/YYYY' => 'DD/MM/YYYY',
                    'YYYY-MM-DD' => 'YYYY-MM-DD',
                ]),
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'pricing_scheme',
                'category' => 'pricing',
                'label' => 'Pricing Scheme',
                'value' => 'Day Price',
                'description' => 'Default pricing scheme for rental equipment',
                'type' => 'select',
                'options' => json_encode([
                    'Day Price' => 'Day Price',
                    'Week Price' => 'Week Price',
                    '3-Day Week' => '3-Day Week',
                    '4-Day Week' => '4-Day Week',
                ]),
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_configurations');
    }
};
