<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Exception;

class ForgotPasswordController extends Controller
{
    /**
     * Send Reset Link Email
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'username' => 'required|string|exists:users,username',
        ]);

        try {
            $user = User::with(['profile'])->where('username', $request->username)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 200); // return success:false instead of 404
            }

            $token = Str::random(60);

            $user->token = $token;
            $user->save();

            $mail_content = [
                'token' => $token,
                'full_name' => $user->profile->full_name,
                'email' => $user->profile->email,
                'reset_url' => env('APP_FRONTEND_URL') . '/reset-password/' . $token,
            ];

            $to_name = $user->profile->full_name;
            $to_email = $user->profile->email;

            Mail::send('emails.forgotPassword', $mail_content, function ($message) use ($to_name, $to_email) {
                $message->to($to_email, $to_name)
                    ->subject('Password Reset - Pro Subrental Marketplace');
                $message->from(config('mail.from.address'), config('mail.from.name'));
            });

            return response()->json([
                'success' => true,
                'message' => 'Password reset link sent to your email.',
            ], 200);

        } catch (Exception $e) {
            Log::error('Exception in sendResetLink', [
                'username' => $request->username ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while sending the reset link. Please try again later.',
            ], 200);
        }
    }

    /**
     * Reset Password
     */
    public function reset(Request $request, $token)
    {
        $request->validate([
            'password' => 'required|string|min:6',
        ]);

        try {
            $user = User::where('token', $token)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired reset token.',
                ], 200);
            }

            $user->password = Hash::make($request->password);
            $user->token = null; // clear token after reset
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully.',
            ], 200);

        } catch (Exception $e) {
            Log::error('Exception in resetPassword', [
                'token' => $token ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while resetting the password. Please try again later.',
            ], 200);
        }
    }
}
