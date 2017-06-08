<?php

namespace mindplay\sql_parser;

use RuntimeException;

class SQLTokenizer
{
    /**
     * @var int
     */
    protected $offset = 0;

    /**
     * @var string
     */
    protected $input;

    /**
     * @param string $input
     *
     * @return array tree-structure of SQL tokens
     */
    public static function tokenize(string $input)
    {
        $parser = new self($input);

        return $parser->statements();
    }

    protected function __construct(string $input)
    {
        $this->input = $input;
    }

    /**
     * @return string[]
     */
    protected function statements()
    {
        $statements = [];

        while ($result = $this->statement()) {
            $statements[] = $result;
        }

        return $statements;
    }

    /**
     * @return string[]|null
     */
    protected function statement()
    {
        $this->consume('\s*');

        if ($this->isEOF()) {
            return null;
        }

        $tokens = [];

        while ($token = $this->token()) {
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @return string|array
     */
    protected function token()
    {
        if ("" !== $token = $this->consume('\w+')) {
            return $token;
        }

        if ($token = $this->consume('\s+')) {
            return $token;
        }

        if ($token = $this->comment()) {
            return $token;
        }

        if ($token = $this->consume('[\*,.+-\/=]')) {
            return $token;
        }

        if ($token = $this->quoted()) {
            return $token;
        }

        if ($tokens = $this->grouped()) {
            return $tokens;
        }

        if ($token = $this->placeholder()) {
            return $token;
        }

        if ($token = $this->dollarquoted()) {
            return $token;
        }

        if ($this->isEOF()) {
            return ""; // end of file/statement
        }

        if ($this->consume(";")) {
            return ""; // end of statement
        }

        $this->fail("expected SQL token");
    }

    /**
     * @return string|null
     */
    protected function comment()
    {
        if ($start = $this->consume('--')) {
            $comment = $this->consume("[^\r\n]*");

            return "{$start}{$comment}";
        }

        if ($start = $this->consume('\/\*')) {
            $comment = $this->consume('.*?\*\/');

            if ($comment) {
                return "{$start}{$comment}";
            }

            $this->fail("expected end of block-comment");
        }

        return null;
    }

    /**
     * @return string|null
     */
    protected function dollarquoted()
    {
        if ($delimiter = $this->consume('\$\w*\$')) {
            $end_delimiter = preg_quote($delimiter);

            $body = $this->consume(".*?{$end_delimiter}");

            if ($body) {
                $this->consume($end_delimiter);

                return "{$delimiter}{$body}";
            }

            $this->fail("expected end-delimiter of dollar-quoted string: {$delimiter}");
        }

        return null;
    }

    /**
     * @return array|null
     */
    protected function grouped()
    {
        static $end = [
            "(" => ")",
            "{" => "}",
            "[" => "]",
        ];

        if ($opening = $this->consume('[({\[]')) {
            $closing = $end[$opening];

            $tokens = [$opening];

            while (true) {
                if ($this->is($closing)) {
                    $tokens[] = $closing;

                    $this->offset +=1 ;

                    return $tokens;
                }

                if ("" !== $token = $this->token()) {
                    $tokens[] = $token;
                } else {
                    $this->fail("expected token or group end: {$closing}");
                }
            }
        }

        return null;
    }

    /**
     * @return string|null
     */
    protected function placeholder()
    {
        if ($token = $this->consume(':\w+')) {
            return $token;
        }

        return null;
    }

    /**
     * @return string|null
     */
    protected function quoted()
    {
        if ($quote = $this->consume('[`\'"]')) {
            $tokens = [$quote];

            $not_quote = '[^' . preg_quote($quote) . '\\\\]*';

            while (true) {
                if ($this->is('\\')) {
                    $tokens[] = substr($this->input, $this->offset, 2);

                    $this->offset += 2;

                    continue;
                }

                if ($this->is($quote)) {
                    $tokens[] = $quote;

                    $this->offset += 1;

                    return implode('', $tokens);
                }

                if ("" !== $token = $this->consume($not_quote)) {
                    $tokens[] = $token;

                    continue;
                }

                $this->fail("expected end quote [{$quote}]");
            }
        }

        return null;
    }

    protected function isEOF(): bool
    {
        return $this->offset === strlen($this->input);
    }

    protected function is(string $exact): bool
    {
        return substr_compare($this->input, $exact, $this->offset, strlen($exact)) === 0;
    }

    protected function matches(string $pattern): bool
    {
        return preg_match("/{$pattern}/sA", $this->input, $matches, 0, $this->offset) === 1;
    }

    protected function consume(string $pattern): string
    {
        if (preg_match("/{$pattern}/sA", $this->input, $matches, 0, $this->offset) === 1) {
            $this->offset += strlen($matches[0]);

            return $matches[0];
        }

        return '';
    }

    protected function fail(string $why)
    {
        throw new RuntimeException("unexpected input: {$why}, at: {$this->offset}, got: \"" . substr($this->input, $this->offset, 1) . "\"");
    }
}
