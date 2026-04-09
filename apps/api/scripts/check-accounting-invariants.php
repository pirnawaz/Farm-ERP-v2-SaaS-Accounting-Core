#!/usr/bin/env php
<?php

/**
 * Phase 1H.8 — Accounting CI invariants (lightweight grep-style checks; no AST library).
 *
 * Run from apps/api: php scripts/check-accounting-invariants.php
 */

declare(strict_types=1);

$apiRoot = dirname(__DIR__);
chdir($apiRoot);

function relativePath(string $root, string $absolute): string
{
    $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
    $absolute = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolute);
    if (! str_starts_with($absolute, $root . DIRECTORY_SEPARATOR) && $absolute !== $root) {
        return $absolute;
    }
    $rel = substr($absolute, strlen($root) + 1);

    return str_replace(DIRECTORY_SEPARATOR, '/', $rel);
}

$errors = [];
$warnings = [];

function fail(array &$errors, string $code, string $message): void
{
    $errors[] = "[{$code}] {$message}";
}

function warn(array &$warnings, string $code, string $message): void
{
    $warnings[] = "[{$code}] {$message}";
}

function iterPhpFiles(string $dir, callable $filter): Generator
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && strcasecmp($file->getExtension(), 'php') === 0) {
            $path = $file->getPathname();
            if ($filter($path)) {
                yield $path;
            }
        }
    }
}

function appClassFromFile(string $apiRoot, string $filePath): ?string
{
    $appDir = $apiRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR;
    $normalizedFile = str_replace('/', DIRECTORY_SEPARATOR, $filePath);
    if (! str_starts_with($normalizedFile, $appDir)) {
        return null;
    }
    $rel = substr($normalizedFile, strlen($appDir));
    $rel = preg_replace('/\.php$/', '', $rel);
    if ($rel === null) {
        return null;
    }
    $rel = str_replace(DIRECTORY_SEPARATOR, '\\', $rel);

    return 'App\\' . $rel;
}

echo "[A] LedgerEntry::create allowlist (config/ledger_write_allowlist.php)…\n";
// --- A. LedgerEntry::create allowlist ---
$ledgerConfig = require $apiRoot . '/config/ledger_write_allowlist.php';
$allowedClasses = $ledgerConfig['classes'] ?? [];
$allowedSet = array_fill_keys($allowedClasses, true);

foreach (iterPhpFiles($apiRoot . '/app', fn (string $p) => true) as $file) {
    $content = @file_get_contents($file);
    if ($content === false) {
        continue;
    }
    // Real writes use array payload; docblocks/strings mention LedgerEntry::create() without '['
    if (! preg_match('/LedgerEntry::create\s*\(\s*\[/', $content)
        && ! preg_match('/\\\\App\\\\Models\\\\LedgerEntry::create\s*\(\s*\[/', $content)) {
        continue;
    }
    $class = appClassFromFile($apiRoot, $file);
    if ($class === null) {
        continue;
    }
    if (! isset($allowedSet[$class])) {
        fail($errors, 'A', "LedgerEntry::create used in non-allowlisted class {$class} ({$file})");
    }
}

echo "[B] Bare tenant-model ::find(\$…) (Controllers, Http/Requests, Domains)…\n";
// --- B. Bare ::find($…) on tenant-scoped models (Controllers + Requests + Domains) ---
$tenantModels = [
    'Project', 'Party', 'SettlementPack', 'Settlement', 'Payment', 'LoanDrawdown', 'LoanRepayment',
    'SupplierInvoice', 'CropCycle', 'OperationalTransaction', 'Advance', 'Sale', 'Harvest',
    'InvGrn', 'LandLeaseAccrual', 'MachineryCharge', 'PostingGroup', 'LandParcel', 'JournalEntry',
    'LoanAgreement', 'CropActivity', 'InvIssue', 'InvTransfer', 'InvAdjustment', 'LabWorkLog',
    'MachineMaintenanceJob', 'DailyBookEntry', 'HarvestLine', 'SupplierPaymentAllocation',
];

$bareFindPattern = '/\b(' . implode('|', array_map('preg_quote', $tenantModels)) . ')::find\(\$/';

$skipPrefixes = [
    $apiRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Platform',
    $apiRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Dev',
    $apiRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Middleware',
];

$bScanDirs = [
    $apiRoot . '/app/Http/Controllers',
    $apiRoot . '/app/Http/Requests',
    $apiRoot . '/app/Domains',
];

$bareFindIgnores = require $apiRoot . '/config/accounting_invariants.php';

