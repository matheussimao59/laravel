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
        $pairs = fn (array $items): array => array_map(
            fn (array $item) => ['name' => $item[0], 'age' => $item[1]],
            $items
        );

        return [
            // Rotulados: formatos originais preservados.
            [['Nome: Ana Clara', 'Idade: 5 anos'], 'identified', 'Ana Clara', '5 anos', $pairs([['Ana Clara', '5 anos']])],
            [["Nome: João Pedro\nIdade: 6"], 'identified', 'João Pedro', '6 anos', $pairs([['João Pedro', '6 anos']])],
            [['Maria Clara,5 anos'], 'identified', 'Maria Clara', '5 anos', $pairs([['Maria Clara', '5 anos']])],
            [['Nome: Ana Clara | Idade: 5 anos'], 'identified', 'Ana Clara', '5 anos', $pairs([['Ana Clara', '5 anos']])],
            [["Ana\n6 meses"], 'identified', 'Ana', '6 meses', $pairs([['Ana', '6 meses']])],
            [['Nome: Ana', 'Idade: 1 mes'], 'identified', 'Ana', '1 mes', $pairs([['Ana', '1 mes']])],
            [['Nome: Ana'], 'review', 'Ana', null, []],
            [['Nome: Ana', 'Idade: 25/10/2026'], 'review', 'Ana', null, []],
            [['Nome: Ana', 'Idade: 5 anos', 'Nome: Maria'], 'review', null, '5 anos', []],
            [['Nome: Ana', 'Idade: 5 anos', 'Na verdade, corrigir para 6 anos'], 'review', 'Ana', '5 anos', []],
            [['Nome: Ana?', 'Idade: 5 anos'], 'review', null, '5 anos', []],
            [['Nome: Ana', 'Idade: 5 anos', 'Nome: Bruno?'], 'review', 'Ana', '5 anos', []],
            [['Nome: Ana', 'Idade: 20 unidades'], 'review', 'Ana', null, []],
            [['Sem nome e idade, por favor'], 'no_personalization', null, null, []],
            [['Bom dia 5 anos'], 'awaiting', null, null, []],
            [['Joao e Ana 5 anos'], 'awaiting', null, null, []],
            [['Meu telefone e 11999999999. Preciso de 20 unidades.'], 'awaiting', null, null, []],
            [['Pedido 2000015000000000'], 'awaiting', null, null, []],
            [['Meu nome é Maria', 'Idade: 5 anos'], 'review', null, '5 anos', []],

            // Qualquer nome: acento, hifen, compostos e idades em meses.
            [['Benícia 4 anos'], 'identified', 'Benícia', '4 anos', $pairs([['Benícia', '4 anos']])],
            [['Ana-Sofia 3 anos'], 'identified', 'Ana-Sofia', '3 anos', $pairs([['Ana-Sofia', '3 anos']])],
            [['Eduardo 7 anos'], 'identified', 'Eduardo', '7 anos', $pairs([['Eduardo', '7 anos']])],
            [['Maria Clara, 5 anos. Obrigado!'], 'identified', 'Maria Clara', '5 anos', $pairs([['Maria Clara', '5 anos']])],
            [['Maria 9 meses'], 'identified', 'Maria', '9 meses', $pairs([['Maria', '9 meses']])],

            // Dois pares na mesma mensagem: "Matheus 8 anos Lara 8 anos".
            [['Matheus 8 anos Lara 8 anos'], 'identified', null, null, $pairs([['Matheus', '8 anos'], ['Lara', '8 anos']])],
            [['Matheus 8 anos e Lara 8 anos'], 'identified', null, null, $pairs([['Matheus', '8 anos'], ['Lara', '8 anos']])],
            [['Ana 2 anos, Lara 3 anos'], 'identified', null, null, $pairs([['Ana', '2 anos'], ['Lara', '3 anos']])],
            [['Bom dia, quero Maria 8 anos e Lara 8 anos'], 'identified', null, null, $pairs([['Maria', '8 anos'], ['Lara', '8 anos']])],
            [['Nome: Matheus 8 anos', 'Nome: Lara 8 meses'], 'identified', null, null, $pairs([['Matheus', '8 anos'], ['Lara', '8 meses']])],

            // Dois pares em mensagens separadas ou rotuladas em sequencia.
            [['Nome: Ana', 'Idade: 5 anos', 'Nome: Lara', 'Idade: 7 anos'], 'identified', null, null, $pairs([['Ana', '5 anos'], ['Lara', '7 anos']])],
            [['Matheus 8 anos', 'Lara 8 anos'], 'identified', null, null, $pairs([['Matheus', '8 anos'], ['Lara', '8 anos']])],

            // Nome numa mensagem, idade na outra (formato livre).
            [['Matheus', '6 anos'], 'identified', 'Matheus', '6 anos', $pairs([['Matheus', '6 anos']])],
            [['Nome: Ana', '6 anos'], 'identified', 'Ana', '6 anos', $pairs([['Ana', '6 anos']])],

            // Idade antes do nome: "9 anos de Lara".
            [['Ana 8 anos, 9 anos de Lara'], 'identified', null, null, $pairs([['Ana', '8 anos'], ['Lara', '9 anos']])],
            [['8 anos pra Bea'], 'identified', 'Bea', '8 anos', $pairs([['Bea', '8 anos']])],

            // Ambiguidades realistas ficam para revisao ou aguardando.
            [['Matheus 8 anos Lara'], 'awaiting', null, null, []],
            [['Maria 8 anos', 'Lara'], 'review', 'Maria', '8 anos', []],
            [['Maria 8 anos Maria 9 anos'], 'review', 'Maria', null, []],
            [['Onze 8 anos'], 'awaiting', null, null, []],
        ];
    }

    #[DataProvider('samples')]
    public function test_conservative_extraction(array $texts, string $status, ?string $name, ?string $age, array $expectedPairs): void
    {
        $result = (new MlPersonalizationExtractor())->extract(array_map(fn ($text) => ['text' => $text], $texts));
        $this->assertSame($status, $result['status']);
        $this->assertSame($name, $result['name']);
        $this->assertSame($age, $result['age']);
        $this->assertSame($expectedPairs, $result['pairs']);
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
