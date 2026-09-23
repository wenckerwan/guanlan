#!/usr/bin/env python3
"""离线 PHP 结构检查器。

本机没有 PHP/Docker，无法跑 `php -l`。这个脚本用最小解析覆盖本项目实际踩过的坑：

1. 语法骨架：每文件的 `<?php`、花括号/圆括号/方括号配平、`declare(strict_types=1)`。
2. PSR-4 一致性：`src/` 下 `namespace App\X;` 与 `class Name` 必须匹配文件路径。
3. 类型/父类引用：`extends X` / `implements X` / `new X(` / `X::` 中的类要么在本项目内
   可解析，要么属于 `use` 导入，要么是 PHP 内置与已知框架基类白名单。
4. 模型列引用：`$model->col` 与对应 migration 的列集合比对（只报本项目模型）。
5. 路由 -> 控制器方法：`config/routes.php` 里 `[C::class, 'm']` 的 `m` 方法必须存在。

退出码非 0 表示发现问题。
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
API = ROOT / "apps" / "api"

# PHP 内置类/接口与常见扩展
BUILTIN = {
    "Throwable", "Exception", "Error", "Closure", "Generator", "ArrayAccess",
    "Countable", "IteratorAggregate", "Traversable", "Iterator", "Stringable",
    "JsonSerializable", "DateTimeInterface", "DateTime", "DateTimeImmutable",
    "stdClass", "ArrayObject", "SplStack", "SplQueue", "WeakMap", "ReflectionClass",
    "JsonException", "InvalidArgumentException", "RuntimeException", "LogicException",
    "TypeError", "ValueError", "UnhandledMatchError", "Attribute",
}

# 框架基类白名单（vendor 未安装，离线无法解析）
FRAMEWORK = {
    "Hyperf\\Database\\Model\\Model", "Hyperf\\Database\\Model\\Builder",
    "Hyperf\\Database\\Model\\Relations\\BelongsTo", "Hyperf\\Database\\Model\\Relations\\HasMany",
    "Hyperf\\Database\\Model\\Relations\\HasOne", "Hyperf\\Database\\Model\\Relations\\BelongsToMany",
    "Hyperf\\Database\\Migrations\\Migration", "Hyperf\\Database\\Schema\\Blueprint",
    "Hyperf\\Database\\Schema\\Schema", "Hyperf\\Db\\Query\\Builder",
    "Hyperf\\Db\\ConnectionInterface", "Hyperf\\Db\\Connection\\Connection",
    "Hyperf\\ExceptionHandler\\ExceptionHandler",
    "Hyperf\\HttpServer\\Exception\\Handler\\HttpExceptionHandler",
    "Hyperf\\HttpServer\\Contract\\RequestInterface", "Hyperf\\HttpServer\\Contract\\ResponseInterface",
    "Hyperf\\HttpServer\\Router\\Router", "Hyperf\\HttpMessage\\Stream\\SwooleStream",
    "Hyperf\\HttpMessage\\Response", "Hyperf\\HttpMessage\\ServerRequest",
    "Hyperf\\Context\\ApplicationContext", "Hyperf\\Di\\Annotation\\Inject",
    "Hyperf\\Contract\\StdoutLoggerInterface", "Hyperf\\Command\\Command",
    "Hyperf\\Command\\Annotation\\Command", "Hyperf\\Support\\Str", "Hyperf\\Support\\Arr",
    "Hyperf\\Support\\Collection", "Hyperf\\Stdlib\\Str\\Str",
    "Psr\\Http\\Message\\ResponseInterface", "Psr\\Http\\Message\\ServerRequestInterface",
    "Psr\\Http\\Message\\StreamInterface", "Psr\\Container\\ContainerInterface",
    "Swow\\Psr7\\Message\\ResponsePlusInterface",
    "Stringable", "Pdo\\PDO",
    # 日志与运行时类（config/autoload/logger.php）
    "Monolog\\Handler\\StreamHandler", "Monolog\\Formatter\\LineFormatter", "Monolog\\Level",
    "Hyperf\\DbConnection\\Db",
}

# 以短名/字符串形式出现的运行时类名，静态无法解析（Hyperf server 回调、seeder 自举）
RUNTIME_SHORTNAMES = {
    "StreamHandler", "LineFormatter", "Level",
    "PipeMessageCallback", "WorkerStartCallback", "WorkerExitCallback",
    "Db",
}

errors: list[str] = []
checked = 0


def strip_php(text: str) -> str:
    """去掉注释与字符串字面量，避免误报。

    用逐字符扫描而不是正则：PHP 单引号串里的 `\\` 转义会让正则版本错配
    （本项目 SearchService 的 `'/\\s+/u'` 就触发过假告警）。
    """
    out = []
    i, n = 0, len(text)
    while i < n:
        c = text[i]
        if c in "'\"":
            quote = c
            i += 1
            while i < n:
                if text[i] == "\\":
                    i += 2
                    continue
                if text[i] == quote:
                    i += 1
                    break
                i += 1
            out.append("''")
            continue
        if c == "/" and i + 1 < n and text[i + 1] == "*":
            j = text.find("*/", i + 2)
            i = n if j < 0 else j + 2
            out.append(" ")
            continue
        if c == "/" and i + 1 < n and text[i + 1] == "/":
            j = text.find("\n", i)
            i = n if j < 0 else j
            out.append(" ")
            continue
        if c == "#" and (i + 1 < n and text[i + 1] != "["):
            j = text.find("\n", i)
            i = n if j < 0 else j
            out.append(" ")
            continue
        out.append(c)
        i += 1
    return "".join(out)


def balanced(path: Path, raw: str) -> None:
    code = strip_php(raw)
    for open_c, close_c, name in [("{", "}", "brace"), ("(", ")", "paren"), ("[", "]", "bracket")]:
        if code.count(open_c) != code.count(close_c):
            errors.append(f"{path.relative_to(ROOT)}: unbalanced {name} {code.count(open_c)}/{code.count(close_c)}")


def syntax_hazards(path: Path, raw: str) -> None:
    code = strip_php(raw)
    if re.search(r"(?<!\()\bnew\s+[A-Z]\w*\s*\([^;]*\)\s*->", code, re.S):
        errors.append(
            f"{path.relative_to(ROOT)}: invalid direct method chain after new expression; wrap it in parentheses"
        )


def php_files(*dirs: str) -> list[Path]:
    out: list[Path] = []
    for d in dirs:
        out.extend(sorted((API / d).rglob("*.php")))
    return out


def main() -> int:
    global checked

    files = php_files("src", "config", "migrations", "seeders")
    if not files:
        print("no php files found")
        return 1

    project_classes: dict[str, Path] = {}
    file_info: dict[Path, dict] = {}

    for path in files:
        raw = path.read_text(encoding="utf-8")
        code = strip_php(raw)
        checked += 1

        if "<?php" not in raw:
            errors.append(f"{path.relative_to(ROOT)}: missing <?php")
        balanced(path, raw)
        syntax_hazards(path, raw)
        if "declare(strict_types=1)" in code and "declare(strict_types=1);" not in code:
            errors.append(f"{path.relative_to(ROOT)}: malformed declare")

        ns = re.search(r"^namespace\s+([^;]+);", code, re.M)
        cls = re.search(r"^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)", code, re.M)
        uses = dict(re.findall(r"^use\s+([\w\\]+)(?:\s+as\s+(\w+))?\s*;", code, re.M))

        info = {"ns": ns.group(1).strip() if ns else "", "class": cls.group(1) if cls else "",
                "uses": uses, "path": path}
        file_info[path] = info

        if info["ns"].startswith("App\\") and info["class"]:
            fqcn = f"{info['ns']}\\{info['class']}"
            project_classes[fqcn] = path

    # PSR-4：src/ 内 namespace 必须与目录一致
    for path, info in file_info.items():
        try:
            rel = path.relative_to(API / "src")
        except ValueError:
            continue  # migrations / seeders / config 不受 PSR-4 约束
        expected = "App\\" + "\\".join(rel.parts[:-1])
        if info["ns"] != expected.rstrip("\\"):
            errors.append(
                f"{path.relative_to(ROOT)}: namespace '{info['ns']}' != path '{expected}'"
            )

    # 引用解析：extends / new / :: 用到但没 use 也没在项目里的类
    known = set(project_classes) | BUILTIN | FRAMEWORK
    short_names = {fqcn.rsplit("\\", 1)[-1]: fqcn for fqcn in known}

    # seeders/ 与 migrations/ 是全局命名空间且靠 require_once 互相引用，
    # 因此同目录下的类名视为已解析。
    sibling_names = set()
    for path in php_files("seeders", "migrations"):
        code = strip_php(path.read_text(encoding="utf-8"))
        cls = re.search(r"^(?:final\s+|abstract\s+)?class\s+(\w+)", code, re.M)
        if cls:
            sibling_names.add(cls.group(1))
    short_names.update({name: name for name in sibling_names})

    for path, info in file_info.items():
        code = strip_php(path.read_text(encoding="utf-8"))
        own_ns = info["ns"]
        aliases = set(info["uses"].values()) | set(k.rsplit("\\", 1)[-1] for k in info["uses"])

        refs = set()
        refs |= set(re.findall(r"(?:extends|implements)\s+([A-Z]\w+)", code))
        refs |= set(re.findall(r"\bnew\s+([A-Z]\w+)\s*\(", code))
        refs |= set(re.findall(r"\b([A-Z]\w+)::", code))
        refs |= set(re.findall(r"\)\s*:\s*\??([A-Z]\w+)", code))

        for ref in refs:
            if ref in aliases or ref in BUILTIN or ref in RUNTIME_SHORTNAMES:
                continue
            if f"{own_ns}\\{ref}" in known:
                continue
            if ref in short_names:
                continue
            if ref in ("self", "static", "parent"):
                continue
            errors.append(f"{path.relative_to(ROOT)}: unresolved class reference '{ref}'")

    # 模型列：model -> table -> migration columns
    table_columns: dict[str, set[str]] = {}
    for path in php_files("migrations"):
        # 必须用原文：strip_php 会把 'papers' 这类表名字符串抹掉
        code = path.read_text(encoding="utf-8")
        for tbl, body in re.findall(r"Schema::create\(\s*'(\w+)'\s*,\s*function\s*\([^)]*\)\s*\{(.*?)\n\s*\}\s*\);", code, re.S):
            cols = set(re.findall(r"\$table->\w+\(\s*'(\w+)'", body))
            cols |= {"id", "created_at", "updated_at"}
            table_columns.setdefault(tbl, set()).update(cols)
        # 追加列（extend migrations）
        for tbl, body in re.findall(r"Schema::table\(\s*'(\w+)'\s*,\s*function\s*\([^)]*\)\s*\{(.*?)\n\s*\}\s*\);", code, re.S):
            cols = set(re.findall(r"\$table->\w+\(\s*'(\w+)'", body))
            table_columns.setdefault(tbl, set()).update(cols)

    model_table: dict[str, str] = {}
    for fqcn, path in project_classes.items():
        if "\\Model\\" not in fqcn:
            continue
        # 用原文：表名字符串会被 strip_php 抹掉
        code = path.read_text(encoding="utf-8")
        m = re.search(r"\$table\s*=\s*'(\w+)'", code)
        if m:
            model_table[fqcn.rsplit("\\", 1)[-1]] = m.group(1)
        else:
            model_table[fqcn.rsplit("\\", 1)[-1]] = re.sub(r"(?<!^)(?=[A-Z])", "_", fqcn.rsplit("\\", 1)[-1]).lower() + "s"

    for fqcn, path in project_classes.items():
        if "\\Model\\" not in fqcn:
            continue
        cls_name = fqcn.rsplit("\\", 1)[-1]
        table = model_table.get(cls_name)
        if table not in table_columns:
            continue
        cols = table_columns[table]
        # 用原文：列名字符串同样会被 strip_php 抹掉
        code = path.read_text(encoding="utf-8")
        # 只检查 casts 里声明的列存在
        cast_block = re.search(r"\$casts\s*=\s*\[(.*?)\]", code, re.S)
        for cast_col in re.findall(r"'(\w+)'\s*=>", cast_block.group(1) if cast_block else ""):
            if cast_col not in cols:
                errors.append(f"{path.relative_to(ROOT)}: cast column '{cast_col}' not in table '{table}'")

    # 路由 -> 控制器方法
    routes = API / "config" / "routes.php"
    if routes.exists():
        # 必须用原文：strip_php 会把 'methodName' 字符串抹掉，导致路由检查失效
        code = routes.read_text(encoding="utf-8")
        # 控制器可能写成短名（已 use）或 FQCN（\App\Controller\X::class）
        pattern = r"\[\s*\\?([\w\\]+)::class\s*,\s*'(\w+)'\s*\]"
        for ctrl, method in re.findall(pattern, code):
            ctrl_short = ctrl.rsplit("\\", 1)[-1]
            target = None
            for fqcn, path in project_classes.items():
                if fqcn == ctrl or (fqcn.rsplit("\\", 1)[-1] == ctrl_short and "\\Controller\\" in fqcn):
                    target = path
                    break
            if target is None:
                errors.append(f"config/routes.php: controller '{ctrl_short}' not found")
                continue
            body = strip_php(target.read_text(encoding="utf-8"))
            if not re.search(rf"function\s+{method}\s*\(", body):
                errors.append(
                    f"config/routes.php: {ctrl_short}::{method} not defined in {target.relative_to(ROOT)}"
                )

    print(f"checked={checked} files, classes={len(project_classes)}, tables={len(table_columns)}")
    if errors:
        print(f"\nFAILED ({len(errors)}):")
        for e in errors:
            print("  -", e)
        return 1
    print("OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
