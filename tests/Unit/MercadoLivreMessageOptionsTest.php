<?php

namespace Tests\Unit;

use App\Support\MercadoLivreMessageOptions;
use PHPUnit\Framework\TestCase;

class MercadoLivreMessageOptionsTest extends TestCase
{
    public function test_text_is_available_only_when_option_and_capacity_are_confirmed(): void
    {
        $options = MercadoLivreMessageOptions::fromGuide(
            ['options' => [['id' => 'OTHER', 'char_limit' => 200]]],
            ['options' => [['id' => 'OTHER', 'cap_available' => 1]]]
        );

        $this->assertTrue($options[0]['enabled']);
        $this->assertSame(200, $options[0]['char_limit']);
        $this->assertSame(1, $options[0]['cap_available']);
    }

    public function test_zero_capacity_cannot_be_overridden_by_another_positive_limit(): void
    {
        $options = MercadoLivreMessageOptions::fromGuide(
            ['options' => [['id' => 'OTHER', 'cap_available' => 1]]],
            ['cap_available' => 3, 'options' => [['id' => 'OTHER', 'cap_available' => 0]]]
        );

        $this->assertFalse($options[0]['enabled']);
        $this->assertSame(0, $options[0]['cap_available']);
    }

    public function test_missing_capacity_or_missing_option_does_not_allow_sending(): void
    {
        $options = MercadoLivreMessageOptions::fromGuide(['options' => [['id' => 'OTHER']]], []);
        $this->assertFalse($options[0]['enabled']);
        $this->assertNull($options[0]['cap_available']);
        $this->assertSame([], MercadoLivreMessageOptions::fromGuide([], ['cap_available' => 1]));
    }

    public function test_template_ids_are_never_invented_and_the_text_is_from_ml(): void
    {
        $guide = [['id' => 'REQUEST_VARIANTS', 'templates' => [['id' => 'ML_TEMPLATE_1', 'text' => 'Texto oficial.']]]];
        $options = MercadoLivreMessageOptions::fromGuide($guide, [['id' => 'REQUEST_VARIANTS', 'cap_available' => 1]]);

        $this->assertSame('ML_TEMPLATE_1', $options[0]['template_id']);
        $this->assertSame('Texto oficial.', $options[0]['text']);
        $this->assertSame('template', $options[0]['type']);
        $this->assertSame([], MercadoLivreMessageOptions::fromGuide([['id' => 'REQUEST_VARIANTS']], ['cap_available' => 1]));
    }

    public function test_disabled_options_remain_disabled_even_with_capacity(): void
    {
        $options = MercadoLivreMessageOptions::fromGuide(
            ['options' => [['id' => 'OTHER', 'enabled' => false]]], ['cap_available' => 1]
        );

        $this->assertFalse($options[0]['enabled']);
    }
}
