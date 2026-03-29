<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Models\UploadedImage;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserController extends Controller
{
    //User signing up,
    public function create(Request $request): JsonResponse  {
        try {
            $validated = validatorHelper()->validate('create-user', $request);

            if(! $validated['status']) {
                return response()->json(['status' => false, 'message' => $validated['response']], 201);
            }
            
            $user = User::create($validated['validated']);

            if (!$user) {
                logHelper()->logInfo('Failed to create user');
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to create user'
                ], 500);
            }

            return response()->json([
                'status' => true,
                'message' => 'User created successfully. Please check your email to verify your account.',
                'name' => $user->name,
                'user' => $user
            ], 200);    

        } catch (Exception $e) {
            logHelper()->logInfo($e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add user!'
            ], 500);
        }
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $authUser = $request->user();
            $query = $request->query->get('q', '');
            $query = trim((string) $query);

            if ($query === '') {
                return response()->json([
                    'status' => true,
                    'data' => []
                ], 200);
            }

            $usersQuery = User::query();
            if (! $authUser?->isAdmin()) {
                if (Schema::hasColumn('users', 'searchable')) {
                    $usersQuery->where('searchable', true);
                }
            }

            $users = $usersQuery
                ->where(function ($q) use ($query) {
                    $q->where('name', 'like', '%' . $query . '%')
                        ->orWhere('email', 'like', '%' . $query . '%');
                })
                ->limit(10)
                ->get();

            return response()->json([
                'status' => true,
                'data' => $users
            ], 200);
        } catch (Exception $e) {
            logHelper()->logInfo($e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Cannot continue, please try again later!'
            ], 500);
        }
    }

    public function index() {
        try {
            $users = User::paginate(10);
            if (! $users) {

                return response()->json([
                    'status' => false,
                    'message' => 'Failed fetch user'
                ], 500);
            }
            return response()->json([
                'status' => true,
                'data' => $users
            ]);
        } catch (Exception $e) {
            logHelper()->logInfo($e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Cannot continue, please try again later!'
            ], 500);
        }
    }

    public function me(Request $request): JsonResponse
    {
        try {
            $auth = $request->user();
            if (! $auth) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $query = User::query()->whereKey($auth->id);
            if (Schema::hasColumn('users', 'profile_image_id')) {
                $query->with('profileImage:id,s3_url,original_name,image_type');
            }

            $user = $query->firstOrFail();
            
            if ($user) {
                if (isset($user->created_at)) {
                    $user->created_at_formatted = $user->created_at->format('M d, Y H:i a');
                }
                if (isset($user->updated_at)) {
                    $user->updated_at_formatted = $user->updated_at->format('M d, Y H:i a');
                }

                $user->searchable = $user->searchable ? 'YES' : 'NO';
            }




            return response()->json([
                'status' => true,
                'data' => $user,
            ], 200);
        } catch (Exception $e) {
            logHelper()->logInfo($e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Cannot continue, please try again later!',
            ], 500);
        }
    }

    public function show($id) {
        try {
            $user = User::where('id', $id)->get();
            if (! $user) {

                return response()->json([
                    'status' => false,
                    'message' => 'Failed fetch user'
                ], 500);
            }
            return response()->json([
                'status' => true,
                'data' => $user
            ]);

        } catch (Exception $e) {
            logHelper()->logInfo($e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Cannot continue, please try again later!'
            ], 500);
        }
    }
    
    /**
     * Update the authenticated user's profile: name, email, searchable, and/or profile image.
     * Use multipart/form-data when sending `image`.
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $rules = [
                'name' => 'sometimes|string|max:255',
                'image' => 'sometimes|file|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
            ];
            
            if (Schema::hasColumn('users', 'searchable')) {
                $rules['searchable'] = 'sometimes|boolean';
            }

            $validated = $request->validate($rules, [
                'image.image' => 'The file must be an image.',
            ]);

            if (isset($validated['name'])) {
                $user->name = $validated['name'];
            }
            if (isset($validated['email'])) {
                $user->email = $validated['email'];
            }
            if (Schema::hasColumn('users', 'searchable') && array_key_exists('searchable', $validated)) {
                $user->searchable = (bool) $validated['searchable'];
            }

            if ($request->hasFile('image')) {
                if (! Schema::hasColumn('users', 'profile_image_id')) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Profile image is not enabled yet. Please run database migrations.',
                    ], 500);
                }
                $uploadedImage = $this->createProfileImageRecord($user, $request->file('image'));
                $user->profile_image_id = $uploadedImage->id;
            }

            if (! $user->isDirty() && ! $request->hasFile('image')) {
                $query = User::query()->whereKey($user->id);
                if (Schema::hasColumn('users', 'profile_image_id')) {
                    $query->with('profileImage:id,s3_url,original_name,image_type');
                }

                return response()->json([
                    'status' => true,
                    'message' => 'No changes submitted.',
                    'data' => $query->firstOrFail(),
                ], 200);
            }

            $user->save();

            $query = User::query()->whereKey($user->id);
            if (Schema::hasColumn('users', 'profile_image_id')) {
                $query->with('profileImage:id,s3_url,original_name,image_type');
            }
            $fresh = $query->firstOrFail();

            return response()->json([
                'status' => true,
                'message' => 'Profile updated.',
                'data' => $fresh,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            logHelper()->logInfo($e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Cannot continue, please try again later!',
            ], 500);
        }
    }

    public function uploadProfilePicture(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (! Schema::hasColumn('users', 'profile_image_id')) {
                return response()->json([
                    'status' => false,
                    'message' => 'Profile image is not enabled yet. Please run database migrations.',
                ], 500);
            }

            $request->validate([
                'image' => 'required|file|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
            ], [
                'image.required' => 'Please select an image to upload.',
                'image.image' => 'The file must be an image.',
            ]);

            $uploadedImage = $this->createProfileImageRecord($user, $request->file('image'));
            $user->profile_image_id = $uploadedImage->id;
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'Profile picture updated.',
                'data' => [
                    'profile_image' => [
                        'id' => $uploadedImage->id,
                        'original_name' => $uploadedImage->original_name,
                        's3_url' => $uploadedImage->s3_url,
                        'image_type' => $uploadedImage->image_type,
                    ],
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            logHelper()->logInfo($e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to upload profile picture. Please try again.',
            ], 500);
        }
    }

    public function setSearchable(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (! Schema::hasColumn('users', 'searchable')) {
                return response()->json([
                    'status' => false,
                    'message' => 'Search toggle is not enabled yet. Please run database migrations.',
                ], 500);
            }

            $validated = $request->validate([
                'searchable' => 'required|boolean',
            ]);

            $user->searchable = (bool) $validated['searchable'];
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'Search setting updated.',
                'data' => [
                    'id' => $user->id,
                    'searchable' => $user->searchable,
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            logHelper()->logInfo($e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Cannot continue, please try again later!',
            ], 500);
        }
    }

    private function createProfileImageRecord(User $user, UploadedFile $file): UploadedImage
    {
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();

        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension();
        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $uniqueName = Str::uuid()->toString();
        $filename = $uniqueName . '-' . $safeName . '.' . $extension;

        $s3Directory = 'profile_images/' . date('Y/m/d');
        /** @var \Illuminate\Filesystem\FilesystemAdapter $s3 */
        $s3 = Storage::disk('s3');
        $s3Key = $s3->putFileAs($s3Directory, $file, $filename);
        /** @noinspection PhpUndefinedMethodInspection */
        $url = $s3->url($s3Key);

        return UploadedImage::create([
            'user_id' => $user->id,
            'original_name' => $originalName,
            'image_type' => 'profile',
            's3_key' => $s3Key,
            's3_url' => $url,
            'mime_type' => $mimeType,
            'size' => $size,
            'disk' => 's3',
        ]);
    }

    public function delete() {
        return response()->json(['message' => 'User deleted']);
    }

    public function login(Request $request) {
        try {
            $validated = validatorHelper()->validate('login', $request);
                
            if(! $validated['status']) {
                logHelper()->logInfo($validated['response']);
                return response()->json([
                    'status' => false, 
                    'message' => $validated['response']
                ], 500);
            }

            if (! $token = JWTAuth::attempt($validated['validated'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid username or password.'
                ]);
            }   
            JWTAuth::setToken($token);
            $authUser = JWTAuth::toUser($token);

            return response()->json([
                'status' => true,
                'token' => $token,
                'name' => $authUser->name
            ], 200);
        } catch (JWTException $e) {
            logHelper()->logInfo($e->getTraceAsString());
            return response()->json([
                'status' => false,
                'message' => 'Failed to login!'
            ], 500);
        } catch (Exception $e) {
            logHelper()->logInfo($e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Cannot continue, please try again later!'
            ], 500);
        }
    }

    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());
        return response()->json(['message' => 'Successfully logged out']);
    }
}