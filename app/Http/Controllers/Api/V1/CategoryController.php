<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ConversationCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * List all categories (available to any authenticated user).
     */
    public function index(Request $request): JsonResponse
    {
        $categories = ConversationCategory::orderBy('name')->get();

        return response()->json([
            'status' => true,
            'data' => $categories->map(fn (ConversationCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
                'created_at' => $c->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Create a new category (admin only).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:conversation_categories,name',
            'description' => 'nullable|string|max:1000',
            'slug' => 'nullable|string|max:255|unique:conversation_categories,slug',
        ]);

        $category = ConversationCategory::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Category created.',
            'data' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'created_at' => $category->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Show a single category.
     */
    public function show(int $id): JsonResponse
    {
        $category = ConversationCategory::find($id);

        if (! $category) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'created_at' => $category->created_at->toIso8601String(),
                'updated_at' => $category->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Update a category (admin only).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = ConversationCategory::find($id);

        if (! $category) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:conversation_categories,name,' . $category->id,
            'description' => 'nullable|string|max:1000',
            'slug' => 'nullable|string|max:255|unique:conversation_categories,slug,' . $category->id,
        ]);

        if (isset($validated['name'])) {
            $category->name = $validated['name'];
        }
        if (array_key_exists('description', $validated)) {
            $category->description = $validated['description'];
        }
        if (isset($validated['slug'])) {
            $category->slug = $validated['slug'];
        } elseif (isset($validated['name']) && empty($validated['slug'] ?? null)) {
            $category->slug = Str::slug($validated['name']);
        }
        $category->save();

        return response()->json([
            'status' => true,
            'message' => 'Category updated.',
            'data' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'updated_at' => $category->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Delete a category (admin only).
     */
    public function destroy(int $id): JsonResponse
    {
        $category = ConversationCategory::find($id);

        if (! $category) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found.',
            ], 404);
        }

        $conversationCount = $category->conversations()->count();
        if ($conversationCount > 0) {
            return response()->json([
                'status' => false,
                'message' => "Cannot delete category. It has {$conversationCount} conversation(s). Remove or reassign them first.",
            ], 422);
        }

        $category->delete();

        return response()->json([
            'status' => true,
            'message' => 'Category deleted.',
        ]);
    }
}
