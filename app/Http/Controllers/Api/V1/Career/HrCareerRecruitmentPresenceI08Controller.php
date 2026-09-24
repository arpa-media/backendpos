<?php

namespace App\Http\Controllers\Api\V1\Career;

use App\Http\Controllers\Controller;
use App\Services\HumanResource\HrRecruitmentPresenceI08Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrCareerRecruitmentPresenceI08Controller extends Controller
{
    public function __construct(private readonly HrRecruitmentPresenceI08Service $service) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->careerStatus($request->user())]);
    }

    public function presence(Request $request, string $id, string $flow): JsonResponse
    {
        validator(['flow' => $flow], ['flow' => ['required', Rule::in(HrRecruitmentPresenceI08Service::FLOWS)]])->validate();
        $data = $this->service->recordCareerPresence($request, $request->user(), $id, $flow);
        return response()->json(['success' => true, 'data' => $data, 'message' => 'Presensi recruitment berhasil dicatat.']);
    }
}
