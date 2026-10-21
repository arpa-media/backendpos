<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrDashboardI13Service;
use App\Services\HumanResource\HrDashboardV13I05Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrDashboardController extends Controller
{
    public function index(Request $request, HrDashboardI13Service $dashboard, HrDashboardV13I05Service $v13Dashboard)
    {
        $i13 = $dashboard->build($request);
        $v13 = $v13Dashboard->build($request, $i13);
        return ApiResponse::ok([
            'outlets' => $v13['outlets'],
            'squads' => $v13['squads'],
            'i13' => $i13,
            'v13_i05' => $v13,
        ], 'OK');
    }

}
