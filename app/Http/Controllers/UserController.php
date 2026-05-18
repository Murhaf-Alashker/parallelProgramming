<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email','exists:users,email'],
            'password' => ['required','min:8','max:20','string', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).+$/']
        ]);
        $user = User::where('email',$data['email'])->first();
        if(!$user || !Hash::check($data['password'],$user->password)){
            return response()->json(['error'=>'Unauthorized'],401);
        }
        $token = $user->createToken('user-token')->plainTextToken;
        return response()->json([
            'message' => 'Logged in successfully',
            'token' => $token,
        ]);
    }
}
