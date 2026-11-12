<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldSpec;
use App\Modules\Telegram\Emoji\PremiumEmojiField;
use App\Modules\Telegram\Texts\PremiumText;
use PHPUnit\Framework\TestCase;

/**
 * A generic form's premium emoji for one of its names (a server's): an id or none, kept only as one, refused beside a
 * name that starts with no emoji, and described with the name it is for.
 */
final class PremiumEmojiFieldTest extends TestCase
{
    private PremiumEmojiField $field;

    protected function setUp(): void
    {
        parent::setUp();

        $this->field = new PremiumEmojiField('emoji_id', 'meta.emoji_id', 'name', new FieldSpec('ایموجی پرمیوم نام'));
    }

    public function testItIsAnIdOrNone(): void
    {
        self::assertSame('5368324170671202286', $this->field->read(['emoji_id' => ' 5368324170671202286 '], null));
        self::assertSame('', $this->field->read(['emoji_id' => ''], '7'), 'blank: none');
        self::assertSame('', $this->field->read([], '7'), 'left out: none');

        $this->expectException(FieldRefused::class);
        $this->expectExceptionMessage(PremiumText::ID_REFUSED);
        $this->field->read(['emoji_id' => 'flag'], null);
    }

    public function testAKeptValueReadsBackOnlyAsAnId(): void
    {
        self::assertSame('7', $this->field->cast('7'));
        self::assertSame('', $this->field->cast('x7'));
        self::assertSame('', $this->field->cast(null));
    }

    public function testItNeedsAnEmojiAtItsNamesStart(): void
    {
        self::assertNull($this->field->conflict(['name' => '🇩🇪 آلمان', 'emoji_id' => '7']));
        self::assertNull($this->field->conflict(['name' => 'آلمان', 'emoji_id' => '']), 'none chosen: any name');
        self::assertSame(PremiumText::NO_EMOJI, $this->field->conflict(['name' => 'آلمان', 'emoji_id' => '7']));
    }

    public function testItIsDescribedWithTheNameItIsFor(): void
    {
        $described = $this->field->describe();

        self::assertSame(['premium_emoji', ['name'], ''], [$described['type'], $described['bound_to'], $described['default']]);
    }
}
