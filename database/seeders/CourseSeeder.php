<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Course;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $instructor = \App\Models\User::where('role', 'instructor')->inRandomOrder()->first();

        // 講座を作成
        Course::factory(10)
            ->for($instructor, 'instructor')
            ->active()
            ->create();
    }
}
