<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\UploadedImage;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploadController extends Controller
{
    /**
     * Max file size in bytes (e.g. 5MB).
     */
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /**
     * Upload a single image to AWS S3 and store record in uploaded_images.
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'image' => 'required|file|image|mimes:jpeg,jpg,png,gif,webp',
                'image_type' => 'required|string',
            ], [
                'image.required' => 'Please select an image to upload.',
                'image.image' => 'The file must be an image.',
                'image.mimes' => 'Allowed formats: JPEG, PNG, GIF, WEBP.',
                'image.max' => 'Maximum file size is 5MB.',
                'image_type.required' => 'Please select an image type.',
                'image_type.string' => 'The image type must be a string.',
            ]);

            $file = $request->file('image');
            $imageType = $request->input('image_type');
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();
            $size = $file->getSize();

            $s3_directory = $imageType == "meme" ? 'memes' : 'finds';
            $extension = $file->getClientOriginalExtension() ?: $file->guessExtension();
            $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
            $uniqueName = Str::uuid()->toString();
            $directory = 'images/' . date('Y/m/d');
            $filename = $uniqueName . '-' . $safeName . '.' . $extension;
                
            $imageFile = Storage::disk('s3')->put($s3_directory, $file);
            $url = Storage::disk('s3')->url($imageFile);

            $uploadedImage = UploadedImage::create([
                'user_id' => auth()->id(),
                'original_name' => $originalName,
                'image_type' => $imageType,
                's3_key' => $imageFile,
                's3_url' => $url,
                'mime_type' => $mimeType,
                'size' => $size,
                'disk' => 's3',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Image uploaded successfully.',
                'data' => [
                    'id' => $uploadedImage->id,
                    'original_name' => $uploadedImage->original_name,
                    's3_url' => $uploadedImage->s3_url,
                    's3_key' => $uploadedImage->s3_key,
                    'image_type' => $uploadedImage->image_type,
                    'mime_type' => $uploadedImage->mime_type,
                    'size' => $uploadedImage->size,
                    'uploaded_at' => $uploadedImage->created_at->toIso8601String(),
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            logHelper()->logInfo($e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            logHelper()->logInfo($e->getMessage());
            
            return response()->json([
                'status' => false,
                'message' => 'Failed to upload image. Please try again.',
            ], 500);
        }
    }

    /**
     * List uploaded images (history) with optional user scope.
     */
    public function index($image_type = null, Request $request): JsonResponse
    {
        try {   
            $imageType = $image_type;
            if ($imageType) {
                $query = UploadedImage::query()->where('image_type', $imageType);
            } else {
                $query = UploadedImage::query();
            }
            $images = $query->orderByDesc('created_at')->with('user')->paginate(15);

            if (auth()->check()) {
                $query->where('user_id', auth()->id());
            } else {
                $query->where('user_id', null);
            }

            return response()->json([
                'status' => true,
                'data' => $images,
            ], 200);
        } catch (Exception $e) {
            if (function_exists('logHelper')) {
                logHelper()->logInfo($e->getMessage());
            } else {
                Log::error('List images failed: ' . $e->getMessage());
            }
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch upload history.',
            ], 500);
        }
    }

    /**
     * Get a single uploaded image record by id.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $image = UploadedImage::findOrFail($id);

            if (auth()->check() && (int) $image->user_id !== (int) auth()->id()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized.',
                ], 403);
            }

            return response()->json([
                'status' => true,
                'data' => $image,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Image not found.',
            ], 404);
        } catch (Exception $e) {
            if (function_exists('logHelper')) {
                logHelper()->logInfo($e->getMessage());
            } else {
                Log::error('Show image failed: ' . $e->getMessage());
            }
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch image.',
            ], 500);
        }
    }
}