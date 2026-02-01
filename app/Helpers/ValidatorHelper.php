<?php
declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ValidatorHelper {
    public function validate(string $type, Request $request): array {
        $validated = Validator::make($request->toArray(), $this->rules($type), [
            'password' => 'The password did not match.',
            'password_confirmation.confirmed' => 'The password did not match.',
            'password.min' => 'The password must be at least 8 characters long.',
            'password_confirmation.min' => 'The password confirmation must be at least 8 characters long.',
            "email.unique" => "This email address is already associated with an account."
        ]);
        
        if ($validated->fails()) {
            return [
                'status' => false,
                'response' => $validated->errors()->first(),
            ];
        }

        return [
            'status' => true,
            'validated' => $validated->validated(),
        ]; 
    }

    // private function key_map($to_map): array {

    //     $mapped = [];
    //     foreach($to_map as $key => $value) {
    //         if($value) {
    //             $mapped[keysHelper()->getKey($key)] = $value;
    //         }
    //     }

    //     return $mapped;
    // }

    private function rules(string $type) {
        switch($type) {
            case 'create-user':
                return [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|unique:users',
                    'password' => 'required|string|min:8|confirmed',
                    'password_confirmation' => 'required|string|min:8',
                ];
            case 'login':
                return [
                    'email' => 'required|email',
                    'password' => 'required|string|min:8',
                ];
        }
    }
}