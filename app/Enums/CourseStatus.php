<?php

namespace App\Enums;

enum CourseStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';

    public function label(): string
    {
        return match($this) {
            self::Draft => '下書き',
            self::Active => '公開中',
            self::Closed => '終了',
        };
    }
}
