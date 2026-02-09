<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    public function run(): void
    {
        // 講師を作成
        $instructor = User::factory()->create([
            'name' => '山田講師',
            'email' => 'instructor@example.com',
            'role' => UserRole::Instructor,
        ]);

        // 講座を作成
        Course::factory(10)
            ->for($instructor, 'instructor')
            ->active()
            ->create();
    }
}
