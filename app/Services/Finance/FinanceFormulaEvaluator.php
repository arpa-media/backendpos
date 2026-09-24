<?php

namespace App\Services\Finance;

use InvalidArgumentException;

final class FinanceFormulaEvaluator
{
    public function evaluate(string $formula, array $context): float
    {
        $expression = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function (array $matches) use ($context): string {
            $value = data_get($context, $matches[1], 0);
            if (! is_numeric($value)) {
                throw new InvalidArgumentException("Token {{$matches[1]}} harus bernilai numerik.");
            }
            return (string) ((float) $value);
        }, trim($formula ?: '{{amount}}'));

        if ($expression === null || ! preg_match('/^[0-9eE\.\+\-\*\/\(\)\s]+$/', $expression)) {
            throw new InvalidArgumentException('Formula amount hanya boleh berisi token numerik dan operator + - * / ( ).');
        }

        $tokens = $this->tokenize($expression);
        $rpn = $this->toRpn($tokens);
        return round($this->evaluateRpn($rpn), 2);
    }

    private function tokenize(string $expression): array
    {
        $expression = preg_replace('/\s+/', '', $expression) ?? '';
        preg_match_all('/(?:\d+(?:\.\d+)?(?:[eE][+\-]?\d+)?)|[+\-*\/()]/', $expression, $matches);
        $tokens = $matches[0] ?? [];
        if (implode('', $tokens) !== $expression) {
            throw new InvalidArgumentException('Formula amount tidak valid.');
        }
        return $tokens;
    }

    private function toRpn(array $tokens): array
    {
        $output = [];
        $operators = [];
        $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
        $previous = null;

        foreach ($tokens as $token) {
            if (is_numeric($token)) {
                $output[] = $token;
                $previous = 'number';
                continue;
            }

            if ($token === '(') {
                $operators[] = $token;
                $previous = '(';
                continue;
            }

            if ($token === ')') {
                while ($operators && end($operators) !== '(') {
                    $output[] = array_pop($operators);
                }
                if (! $operators || array_pop($operators) !== '(') {
                    throw new InvalidArgumentException('Kurung formula amount tidak seimbang.');
                }
                $previous = ')';
                continue;
            }

            if (isset($precedence[$token])) {
                // Unary minus is represented as 0 - value.
                if ($token === '-' && ($previous === null || $previous === '(' || $previous === 'operator')) {
                    $output[] = '0';
                }
                while ($operators && isset($precedence[end($operators)]) && $precedence[end($operators)] >= $precedence[$token]) {
                    $output[] = array_pop($operators);
                }
                $operators[] = $token;
                $previous = 'operator';
                continue;
            }

            throw new InvalidArgumentException('Token formula amount tidak valid.');
        }

        while ($operators) {
            $operator = array_pop($operators);
            if ($operator === '(') {
                throw new InvalidArgumentException('Kurung formula amount tidak seimbang.');
            }
            $output[] = $operator;
        }

        return $output;
    }

    private function evaluateRpn(array $tokens): float
    {
        $stack = [];
        foreach ($tokens as $token) {
            if (is_numeric($token)) {
                $stack[] = (float) $token;
                continue;
            }

            if (count($stack) < 2) {
                throw new InvalidArgumentException('Formula amount tidak lengkap.');
            }
            $right = array_pop($stack);
            $left = array_pop($stack);
            $stack[] = match ($token) {
                '+' => $left + $right,
                '-' => $left - $right,
                '*' => $left * $right,
                '/' => abs($right) < 0.000000001 ? throw new InvalidArgumentException('Formula amount membagi dengan nol.') : $left / $right,
                default => throw new InvalidArgumentException('Operator formula amount tidak dikenal.'),
            };
        }

        if (count($stack) !== 1 || ! is_finite($stack[0])) {
            throw new InvalidArgumentException('Formula amount tidak menghasilkan angka valid.');
        }

        return (float) $stack[0];
    }
}
