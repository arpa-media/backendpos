<?php

namespace App\Http\Controllers\Api\V1\Career;

use App\Http\Controllers\Controller;
use App\Models\HumanResource\HrCareerAccount;
use App\Services\HumanResource\HrCareerAccountService;
use App\Services\HumanResource\HrCareerRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrCareerAuthController extends Controller
{
    public function __construct(private readonly HrCareerAccountService $accounts) {}

    public function register(Request $request): JsonResponse
    {
        $data=$request->validate(['nik'=>['required','string','regex:/^[0-9]{16}$/'],'full_name'=>['required','string','max:200'],'phone'=>['required','string','min:8','max:40'],'email'=>['nullable','email','max:190']]);
        return response()->json(['success'=>true,'data'=>$this->accounts->register($data),'message'=>'Request registrasi berhasil dikirim. Tunggu approval admin Recruitment.'],201);
    }

    public function login(Request $request): JsonResponse
    {
        $data=$request->validate(['nik'=>['required','string','regex:/^[0-9]{16}$/'],'password'=>['required','string','max:255']]);
        return response()->json(['success'=>true,'data'=>$this->accounts->login($data['nik'],$data['password'])]);
    }

    public function requestPasswordReset(Request $request, HrCareerRecoveryService $recovery): JsonResponse
    {
        $data = $request->validate([
            'nik' => ['required','string','regex:/^[0-9]{16}$/'],
            'phone' => ['required','string','min:8','max:40'],
        ]);
        return response()->json([
            'success' => true,
            'data' => $recovery->requestPasswordReset($data),
            'message' => 'Pengajuan reset password berhasil dikirim. Tunggu approval admin Recruitment.',
        ], 201);
    }

    public function me(Request $request): JsonResponse { /** @var HrCareerAccount $a */ $a=$request->user(); return response()->json(['success'=>true,'data'=>$this->accounts->accountPayload($a)]); }
    public function logout(Request $request): JsonResponse { $request->user()?->currentAccessToken()?->delete(); return response()->json(['success'=>true,'data'=>null,'message'=>'Logout berhasil.']); }
    public function changePassword(Request $request): JsonResponse
    {
        $data=$request->validate(['current_password'=>['required','string','max:255'],'password'=>['required','string','min:8','max:255','confirmed','different:current_password']]);
        $this->accounts->changePassword($request->user(),$data['current_password'],$data['password']);
        return response()->json(['success'=>true,'data'=>null,'message'=>'Password Career berhasil diubah.']);
    }
}
