<?php

namespace App\Http\Controllers;

use App\Services\RefereeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The referee's tokenised form. No account, no session identity beyond an
 * optional email-verification flag; the token resolves to one invitation and
 * the responses never include the candidate's application.
 */
class RefereeController extends Controller
{
    private const MESSAGES = [
        'invalid' => 'This link is not valid. Please use the link exactly as it appears in the email from the Institute.',
        'expired' => 'The deadline for this recommendation has passed. Please contact the Recruitment Section if you still wish to submit it.',
        'revoked' => 'This invitation has been withdrawn by the Institute. If a new invitation was issued, please use the link in the most recent email.',
        'completed' => 'Thank you. Your recommendation has already been submitted.',
    ];

    public function __construct(private RefereeService $referees) {}

    public function show(string $token): JsonResponse
    {
        [$invitation, $status] = $this->referees->resolve($token);
        if ($status !== 'valid') {
            return response()->json(['status' => $status, 'message' => self::MESSAGES[$status]], $status === 'invalid' ? 404 : 410);
        }

        $this->referees->recordOpen($invitation);

        return response()->json(['status' => 'valid', ...$this->referees->publicView($invitation)]);
    }

    public function requestCode(string $token): JsonResponse
    {
        [$invitation, $status] = $this->referees->resolve($token);
        abort_unless($status === 'valid', 410, self::MESSAGES[$status] ?? 'Invalid link.');
        if ($this->referees->requiresVerification($invitation)) {
            $this->referees->sendVerificationCode($invitation);
        }

        return response()->json(['message' => 'A verification code has been sent to the email address this invitation was sent to.']);
    }

    public function verify(Request $request, string $token): JsonResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        [$invitation, $status] = $this->referees->resolve($token);
        abort_unless($status === 'valid', 410, self::MESSAGES[$status] ?? 'Invalid link.');

        if (! $this->referees->verifyCode($invitation, $request->code)) {
            return response()->json(['message' => 'The code is incorrect or has expired. Request a new code.', 'errors' => ['code' => ['The code is incorrect or has expired.']]], 422);
        }

        return response()->json(['verified' => true]);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        [$invitation, $status] = $this->referees->resolve($token);
        if ($status !== 'valid') {
            return response()->json(['status' => $status, 'message' => self::MESSAGES[$status]], $status === 'invalid' ? 404 : 409);
        }
        abort_unless($this->referees->isVerified($invitation), 403, 'Verify your email address before submitting.');

        $response = $this->referees->submit($invitation, $request->all());

        return response()->json([
            'status' => 'completed',
            'reference_no' => $response->reference_no,
            'submitted_at' => $response->submitted_at->toIso8601String(),
            'message' => 'Thank you. Your confidential recommendation has been submitted to ABV-IIITM Gwalior.',
        ], 201);
    }
}
