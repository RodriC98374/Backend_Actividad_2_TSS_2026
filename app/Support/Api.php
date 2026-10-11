<?php

namespace App\Support;

use Illuminate\Http\Request;

class Api
{
    public static function data(mixed $data, int $status = 200)
    {
        return response()->json(['data' => $data], $status);
    }

    public static function page($query, Request $request, ?callable $map = null)
    {
        $v = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $page = $query->paginate($v['per_page'] ?? 50);

        return response()->json(['data' => $map ? $page->getCollection()->map($map)->values() : $page->items(), 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public static function canonical(array $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function revision(int $current, int $expected): void
    {
        if ($current !== $expected) {
            throw new ApiProblem('revision_conflict', 'La revisión cambió; vuelva a consultar el recurso.', 409);
        }
    }

    public static function contacts(Request $request): void
    {
        if (! $request->user()?->can_view_contacts) {
            throw new ApiProblem('forbidden', 'Se requiere permiso para consultar contactos y originales.', 403);
        }
    }
}
