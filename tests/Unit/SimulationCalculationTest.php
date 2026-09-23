<?php

namespace Tests\Unit;

use App\Actions\Simulations\CalculateScenario;
use PHPUnit\Framework\TestCase;

class SimulationCalculationTest extends TestCase
{
    public function test_baseline_matches_the_published_reference(): void
    {
        $result = (new CalculateScenario)->handle($this->dataset(), []);

        self::assertSame('52.55768000', $result['score']);
        self::assertSame('56.86240000', $result['average']);
        self::assertSame('49.18000000', $result['minimum']);
        self::assertCount(2, $result['critical']);
    }

    public function test_reference_scenario_accounts_for_lags_and_synergy(): void
    {
        $data = $this->dataset();
        $result = (new CalculateScenario)->handle($data, $data['example']);
        $nura = array_column($result['districts'], null, 'id')['nura'];

        self::assertSame(95, $result['cost']);
        self::assertSame(5, $result['remaining']);
        self::assertSame('56.54307000', $result['score']);
        self::assertSame('48.00000000', $nura['indicators']['S1']);
        self::assertSame('43.75000000', $nura['indicators']['S2']);
        self::assertSame('67.50000000', $nura['indicators']['B1']);
        self::assertSame([], $result['critical']);
        self::assertSame([['measures' => ['M10', 'M12'], 'district_id' => 'nura', 'indicator' => 'B1', 'delta' => '2']], $result['synergies']);
    }

    public function test_decision_order_cannot_change_scores_or_indicators(): void
    {
        $data = $this->dataset();
        $calculator = new CalculateScenario;
        $first = $calculator->handle($data, $data['example']);
        $second = $calculator->handle($data, array_reverse($data['example']));

        self::assertSame($first['score'], $second['score']);
        self::assertSame($first['districts'], $second['districts']);
    }

    public function test_negative_effects_clipping_and_strict_critical_boundary(): void
    {
        $data = $this->dataset();
        $data['districts'][0]['indicators']['B2'] = 99;
        $data['districts'][0]['indicators']['T1'] = 1;
        $result = (new CalculateScenario)->handle($data, [['measure_id' => 'M11', 'district_id' => 'esil']]);
        $esil = $result['districts'][0];

        self::assertSame('100.00000000', $esil['indicators']['B2']);
        self::assertSame('0.00000000', $esil['indicators']['T1']);
        self::assertCount(2, $result['clipping']);
        self::assertCount(3, $result['critical']);
        self::assertSame('40.00000000', $result['districts'][2]['indicators']['E2']);
    }

    public function test_city_effects_reach_every_district_and_synergies_are_not_lag_scaled(): void
    {
        $result = (new CalculateScenario)->handle($this->dataset(), [
            ['measure_id' => 'M1', 'district_id' => 'nura'],
            ['measure_id' => 'M2', 'district_id' => null],
            ['measure_id' => 'M5', 'district_id' => 'saryarka'],
            ['measure_id' => 'M6', 'district_id' => null],
        ]);
        $districts = array_column($result['districts'], null, 'id');

        self::assertSame('48.00000000', $districts['esil']['indicators']['T1']);
        self::assertSame('64.50000000', $districts['nura']['indicators']['T1']);
        self::assertSame('52.25000000', $districts['saryarka']['indicators']['E2']);
        self::assertCount(2, $result['synergies']);
    }

    /** @return array<string, mixed> */
    private function dataset(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../database/seeders/data/astana-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
