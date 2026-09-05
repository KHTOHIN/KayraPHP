<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use Kayra\Exceptions\ValidationException;
use Kayra\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The validator, including the parts that are load-bearing for security.
 *
 * The one that matters most is {@see only_validated_fields_come_back}: the
 * whole point of validate() returning data rather than a boolean is that an
 * unvalidated key can never reach a mass-assignment call.
 */
#[CoversClass(Validator::class)]
final class ValidationTest extends TestCase
{
    /* --------------------------------------------------------------------
     | The contract
     * -------------------------------------------------------------------- */

    #[Test]
    public function only_validated_fields_come_back(): void
    {
        // `is_admin` had no rule, so it is not in the result. Anything else and
        // Model::fill() would be handed a key nobody vetted.
        $data = Validator::make(
            ['name' => 'Kawsar', 'is_admin' => '1'],
            ['name' => 'required|string'],
        )->validate();

        $this->assertSame(['name' => 'Kawsar'], $data);
    }

    #[Test]
    public function validate_throws_with_the_messages_attached(): void
    {
        try {
            Validator::make(['email' => 'nope'], ['email' => 'required|email'])->validate();
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors);
            $this->assertStringContainsString('valid email address', $e->errors['email'][0]);
        }
    }

    #[Test]
    public function a_field_reports_one_message_not_a_pile(): void
    {
        $errors = Validator::make(['n' => ''], ['n' => 'required|string|min:5|email'])->errors();

        $this->assertCount(1, $errors['n']);
    }

    #[Test]
    public function nullable_lets_an_empty_optional_field_through(): void
    {
        $v = Validator::make(['bio' => ''], ['bio' => 'nullable|string|min:10']);

        $this->assertTrue($v->passes());
        $this->assertSame(['bio' => ''], $v->validated());
    }

    #[Test]
    public function sometimes_only_applies_when_the_key_is_present(): void
    {
        $this->assertTrue(Validator::make([], ['age' => 'sometimes|integer'])->passes());
        $this->assertFalse(Validator::make(['age' => 'x'], ['age' => 'sometimes|integer'])->passes());
    }

    #[Test]
    public function a_custom_rule_can_supply_its_own_message(): void
    {
        $errors = Validator::make(['code' => 'xyz'], ['code' => 'even_length'])
            ->extend('even_length', static fn (mixed $v): bool|string => is_string($v) && mb_strlen($v) % 2 === 0
                ? true
                : 'The code must have an even number of characters.')
            ->errors();

        $this->assertSame(['The code must have an even number of characters.'], $errors['code']);
    }

    #[Test]
    public function a_caller_supplied_message_wins(): void
    {
        $errors = Validator::make(
            ['email' => ''],
            ['email' => 'required'],
            ['email.required' => 'We need somewhere to send the receipt.'],
        )->errors();

        $this->assertSame(['We need somewhere to send the receipt.'], $errors['email']);
    }

    /* --------------------------------------------------------------------
     | Rules
     * -------------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('passingCases')]
    public function it_accepts_valid_input(array $data, string $rules): void
    {
        $this->assertTrue(
            Validator::make($data, ['f' => $rules])->passes(),
            'expected ' . var_export($data['f'] ?? null, true) . " to pass [{$rules}]",
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function passingCases(): iterable
    {
        yield 'email'            => [['f' => 'a@b.co'], 'email'];
        yield 'url'              => [['f' => 'https://kayra.dev/x'], 'url'];
        yield 'ip'               => [['f' => '192.168.0.1'], 'ip'];
        yield 'uuid'             => [['f' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301'], 'uuid'];
        yield 'integer string'   => [['f' => '-42'], 'integer'];
        yield 'numeric float'    => [['f' => '3.14'], 'numeric'];
        yield 'boolean zero'     => [['f' => '0'], 'boolean'];
        yield 'alpha unicode'    => [['f' => 'সোনার'], 'alpha'];
        yield 'alpha_num'        => [['f' => 'kayra85'], 'alpha_num'];
        yield 'alpha_dash'       => [['f' => 'kayra-php_8'], 'alpha_dash'];
        yield 'in'               => [['f' => 'draft'], 'in:draft,published'];
        yield 'not_in'           => [['f' => 'draft'], 'not_in:spam,deleted'];
        yield 'regex'            => [['f' => 'AB12'], 'regex:/^[A-Z]{2}\d{2}$/'];
        yield 'between length'   => [['f' => 'abcd'], 'between:2,8'];
        yield 'size length'      => [['f' => 'abcd'], 'size:4'];
        yield 'array count'      => [['f' => [1, 2, 3]], 'array|max:3'];
        yield 'accepted'         => [['f' => 'yes'], 'accepted'];
        yield 'date'             => [['f' => '2026-09-04'], 'date'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('failingCases')]
    public function it_rejects_invalid_input(array $data, string $rules): void
    {
        $this->assertFalse(
            Validator::make($data, ['f' => $rules])->passes(),
            'expected ' . var_export($data['f'] ?? null, true) . " to fail [{$rules}]",
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function failingCases(): iterable
    {
        yield 'missing required'   => [[], 'required'];
        yield 'blank required'     => [['f' => '   '], 'required'];
        yield 'empty array'        => [['f' => []], 'required'];
        yield 'bad email'          => [['f' => 'a@b'], 'email'];
        yield 'bad url'            => [['f' => 'kayra.dev'], 'url'];
        yield 'bad ip'             => [['f' => '999.1.1.1'], 'ip'];
        yield 'bad uuid'           => [['f' => 'not-a-uuid'], 'uuid'];
        yield 'float as integer'   => [['f' => '4.5'], 'integer'];
        yield 'alpha with digit'   => [['f' => 'kayra8'], 'alpha'];
        yield 'not in list'        => [['f' => 'archived'], 'in:draft,published'];
        yield 'in the deny list'   => [['f' => 'spam'], 'not_in:spam,deleted'];
        yield 'regex mismatch'     => [['f' => 'ab12'], 'regex:/^[A-Z]{2}\d{2}$/'];
        yield 'too short'          => [['f' => 'ab'], 'min:3'];
        yield 'too long'           => [['f' => 'abcdef'], 'max:3'];
        yield 'array over max'     => [['f' => [1, 2, 3, 4]], 'array|max:3'];
        yield 'not accepted'       => [['f' => 'no'], 'accepted'];
        yield 'not a date'         => [['f' => 'someday'], 'date'];
        yield 'age below range'    => [['f' => '5'], 'integer|between:13,120'];
        yield 'age above range'    => [['f' => '121'], 'integer|between:13,120'];
    }

    /* --------------------------------------------------------------------
     | Regressions
     * -------------------------------------------------------------------- */

    #[Test]
    public function a_size_rule_on_a_numeric_field_compares_the_value_not_the_digits(): void
    {
        // `integer|between:13,120` is an age range. Measuring "42" as two
        // characters made every real age fail -- including the example in the
        // Validator's own docblock.
        foreach (['13', '42', '120'] as $age) {
            $this->assertTrue(
                Validator::make(['age' => $age], ['age' => 'integer|between:13,120'])->passes(),
                "age {$age} should be inside 13..120",
            );
        }

        $this->assertFalse(Validator::make(['age' => '12'], ['age' => 'integer|between:13,120'])->passes());
        $this->assertFalse(Validator::make(['age' => '121'], ['age' => 'integer|between:13,120'])->passes());
    }

    /**
     * @param string $value A word written in one script, with its combining marks.
     */
    #[Test]
    #[DataProvider('alphabeticScripts')]
    public function alpha_accepts_scripts_that_write_with_combining_marks(string $value, string $script): void
    {
        // \p{L} alone excludes \p{M}, so vowel signs and accents made a word
        // "not alphabetic" -- rejecting most of the writing systems on earth.
        $this->assertTrue(
            Validator::make(['f' => $value], ['f' => 'alpha'])->passes(),
            "{$script} should be alphabetic",
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function alphabeticScripts(): iterable
    {
        yield 'bengali'    => ['সোনার', 'Bengali'];
        yield 'devanagari' => ['हिन्दी', 'Devanagari'];
        yield 'thai'       => ['ไทย', 'Thai'];
        yield 'arabic'     => ['مرحبا', 'Arabic'];
        yield 'hebrew'     => ['שלום', 'Hebrew'];
        yield 'nfd latin'  => ["cafe\u{0301}", 'decomposed Latin'];
        yield 'ascii'      => ['Hello', 'ASCII'];
    }

    #[Test]
    public function alpha_still_rejects_digits_and_punctuation(): void
    {
        $this->assertFalse(Validator::make(['f' => 'kayra8'], ['f' => 'alpha'])->passes());
        $this->assertFalse(Validator::make(['f' => 'kayra-php'], ['f' => 'alpha'])->passes());
        $this->assertFalse(Validator::make(['f' => 'সোনার!'], ['f' => 'alpha'])->passes());
        $this->assertTrue(Validator::make(['f' => 'সোনার-বাংলা'], ['f' => 'alpha_dash'])->passes());
    }

    #[Test]
    public function confirmed_compares_against_the_confirmation_field(): void
    {
        $rules = ['password' => 'required|confirmed'];

        $this->assertTrue(
            Validator::make(['password' => 'hunter22', 'password_confirmation' => 'hunter22'], $rules)->passes(),
        );
        $this->assertFalse(
            Validator::make(['password' => 'hunter22', 'password_confirmation' => 'hunter2'], $rules)->passes(),
        );
        // No confirmation field at all must fail, not pass by absence.
        $this->assertFalse(Validator::make(['password' => 'hunter22'], $rules)->passes());
    }

    /* --------------------------------------------------------------------
     | Size messages
     |
     | "must be at least 8" is ambiguous the moment a form mixes strings and
     | numbers; the message has to say what it counted.
     * -------------------------------------------------------------------- */

    #[Test]
    public function a_string_length_message_says_characters(): void
    {
        $errors = Validator::make(['password' => 'abc'], ['password' => 'string|min:8'])->errors();

        $this->assertSame('The password field must be at least 8 characters.', $errors['password'][0]);
    }

    #[Test]
    public function an_array_message_says_items(): void
    {
        $errors = Validator::make(['tags' => [1, 2, 3, 4]], ['tags' => 'array|max:3'])->errors();

        $this->assertSame('The tags field must not be greater than 3 items.', $errors['tags'][0]);
    }

    #[Test]
    public function a_number_message_has_no_unit(): void
    {
        // `integer` casts before the size check, so this is compared as 4, not
        // as the one-character string "4" -- and the message must agree.
        $errors = Validator::make(['age' => '4'], ['age' => 'integer|min:13'])->errors();

        $this->assertSame('The age field must be at least 13.', $errors['age'][0]);
    }

    #[Test]
    public function a_numeric_string_without_a_numeric_rule_is_still_measured_by_length(): void
    {
        // max:8 on a password means eight characters even if the password is
        // all digits. The message has to reflect that, or "12345678901" failing
        // "max 8" reads as a value comparison.
        $errors = Validator::make(['pin' => '12345678901'], ['pin' => 'string|max:8'])->errors();

        $this->assertSame('The pin field must not be greater than 8 characters.', $errors['pin'][0]);
    }

    #[Test]
    public function an_underscored_field_name_is_humanised(): void
    {
        $errors = Validator::make([], ['first_name' => 'required'])->errors();

        $this->assertSame('The first name field is required.', $errors['first_name'][0]);
    }
}
