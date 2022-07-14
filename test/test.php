<?php

use Kodus\SQL\Splitter;
use Kodus\SQL\Tokenizer;

require dirname(__DIR__) . '/vendor/autoload.php';

test(
    'single statement',
    function () {
        eq(Tokenizer::tokenize("SELECT 1"), [["SELECT", " ", "1"]], "unterminated");
        eq(Tokenizer::tokenize("SELECT 1"), [["SELECT", " ", "1"]], "terminated");
    }
);

test(
    'multiple statements',
    function () {
        eq(Tokenizer::tokenize("SELECT 1; SELECT 2"), [["SELECT", " ", "1"], ["SELECT", " ", "2"]], "separated statements");
        eq(Tokenizer::tokenize("SELECT 1; SELECT 2;"), [["SELECT", " ", "1"], ["SELECT", " ", "2"]], "terminated statements");
    }
);

test(
    'various tokens',
    function () {
        eq(Tokenizer::tokenize("SELECT * FROM bar"), [["SELECT", " ", "*", " ", "FROM", " ", "bar"]]);
        eq(Tokenizer::tokenize("SELECT `foo` FROM `bar`"), [["SELECT", " ", "`foo`", " ", "FROM", " ", "`bar`"]]);
        eq(Tokenizer::tokenize("SELECT 'some\"quotes'"), [["SELECT", " ", "'some\"quotes'"]]);
        eq(Tokenizer::tokenize('SELECT "more quotes" AS `bar`'), [["SELECT", " ", '"more quotes"', " ", "AS", " ", "`bar`"]]);
        eq(Tokenizer::tokenize("SELECT :foo, :bat AS bar"), [["SELECT", " ", ":foo", ",", " ", ":bat", " ", "AS", " ", "bar"]]);
        eq(Tokenizer::tokenize("SELECT a*b+c-d FROM tbl"), [["SELECT", " ", "a", "*", "b", "+", "c", "-", "d", " ", "FROM", " ", "tbl"]]);
        eq(Tokenizer::tokenize("UPDATE foo (a, b) SET (1, 2)"), [["UPDATE", " ", "foo", " ", ["(", "a", ",", " ", "b", ")"], " ", "SET", " ", ["(", "1", ",", " ", "2", ")"]]]);
        eq(Tokenizer::tokenize("SELECT (({[1,2]}))"), [["SELECT", " ", ["(", ["(", ["{", ["[", "1", ",", "2", "]"], "}"], ")"], ")"]]], "nested brackets/braces");
        eq(Tokenizer::tokenize("SELECT ( [ 1 ] )"), [["SELECT", " ", ["(", " ", ["[", " ", "1", " ", "]"], " ", ")"]]], "nested brackets/braces");
        eq(Tokenizer::tokenize('CREATE FUNCTION foo AS $$RETURN $1$$;'), [["CREATE", " ", "FUNCTION", " ", "foo", " ", "AS", " ", '$$RETURN $1$$']], "stored procedure");
        eq(Tokenizer::tokenize('SELECT $$FOO$$; SELECT $$BAR$$'), [["SELECT", " ", '$$FOO$$'], ["SELECT", " ", '$$BAR$$']], "dollar-quoted strings");
        eq(Tokenizer::tokenize("SELECT 'foo\\'\\\\'"), [["SELECT", " ", "'foo\\'\\\\'"]]);
        eq(Tokenizer::tokenize("SELECT '{\"one\":\"two\"}'::jsonb"), [["SELECT", " ", "'{\"one\":\"two\"}'", "::", "jsonb"]]);
    }
);

test(
    'Empty statements',
    function () {
        eq(Tokenizer::tokenize("SELECT 1;; SELECT 2"), [["SELECT", " ", "1"], ["SELECT", " ", "2"]], "Empty statement - in between");
        eq(Tokenizer::tokenize(";;SELECT 1; SELECT 2;"), [["SELECT", " ", "1"], ["SELECT", " ", "2"]], "Empty statements - first");
        eq(Tokenizer::tokenize("SELECT 1; SELECT 2;;;"), [["SELECT", " ", "1"], ["SELECT", " ", "2"]], "Empty statements - last");
        eq(Tokenizer::tokenize(";;;;SELECT 1;;;; SELECT 2;;;"), [["SELECT", " ", "1"], ["SELECT", " ", "2"]], "Empty statements - all over");
    }
);

