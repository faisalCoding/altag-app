<?php

namespace App\Http\Middleware;

use App\Models\Teacher;
use App\Support\RolePages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API's counterpart to the web's `approved` and `page.enabled` checks.
 *
 * A token outlives the decisions taken after it was issued: a teacher can be
 * suspended, or the screen a request serves can be switched off for teachers,
 * while the phone still holds a valid token. Both are asked again on every
 * request, and answered in JSON with a code the app can act on rather than
 * with the web's redirect.
 */
class EnsureTeacherCanUseApi
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string|null  $page  The teacher screen the route serves, e.g. "teacher.attendance".
     */
    public function handle(Request $request, Closure $next, ?string $page = null): Response
    {
        $teacher = $request->user();

        if (! $teacher instanceof Teacher) {
            return response()->json([
                'message' => 'هذا التطبيق مخصص للمعلمين.',
                'code' => 'not_teacher',
            ], 403);
        }

        if (! $teacher->is_approved) {
            return response()->json([
                'message' => 'لم يتم تفعيل حسابك من قبل الإدارة بعد.',
                'code' => 'not_approved',
            ], 403);
        }

        if ($page !== null && ! RolePages::isEnabled('teacher', $page)) {
            return response()->json([
                'message' => 'هذه الصفحة غير متاحة لك حالياً. تواصل مع إدارة المجمع إذا كنت تحتاجها.',
                'code' => 'page_disabled',
            ], 403);
        }

        return $next($request);
    }
}
