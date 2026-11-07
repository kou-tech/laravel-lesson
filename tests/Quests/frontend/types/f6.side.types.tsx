// Stage F6 のサイドの判定（練習問題1）。CourseList が、空のときの表示を empty で受け取れるかを確かめます。

import { CourseList } from '@/components/courses/course-list';

export function Check() {
    return <CourseList courses={[]} empty={<span />} />;
}
