#!/usr/bin/env php
<?php

/**
 * Repo entrypoint — delegates to apps/api/scripts/check-accounting-invariants.php
 * (same checks as: cd apps/api && composer run check:accounting).
 */
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'check-accounting-invariants.php';