test(
    'comments',
    function () {
        eq(Tokenizer::tokenize("-- one\nSELECT -- two\n1; -- three\n-- four"), [["-- one", "\n", "SELECT", " ", "-- two", "\n", "1"], ["-- three", "\n", "-- four"]]);
        eq(Tokenizer::tokenize("/* one\ntwo */\nSELECT 1;/* three\nfour */\nSELECT 2;"), [["/* one\ntwo */", "\n", "SELECT", " ", "1"], ["/* three\nfour */", "\n", "SELECT", " ", "2"]]);
    }
);

test(
    'split statements',
    function () {
        eq(Splitter::split("SELECT 1; SELECT 2;"), ["SELECT 1", "SELECT 2"]);
        eq(Splitter::split("-- one\nSELECT -- two\n1; -- three\n-- four"), ["SELECT \n1"]);
        eq(Splitter::split("-- one\nSELECT -- two\n1; -- three\n-- four", false), ["-- one\nSELECT -- two\n1", "-- three\n-- four"]);
        eq(Splitter::split("/* one\ntwo */\nSELECT 1;/* three\nfour */\nSELECT 2;"), ["SELECT 1", "SELECT 2"]);
        eq(Splitter::split("/* one\ntwo */\nSELECT 1;\n/* three\nfour */\nSELECT 2;", false), ["/* one\ntwo */\nSELECT 1", "/* three\nfour */\nSELECT 2"]);
    }
);

$sql_template = <<<SQL
CREATE SCHEMA "blocks";
-----
CREATE TABLE "blocks"."block"
(
    uuid                UUID PRIMARY KEY   NOT NULL,
    settings_type       CHARACTER VARYING  NOT NULL,
    settings            JSONB              NOT NULL,
    title               CHARACTER VARYING,
    view_type           CHARACTER VARYING,
    enabled             BOOL DEFAULT TRUE  NOT NULL,
    visibility_settings JSONB
);
-----
CREATE UNIQUE INDEX "block_uuid_uindex"
    ON "blocks"."block" (uuid);
-----
CREATE INDEX "enabled_index"
    ON "blocks"."block" (enabled);
-----
CREATE TABLE "blocks"."placement"
(
    location_name   CHARACTER VARYING   NOT NULL,
    index           INT                 NOT NULL,
    block_uuid      UUID                NOT NULL,
    scope_path      CHARACTER VARYING   NOT NULL,
    inherited       BOOL DEFAULT FALSE  NOT NULL,
    excluded_scopes CHARACTER VARYING [],

    -- NOTE: Because we UPDATE the primary (location_name, index) key on the placement-table,
    -- the constraint behavior on the primary key is set to DEFERRABLE and INITIALLY IMMEDIATE.
    --
    -- For more inforation, refer to this bug-report:
    --
    -- https://www.postgresql.org/message-id/flat/20170322123053.1421.55154%40wrigleys.postgresql.org

    CONSTRAINT placement_location_name_index_pk PRIMARY KEY (location_name, index) DEFERRABLE INITIALLY IMMEDIATE,
    CONSTRAINT placement_block_uuid_fk FOREIGN KEY (block_uuid) REFERENCES "blocks"."block" (uuid) ON DELETE CASCADE
);
-----
CREATE INDEX "placement_location_name_index_index"
    ON "blocks".placement (location_name, index);
-----
CREATE INDEX "placement_scope_path_index"
    ON "blocks".placement (scope_path);
-----
CREATE INDEX "placement_index_index"
    ON "blocks".placement (index);
-----
CREATE TABLE "blocks"."location_hash"
(
    location_name CHARACTER VARYING PRIMARY KEY  NOT NULL,
    hash          CHARACTER VARYING              NOT NULL
);
-----
CREATE TABLE "blocks"."child_blocks"
(
    parent_uuid  UUID    NOT NULL,
    column_index INT     NOT NULL,
    child_uuids  UUID [] NOT NULL,
    CONSTRAINT child_blocks_parent_uuid_index_pk PRIMARY KEY (parent_uuid, column_index),
    CONSTRAINT child_blocks_block_uuid_fk FOREIGN KEY (parent_uuid) REFERENCES "blocks"."block" (uuid) ON DELETE CASCADE

    -- TODO add a trigger to sanitize child_uuids when a block record is deleted
);
-----
CREATE INDEX "child_blocks_parent_uuid_index"
    ON "blocks"."child_blocks" (parent_uuid);
