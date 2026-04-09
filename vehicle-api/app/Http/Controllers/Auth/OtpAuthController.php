<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\OtpCodeMail;
use App\Models\AuthToken;
use App\Models\OtpCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class OtpAuthController extends Controller
{
    private const OTP_EXPIRES_IN_MINUTES = 10;

    private const TOKEN_EXPIRES_IN_DAYS = 7;

    public function sendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower(trim($validated['email']));
        $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(self::OTP_EXPIRES_IN_MINUTES);

        OtpCode::updateOrCreate(
            ['email' => $email],
            [
                'code_hash' => Hash::make($otpCode),
                'expires_at' => $expiresAt,
            ],
        );

        try {
            Mail::to($email)->send(new OtpCodeMail($otpCode, self::OTP_EXPIRES_IN_MINUTES));
        } catch (Throwable) {
            OtpCode::where('email', $email)->delete();

            return response()->json([
                'success' => false,
                'message' => 'Unable to send OTP email right now.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully.',
        ], 200);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $email = strtolower(trim($validated['email']));
        $otp = trim($validated['otp']);

        $otpRecord = OtpCode::where('email', $email)->first();

        if (! $otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP code.',
            ], 422);
        }

        if (! $otpRecord->expires_at || $otpRecord->expires_at->isPast()) {
            $otpRecord->delete();

            return response()->json([
                'success' => false,
                'message' => 'OTP has expired.',
            ], 422);
        }

        if (! Hash::check($otp, (string) $otpRecord->code_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP code.',
            ], 422);
        }

        $otpRecord->delete();

        $plainToken = Str::random(80);
        $tokenExpiresAt = now()->addDays(self::TOKEN_EXPIRES_IN_DAYS);

        AuthToken::where('email', $email)->delete();

        AuthToken::create([
            'email' => $email,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => $tokenExpiresAt,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully.',
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $plainToken,
                'expires_at' => $tokenExpiresAt->toIso8601String(),
            ],
        ], 200);
    }
}