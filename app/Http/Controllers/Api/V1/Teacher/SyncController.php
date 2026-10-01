<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Services\TeacherSyncSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /**
     * The full set of data the teacher app stores on the phone, which it
     * replaces its local copy with on every sync.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'data' => TeacherSyncSnapshot::for($request->user()),
        ]);
    }
}
