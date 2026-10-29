<?php

namespace Tests\Concurrency\Support;

use RuntimeException;

/**
 * A connection the MySQL concurrency tests refuse to touch: not provably the
 * isolated test database.
 */
class UnsafeDatabaseConnection extends RuntimeException {}
