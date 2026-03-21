<?php

namespace App\Support;

use Illuminate\Http\Request;

class OutletScope
{
    public static function id(?Request $request = null): ?string
    {
        $request ??= request();
        $id = $request->attributes->get('outlet_scope_id');
        return is_string($id) ? $id : null;
    }

    public static function isAll(?Request $request = null): bool
    {
        $request ??= request();
        return ($request->attributes->get('outlet_scope_mode') === 'ALL');
    }

    public static function isLocked(?Request $request = null): bool
    {
        $request ??= request();
        return (bool) $request->attributes->get('outlet_scope_locked', false);
    }

    public static function mode(?Request $request = null): string
    {
        $request ??= request();
        return (string) $request->attributes->get('outlet_scope_mode', 'NONE');
    }

    public static function classification(?Request $request = null): ?string
    {
        $request ??= request();
        $value = $request->attributes->get('outlet_scope_classification');
        return is_string($value) ? $value : null;
    }
}
