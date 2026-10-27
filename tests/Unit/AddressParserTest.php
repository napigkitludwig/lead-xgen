<?php

namespace Tests\Unit;

use App\Support\AddressParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AddressParserTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: array{town: ?string, zip_code: ?string, country: ?string}}>
     */
    public static function addresses(): array
    {
        return [
            'philippines with region' => [
                'Centuria Medical Makati, 1408 Kalayaan Ave, Poblacion, Makati City, 1210 Metro Manila',
                null,
                ['town' => 'Makati City', 'zip_code' => '1210', 'country' => null],
            ],
            'philippines country from search hint' => [
                '3rd Floor, 4726 Eduque, Poblacion, Makati City, 1200 Metro Manila',
                'Makati, Philippines',
                ['town' => 'Makati City', 'zip_code' => '1200', 'country' => 'Philippines'],
            ],
            'united states' => [
                '123 Main St, Austin, TX 78701, United States',
                null,
                ['town' => 'Austin', 'zip_code' => '78701', 'country' => 'United States'],
            ],
            'united kingdom town shares the zip segment' => [
                '10 Downing St, London SW1A 2AA, United Kingdom',
                null,
                ['town' => 'London', 'zip_code' => 'SW1A 2AA', 'country' => 'United Kingdom'],
            ],
            'germany zip before town' => [
                'Unter den Linden 77, 10115 Berlin, Germany',
                null,
                ['town' => 'Berlin', 'zip_code' => '10115', 'country' => 'Germany'],
            ],
            'no zip falls back to last segment' => [
                'Rizal Street, Cebu City, Philippines',
                null,
                ['town' => 'Cebu City', 'zip_code' => null, 'country' => 'Philippines'],
            ],
            'missing address uses the city search' => [
                null,
                'Cebu City',
                ['town' => 'Cebu City', 'zip_code' => null, 'country' => null],
            ],
            'a country search is not a town' => [
                null,
                'Singapore',
                ['town' => null, 'zip_code' => null, 'country' => 'Singapore'],
            ],
        ];
    }

    /**
     * @param  array{town: ?string, zip_code: ?string, country: ?string}  $expected
     */
    #[DataProvider('addresses')]
    public function test_it_splits_an_address(?string $address, ?string $hint, array $expected): void
    {
        $this->assertSame($expected, AddressParser::parse($address, $hint));
    }
}
