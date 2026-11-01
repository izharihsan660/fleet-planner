<?php

namespace Tests\Unit;

use App\Support\VehicleGroupResolver;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VehicleGroupResolverTest extends TestCase
{
    #[DataProvider('groupCases')]
    public function test_resolves_each_model_group_using_type_only(string $type, string $expectedGroup): void
    {
        $this->assertSame($expectedGroup, (new VehicleGroupResolver)->resolve($type));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function groupCases(): array
    {
        return [
            'hilux dc' => ['TOYOTA HILUX DC', 'HILUX 2.4 DC'],
            'hilux sc' => ['TOYOTA HILUX SC', 'HILUX 2.4 SC'],
            'hilux rangga' => ['TOYOTA HILUX RANGGA PICK UP 2,0 STD M', 'HILUX RANGGA 2.0'],
            'innova reborn 2.4' => ['TOYOTA ALL NEW INNOVA 2,4G M', 'INNOVA REBORN 2.4'],
            'innova reborn unknown cc' => ['TOYOTA INNOVA REBORN', 'INNOVA REBORN (CC TIDAK JELAS)'],
            'innova 2.0' => ['TOYOTA ALL NEW INNOVA 2,0 G A', 'INNOVA 2.0'],
            'innova zenix' => ['TOYOTA KIJANG INNOVA ZENIX 2,0G CVT', 'INNOVA ZENIX 2.0'],
            'avanza' => ['T. NEW AVANZA 1.3 G M', 'AVANZA 1.3'],
            'all new veloz' => ['TOYOTA ALL NEW AVANZA 1,5 VELOZ M', 'VELOZ 1.5 (ALL NEW)'],
            'new veloz' => ['TOYOTA NEW AVANZA VELOZ 1,5 M', 'VELOZ 1.5 (NEW)'],
            'agya' => ['TOYOTA ALL NEW AGYA 1,2 G AT', 'AGYA 1.2'],
            'fortuner 2.4' => ['TOYOTA ALL NEW FORTUNER 2,4 VRZ 4X4 A', 'FORTUNER 2.4'],
            'fortuner 2.7' => ['TOYOTA FORTUNER 2,7V AUTOMATIC TCN51', 'FORTUNER 2.7'],
            'triton dc' => ['MITSUBISHI ALL NEW TRITON DC GLS 4X4 M', 'TRITON 2.4 DC'],
            'triton sc' => ['MITSUBISHI ALL NEW TRITON 2,5L SC HDX-L 4X4 M', 'TRITON 2.5 SC'],
            'pajero sport' => ['MITSUBISHI PAJERO SPORT 2.4 L DAKAR 4X4 A', 'PAJERO SPORT 2.4'],
            'canter fe71' => ['MITSUBISHI CANTER FE 71L 3,907 CC BC N 4X2 M', 'CANTER FE 71'],
            'canter fe84' => ['MITSUBISHI CANTER FE 84G 3908 CC BC 4X2 M', 'CANTER FE 84'],
            'canter unknown type' => ['MITSUBISHI CANTER', 'CANTER (TIPE TIDAK JELAS)'],
            'gran max pickup' => ['DAIHATSU GRAN MAX PU AC PS 1.5', 'GRAN MAX PU 1.5'],
            'gran max van' => ['DAIHATSU GRAN MAX 1.3 BLIND VAN ACS401RV-BMREJJ', 'GRAN MAX BV 1.3'],
            'terios' => ['DAIHATSU NEW TERIOS 1,5 R A', 'TERIOS 1.5'],
            'dmax' => ['ISUZU D-MAX RODEO DC VGS 4X4 M', 'D-MAX DC'],
            'elf' => ['ISUZU ELF NLR 55B LX', 'ELF NLR 55'],
            'elf mikrobus' => ['ISUZU NLR 55B LX MIKROBUS 2,771 (20 SEAT + AC)', 'ELF NLR 55 MIKROBUS'],
            'nqr' => ['ISUZU NQR 71 EC E2-1 5,0 BUS', 'NQR 71'],
            'scorpio' => ['MAHINDRA SCORPIO PIKUP DC E11 WD 2,2 4X4 M', 'SCORPIO PU DC'],
            'xtrail' => ['NEW NISSAN X-TRAIL 2,5 ST A', 'X-TRAIL 2.5'],
            'picanto' => ['KIA NEW PICANTO SE 1,1L M', 'PICANTO 1.1'],
            'mercedes' => ['MERC BENZ C200 CGI AT', 'MERCEDES C200'],
        ];
    }

    public function test_all_configured_aliases_resolve_without_collisions(): void
    {
        $resolver = new VehicleGroupResolver;
        $seen = [];
        $groups = config('vehicle_groups');
        $this->assertCount(30, $groups);

        foreach ($groups as $group => $types) {
            foreach ($types as $type) {
                $normalizedType = Str::upper(Str::squish($type));
                $this->assertArrayNotHasKey($normalizedType, $seen);
                $this->assertSame($group, $resolver->resolve($type));
                $seen[$normalizedType] = $group;
            }
        }

        $this->assertCount(67, $seen);
    }

    public function test_normalizes_case_and_whitespace_in_both_type_and_config_aliases(): void
    {
        $this->assertSame('HILUX 2.4 DC', (new VehicleGroupResolver)->resolve("  Toyota  \t hilux \n DC  "));
        config(['vehicle_groups' => ['CUSTOM GROUP' => ["  Toyota  \t Hilux  DC  "]]]);

        $this->assertSame('CUSTOM GROUP', (new VehicleGroupResolver)->resolve("\n toyota Hilux   dc  "));
    }

    public function test_unknown_types_are_ungrouped_without_fuzzy_or_punctuation_matching(): void
    {
        $resolver = new VehicleGroupResolver;

        foreach (['MODEL BARU', '0', 'HILUX DC', 'TOYOTA TOYOTA HILUX DC', 'TOYOTA ALL NEW HILUX 2.4G DC 4X4 M', 'NEW NISSAN X TRAIL 2,5 ST A'] as $type) {
            $this->assertSame('BELUM DIKELOMPOKKAN', $resolver->resolve($type));
        }
    }

    public function test_null_empty_and_whitespace_types_are_unknown(): void
    {
        $resolver = new VehicleGroupResolver;

        foreach ([null, '', "  \t\r\n  "] as $type) {
            $this->assertSame('TIDAK DIKETAHUI', $resolver->resolve($type));
        }
    }
}
