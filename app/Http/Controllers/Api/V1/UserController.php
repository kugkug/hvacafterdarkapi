<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Request;
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
    
    public function update() {
        return response()->json(['message' => 'User updated']);
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
            
            // if (!auth()->guard()->attempt($validated['validated'])) {
            //     logHelper()->logInfo('Invalid username or password.');
            //     return response()->json([
            //         'status' => 'error',
            //         'message' => 'Invalid username or password.'
            //     ]);
            // }

            // $user = auth()->guard()->user();
            // $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json([
                'status' => true,
                'token' => $token
            ], 200);
        } catch (JWTException $e) {
            logHelper()->logInfo($e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to add user!'
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