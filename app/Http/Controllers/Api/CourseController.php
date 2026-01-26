<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    /**
     * 講座一覧を取得
     */
    public function index(Request $request)
    {
        $query = Course::with('instructor');

        // ステータスでフィルタリング
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // ページネーション
        $perPage = $request->input('per_page', 15);
        $courses = $query->latest()->paginate($perPage);

        return CourseResource::collection($courses);
    }

    /**
     * 講座詳細を取得
     */
    public function show(Course $course)
    {
        // instructorをロードして返す
        $course->load('instructor');

        return new CourseResource($course);
    }

    /**
     * 講座を作成
     */
    public function store(Request $request)
    {
        // 認可チェック（講師のみ）
        $this->authorize('create', Course::class);

        // バリデーション
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['required', 'string', Rule::enum(CourseStatus::class)],
        ]);

        // 講座を作成（講師は認証ユーザー）
        $course = Course::create([
            ...$validated,
            'instructor_id' => $request->user()->id,
        ]);

        $course->load('instructor');

        return new CourseResource($course);
    }

    /**
     * 講座を更新
     */
    public function update(Request $request, Course $course)
    {
        // 認可チェック（担当講師のみ）
        $this->authorize('update', $course);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', 'in:draft,active,closed'],
        ]);

        $course->update($validated);
        $course->load('instructor');

        return new CourseResource($course);
    }

    /**
     * 講座を削除
     */
    public function destroy(Course $course)
    {
        // 認可チェック（担当講師のみ）
        $this->authorize('delete', $course);

        $course->delete();

        return response()->json(null, 204);
    }
}
