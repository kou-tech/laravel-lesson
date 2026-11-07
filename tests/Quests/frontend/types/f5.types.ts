// Stage F5 の判定（make quest-f5）。受講者の型が、API が実際に返す JSON と合っているかを tsc で確かめます。
// 下の @ts-expect-error の行で「Unused '@ts-expect-error' directive」が出たら、その行のコメントの値が型で防げていません。

import type { Attendance, AttendanceStatus } from '@/types/attendance';
import type { Course, CourseStats, CourseStatus } from '@/types/course';
import type { User } from '@/types';
import type { SeatAvailability } from '@/lib/course-seats';

export const withCount = {
    id: 1,
    title: 'Omnis porro non.',
    description: 'x',
    instructor: { id: 3, name: '山田講師', full_name: '山田講師さん', email: 'instructor@example.com', created_at: '2026-11-07 15:20:36' },
    capacity: 20,
    remaining_seats: 20,
    status: 'active',
    status_label: '公開中',
    starts_at: '2026-11-30 14:46:59',
    attendances_count: 0,
    created_at: '2026-11-07 15:20:36',
} satisfies Course;

export const plain = {
    id: 1, title: 'a', description: null, capacity: 20, status: 'draft', status_label: '下書き', starts_at: '2026-11-30 14:46:59', created_at: '2026-11-07 15:20:36',
} satisfies Course;

export const attendance = {
    id: 1,
    user: { id: 1, name: 'Test User', full_name: 'Test Userさん', email: 'test@example.com', created_at: '2026-11-07 15:20:36' },
    course: { id: 1, title: 'a', description: null, instructor: { id: 3, name: 'b', full_name: 'bさん', email: 'i@example.com', created_at: 'x' }, capacity: 20, status: 'active', status_label: '公開中', starts_at: 'x', created_at: 'x' },
    status: 'attending',
    status_label: '受講中',
    attended_at: '2026-11-07 15:20:37',
} satisfies Attendance;

export const stats = { draft: 1, active: 2, closed: 0 } satisfies CourseStats;
export const user = { id: 1, name: 'a', email: 'a', role: 'student', email_verified_at: null, created_at: 'x', updated_at: 'x', two_factor_confirmed_at: null } satisfies User;

// @ts-expect-error status に 'published' は入らない
export const bad1 = { ...plain, status: 'published' } satisfies Course;
// @ts-expect-error CourseStatus は string ではない
export const bad2 = 'published' satisfies CourseStatus;
// @ts-expect-error AttendanceStatus に 'pending' は入らない
export const bad3 = 'pending' satisfies AttendanceStatus;
// @ts-expect-error closed が欠けている
export const bad4 = { draft: 1, active: 2 } satisfies CourseStats;
// @ts-expect-error role に admin は入らない
export const bad5 = { ...user, role: 'admin' } satisfies User;
// @ts-expect-error 満席に残席の数は持てない
export const bad6 = { kind: 'full', remaining: 3 } satisfies SeatAvailability;
// @ts-expect-error title がない
export const bad7 = { id: 1, description: null, capacity: 20, status: 'draft', status_label: '下書き', starts_at: 'x', created_at: 'x' } satisfies Course;
