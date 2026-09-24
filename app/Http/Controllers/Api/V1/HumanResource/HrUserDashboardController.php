<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HrUserDashboardService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrUserDashboardController extends Controller
{
    public function show(Request $request, HrUserDashboardService $dashboard)
    {
        $user = $request->user()->loadMissing([
            'roles',
            'employee.assignment.outlet',
            'outlet',
            'accessAssignment.role',
            'accessAssignment.level',
        ]);

        $context = $dashboard->context($user);
        if (! ($context['eligible'] ?? false)) {
            return ApiResponse::error(
                'Dashboard user hanya tersedia untuk Data Squad operasional berstatus active.',
                'HR_USER_DASHBOARD_FORBIDDEN',
                403
            );
        }

        return ApiResponse::ok($dashboard->dashboard($user), 'OK');
    }

    public function updateProfile(Request $request, HrUserDashboardService $dashboard)
    {
        $user = $request->user()->loadMissing([
            'roles',
            'employee.assignment.outlet',
            'outlet',
            'accessAssignment.role',
            'accessAssignment.level',
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'nickname' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:180', Rule::unique('users', 'email')->ignore($user->id)],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', 'max:30'],
            'religion' => ['nullable', 'string', 'max:60'],
            'education' => ['nullable', 'string', 'max:80'],
            'marital_status' => ['nullable', 'string', 'max:80'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        $result = $dashboard->updateSelfProfile($user, $data, $request->file('photo'));

        return ApiResponse::ok([
            'dashboard' => $result['dashboard'],
            'user' => [
                'id' => (string) $result['user']->id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'nisj' => $result['user']->nisj,
            ],
        ], 'Profil berhasil diperbarui');
    }
}
