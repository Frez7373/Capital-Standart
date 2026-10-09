<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this script from the command line only.\n");
}

fwrite(STDOUT, "Создание учётной записи сотрудника банка «Капитал-Стандарт»\nEmail: ");
$email = mb_strtolower(trim((string)fgets(STDIN)));
fwrite(STDOUT, "First name: ");
$first = trim((string)fgets(STDIN));
fwrite(STDOUT, "Last name: ");
$last = trim((string)fgets(STDIN));
fwrite(STDOUT, "Password (min 12 characters): ");
if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
    $password = trim((string)fgets(STDIN));
} else {
    shell_exec('stty -echo');
    $password = rtrim((string)fgets(STDIN), "\r\n");
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $first === '' || $last === '' || strlen($password) < 12) {
    fwrite(STDERR, "Invalid input. Use a valid email, names, and password of at least 12 characters.\n");
    exit(1);
}
try {
    $stmt = db()->prepare("INSERT INTO users (first_name,last_name,email,password_hash,role,status) VALUES (?,?,?,?,'banker','active')");
    $stmt->execute([$first,$last,$email,password_hash($password,PASSWORD_DEFAULT)]);
    fwrite(STDOUT, "Banker created successfully: {$email}\n");
} catch (PDOException $e) {
    fwrite(STDERR, "Could not create banker. The email may already exist.\n");
    exit(1);
}