-----
CREATE TABLE "blocks"."user_bucket"
(
    user_uuid   UUID PRIMARY KEY NOT NULL,
    block_uuids UUID []          NOT NULL
);
-----
CREATE UNIQUE INDEX "user_bucket_user_uuid_uindex"
    ON "blocks"."user_bucket" (user_uuid);
-----
CREATE VIEW "blocks"."block_count" AS
    SELECT
        b."uuid"                               AS "block_uuid",
        (
            (SELECT COUNT(*)
             FROM "blocks"."placement" p
             WHERE p."block_uuid" = b."uuid")
            +
            (SELECT COALESCE(SUM((SELECT COUNT(*)
                                  FROM unnest(c."child_uuids") cb
                                  WHERE cb = b."uuid")), 0)
             FROM "blocks"."child_blocks" c)
        )                                      AS "ref_count",
        (SELECT COUNT(*)
         FROM "blocks"."user_bucket" k
         WHERE "uuid" = ANY (k."block_uuids")) AS "user_count"
    FROM "blocks"."block" b;
-----
CREATE OR REPLACE FUNCTION "blocks".delete_orphaned_blocks()
    RETURNS TRIGGER
AS $$
BEGIN
    LOOP
        -- Note that RETURN QUERY does not return from the function - it works
        -- more like the yield-statement in PHP, in that records from the
        -- DELETE..RETURNING statement are returned, and execution then
        -- resumes from the following statement.

        DELETE FROM "blocks"."block" b
        WHERE b.uuid IN (
            SELECT c.block_uuid
            FROM "blocks"."block_count" c
            WHERE c.ref_count = 0 AND c.user_count = 0
        );

        -- The FOUND flag is set TRUE/FALSE after executing a query - so we
        -- EXIT from the LOOP block when the DELETE..RETURNING statement does
        -- not delete and return any records.

        EXIT WHEN NOT FOUND;
    END LOOP;

    RETURN NULL;
END;
$$
LANGUAGE plpgsql;
-----
CREATE TRIGGER delete_orphans
AFTER UPDATE OR DELETE
    ON "blocks"."user_bucket"
FOR EACH STATEMENT
EXECUTE PROCEDURE "blocks".delete_orphaned_blocks();
-----
CREATE TRIGGER delete_orphans
AFTER UPDATE OR DELETE
    ON "blocks"."placement"
FOR EACH STATEMENT
EXECUTE PROCEDURE "blocks".delete_orphaned_blocks();
-----
CREATE TRIGGER delete_orphans
AFTER UPDATE OR DELETE
    ON "blocks"."child_blocks"
FOR EACH STATEMENT
EXECUTE PROCEDURE "blocks".delete_orphaned_blocks();
SQL;

test("postgres use-case", function () use ($sql_template) {
    $expected_statements = array_map(
        function (string $sql) {
            return rtrim(trim($sql), ";");
        },
        explode("-----", $sql_template)
    );

    $sql = str_replace("-----", "\n", $sql_template);

    foreach (Splitter::split($sql, false) as $index => $statement) {
        eq($statement, $expected_statements[$index]);
    }
});

$mysql_script = <<<SQL
-- delimiter test
DELIMITER $$

CREATE PROCEDURE dorepeat(p1 INT)
  BEGIN
    SET @x = 0;-- comment
    REPEAT SET @x = @x + 1; UNTIL @x > p1 END REPEAT;
  END
$$

DELIMITER ;
-- comment
CALL dorepeat(1000);
SELECT @x;
SQL;

$mysql_statements = <<<SQL
CREATE PROCEDURE dorepeat(p1 INT)
  BEGIN
    SET @x = 0;
    REPEAT SET @x = @x + 1; UNTIL @x > p1 END REPEAT;
  END
-----
CALL dorepeat(1000)
-----
SELECT @x
SQL;

test("mysql use-case", function () use ($mysql_script, $mysql_statements) {
    eq(Splitter::split($mysql_script), array_map("trim", explode("-----", $mysql_statements)));
});

exit(run());
