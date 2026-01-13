<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\IssueType;

class IssueTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $issueTypes = [
            'Subscription Issue',
            'General Technical Support Issue',
            'Importing Products Issue',
        ];

        foreach ($issueTypes as $issueTypeName) {
            IssueType::firstOrCreate(
                ['name' => $issueTypeName],
                ['is_active' => true]
            );
        }
    }
}
