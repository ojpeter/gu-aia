<?php

declare(strict_types=1);

/**
 * PDO connection options shared by every entry point that talks to the database.
 *
 * Kept as a plain `require`-able file rather than a class so that bin/ scripts
 * work with no autoloader and no Composer install — the migration runner in
 * particular has to run on a fresh checkout before anything else exists.
 *
 * The sql_mode line is the important one, and it is here because of a real bug
 * rather than as boilerplate. See db/migrations/0007_enforce_review_dates.sql:
 * the XAMPP MariaDB default omits STRICT_TRANS_TABLES, and in that mode omitting
 * a NOT NULL DATE does not raise an error — the server substitutes '0000-00-00'
 * and inserts the row. That silently defeated INV-11's structural guarantee.
 *
 * Setting the mode per connection means the guarantee no longer depends on how a
 * particular server happens to be configured. It matters here more than usual
 * because development runs on MariaDB 10.4 and production targets MySQL 8, whose
 * defaults differ.
 *
 * STRICT_ALL_TABLES rather than STRICT_TRANS_TABLES: the stricter of the two, and
 * there is no reason to want a silently truncated value anywhere in this schema.
 */

const GU_AIA_SQL_MODE = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

return [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // Real prepared statements, not client-side interpolation.
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = '" . GU_AIA_SQL_MODE . "'",
];
