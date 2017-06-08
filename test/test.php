<?php

use mindplay\sql_parser\SQLSplitter;
use mindplay\sql_parser\SQLTokenizer;

require dirname(__DIR__) . '/vendor/autoload.php';

eq(SQLTokenizer::tokenize("SELECT 'foo\\'\\\\'"), [["SELECT", " ", "'foo\\'\\\\'"]]);

test(
    'single statement',
    function () {
        eq(SQLTokenizer::tokenize("SELECT 1"), [["SELECT", " ", "1"]], "unterminated");
        eq(SQLTokenizer::tokenize("SELECT 1"), [["SELECT", " ", "1"]], "terminated");
    }
);

test(
    'multiple statements',
    function () {
        eq(SQLTokenizer::tokenize("SELECT 1; SELECT 2"), [["SELECT", " ", "1"], ["SELECT", " ", "2"]], "separated statements");
        eq(SQLTokenizer::tokenize("SELECT 1; SELECT 2;"), [["SELECT", " ", "1"], ["SELECT", " ", "2"]], "terminated statements");
    }
);

test(
    'various tokens',
    function () {
        eq(SQLTokenizer::tokenize("SELECT * FROM bar"), [["SELECT", " ", "*", " ", "FROM", " ", "bar"]]);
        eq(SQLTokenizer::tokenize("SELECT `foo` FROM `bar`"), [["SELECT", " ", "`foo`", " ", "FROM", " ", "`bar`"]]);
        eq(SQLTokenizer::tokenize("SELECT 'some\"quotes'"), [["SELECT", " ", "'some\"quotes'"]]);
        eq(SQLTokenizer::tokenize('SELECT "more quotes" AS `bar`'), [["SELECT", " ", '"more quotes"', " ", "AS", " ", "`bar`"]]);
        eq(SQLTokenizer::tokenize("SELECT :foo, :bat AS bar"), [["SELECT", " ", ":foo", ",", " ", ":bat", " ", "AS", " ", "bar"]]);
        eq(SQLTokenizer::tokenize("SELECT a*b+c-d FROM tbl"), [["SELECT", " ", "a", "*", "b", "+", "c", "-", "d", " ", "FROM", " ", "tbl"]]);
        eq(SQLTokenizer::tokenize("UPDATE foo (a, b) SET (1, 2)"), [["UPDATE", " ", "foo", " ", ["(", "a", ",", " ", "b", ")"], " ", "SET", " ", ["(", "1", ",", " ", "2", ")"]]]);
        eq(SQLTokenizer::tokenize("SELECT (({[1,2]}))"), [["SELECT", " ", ["(", ["(", ["{", ["[", "1", ",", "2", "]"], "}"], ")"], ")"]]], "nested brackets/braces");
        eq(SQLTokenizer::tokenize('CREATE FUNCTION foo AS $$RETURN $1$$;'), [["CREATE", " ", "FUNCTION", " ", "foo", " ", "AS", " ", '$$RETURN $1$$']], "stored procedure");
        eq(SQLTokenizer::tokenize('SELECT $$FOO$$; SELECT $$BAR$$'), [["SELECT", " ", '$$FOO$$'], ["SELECT", " ", '$$BAR$$']], "dollar-quoted strings");
        eq(SQLTokenizer::tokenize("SELECT 'foo\\'\\\\'"), [["SELECT", " ", "'foo\\'\\\\'"]]);
    }
);

test(
    'comments',
    function () {
        eq(SQLTokenizer::tokenize("-- one\nSELECT -- two\n1; -- three\n-- four"), [["-- one", "\n", "SELECT", " ", "-- two", "\n", "1"], ["-- three", "\n", "-- four"]]);
        eq(SQLTokenizer::tokenize("/* one\ntwo */\nSELECT 1;/* three\nfour */\nSELECT 2;"), [["/* one\ntwo */", "\n", "SELECT", " ", "1"], ["/* three\nfour */", "\n", "SELECT", " ", "2"]]);
    }
);

test(
    'split statements',
    function () {
        eq(SQLSplitter::split("SELECT 1; SELECT 2;"), ["SELECT 1", "SELECT 2"]);
        eq(SQLSplitter::split("-- one\nSELECT -- two\n1; -- three\n-- four"), ["SELECT \n1"]);
        eq(SQLSplitter::split("-- one\nSELECT -- two\n1; -- three\n-- four", false), ["-- one\nSELECT -- two\n1", "-- three\n-- four"]);
        eq(SQLSplitter::split("/* one\ntwo */\nSELECT 1;/* three\nfour */\nSELECT 2;"), ["SELECT 1", "SELECT 2"]);
        eq(SQLSplitter::split("/* one\ntwo */\nSELECT 1;\n/* three\nfour */\nSELECT 2;", false), ["/* one\ntwo */\nSELECT 1", "/* three\nfour */\nSELECT 2"]);
    }
);

exit(run());
