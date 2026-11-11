<?php

namespace Tests\Unit;

use App\Conversation\SocialText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SocialTextTest extends TestCase
{
    public static function messages(): array
    {
        return array_map(fn ($s) => [$s, true], ['ce ta bao ?', 'cê tá bão?', 'tá tudo certo?', 'como vc tá?', 'suave por aí?', 'Como você está?', 'oi, td bem com vc?', 'ce taa bemm?', 'E aí meu rei, tudo certo?', 'tudo bem com você? kkkk']);
    }

    #[DataProvider('messages')]
    public function test_social_lexical_variants(string $body, bool $expected): void
    {
        $this->assertSame($expected, (new SocialText)->wellbeing($body));
    }

    public function test_technical_targets_and_operational_words_are_not_personal_wellbeing(): void
    {
        foreach (['como está a rede?', 'tá tudo certo no BNG?', 'como você está configurado?', 'sim', 'confirmo', 'testa google.com', 'bem lento', 'suave.example.com'] as $body) {
            $this->assertFalse((new SocialText)->wellbeing($body), $body);
        }
    }
}
