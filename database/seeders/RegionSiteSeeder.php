<?php

namespace Database\Seeders;

use App\Models\Region;
use App\Models\Site;
use Illuminate\Database\Seeder;

class RegionSiteSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const REGIONS = [
        'kalimantan' => 'Kalimantan',
        'sulawesi' => 'Sulawesi',
    ];

    /**
     * @var array<string, array{name: string, region: string, region_slug: string}>
     */
    private const SITES = [
        'adaro' => ['name' => 'ADARO', 'region' => 'Kalimantan Selatan', 'region_slug' => 'kalimantan'],
        'bpn' => ['name' => 'BPN', 'region' => 'Kalimantan Timur', 'region_slug' => 'kalimantan'],
        'gorontalo' => ['name' => 'GORONTALO', 'region' => 'Gorontalo', 'region_slug' => 'sulawesi'],
        'kendari' => ['name' => 'KENDARI', 'region' => 'Sulawesi Tenggara', 'region_slug' => 'sulawesi'],
        'loa-kulu' => ['name' => 'LOA KULU', 'region' => 'Kalimantan Timur', 'region_slug' => 'kalimantan'],
        'loajanan' => ['name' => 'LOAJANAN', 'region' => 'Kalimantan Timur', 'region_slug' => 'kalimantan'],
        'loreh' => ['name' => 'LOREH', 'region' => 'Kalimantan Utara', 'region_slug' => 'kalimantan'],
        'm-lawa' => ['name' => 'MUARA LAWA', 'region' => 'Kalimantan Timur', 'region_slug' => 'kalimantan'],
        'makassar' => ['name' => 'MAKASSAR', 'region' => 'Sulawesi Selatan', 'region_slug' => 'sulawesi'],
        'manado' => ['name' => 'MANADO', 'region' => 'Sulawesi Utara', 'region_slug' => 'sulawesi'],
        'mks' => ['name' => 'MKS', 'region' => 'Sulawesi Selatan', 'region_slug' => 'sulawesi'],
        'sanga-sanga' => ['name' => 'SANGA SANGA', 'region' => 'Kalimantan Timur', 'region_slug' => 'kalimantan'],
        'sangatta' => ['name' => 'SANGATTA', 'region' => 'Kalimantan Timur', 'region_slug' => 'kalimantan'],
        'smd' => ['name' => 'SMD', 'region' => 'Kalimantan Timur', 'region_slug' => 'kalimantan'],
        'soroako' => ['name' => 'SOROAKO', 'region' => 'Sulawesi Selatan', 'region_slug' => 'sulawesi'],
        'tabang' => ['name' => 'TABANG', 'region' => 'Kalimantan Timur', 'region_slug' => 'kalimantan'],
        'tgr' => ['name' => 'TENGGARONG', 'region' => 'Kalimantan Timur', 'region_slug' => 'kalimantan'],
        'tj-redeb' => ['name' => 'TJ. REDEB', 'region' => 'Kalimantan Timur', 'region_slug' => 'kalimantan'],
        'tarakan' => ['name' => 'TARAKAN', 'region' => 'Kalimantan Utara', 'region_slug' => 'kalimantan'],
        'separi' => ['name' => 'SEPARI', 'region' => 'Kalimantan Timur', 'region_slug' => 'kalimantan'],
        'jakarta' => ['name' => 'JAKARTA', 'region' => 'DKI Jakarta', 'region_slug' => 'kalimantan'],
        'ho' => ['name' => 'HO', 'region' => 'DKI Jakarta', 'region_slug' => 'kalimantan'],
    ];

    public function run(): void
    {
        $regions = [];

        foreach (self::REGIONS as $slug => $name) {
            $regions[$slug] = Region::query()->updateOrCreate(['name' => $name]);
        }

        foreach (self::SITES as $site) {
            Site::query()->updateOrCreate(
                ['name' => $site['name']],
                [
                    'region' => $site['region'],
                    'region_id' => $regions[$site['region_slug']]->id,
                ],
            );
        }
    }
}
