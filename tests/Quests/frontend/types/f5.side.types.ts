// Stage F5 のサイドの判定（練習問題1）。ページネーションの型 Paginated<T> が、paginate() の JSON と合っているかを確かめます。

import type { Paginated } from '@/types/api';
import type { Course } from '@/types/course';

const plain = {
    id: 1,
    title: 'a',
    description: null,
    capacity: 20,
    status: 'draft',
    status_label: '下書き',
    starts_at: '2026-11-30 14:46:59',
    created_at: '2026-11-07 15:20:36',
} satisfies Course;

export const page = {
    data: [plain],
    links: { first: 'http://localhost?page=1', last: 'http://localhost?page=3', prev: null, next: 'http://localhost?page=2' },
    meta: { current_page: 1, from: 1, last_page: 3, links: [{ url: null, label: '&laquo; Previous', page: null, active: false }], path: 'http://localhost', per_page: 2, to: 2, total: 5 },
} satisfies Paginated<Course>;

