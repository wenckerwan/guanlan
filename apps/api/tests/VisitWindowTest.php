<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/VisitWindow.php';

use App\Support\VisitWindow;

$check = static function (bool $condition, string $label): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
};
$check(VisitWindow::shouldCount(null, 1000), 'first visit');
$check(! VisitWindow::shouldCount(1000, 4599), 'before boundary');
$check(VisitWindow::shouldCount(1000, 4600), 'at exact boundary');
$check(! VisitWindow::shouldCount(1000, 999), 'clock rollback');
$midnight = (new DateTimeImmutable('2026-10-07T00:00:00+08:00'))->getTimestamp();
$check(! VisitWindow::shouldCount($midnight - 600, $midnight + 600), 'midnight preserves window');
$check(VisitWindow::date($midnight - 1) === '2026-10-06', 'Shanghai yesterday');
$check(VisitWindow::date($midnight) === '2026-10-07', 'Shanghai today');
echo "VisitWindowTest: PASS\n";
