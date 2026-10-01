<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeacherResource;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Exchange a teacher's email and password for a token the app keeps on the
     * phone. Tokens do not expire: an app that works offline cannot be asked
     * to sign in again in the middle of a register taken without a connection.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $teacher = Teacher::where('email', $credentials['email'])->first();

        if (! $teacher || ! Hash::check($credentials['password'], $teacher->password)) {
            return response()->json([
                'message' => 'بيانات الدخول غير صحيحة.',
            ], 401);
        }

        if (! $teacher->is_approved) {
            return response()->json([
                'message' => 'لم يتم تفعيل حسابك من قبل الإدارة بعد.',
            ], 403);
        }

        return response()->json([
            'data' => [
                'token' => $teacher->createToken($credentials['device_name'] ?? 'teacher-app')->plainTextToken,
                'teacher' => new TeacherResource($teacher),
            ],
        ]);
    }

    /**
     * Revoke the token the request was made with, leaving the teacher's other
     * devices signed in.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'تم تسجيل الخروج.',
        ]);
    }
}