foreach ($bScanDirs as $scanDir) {
    foreach (iterPhpFiles($scanDir, fn (string $p) => true) as $file) {
        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($file, $prefix)) {
                continue 2;
            }
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }
        $rel = relativePath($apiRoot, $file);
        foreach ($lines as $i => $line) {
            $lineNum = $i + 1;
            if (! preg_match($bareFindPattern, $line)) {
                continue;
            }
            if (str_contains($line, 'TenantScoped::for')) {
                continue;
            }
            if (preg_match('/^\s*(\/\/|#|\*)/', $line)) {
                continue;
            }
            $ignored = $bareFindIgnores['bare_find_line_ignores'] ?? [];
            $key = $rel . ':' . $lineNum;
            if (isset($ignored[$key])) {
                continue;
            }
            fail($errors, 'B', "Possible tenant leak: bare Model::find(\$…) without TenantScoped::for in {$rel}:{$lineNum}\n    " . trim($line));
        }
    }
}

echo "[C] POST controller post() methods — posting_date validation…\n";
// --- C. POST …/post endpoints must validate posting_date (FormRequest rules or inline validate) ---
$controllerDir = $apiRoot . '/app/Http/Controllers';
foreach (iterPhpFiles($controllerDir, fn (string $p) => true) as $file) {
    if (str_contains($file, DIRECTORY_SEPARATOR . 'Platform' . DIRECTORY_SEPARATOR)
        || str_contains($file, DIRECTORY_SEPARATOR . 'Dev' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $content = @file_get_contents($file);
    if ($content === false || ! preg_match('/public\s+function\s+post\s*\(/', $content)) {
        continue;
    }
    // Extract post(RequestClass $request... method blocks naïvely
    if (! preg_match_all(
        '/public\s+function\s+post\s*\(\s*([^)]+)\)\s*(?:\:[^{]+)?\s*\{/s',
        $content,
        $paramMatches,
        PREG_OFFSET_CAPTURE
    )) {
        continue;
    }
    foreach ($paramMatches[0] as $idx => $fullMatch) {
        $params = trim($paramMatches[1][$idx][0]);
        $start = $paramMatches[0][$idx][1] + strlen($fullMatch[0]);
        $brace = 1;
        $len = strlen($content);
        $end = $start;
        for ($i = $start; $i < $len && $brace > 0; $i++) {
            $c = $content[$i];
            if ($c === '{') {
                $brace++;
            } elseif ($c === '}') {
                $brace--;
            }
            $end = $i;
        }
        $methodBody = substr($content, $start, $end - $start);

        if (preg_match('/\\\\Illuminate\\\\Http\\\\Request\s+\$/', $params) || preg_match('/\bRequest\s+\$/', $params)) {
            if (! str_contains($methodBody, 'posting_date')) {
                $rel = relativePath($apiRoot, $file);
                fail($errors, 'C', "POST handler uses Illuminate Request but method body never references posting_date: {$rel}");
            }
            continue;
        }
        if (preg_match('/(\w+Request)\s+\$/', $params, $rm)) {
            $reqShort = $rm[1];
            $reqFile = $apiRoot . '/app/Http/Requests/' . $reqShort . '.php';
            if (! is_file($reqFile)) {
                warn($warnings, 'C', "Could not resolve FormRequest file for {$reqShort} ({$file})");

                continue;
            }
            $reqContent = (string) file_get_contents($reqFile);
            if (! str_contains($reqContent, 'posting_date')) {
                $relReq = relativePath($apiRoot, $reqFile);
                fail($errors, 'C', "FormRequest {$reqShort} has no posting_date rule (journal may use prohibited — file must still declare posting_date): {$relReq}");
            }
        }
    }
}

echo "[D] PostingService classes — PostingGroup::create…\n";
// --- D. *PostingService.php must create a PostingGroup ---
$postingServices = iterator_to_array(iterPhpFiles(
    $apiRoot . '/app',
    fn (string $p) => str_ends_with($p, 'PostingService.php')
));

foreach ($postingServices as $file) {
    $c = (string) file_get_contents($file);
    if (! preg_match('/PostingGroup::create\s*\(/', $c)) {
        $rel = str_replace($apiRoot . DIRECTORY_SEPARATOR, '', $file);
        fail($errors, 'D', "Posting service never calls PostingGroup::create(): {$rel}");
    }
}

// --- Output ---
if ($warnings !== []) {
    fwrite(STDERR, "Accounting invariant warnings:\n");
    foreach ($warnings as $w) {
        fwrite(STDERR, $w . "\n");
    }
    fwrite(STDERR, "\n");
}

if ($errors !== []) {
    fwrite(STDERR, "\nAccounting invariant check FAILED:\n\n");
    foreach ($errors as $e) {
        fwrite(STDERR, $e . "\n\n");
    }
    exit(1);
}

echo "Accounting invariant checks passed.\n";
if ($warnings !== []) {
    echo "(with warnings — see stderr)\n";
}
exit(0);
