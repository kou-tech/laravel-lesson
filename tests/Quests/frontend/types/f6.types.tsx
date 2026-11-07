// Stage F6 の判定（make quest-f6）。部品とフックの props と戻り値が、本文の設計どおりかを tsc で確かめます。

import { CourseCard } from '@/components/courses/course-card';
import { CourseList } from '@/components/courses/course-list';
import { StatusFilter, type StatusOption } from '@/components/courses/status-filter';
import { useCourseFilter } from '@/hooks/use-course-filter';
import type { Course, CourseStatus } from '@/types/course';

declare const course: Course;

export function Check() {
    const filter: { selected: CourseStatus | 'all'; changeStatus: (status: CourseStatus | 'all') => void } = useCourseFilter();
    const option: StatusOption = 'all';

    return (
        <>
            <CourseCard course={course} />
            <CourseCard course={course}>
                <button type="button">受講する</button>
            </CourseCard>
            <CourseList courses={[course]} />
            <StatusFilter selected={option} onChange={filter.changeStatus} />
            {/* @ts-expect-error published は選べない */}
            <StatusFilter selected="published" onChange={filter.changeStatus} />
        </>
    );
}
