<?php

namespace App\Services\HumanResource;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrDevelopmentScoringService
{
    public function calculate(object $development, ?object $policy, float $rawScore): array
    {
        if (!(bool)$development->has_test) {
            throw ValidationException::withMessages(['score'=>['Development ini tidak menggunakan test.']]);
        }
        if (!$policy) {
            throw ValidationException::withMessages(['scoring_policy'=>['Scoring policy aktif belum tersedia.']]);
        }

        $config = $this->decode($policy->config);
        $mode = strtolower((string)$policy->mode);
        $final = $rawScore;
        if ($mode === 'linear') {
            $multiplier = (float)($config['multiplier'] ?? 1);
            $offset = (float)($config['offset'] ?? 0);
            $min = (float)($config['min'] ?? 0);
            $max = (float)($config['max'] ?? 100);
            if ($max < $min) [$min,$max] = [$max,$min];
            $final = max($min, min($max, ($rawScore * $multiplier) + $offset));
        } elseif ($mode !== 'bands') {
            throw ValidationException::withMessages(['scoring_policy'=>['Mode scoring hanya mendukung bands atau linear.']]);
        }

        $bands = array_values(array_filter((array)($config['bands'] ?? []), fn($v)=>is_array($v)));
        $matched = null;
        foreach ($bands as $band) {
            $min = (float)($band['min'] ?? -INF);
            $max = (float)($band['max'] ?? INF);
            if ($final >= $min && $final <= $max) { $matched = $band; break; }
        }
        if (!$matched) {
            throw ValidationException::withMessages(['score'=>['Nilai tidak masuk rentang scoring policy aktif.']]);
        }

        return [
            'raw_score'=>round($rawScore,4),
            'final_score'=>round($final,4),
            'label'=>trim((string)($matched['label'] ?? 'Result')) ?: 'Result',
            'passed'=>(bool)($matched['passed'] ?? false),
            'policy_id'=>(string)$policy->id,
            'snapshot'=>[
                'policy_id'=>(string)$policy->id,'version'=>(int)$policy->version,'name'=>(string)$policy->name,
                'mode'=>$mode,'config'=>$config,'calculated_at'=>now()->toIso8601String(),
            ],
        ];
    }

    public function activePolicy(string $developmentId): ?object
    {
        $dev = DB::table('HR_developments')->where('id',$developmentId)->first();
        if (!$dev) return null;
        if (!empty($dev->active_scoring_policy_id)) {
            $row = DB::table('HR_development_scoring_policies')->where('id',$dev->active_scoring_policy_id)->first();
            if ($row) return $row;
        }
        return DB::table('HR_development_scoring_policies')->where('development_id',$developmentId)->where('is_active',true)->orderByDesc('version')->first();
    }

    public function validateConfig(string $mode, array $config): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode,['bands','linear'],true)) {
            throw ValidationException::withMessages(['scoring_policy.mode'=>['Mode harus bands atau linear.']]);
        }
        if ($mode === 'linear') {
            foreach (['multiplier','offset','min','max'] as $key) {
                if (isset($config[$key]) && !is_numeric($config[$key])) throw ValidationException::withMessages(["scoring_policy.config.$key"=>['Harus berupa angka.']]);
            }
        }
        $bands = $config['bands'] ?? [];
        if (!is_array($bands) || $bands === []) throw ValidationException::withMessages(['scoring_policy.config.bands'=>['Minimal satu rentang hasil wajib diisi.']]);
        foreach ($bands as $i=>$band) {
            if (!is_array($band) || !isset($band['min'],$band['max']) || !is_numeric($band['min']) || !is_numeric($band['max']) || (float)$band['max'] < (float)$band['min']) {
                throw ValidationException::withMessages(["scoring_policy.config.bands.$i"=>['Rentang min/max tidak valid.']]);
            }
            if (trim((string)($band['label'] ?? '')) === '') throw ValidationException::withMessages(["scoring_policy.config.bands.$i.label"=>['Label hasil wajib diisi.']]);
        }
        return $config;
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value)==='') return [];
        $decoded=json_decode($value,true); return is_array($decoded)?$decoded:[];
    }
}
