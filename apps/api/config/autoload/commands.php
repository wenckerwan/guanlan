<?php

declare(strict_types=1);
/**
 * 显式注册 hyperf/database 的控制台命令（migrate / db:seed 等）。
 *
 * 背景：本地 Composer 镜像提供的 hyperf/database dist 缺少 ConfigProvider.php，
 * 其 composer.json 也没有 extra.hyperf.config，导致 Hyperf 无法通过
 * ProviderConfig 自动发现该组件的命令，`php bin/hyperf.php` 只剩 start，
 * migrate / db:seed 报 "Command not defined"。
 *
 * Hyperf\Config\ConfigFactory 用 array_merge_recursive 合并 config/autoload/*.php，
 * 文件名即配置键，故本文件返回的数组会并入 config('commands')，
 * 与 hyperf/server 提供的 start 命令追加共存（ApplicationFactory 读取该键实例化命令）。
 */

use Hyperf\Database\Commands\CommandCollector;

return CommandCollector::getAllCommands();
