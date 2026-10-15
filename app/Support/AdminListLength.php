<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminListLength
{
    /**
     * Largest page an admin DataTable may load in one response.
     * "All" uses this as the batch size when the filtered total is larger.
     */
    public const MAX_PAGE_SIZE = 500;

    public static function resolve(Request $request, int $default = 25): int|JsonResponse
    {
        $length = filter_var($request->input('length', $default), FILTER_VALIDATE_INT);

        if ($length === false || $length < 1 || $length > self::MAX_PAGE_SIZE) {
            return response()->json([
                'draw' => intval($request->input('draw')),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Loading every row in one request is not supported. Use a page size of '
                    .self::MAX_PAGE_SIZE
                    .' or fewer. When more than '
                    .self::MAX_PAGE_SIZE
                    .' rows match, All browses them in batches of '
                    .self::MAX_PAGE_SIZE
                    .'.',
            ], 422);
        }

        return $length;
    }
}
