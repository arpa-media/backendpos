<?php

namespace Tests\Unit;

use App\Support\PosPricingV16I02;
use App\Support\PosPricingV2HydrationRecoveryV21HF01;
use PHPUnit\Framework\TestCase;

final class PosPricingV2HydrationRecoveryV21HF01Test extends TestCase
{
    private function onlinePayload(): array
    {
        return [
            'client_sync_id' => 'test-v2-online-client-1',
            'pricing_rule_version' => 2,
            'pricing_rule_kind' => 'ONLINE_500',
            'channel' => 'DELIVERY',
            'items' => [
                [
                    'product_id' => 'p-500', 'variant_id' => 'v-500',
                    'channel' => 'DELIVERY', 'qty' => 1,
                    'unit_price_snapshot' => 500, 'line_total_snapshot' => 500,
                ],
                [
                    'product_id' => 'p-2300', 'variant_id' => 'v-2300',
                    'channel' => 'DELIVERY', 'qty' => 1,
                    'unit_price_snapshot' => 2300, 'line_total_snapshot' => 2300,
                ],
            ],
            'payment' => ['payment_method_id' => 'pm-1', 'amount' => 2500],
            'offline_snapshot' => [
                'subtotal' => 2800, 'discount_amount' => 0,
                'tax_total' => 0, 'tax_percent_snapshot' => 0,
                'rounding_total' => -300, 'grand_total' => 2500,
                'paid_total' => 2500, 'change_total' => 0,
            ],
        ];
    }

    private function squadPayload(): array
    {
        $raw = [
            'product_id' => 'p-10000', 'variant_id' => 'v-10000',
            'channel' => 'DINE_IN', 'qty' => 1,
            'category_kind_snapshot' => 'FOOD',
            'unit_price_snapshot' => 10000, 'line_total_snapshot' => 10000,
        ];
        $v2 = PosPricingV16I02::squad([
            [
                'product_id' => 'p-10000', 'qty' => 1,
                'unit_price' => 10000, 'line_total' => 10000,
                'category_kind_snapshot' => 'FOOD',
            ],
        ], ['p-10000'], 'squad-01', 10, 'DINE_IN');
        self::assertNotNull($v2);

        return [
            'client_sync_id' => 'test-v2-squad-client-1',
            'pricing_rule_version' => 2,
            'pricing_rule_kind' => 'SQUAD',
            'channel' => 'DINE_IN',
            'discount_squad_nisj' => 'squad-01',
            'items' => [$raw],
            'payment' => ['payment_method_id' => 'pm-1', 'amount' => $v2['grand_total']],
            'offline_snapshot' => [
                'subtotal' => $v2['subtotal'],
                'discount_amount' => $v2['discount_amount'],
                'tax_total' => $v2['tax_total'],
                'tax_percent_snapshot' => 10,
                'rounding_total' => $v2['rounding_total'],
                'grand_total' => $v2['grand_total'],
                'paid_total' => $v2['grand_total'], 'change_total' => 0,
                'discounts_snapshot' => [[
                    'id' => 'discount-01',
                    'applies_to' => 'SQUAD',
                    'product_ids' => ['p-10000'],
                    'customer_ids' => [],
                ]],
            ],
        ];
    }

    public function test_dropped_v2_online_contract_with_original_prices_is_recoverable(): void
    {
        $payload = $this->onlinePayload();
        self::assertTrue(PosPricingV2HydrationRecoveryV21HF01::canRecover($payload));

        $expected = PosPricingV16I02::online('DELIVERY', [
            ['qty' => 1, 'unit_price' => 500, 'line_total' => 500, 'channel' => 'DELIVERY'],
            ['qty' => 1, 'unit_price' => 2300, 'line_total' => 2300, 'channel' => 'DELIVERY'],
        ], 2800, 0, 0, 0);
        self::assertSame(-300, $expected['rounding_total']);
        self::assertSame(2500, $expected['grand_total']);
    }

    public function test_dropped_v2_squad_contract_with_original_prices_is_recoverable(): void
    {
        self::assertTrue(PosPricingV2HydrationRecoveryV21HF01::canRecover($this->squadPayload()));
    }

    public function test_complete_or_partial_nested_contract_is_never_recovered(): void
    {
        foreach (['pricing_rule_version' => 2, 'pricing_v2' => [], 'pricing_v3' => []] as $field => $value) {
            $payload = $this->onlinePayload();
            $payload['offline_snapshot'][$field] = $value;
            self::assertFalse(PosPricingV2HydrationRecoveryV21HF01::canRecover($payload));
        }
    }

    public function test_missing_original_line_price_or_inconsistent_line_total_is_rejected(): void
    {
        $payload = $this->onlinePayload();
        unset($payload['items'][0]['unit_price_snapshot']);
        self::assertFalse(PosPricingV2HydrationRecoveryV21HF01::canRecover($payload));

        $payload = $this->onlinePayload();
        $payload['items'][0]['line_total_snapshot'] = 999;
        self::assertFalse(PosPricingV2HydrationRecoveryV21HF01::canRecover($payload));
    }

    public function test_wrong_subtotal_or_payment_snapshot_is_rejected(): void
    {
        $payload = $this->onlinePayload();
        $payload['offline_snapshot']['subtotal'] = 2801;
        self::assertFalse(PosPricingV2HydrationRecoveryV21HF01::canRecover($payload));

        $payload = $this->onlinePayload();
        $payload['payment']['amount'] = 2499;
        self::assertFalse(PosPricingV2HydrationRecoveryV21HF01::canRecover($payload));

        $payload = $this->onlinePayload();
        $payload['offline_snapshot']['change_total'] = 100;
        self::assertFalse(PosPricingV2HydrationRecoveryV21HF01::canRecover($payload));
    }

    public function test_v3_suffix_500_must_not_be_treated_as_v2(): void
    {
        $payload = $this->onlinePayload();
        $payload['pricing_rule_version'] = 3;
        $payload['pricing_rule_kind'] = 'ONLINE_SUFFIX_500';
        self::assertFalse(PosPricingV2HydrationRecoveryV21HF01::canRecover($payload));

        $payload = $this->onlinePayload();
        $payload['items'][0]['unit_price_snapshot'] = 24500;
        $payload['items'][0]['line_total_snapshot'] = 24500;
        $payload['offline_snapshot']['subtotal'] = 26800;
        self::assertFalse(PosPricingV2HydrationRecoveryV21HF01::canRecover($payload));
    }

    public function test_squad_requires_immutable_category_and_discount_identity(): void
    {
        $payload = $this->squadPayload();
        unset($payload['items'][0]['category_kind_snapshot']);
        self::assertFalse(PosPricingV2HydrationRecoveryV21HF01::canRecover($payload));

        $payload = $this->squadPayload();
        unset($payload['offline_snapshot']['discounts_snapshot'][0]['product_ids']);
        self::assertFalse(PosPricingV2HydrationRecoveryV21HF01::canRecover($payload));
    }
}
