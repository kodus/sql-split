<?php

namespace unit;

use Codeception\Example;
use Kodus\SQLSplit\Splitter;
use UnitTester;

class SplitterCest
{
    const MESSAGE        = 0;
    const ARGUMENTS      = 1;
    const EXPECTED       = 2;

    /**
     * @dataProvider useCases
     */
    public function testSplitter(UnitTester $I, Example $example)
    {
        $message = $example[self::MESSAGE];
        $arguments = $example[self::ARGUMENTS];
        $expected = $example[self::EXPECTED];

        $I->assertSame($expected, Splitter::split(... $arguments), $message);
    }

    protected function useCases(): array
    {
        return [
            [
                self::MESSAGE   => "Multiple statements",
                self::ARGUMENTS => ["SELECT 1; SELECT 2;"],
                self::EXPECTED  => ["SELECT 1", "SELECT 2"],
            ],
            [
                self::MESSAGE   => "Single statement, strip comments",
                self::ARGUMENTS => ["-- one\nSELECT -- two\n1; -- three\n-- four"],
                self::EXPECTED  => ["SELECT \n1"],
            ],
            [
                self::MESSAGE   => "Single statement, don't strip comments",
                self::ARGUMENTS => ["-- one\nSELECT -- two\n1; -- three\n-- four", false],
                self::EXPECTED  => ["-- one\nSELECT -- two\n1", "-- three\n-- four"],
            ],
            [
                self::MESSAGE   => "Multiple statement, strip comments",
                self::ARGUMENTS => ["/* one\ntwo */\nSELECT 1;/* three\nfour */\nSELECT 2;"],
                self::EXPECTED  => ["SELECT 1", "SELECT 2"],
            ],
            [
                self::MESSAGE   => "Multiple statements, don't strip comments",
                self::ARGUMENTS => ["/* one\ntwo */\nSELECT 1;\n/* three\nfour */\nSELECT 2;", false],
                self::EXPECTED  => ["/* one\ntwo */\nSELECT 1", "/* three\nfour */\nSELECT 2"],
            ],
            [
                self::MESSAGE   => 'MySQL example w. procedure definition and delimiter',
                self::ARGUMENTS => [
                    <<<SQL
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
                    SQL,
                    ],
                    self::EXPECTED => [
                    <<<SQL
                    CREATE PROCEDURE dorepeat(p1 INT)
                      BEGIN
                        SET @x = 0;
                        REPEAT SET @x = @x + 1; UNTIL @x > p1 END REPEAT;
                      END
                    SQL,
                    "CALL dorepeat(1000)",
                    "SELECT @x",
                ],
            ],
            [
                self::MESSAGE   => 'Postgres example',
                self::ARGUMENTS => [
                    <<<SQL
                    CREATE SCHEMA "blocks";

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
                    
                    CREATE UNIQUE INDEX "block_uuid_uindex"
                        ON "blocks"."block" (uuid);
                    
                    CREATE INDEX "enabled_index"
                        ON "blocks"."block" (enabled);
                    
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
                    
                    CREATE INDEX "placement_location_name_index_index"
                        ON "blocks".placement (location_name, index);
                    
                    CREATE INDEX "placement_scope_path_index"
                        ON "blocks".placement (scope_path);
                    
                    CREATE INDEX "placement_index_index"
                        ON "blocks".placement (index);
                    
                    CREATE TABLE "blocks"."location_hash"
                    (
                        location_name CHARACTER VARYING PRIMARY KEY  NOT NULL,
                        hash          CHARACTER VARYING              NOT NULL
                    );
                    
                    CREATE TABLE "blocks"."child_blocks"
                    (
                        parent_uuid  UUID    NOT NULL,
                        column_index INT     NOT NULL,
                        child_uuids  UUID [] NOT NULL,
                        CONSTRAINT child_blocks_parent_uuid_index_pk PRIMARY KEY (parent_uuid, column_index),
                        CONSTRAINT child_blocks_block_uuid_fk FOREIGN KEY (parent_uuid) REFERENCES "blocks"."block" (uuid) ON DELETE CASCADE
                    
                        -- TODO add a trigger to sanitize child_uuids when a block record is deleted
                    );
                    
                    CREATE INDEX "child_blocks_parent_uuid_index"
                        ON "blocks"."child_blocks" (parent_uuid);
                    
                    CREATE TABLE "blocks"."user_bucket"
                    (
                        user_uuid   UUID PRIMARY KEY NOT NULL,
                        block_uuids UUID []          NOT NULL
                    );
                    
                    CREATE UNIQUE INDEX "user_bucket_user_uuid_uindex"
                        ON "blocks"."user_bucket" (user_uuid);
                    
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
                    
                    CREATE TRIGGER delete_orphans
                    AFTER UPDATE OR DELETE
                        ON "blocks"."user_bucket"
                    FOR EACH STATEMENT
                    EXECUTE PROCEDURE "blocks".delete_orphaned_blocks();
                    
                    CREATE TRIGGER delete_orphans
                    AFTER UPDATE OR DELETE
                        ON "blocks"."placement"
                    FOR EACH STATEMENT
                    EXECUTE PROCEDURE "blocks".delete_orphaned_blocks();
                    
                    CREATE TRIGGER delete_orphans
                    AFTER UPDATE OR DELETE
                        ON "blocks"."child_blocks"
                    FOR EACH STATEMENT
                    EXECUTE PROCEDURE "blocks".delete_orphaned_blocks();
                    SQL,
                    false,
                ],
                self::EXPECTED  => [
                    <<<SQL
                    CREATE SCHEMA "blocks"
                    SQL,
                    <<<SQL
                    CREATE TABLE "blocks"."block"
                    (
                        uuid                UUID PRIMARY KEY   NOT NULL,
                        settings_type       CHARACTER VARYING  NOT NULL,
                        settings            JSONB              NOT NULL,
                        title               CHARACTER VARYING,
                        view_type           CHARACTER VARYING,
                        enabled             BOOL DEFAULT TRUE  NOT NULL,
                        visibility_settings JSONB
                    )
                    SQL,
                    <<<SQL
                    CREATE UNIQUE INDEX "block_uuid_uindex"
                        ON "blocks"."block" (uuid)
                    SQL,
                    <<<SQL
                    CREATE INDEX "enabled_index"
                        ON "blocks"."block" (enabled)
                    SQL,
                    <<<SQL
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
                    )
                    SQL,
                    <<<SQL
                    CREATE INDEX "placement_location_name_index_index"
                        ON "blocks".placement (location_name, index)
                    SQL,
                    <<<SQL
                    CREATE INDEX "placement_scope_path_index"
                        ON "blocks".placement (scope_path)
                    SQL,
                    <<<SQL
                    CREATE INDEX "placement_index_index"
                        ON "blocks".placement (index)
                    SQL,
                    <<<SQL
                    CREATE TABLE "blocks"."location_hash"
                    (
                        location_name CHARACTER VARYING PRIMARY KEY  NOT NULL,
                        hash          CHARACTER VARYING              NOT NULL
                    )
                    SQL,
                    <<<SQL
                    CREATE TABLE "blocks"."child_blocks"
                    (
                        parent_uuid  UUID    NOT NULL,
                        column_index INT     NOT NULL,
                        child_uuids  UUID [] NOT NULL,
                        CONSTRAINT child_blocks_parent_uuid_index_pk PRIMARY KEY (parent_uuid, column_index),
                        CONSTRAINT child_blocks_block_uuid_fk FOREIGN KEY (parent_uuid) REFERENCES "blocks"."block" (uuid) ON DELETE CASCADE
                    
                        -- TODO add a trigger to sanitize child_uuids when a block record is deleted
                    )
                    SQL,
                    <<<SQL
                    CREATE INDEX "child_blocks_parent_uuid_index"
                        ON "blocks"."child_blocks" (parent_uuid)
                    SQL,
                    <<<SQL
                    CREATE TABLE "blocks"."user_bucket"
                    (
                        user_uuid   UUID PRIMARY KEY NOT NULL,
                        block_uuids UUID []          NOT NULL
                    )
                    SQL,
                    <<<SQL
                    CREATE UNIQUE INDEX "user_bucket_user_uuid_uindex"
                        ON "blocks"."user_bucket" (user_uuid)
                    SQL,
                    <<<SQL
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
                        FROM "blocks"."block" b
                    SQL,
                    <<<SQL
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
                    LANGUAGE plpgsql
                    SQL,
                    <<<SQL
                    CREATE TRIGGER delete_orphans
                    AFTER UPDATE OR DELETE
                        ON "blocks"."user_bucket"
                    FOR EACH STATEMENT
                    EXECUTE PROCEDURE "blocks".delete_orphaned_blocks()
                    SQL,
                    <<<SQL
                    CREATE TRIGGER delete_orphans
                    AFTER UPDATE OR DELETE
                        ON "blocks"."placement"
                    FOR EACH STATEMENT
                    EXECUTE PROCEDURE "blocks".delete_orphaned_blocks()
                    SQL,
                    <<<SQL
                    CREATE TRIGGER delete_orphans
                    AFTER UPDATE OR DELETE
                        ON "blocks"."child_blocks"
                    FOR EACH STATEMENT
                    EXECUTE PROCEDURE "blocks".delete_orphaned_blocks()
                    SQL,
                ],
            ],
        ];
    }
}
