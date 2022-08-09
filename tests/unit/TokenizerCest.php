<?php

namespace unit;

use Codeception\Example;
use Codeception\PHPUnit\TestCase;
use Kodus\SQL\Tokenizer;
use UnitTester;

class TokenizerCest
{
    const MESSAGE  = 0;
    const INPUT    = 1;
    const EXPECTED = 2;

    /**
     * @dataProvider useCases
     */
    public function testTokenizer(UnitTester $I, Example $example)
    {
        $message = $example[self::MESSAGE];
        $input = $example[self::INPUT];
        $expected = $example[self::EXPECTED];

        $I->assertSame($expected, Tokenizer::tokenize($input), $message);
    }

    protected function useCases(): array
    {
        return [
            [
                self::MESSAGE  => "Single unterminated statement",
                self::INPUT    => "SELECT 1",
                self::EXPECTED => [["SELECT", " ", "1"]],
            ],
            [
                self::MESSAGE  => "Single terminated statement",
                self::INPUT    => "SELECT 1",
                self::EXPECTED => [["SELECT", " ", "1"]],
            ],
            [
                self::MESSAGE  => "Multiple separated statements",
                self::INPUT    => "SELECT 1; SELECT 2",
                self::EXPECTED => [["SELECT", " ", "1"], ["SELECT", " ", "2"]],
            ],
            [
                self::MESSAGE  => "Multiple terminated statements",
                self::INPUT    => "SELECT 1; SELECT 2;",
                self::EXPECTED => [["SELECT", " ", "1"], ["SELECT", " ", "2"]],
            ],
            [
                self::MESSAGE  => "Select *",
                self::INPUT    => "SELECT * FROM bar",
                self::EXPECTED => [["SELECT", " ", "*", " ", "FROM", " ", "bar"]],
            ],
            [
                self::MESSAGE  => "Apostrophe quotes",
                self::INPUT    => "SELECT `foo` FROM `bar`",
                self::EXPECTED => [["SELECT", " ", "`foo`", " ", "FROM", " ", "`bar`"]],
            ],
            [
                self::MESSAGE  => "Single & double quotes",
                self::INPUT    => "SELECT 'some\"quotes'",
                self::EXPECTED => [["SELECT", " ", "'some\"quotes'"]],
            ],
            [
                self::MESSAGE  => "Double & apostrophe quotes",
                self::INPUT    => "SELECT \"more quotes\" AS `bar`",
                self::EXPECTED => [["SELECT", " ", '"more quotes"', " ", "AS", " ", "`bar`"]],
            ],
            [
                self::MESSAGE  => "Bind variables",
                self::INPUT    => "SELECT :foo, :bat AS bar",
                self::EXPECTED => [["SELECT", " ", ":foo", ",", " ", ":bat", " ", "AS", " ", "bar"]],
            ],
            [
                self::MESSAGE  => "Algebra symbols",
                self::INPUT    => "SELECT a*b+c-d FROM tbl",
                self::EXPECTED => [["SELECT", " ", "a", "*", "b", "+", "c", "-", "d", " ", "FROM", " ", "tbl"]],
            ],
            [
                self::MESSAGE  => "Update statement",
                self::INPUT    => "UPDATE foo (a, b) SET (1, 2)",
                self::EXPECTED => [
                    [
                        "UPDATE",
                        " ",
                        "foo",
                        " ",
                        ["(", "a", ",", " ", "b", ")"],
                        " ",
                        "SET",
                        " ",
                        ["(", "1", ",", " ", "2", ")"],
                    ],
                ],
            ],
            [
                self::MESSAGE  => "Nested brackets/braces",
                self::INPUT    => "SELECT (({[1,2]}))",
                self::EXPECTED => [["SELECT", " ", ["(", ["(", ["{", ["[", "1", ",", "2", "]"], "}"], ")"], ")"]]],
            ],
            [
                self::MESSAGE  => "Nested brackets/braces",
                self::INPUT    => "SELECT ( [ 1 ] )",
                self::EXPECTED => [["SELECT", " ", ["(", " ", ["[", " ", "1", " ", "]"], " ", ")"]]],
            ],
            [
                self::MESSAGE  => "Stored procedure",
                self::INPUT    => "CREATE FUNCTION foo AS $\$RETURN $1$$;",
                self::EXPECTED => [["CREATE", " ", "FUNCTION", " ", "foo", " ", "AS", " ", '$$RETURN $1$$']],
            ],
            [
                self::MESSAGE  => "Dollar-quoted strings",
                self::INPUT    => "SELECT $\$FOO$$; SELECT $\$BAR$$",
                self::EXPECTED => [["SELECT", " ", '$$FOO$$'], ["SELECT", " ", '$$BAR$$']],
            ],
            [
                self::MESSAGE  => "Backslashes",
                self::INPUT    => "SELECT 'foo\\'\\\\'",
                self::EXPECTED => [["SELECT", " ", "'foo\\'\\\\'"]],
            ],
            [
                self::MESSAGE  => "Curly braces & typecast",
                self::INPUT    => "SELECT '{\"one\":\"two\"}'::jsonb",
                self::EXPECTED => [["SELECT", " ", "'{\"one\":\"two\"}'", "::", "jsonb"]],
            ],
            [
                self::MESSAGE  => "Comments",
                self::INPUT    => "-- one\nSELECT -- two\n1; -- three\n-- four",
                self::EXPECTED => [["-- one", "\n", "SELECT", " ", "-- two", "\n", "1"], ["-- three", "\n", "-- four"]],
            ],
            [
                self::MESSAGE  => "Multiline comment",
                self::INPUT    => "/* one\ntwo */\nSELECT 1;/* three\nfour */\nSELECT 2;",
                self::EXPECTED => [
                    ["/* one\ntwo */", "\n", "SELECT", " ", "1"],
                    ["/* three\nfour */", "\n", "SELECT", " ", "2"],
                ],
            ],
            [
                self::MESSAGE  => "Empty statement - in between",
                self::INPUT    => "SELECT 1;; SELECT 2",
                self::EXPECTED => [["SELECT", " ", "1"], ["SELECT", " ", "2"]],
            ]
            ,
            [
                self::MESSAGE  => "Empty statements - first",
                self::INPUT    => ";;SELECT 1; SELECT 2;",
                self::EXPECTED => [["SELECT", " ", "1"], ["SELECT", " ", "2"]],
            ],
            [
                self::MESSAGE  => "Empty statements - last",
                self::INPUT    => "SELECT 1; SELECT 2;;;",
                self::EXPECTED => [["SELECT", " ", "1"], ["SELECT", " ", "2"]],
            ],
            [
                self::MESSAGE  => "Empty statements - all over",
                self::INPUT    => ";;;;SELECT 1;;;; SELECT 2;;;",
                self::EXPECTED => [["SELECT", " ", "1"], ["SELECT", " ", "2"]],
            ],
        ];
    }
}
