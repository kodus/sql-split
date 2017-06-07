<?php

namespace mindplay\sql_parser;

abstract class SQLSplitter
{
    /**
     * @param string $sql
     * @param bool   $strip_comments
     *
     * @return string[] list of SQL statements
     */
    public static function split(string $sql, bool $strip_comments = true)
    {
        $tokens = SQLTokenizer::tokenize($sql);

        if ($strip_comments) {
            $tokens = self::stripComments($tokens);
        }

        $statements = [];

        foreach ($tokens as $token) {
            $statements[] = trim(self::implode($token));
        }

        return array_filter($statements);
    }

    protected static function stripComments(array $tokens): array
    {
        $stripped = [];

        foreach ($tokens as $token) {
            if (is_string($token)) {
                if (! self::isComment($token)) {
                    $stripped[] = $token;
                }
            } else {
                $stripped[] = self::stripComments($token);
            }
        }

        return $stripped;
    }

    protected static function isComment(string $token): bool
    {
        $start = substr($token, 0, 2);

        return $start === "--" || $start === "/*";
    }

    protected static function implode(array $tokens): string
    {
        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                $tokens[$index] = self::implode($token);
            }
        }

        return implode("", $tokens);
    }
}
