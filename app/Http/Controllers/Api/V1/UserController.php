<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api\V1;

use App\Mail\GenericUserMail;
use App\Models\User;
use App\Models\UploadedImage;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
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

    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
            ]);

            // Generate a secure token
            $token = hash('sha256', Str::random(60));

            // Find user by email (do not reveal if user exists)
            $user = \App\Models\User::where('email', $validated['email'])->first();

            if ($user) {
                // Save or update the password reset token in password_resets table
                \DB::table('password_reset_tokens')->updateOrInsert(
                    [
                        'email' => $validated['email'],
                    ],
                    [
                        'email' => $validated['email'],
                        'token' => $token,
                        'created_at' => now(),
                    ]
                );

                // Build reset link (adjust route/URL if needed)
                $frontend_url = config('app.frontend_url');
                $resetLink = $frontend_url . '/reset-password?token=' . $token . '&email=' . urlencode($validated['email']);

                // Update message to include link
                // $mailMessage = $resetLink;

                // Modify the mail object for downstream send
                // (we override message argument below)
                $GLOBALS['mailMessage'] = $resetLink;
            } else {
                // Always act as if email was sent, avoid enumeration
                $GLOBALS['mailMessage'] = 'Hello, this is a password reset link from HVAC After Dark. Please check your email for further instructions.';    
            }

            Mail::to($validated['email'])->send(new GenericUserMail(
                subject: 'Password Reset',
                message: $GLOBALS['mailMessage']
            ));

            // Avoid account enumeration by always returning the same message.
            return response()->json([
                'status' => true,
                'message' => 'If an account exists for that email, a password reset link has been sent.',
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
                'message' => 'Failed to process forgot password request.',
            ], 500);
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
                'token' => 'required|string',
                'password' => 'required|string|min:8|confirmed',
            ]);
            
            $reset_data = \DB::table('password_reset_tokens')->where('email', $request->email)->first();
            $token = $reset_data->token;
            $created_at = $reset_data->created_at;
            
            $token_lifetime = config('auth.passwords.users.expire');
            if (!$token_lifetime) {
                return response()->json([
                    'status' => false,
                    'message' => 'Token lifetime not found.',
                ], 500);
            }
            
            $token_lifetime = $token_lifetime * 60;
            $token_expiry_time = Carbon::parse($created_at)->addSeconds($token_lifetime);
            
            if ($token_expiry_time < now()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Token expired.',
                ], 400);
            }
            
            if ($token !== $validated['token']) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid token.',
                ], 400);
            }
            
            $user = User::where('email', $validated['email'])->first();
            $user->password = Hash::make($validated['password']);
            $user->save();
            
            \DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();
            
            return response()->json([
                'status' => true,
                'message' => 'Password reset successfully. Please login with your new password.',
            ], 200);  
        }
        catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }
    }
        

    public function sendMailToUser(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
                'subject' => 'sometimes|string|max:255',
                'message' => 'sometimes|string|max:5000',
            ]);

            Mail::to($validated['email'])->send(new GenericUserMail(
                $validated['subject'] ?? 'Notification',
                $validated['message'] ?? 'Hello, this is a message from HVAC After Dark.'
            ));

            return response()->json([
                'status' => true,
                'message' => 'Email sent successfully.',
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
                'message' => 'Failed to send email.',
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