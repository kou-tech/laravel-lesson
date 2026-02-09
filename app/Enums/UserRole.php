<?php

namespace App\Enums;

enum UserRole: string
{
    case Student = 'student';
    case Instructor = 'instructor';

    public function label(): string
    {
        return match($this) {
            self::Student => '生徒',
            self::Instructor => '講師',
        };
    }
}
