<?php

namespace Kodus\SQLSplit;

use RuntimeException;

/**
 * This class implements a simple recursive descent parser for minimal tokenization
 * of files containing one or more MySQL or PostgreSQL statements.
 *
 * It's used internally by {@see Splitter} which provides the main point of entry -
 * if you want to use the tokenizer for something else, have a look at the test-suite
 * which contains a specification demonstrating the very simple token output format.
 *
 * @see Splitter::split()
 */
class Tokenizer
{
    protected int $offset = 0;

    protected string $input;

    protected string $delimiter_pattern = ";";

    /**
     * @param string $input
     *
     * @return array tree-structure of SQL tokens
     */
    public static function tokenize(string $input): array
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
    protected function statements(): array
    {
        $statements = [];

        $result = $this->statement();
        while ($result) {
            $statements[] = $result;
            $result = $this->statement();
        }

        return $statements;
    }

    /**
     * @return string[]|null
     */
    protected function statement(): ?array
    {
        $this->consume('\s*');

        if ($this->isEOF()) {
            return null;
        }

        $tokens = [];
        $token = $this->token();
        while ($token !== "") {
            if (is_string($token) && preg_match('/^delimiter$/i', $token) === 1) {
                // Omit DELIMITER command - it isn't part of SQL statement syntax

                $this->consume('[ ]*');

                $delimiter = trim($this->consume('.*?[\r\n]+'));

                if ($delimiter === "") {
                    $this->fail("expected delimiter character(s)");
                }

                $this->delimiter_pattern = preg_quote($delimiter);

            } else {
                $tokens[] = $token;
            }
            $token = $this->token();
        }

        return $tokens;
    }

    /**
     * TODO: Refactor this - cyclomatic complexity > 10
     *
     * @return array|string
     */
    protected function token(): array|string
    {
        if ($this->consume($this->delimiter_pattern)) {
            return ""; // end of statement
        }

        $token = $this->consume('\w+');
        if ($token !== "") {
            return $token;
        }

        $token = $this->consume('\s+');
        if ($token) {
            return $token;
        }

        $token = $this->comment();
        if ($token) {
            return $token;
        }

        $token = $this->consume('\@\w+');
        if ($token) {
            return $token; // @var
        }

        $token = $this->consume(':\w+');
        if ($token) {
            return $token; // PDO placeholder
        }

        $token = $this->consume('[+\-\*\/.,!=^|&<>:@%~#]+');
        if ($token) {
            return $token; // various operators
        }

        $token = $this->consume(';');
        if ($token) {
            return $token; // statement separator (when $delimiter_pattern has been modified)
        }

        $token = $this->quoted();
        if ($token) {
            return $token;
        }

        $tokens = $this->grouped();
        if ($tokens) {
            return $tokens;
        }

        $token = $this->dollarquoted();
        if ($token) {
            return $token;
        }

        if ($this->isEOF()) {
            return ""; // end of file/statement
        }

        $this->fail("expected SQL token");
    }

    protected function comment(): ?string
    {
        $start = $this->consume('--');
        if ($start) {
            $comment = $this->consume("[^\r\n]*");

            return "{$start}{$comment}";
        }

        $start = $this->consume('\/\*');
        if ($start) {
            $comment = $this->consume('.*?\*\/');

            if ($comment) {
                return "{$start}{$comment}";
            }

            $this->fail("expected end of block-comment");
        }

        return null;
    }

    protected function dollarquoted(): ?string
    {
        $delimiter = $this->consume('\$\w*\$');
        if ($delimiter) {
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

    protected function grouped(): ?array
    {
        static $end = [
            "(" => ")",
            "{" => "}",
            "[" => "]",
        ];

        $opening = $this->consume('[({\[]');
        if ($opening) {
            $closing = $end[$opening];

            $tokens = [$opening];

            while (true) {
                if ($this->is($closing)) {
                    $tokens[] = $closing;

                    $this->offset += 1;

                    return $tokens;
                }

                $token = $this->token();
                if ($token !== "") {
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
    protected function quoted(): ?string
    {
        $quote = $this->consume('[`\'"]');

        if ($quote) {
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

                $token = $this->consume($not_quote);

                if ($token !== "") {
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
        return preg_match(pattern: "/{$pattern}/sA", subject: $this->input, offset: $this->offset) === 1;
    }

    protected function consume(string $pattern): string
    {
        if (preg_match("/{$pattern}/sA", $this->input, $matches, 0, $this->offset) === 1) {
            $this->offset += strlen($matches[0]);

            return $matches[0];
        }

        return '';
    }

    protected function fail(string $why): void
    {
        throw new RuntimeException("unexpected input: {$why}, at: {$this->offset}, got: \"" . substr($this->input,
                $this->offset, 1) . "\"");
    }
}
