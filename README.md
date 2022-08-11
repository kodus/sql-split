# `kodus/sql-split`

A simple parser to split SQL (and/or DDL) files into individual SQL queries and strip comments.

[![PHP Version](https://img.shields.io/badge/php-8.0%2B-blue.svg)](https://packagist.org/packages/kodus/sql-split)
[![Build Status](https://travis-ci.org/kodus/sql-split.svg?branch=master)](https://travis-ci.org/kodus/sql-split)

### Install via Composer

    composer require kodus/sql-split

### Features

I designed this for use with PDO and MySQL/PostgreSQL statements.

It uses a very simple recursive descent parser to minimally tokenize valid SQL - this approach ensures there
is no ambiguity between quoted strings, keywords, comments, etc. but makes no attempt to validate SQL command
structure or validity of the extracted statements.

It supports the following SQL/DDL features:

 * SQL and DDL Queries
 * Stored procedures, functions, views, triggers, etc.
 * PostgreSQL dollar-tags (`$$` and `$mytag$` delimiters)
 * The MySQL `DELIMITER` command

## Usage

Just this:

```php
$statements = Splitter::split(file_get_contents(...));
```

This will split to individual SQL statements and (by default) strip comments.

Then just loop over your `$statements` and run them via `PDO`.

That's all.
