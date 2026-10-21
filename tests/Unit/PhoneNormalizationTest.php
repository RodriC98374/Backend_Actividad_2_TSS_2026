<?php

namespace Tests\Unit;

use App\Domain\Surveys\Normalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizationTest extends TestCase
{
    public function test_phone_accepts_local_and_international_numbers_without_newlines(): void
    {
        $normalizer = new Normalizer;
        foreach (['70701234', '+591 7070-1234', '(4) 4123456'] as $phone) {
            $answer = $normalizer->field(['id' => 'T33', 'type' => 'contact'], $phone);
            $this->assertSame('respondida', $answer['state']);
            $this->assertSame($phone, $answer['value']);
        }
    }

    public function test_phone_rejects_letters_embedded_newlines_and_short_numbers(): void
    {
        $normalizer = new Normalizer;
        foreach (['abc12345', "7070\n1234", '123'] as $phone) {
            $answer = $normalizer->field(['id' => 'T33', 'type' => 'contact'], $phone);
            $this->assertSame('invalida', $answer['state']);
            $this->assertNull($answer['value']);
        }
    }
}
