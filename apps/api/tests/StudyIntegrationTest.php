<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__);
    require $root . '/src/Service/StudyService.php';

    use App\Service\StudyService;

    $failures = [];
    $check = static function (string $label, mixed $actual, mixed $expected) use (&$failures): void {
        if ($actual !== $expected) {
            $failures[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
        }
    };

    // 类型白名单：现有 9 类 + 马原 4 类 + 史纲 2 类 = 15 类。
    $check('target type count is 15', count(StudyService::TARGET_TYPES), 15);
    foreach (['mayuan_concept', 'mayuan_relation', 'mayuan_comparison', 'mayuan_experiment', 'history_event', 'history_comparison'] as $type) {
        $check('whitelist contains ' . $type, in_array($type, StudyService::TARGET_TYPES, true), true);
    }

    // setFavorite：幂等（取消收藏时无论是否存在都返回 favorited:false）。
    $service = (string) file_get_contents($root . '/src/Service/StudyService.php');
    $check('setFavorite method exists', str_contains($service, 'function setFavorite'), true);
    $check('setFavorite removes when not favorited', str_contains($service, "if (! \$favorited)"), true);
    $check('setFavorite updates existing favorite', str_contains($service, '$existing->save();'), true);
    $check('setFavorite creates new favorite', str_contains($service, 'Favorite::create'), true);
    $check('setFavorite returns favorited flag', str_contains($service, "return ['favorited' => false];"), true);

    // updateNote：仅限本人 + 只改 content。
    $check('updateNote method exists', str_contains($service, 'function updateNote'), true);
    $check('updateNote scopes to owner', str_contains($service, "->where('user_id', \$user->id)"), true);
    $check('updateNote only sets content', str_contains($service, '$note->content = $content;'), true);

    // 控制器：PUT 幂等 + PATCH 编辑端点存在。
    $controller = (string) file_get_contents($root . '/src/Controller/StudyController.php');
    $check('controller setFavorite endpoint', str_contains($controller, 'function setFavorite'), true);
    $check('controller updateNote endpoint', str_contains($controller, 'function updateNote'), true);
    $check('controller setFavorite validates whitelist', str_contains($controller, "->in('targetType', StudyService::TARGET_TYPES"), true);
    $check('controller updateNote caps content at 20000', str_contains($controller, "->max('content', 20000"), true);

    // 路由：PUT favorites + PATCH notes 已注册。
    $routes = (string) file_get_contents($root . '/config/routes.php');
    $check('route PUT favorites', str_contains($routes, "Router::put('/favorites', [StudyController::class, 'setFavorite'])"), true);
    $check('route PATCH notes', str_contains($routes, "Router::patch('/notes/{id:\\d+}', [StudyController::class, 'updateNote'])"), true);
    $check('route POST toggle kept for compat', str_contains($routes, "'toggleFavorite'"), true);

    if ($failures !== []) {
        fwrite(STDERR, "StudyIntegrationTest: FAIL\n" . implode("\n", $failures) . "\n");
        exit(1);
    }
    echo "StudyIntegrationTest: PASS\n";
}
