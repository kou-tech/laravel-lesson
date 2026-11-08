// F2 命令的に画面を作る
// 講座を1回だけ取ってきて、一覧を表示するところまでを用意しています。
// このファイルに、レッスンの手順どおりに機能を足していきます。

const list = document.querySelector('#course-list');

/**
 * 講座を1件、一覧の行として DOM に足す
 */
function appendCourse(course) {
    const item = document.createElement('li');
    item.dataset.id = String(course.id);

    const title = document.createElement('span');
    title.textContent = course.title;

    const status = document.createElement('span');
    status.className = 'status';
    status.textContent = course.status;

    item.append(title, status);
    list.append(item);
}

async function loadCourses() {
    const response = await fetch('/api/courses?per_page=100', {
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(`講座を取得できませんでした（${response.status}）`);
    }

    const json = await response.json();

    return json.data;
}

const courses = await loadCourses();

for (const course of courses) {
    appendCourse(course);
}
