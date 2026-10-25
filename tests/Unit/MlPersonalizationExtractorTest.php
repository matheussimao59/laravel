<?php

namespace Tests\Unit;

use App\Support\MlPersonalizationExtractor;
use App\Support\MlPendingShipment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MlPersonalizationExtractorTest extends TestCase
{
    public static function samples(): array
    {
        return [
            [['Nome: Ana Clara', 'Idade: 5 anos'], 'identified', 'Ana Clara', '5 anos'],
            [["Nome: João Pedro\nIdade: 6"], 'identified', 'João Pedro', '6 anos'],
            [['Maria Clara,5 anos'], 'identified', 'Maria Clara', '5 anos'],
            [['Nome: Ana Clara | Idade: 5 anos'], 'identified', 'Ana Clara', '5 anos'],
            [["Ana\n6 meses"], 'identified', 'Ana', '6 meses'],
            [['Nome: Ana', 'Idade: 1 mes'], 'identified', 'Ana', '1 mes'],
            [['Nome: Ana'], 'review', 'Ana', null],
            [['Nome: Ana', 'Idade: 25/10/2026'], 'review', 'Ana', null],
            [['Nome: Ana', 'Idade: 5 anos', 'Nome: Maria'], 'review', null, '5 anos'],
            [['Nome: Ana', 'Idade: 5 anos', 'Na verdade, corrigir para 6 anos'], 'review', 'Ana', '5 anos'],
            [['Nome: Ana?', 'Idade: 5 anos'], 'review', null, '5 anos'],
            [['Nome: Ana', 'Idade: 5 anos', 'Nome: Bruno?'], 'review', 'Ana', '5 anos'],
            [['Nome: Ana', 'Idade: 20 unidades'], 'review', 'Ana', null],
            [['Sem nome e idade, por favor'], 'no_personalization', null, null],
            [['Bom dia 5 anos'], 'awaiting', null, null],
            [['Joao e Ana 5 anos'], 'awaiting', null, null],
            [['Meu telefone e 11999999999. Preciso de 20 unidades.'], 'awaiting', null, null],
            [['Pedido 2000015000000000'], 'awaiting', null, null],
            [['Meu nome é Maria', 'Idade: 5 anos'], 'review', null, '5 anos'],
        ];
    }

    #[DataProvider('samples')]
    public function test_conservative_extraction(array $texts, string $status, ?string $name, ?string $age): void
    {
        $result = (new MlPersonalizationExtractor())->extract(array_map(fn ($text) => ['text' => $text], $texts));
        $this->assertSame($status, $result['status']);
        $this->assertSame($name, $result['name']);
        $this->assertSame($age, $result['age']);
    }

    public function test_only_verified_pending_shipments_are_listed_as_pending(): void
    {
        $this->assertTrue(MlPendingShipment::classify(['status' => 'paid'], ['status' => 'ready_to_ship']));
        $this->assertTrue(MlPendingShipment::classify(['status' => 'paid'], ['status' => 'handling']));
        $this->assertFalse(MlPendingShipment::classify(['status' => 'cancelled'], ['status' => 'ready_to_ship']));
        $this->assertFalse(MlPendingShipment::classify(['status' => 'paid'], ['status' => 'delivered']));
        $this->assertFalse(MlPendingShipment::classify(['status' => 'paid', 'tags' => ['shipped']], null));
        $this->assertNull(MlPendingShipment::classify(['status' => 'paid'], null));
    }
}
