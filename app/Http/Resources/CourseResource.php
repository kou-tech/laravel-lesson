<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    /** @var \App\Models\Course */
    public $resource;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'instructor' => new UserResource($this->whenLoaded('instructor')),
            'capacity' => $this->resource->capacity,
            'status' => $this->resource->status,
            'status_label' => $this->resource->status->label(),
            'created_at' => $this->resource->created_at->toISOString(),
        ];
    }
}
