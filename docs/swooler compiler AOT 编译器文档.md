# AOT 编译器文档

## 编译参数

AOT 编译器支持命令行参数和 `project.yml` 配置文件两种方式指定编译选项。命令行参数优先级最高，其次是 YAML 配置，最后是默认值。

### 用法

```shell
php bin/compiler.php <file/dir/project.yml> [options]
```

### 命令行参数

#### 基本参数

**`-O <level>`** — 设置 GCC 优化级别。

- 取值范围：`0` ~ `3`，默认 `0`
- `-O0`：不优化，编译速度最快，适合调试
- `-O2`：常用的发布优化级别，平衡编译时间和运行性能
- `-O3`：最高优化级别，激进的函数内联和向量化

```shell
php bin/compiler.php app.php -O2
```

**`-o, --output <file>`** — 指定输出文件名。

默认使用入口文件或目录的基本名。构建二进制时生成可执行文件，构建扩展时生成 `.so`/`.dll`。

```shell
php bin/compiler.php src/ -o myapp
```

**`-m, --mode <mode>`** — 构建模式。

- `bin`（默认）：编译为独立的可执行文件
- `ext`：编译为 PHP 扩展（`.so`/`.dll`）

```shell
php bin/compiler.php myext/ -m ext -o myext
```

#### 调试与诊断

**`-d, --debug`** — 启用调试模式。

自动禁用优化并添加调试符号（`-g`），便于使用 GDB/LLDB 调试生成的二进制文件。

```shell
php bin/compiler.php app.php -d
```

**`--sanitize <type>`** — 启用 Sanitizer。

在生成的 C++ 代码中启用编译器 Sanitizer，用于检测内存错误和未定义行为。

- `address` — AddressSanitizer（内存越界、use-after-free 等）
- `undefined` — UndefinedBehaviorSanitizer（整数溢出、空指针等）

```shell
php bin/compiler.php app.php --sanitize=address
```

#### 性能相关

**`-p, --profile`** — 启用性能分析。

在生成的二进制中插入性能分析探针，可配合 `perf` 等工具进行性能分析。

```shell
php bin/compiler.php app.php -p
```

**`-j, --job <num>`** — 并行编译任务数。

控制同时编译的 C++ 文件数量，类似 `make -j`。默认值为 `4`。在 CPU 核心数较多的机器上可适当增大。

```shell
php bin/compiler.php app.php -j8
```

**`--no-literal-strings`** — 禁用字符串字面量优化。

默认情况下，编译器将字面量字符串优化为 `const char*` 指针以提升性能。某些场景下（如需要 `zend_string` 兼容性）可能需要关闭此优化。

```shell
php bin/compiler.php app.php --no-literal-strings
```

#### 其他

**`-f, --force`** — 强制重新编译。

即使存在编译缓存也强制重新编译所有文件。

```shell
php bin/compiler.php app.php -f
```

**`--cxx-std <version>`** — 指定 C++ 标准版本。

默认使用 `c++17`。如果你的代码或依赖的库需要特定的 C++ 版本，可通过此参数指定。

```shell
php bin/compiler.php app.php --cxx-std=c++20
```

**`--no-console`** — 隐藏控制台窗口（仅 Windows）。

Windows 平台下，使用 `/SUBSYSTEM:WINDOWS` 链接，程序启动时不显示控制台窗口，适用于 GUI 应用程序。

```shell
php bin/compiler.php gui-app.php --no-console
```

**`-v, --version`** — 显示版本信息。

**`-h, --help`** — 显示帮助信息。

### project.yml 配置

对于多文件项目，推荐使用 `project.yml` 配置文件管理项目构建参数。`project.yml` 放置在项目根目录。

#### 完整示例

```yaml
# 项目名称（输出文件名）
name: myapp

# 构建模式：bin(可执行文件) / ext(扩展) / binary / extension / cli
build-mode: bin

# 源码列表（文件或目录）
sources:
  - src/
  - lib/utils.php
  - native/helper.cpp

# 忽略的文件或目录
ignore:
  - src/tests/
  - src/vendor/
  - ext-gd          # 忽略特定扩展依赖

# C++ 标准版本
cxx-std: c++17

# 自定义 C++ 编译器（gcc/g++/clang/clang++ 等）
cpp-compiler: g++

# 额外的 C++ 编译选项
cxx-flags:
  - -march=native
  - -mtune=native

# 额外的链接选项
ld-flags:
  - -lcurl
  - -lssl

# Windows 资源配置（仅 Windows 平台）
resource:
  icon: assets/app.ico
```

#### 配置项说明

| 配置项 | 类型 | 说明 |
| --- | --- | --- |
| `name` | `string` | 输出文件名，等价于 `-o` |
| `build-mode` / `type` | `string` | 构建模式，等价于 `-m`。支持 `bin`/`binary`/`cli`（可执行文件）和 `ext`/`extension`（扩展） |
| `sources` | `array` | 源码文件或目录列表。支持 `.php`、`.cpp`、`.c`、`.s`、`.m`、`.mm` |
| `ignore` | `array` | 忽略的路径或扩展名。路径支持文件/目录；`ext-<name>` 格式用于忽略特定扩展依赖 |
| `cxx-std` / `cxx_std` | `string` | C++ 标准版本，等价于 `--cxx-std` |
| `cpp-compiler` / `cpp_compiler` | `string` | 自定义 C++ 编译器（如 `g++`、`clang++`） |
| `cxx-flags` / `cxxflags` | `string` 或 `array` | 额外的 C++ 编译选项，会追加到编译命令中 |
| `ld-flags` / `ldflags` | `string` 或 `array` | 额外的链接选项，会追加到链接命令中 |
| `resource` | `object` | Windows 平台资源配置（图标等） |

#### 优先级

命令行参数 > `project.yml` > 默认值。

例如，`project.yml` 中设置了 `cxx-std: c++17`，但命令行传入 `--cxx-std=c++20`，最终使用 `c++20`。

#### 不使用 project.yml

也可以直接编译单个 PHP 文件或目录，无需创建 `project.yml`：

```shell
# 编译单个文件
php bin/compiler.php hello.php -O2

# 编译整个目录
php bin/compiler.php myproject/ -O2 -o myapp
```

## 调试

AOT 编译器仅支持 `gdb` 调试，不支持 `Xdebug` 等工具。若要使用调试功能，请确保：

- 优化等级设置为 `-O0`，关闭优化，否则函数调用可能会被内联
- 开启调试符号，编译参数添加 `--debug`
- `PHPX` 和 `PHP` 建议编译为 `debug` 版本

### PHPX

```bash
cmake . -D CMAKE_BUILD_TYPE=Debug
```

### PHP

```bash
./configure --enable-debug
```

### 编译 PHP 项目

```bash
./swoole_compiler project.yml --debug
```

## GDB 调试

```bash
gdb ./hello
```

### 断点设置

所有函数均以 `php_` 为前缀，例如下面的代码

```php
function my_add(int $a, int $b): int
{
    $env = $_ENV;
    var_dump(count($env));
    return $a + $b;
}

function main()
{
    var_dump(my_add(1, 2));
}
```

`b php_my_add` 表示为 `my_add` 添加断点

```bash
b php_my_add
r
Breakpoint 1, php_my_add (a=1, b=2) at /home/swoole/workspace/aot/build/examples/myext/test.cc:9
9	    php::Int tmp_var_0 = 0;
```

如果是原生类型，则可以直接使用 `gdb` 的 `print` 指令输出其值。若为 `PHP` 类型，则可以使用 `call var.print()` 输出。

```bash
(gdb) call env.print()
         array(61) {
           ["SHELL"]=>
           string(9) "/bin/bash"
           ["SESSION_MANAGER"]=>
           string(71) "local/swoole-26:@/tmp/.ICE-unix/4392,unix/swoole-26:/tmp/.ICE-unix/4392"
           ["QT_ACCESSIBILITY"]=>
           string(1) "1"
           ["COLORTERM"]=>
           string(9) "truecolor"
           ["XDG_CONFIG_DIRS"]=>
           string(28) "/etc/xdg/xdg-ubuntu:/etc/xdg"
           ["SSH_AGENT_LAUNCHER"]=>
           string(13) "gnome-keyring"
           ["XDG_MENU_PREFIX"]=>
           string(6) "gnome-"
           ["GNOME_DESKTOP_SESSION_ID"]=>
           string(18) "this-is-deprecated"
...
```

### 函数符号

- 函数：`php_{命名空间}__{函数名}`，若没有命名空间则为 `php_{函数名}`，名称必须全部转为小写
- 类方法：`php_{命名空间}__{类名}__{方法名}`，若命名空间为多层，则需要使用双下划线分割，名称必须全部转为小写
- 内置函数：`zif_{函数名}`
- 内置类方法：`zim_{类名}_{方法名}`，需要查看对应扩展的实现方式

在符号上添加断点，在函数调用发生时，对其进行调试。

## 类型系统

`AOT` 编译器在标准 `PHP` 类型之外扩展了高精度数值类型和强类型容器，并在编译期进行类型推断和检查。本文档介绍编译器支持的所有类型、类型转换方法及使用限制。

### 1. 类型总览

AOT 编译器支持以下 C++ 存储类型（`php::*`）：

| 类型常量 | C++ 类型 | 对应 PHP 类型 | 说明 |
| --- | --- | --- | --- |
| `TYPE_VAR` | `php::Var` | `mixed` | 动态类型，使用 zval 存储 |
| `TYPE_INT` | `php::Int` | `int` | 原生 64 位整数 |
| `TYPE_FLOAT` | `php::Float` | `float` | 原生双精度浮点 |
| `TYPE_BOOL` | `php::Bool` | `bool` | 原生布尔值 |
| `TYPE_STR` | `php::Str` | `string` | 原生字符串 |
| `TYPE_ARRAY` | `php::Array` | `array` | 原生数组 |
| `TYPE_OBJECT` | `php::Object` | `object` | 通用对象（无具体类信息） |
| `TYPE_STREAM` | `php::Stream` | `resource` | 流资源类型 |
| `TYPE_RESOURCE` | `php::Resource` | `resource` | 通用资源类型 |
| `TYPE_BIGINT` | `php::BigInt` | — | 任意精度整数 |
| `TYPE_DECIMAL` | `php::Decimal` | — | 十进制高精度小数 |
| `TYPE_BIGFLOAT` | `php::BigFloat` | — | 二进制高精度浮点 |
| `TYPE_STD_VECTOR` | `php::StdVector` | — | C++ `std::vector` |
| `TYPE_STD_ARRAY` | `php::StdArray` | — | C++ `std::array`（定长） |
| `TYPE_STD_MAP` | `php::StdMap` | — | C++ `std::map`（有序） |
| `TYPE_STD_UNORDERED_MAP` | `php::StdUnorderedMap` | — | C++ `std::unordered_map` |
| `TYPE_ARGS` | `php::Args` | — | 可变参数列表 |
| `TYPE_REF` | `php::Ref` | — | 引用类型 |
| `TYPE_VOID` | `void` | `void` | 无返回值 |

### 2. 原生类型

在 `use native_types` 声明下，编译器将 `int`、`float`、`bool` 映射为原生 C++ 类型，消除 `zval` 包装开销。

```php
declare(strict_types=1);
use native_types;

function sum(int $n): int {
    $total = 0;        // php::Int
    $pi = 3.14159;     // php::Float
    $flag = true;      // php::Bool
    $name = "hello";   // php::Str
    $items = [1, 2];   // php::Array
    return $total + $n;
}
```

不使用 `use native_types` 时，所有变量默认为 `php::Var`（动态类型）。

#### 2.1 原生类型与 PHP 类型声明映射

| PHP 类型声明 | AOT 类型 | 说明 |
| --- | --- | --- |
| `int` | `php::Int` | |
| `float` / `double` | `php::Float` | |
| `bool` / `false` / `true` | `php::Bool` | |
| `string` | `php::Str` | |
| `array` | `php::Array` | |
| `object` | `php::Object` | 无具体类信息 |
| `void` / `never` | `void` | |
| `mixed` | `php::Var` | |
| `null` | `php::Var` | 退化为动态类型 |
| `callable` | `php::Var` | 编译器无法追踪 |
| `iterable` | `php::Var` | 编译器无法追踪 |
| `stream` | `php::Stream` | AOT 专有 |

> **注意**：`null`、`callable`、`iterable` 类型声明在编译阶段会退化为 `php::Var`，无法享受原生类型的性能优势。

### 3. 高精度数值类型

AOT 编译器提供三种高精度数值类型，详见 [math.md](#高精度运算)。

#### 3.1 构造方式

```php
declare(strict_types=1);
use native_types;

// 通过 std:: 工厂函数构造
$a = std::bigInt("12345678901234567890");
$b = std::decimal("0.1");
$c = std::bigFloat("1.2345e100");

// 字面量自动识别（超长整数 / 高精度浮点）
$d = 12345678901234567890;    // 19 位以上自动识别为 BigInt
$e = 0.1234567890123456;      // 16 位以上有效数字自动识别为 Decimal
```

#### 3.2 声明指令

- **`use bigint_types`**：文件中所有整数字面量自动成为 `BigInt`
- **`use decimal_types`**：文件中所有浮点字面量自动成为 `Decimal`

```php
declare(strict_types=1);
use bigint_types;
use decimal_types;

$a = 42;      // php::BigInt（不是 php::Int）
$b = 3.14;    // php::Decimal（不是 php::Float）
```

#### 3.3 不可变性

Big* 类型是**不可变的**（immutable）——每次运算返回新值，不修改原变量。复合赋值运算（`+=`、`-=` 等）在编译时展开为 `$a = BigInt::add($a, ...)`。

### 4. Std 强类型容器

AOT 编译器直接映射 C++ 标准库容器，提供零开销的类型安全存储。键类型仅支持 `native_types::type_int` 和 `complex_types::type_string`。

```php
declare(strict_types=1);
use native_types;

// std::vector — 动态数组
$v = std::vector(native_types::type_int);
$v[] = 10;
$v[] = 20;

// std::array — 定长数组（编译期边界检查）
$a = std::array(native_types::type_float, 5);
$a[0] = 3.14;

// std::map — 有序映射
$m = std::map(complex_types::type_string, native_types::type_int);
$m["key"] = 100;

// std::unordered_map — 哈希映射
$u = std::unordered_map(native_types::type_int, User::class);
$u[1] = new User(1);
```

#### 4.1 类型辅助类

| 辅助类 | 常量 | 值 | 用途 |
| --- | --- | --- | --- |
| `native_types` | `type_int` | `'int'` | 标注整型元素 |
| `native_types` | `type_float` | `'float'` | 标注浮点元素 |
| `native_types` | `type_bool` | `'bool'` | 标注布尔元素 |
| `native_types` | `type_bigint` | `'bigint'` | 标注 BigInt 元素 |
| `native_types` | `type_bigfloat` | `'bigfloat'` | 标注 BigFloat 元素 |
| `native_types` | `type_decimal` | `'decimal'` | 标注 Decimal 元素 |
| `complex_types` | `type_string` / `type_str` | `'string'` | 标注字符串元素 |
| `complex_types` | `type_array` | `'array'` | 标注数组元素 |
| `complex_types` | `type_object` | `'object'` | 标注对象元素 |
| `complex_types` | `type_any` / `type_var` | `'any'` | 标注动态类型元素 |
| `complex_types` | `type_stream` | `'stream'` | 标注 Stream 元素 |

容器值类型也可以是任意 PHP 类名（如 `User::class`），编译器会为每个具体类型生成独立的 C++ 模板实例。

### 5. 类型推断

编译器在编译期通过 `detectTypeOfExpr()` 对表达式进行类型推断：

#### 5.1 字面量

| 表达式 | 推断类型 |
| --- | --- |
| 整数字面量 `123` | `php::Int`（启用 `bigint_types` 时为 `php::BigInt`） |
| 浮点字面量 `3.14` | `php::Float`（启用 `decimal_types` 时为 `php::Decimal`） |
| 超长整数（≥19 位） | 自动识别为 `php::BigInt` |
| 高精度浮点（≥16 位有效数字） | 自动识别为 `php::Decimal` |
| 布尔字面量 `true` / `false` | `php::Bool` |
| 字符串字面量 `"hello"` | `php::Str`（启用 `native_types` 时） |
| 数组字面量 `[1, 2]` | `php::Array` |

#### 5.2 类型转换表达式

| 表达式 | 推断类型 |
| --- | --- |
| `(int) $x` | `php::Int` |
| `(float) $x` | `php::Float` |
| `(bool) $x` | `php::Bool` |
| `(string) $x` | `php::Str` |
| `(array) $x` | `php::Array` |
| `(object) $x` | `php::Object`（**无类信息**） |

#### 5.3 二元运算

编译器根据左右操作数类型按优先级确定结果类型：

1. 任一操作数为 `BigFloat` → 结果 `BigFloat`
2. 任一操作数为 `Decimal` → 结果 `Decimal`
3. 任一操作数为 `BigInt` → 结果 `BigInt`
4. 任一操作数为 `Float` → 结果 `Float`
5. 任一操作数为 `Int` → 结果 `Int`
6. 否则退化为 `Var`

> 除法例外：两个 `Int` 相除结果仍为 `Int`（整数除法），若除不尽则退化为 `Var`。

#### 5.4 函数返回值

- 内置函数（如 `fopen`、`stream_socket_client`）的返回类型由编译器内置规则确定
- 用户定义函数的返回类型从 `declare` 声明推断
- 动态调用（`call_user_func`、`eval` 定义的函数）退化为 `Var`

### 6. 类型转换

> `to*` 关键词方法是实现类型转换的主要途径，详细说明见 [类型转换](#类型转换) 文档。

#### 6.1 自动类型提升

Big* 类型与普通 Int/Float 混合运算时，编译器自动进行类型提升：

| 源类型 | 目标类型 | 提升方式 |
| --- | --- | --- |
| `Int` | `BigInt` | `php::newBigInt($n)` |
| `Float` | `Decimal` (字面量) | `php::newDecimal("...")` |
| `Float` | `Decimal` (变量) | **报错**——必须使用字符串构造 |
| `Float` | `BigInt` | **报错**——禁止转换 |
| `Float` | `BigFloat` | `php::newBigFloat($f)` |
| `Int` | `Decimal` | `php::newDecimal(php::toString($n))` |
| `Int` | `BigFloat` | `php::newBigFloat($n)` |
| `BigInt` | `Decimal` | `php::newDecimal(php::BigInt::toString($n))` |
| `BigInt` | `BigFloat` | `php::BigFloat::newInstance(php::BigInt::toString($n))` |
| `Decimal` | `BigFloat` | `php::BigFloat::newInstance(php::Decimal::toString($n))` |

#### 6.2 手动类型转换

编译器提供类型转换函数，也可在 ZendPHP 中通过 polyfills 兼容：

```php
// 通过 std:: 工厂构造 Big* 类型（推荐）
$a = std::bigInt("1234567890");      // string → BigInt
$b = std::decimal("0.01");           // string → Decimal
$c = std::bigFloat("1.23e100");      // string → BigFloat

// Big* → 普通类型的方法
$d = $a->toInt();                    // BigInt → Int（可能溢出）
$e = $b->toFloat();                  // Decimal → Float
$f = $c->toString();                 // BigFloat → String

// 跨 Big* 类型转换
$g = std::bigInt($a->toString());    // Decimal → String → BigInt
$h = std::decimal($a->toString());   // BigInt → String → Decimal
```

#### 6.3 类型接续（从 Var 恢复类型）

从数组取出元素或调用返回 `any` 类型的函数后，编译器丢失类型信息。可使用以下方式完成类型接续：

```php
// 对象类型接续
$user = objval($array['user'], User::class);
echo $user->greet();  // 编译器可生成 Native Call

// Stream 类型接续
$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$client = stream_cast($sockets[0]);
$client->write("hello");

// 基础类型转换语法
$v = (int) $array['count'];       // → php::Int
$v = (float) $array['price'];     // → php::Float
$v = (bool) $array['active'];     // → php::Bool
$v = (string) $array['name'];     // → php::Str
$v = (array) $array['items'];     // → php::Array

// 基础类型转换函数
$v = intval($array['count']);     // → php::Int
$v = floatval($array['price']);   // → php::Float
$v = boolval($array['active']);   // → php::Bool
$v = strval($array['name']);      // → php::Str
```

> **注意**：`(object)` 转换得到的是 `php::Object`，**不包含具体的类信息**，编译器无法对其方法进行 Native Call 优化。请始终使用 `objval()` 重建对象类型。

#### 6.4 类型丢弃

某些场景下，一个变量在不同条件分支中需要持有不同类型的值（如不同类的对象），此时编译器的静态类型推断会成为障碍——编译器根据第一次赋值推断类型，后续赋值不同类型会报错。使用 `any()` 可以将类型标注为 `php::Var`，放弃编译期类型跟踪，交由运行时动态处理。

```php
class Foo1 {
    public function run() {
        var_dump(__METHOD__);
    }
}

class Foo2 {
    public function run() {
        var_dump(__METHOD__);
    }
}

function main() {
    $rand = random_int(0, 10000);
    if ($rand % 2) {
        $o = any(new Foo1());
    } else {
        $o = any(new Foo2());
    }
    if (method_exists($o, 'run')) {
        $o->run();
    }
}
```

在此例中：
- 去掉 `any()`，编译器会将 `$o` 的类型锁定为 `Foo1`，`else` 分支中赋值 `Foo2` 对象会报类型冲突错误
- 加上 `any()` 后，`$o` 被标注为 `php::Var`，可接收任意类型的值，方法调用走动态分发

`any()` 在编译期被直接替换为表达式本身，**零运行时开销**。

#### 垫片函数

```php
function any(mixed $var): mixed
{
    return $var;
}
```

### 7. 类型限制

#### 7.1 跨 Big* 类型禁止隐式混合

编译器阻止可能导致精度损失的跨类型隐式混合：

```php
$a = std::bigInt("100");
$b = std::decimal("2.5");
$c = $a + $b;  // ❌ 编译错误：BigInt 和 Decimal 不能直接运算
```

解决方式：显式转换为同类型后再运算。

#### 7.2 Float 转 Decimal 仅限字面量

```php
$a = std::decimal("0.1");    // ✅ 推荐
$b = std::decimal(0.1);      // ✅ 字面量 —— 编译器提取原始文本 "0.1" 作为字符串处理
$pi = 3.14159;
$c = std::decimal($pi);      // ❌ 编译错误：无法从变量转换
```

> **特殊逻辑**：当 `std::decimal()` 的参数为浮点字面量时，编译器直接从 AST 提取 `rawValue` 作为字符串传递给构造函数，避免二进制浮点误差。但变量不保留原始文本，因此无法安全转换。

#### 7.3 Float 禁止转 BigInt

```php
$a = 3.14;
$b = std::bigInt($a);       // ❌ 编译错误：Cannot convert float to BigInt
$b = std::bigInt("3");      // ✅ 使用字符串
```

#### 7.4 Big* 类型不支持自增/自减

Big* 类型是不可变的，`++` / `--` 语义不匹配，编译器会报错：

```php
$a = std::bigInt("100");
$a++;  // ❌ 编译错误
$a--;  // ❌ 编译错误
```

#### 7.5 联合类型退化为 Var

PHP 的联合类型（`int|float`、`int|string` 等）在编译时退化为 `php::Var`，无法享受原生类型的性能优势。

#### 7.6 object 类型不保留类信息

`(object)` 转换和 `object` 类型声明只产生 `php::Object`，编译器不知道具体的类名，无法优化方法调用。必须使用 `objval()` 重建。

#### 7.7 Std 容器键类型限制

`std::map` 和 `std::unordered_map` 的键类型仅支持：
- `native_types::type_int` — 整数键
- `complex_types::type_string` / `complex_types::type_str` — 字符串键

其他键类型会导致编译错误。

#### 7.8 Std 容器不能在 foreach 中删除元素

当 std 容器处于 `foreach` 循环中且启用锁定时，不能对其元素执行 `unset` 操作。

#### 7.9 原生类型变量不能 unset

原生类型变量（`php::Int`、`php::Float` 等）不能使用 `unset()`，因为它们在 C++ 中是栈上值类型。

#### 7.10 关闭原生类型以启用特定行为

需要溢出检测、动态类型赋值等动态特性时，不应使用 `use native_types`，或使用 `any()` 将变量标注为 `php::Var`：

```php
$a = any(10);        // $a 的类型为 php::Var，保留溢出检测能力
$b = $a / 3;         // 浮点除法，结果为 3.333...
```

## 类型转换

`to*` 是 AOT 编译器提供的关键词方法（keyword method），用于将表达式或变量的值显式转换为目标类型。与普通通用方法不同，`to*` 方法在编译器中具有一等公民地位——无论 receiver 为何种类型，编译器都按照内置规则进行转换，跳过类型方法表查找。

所有 `to*` 方法调用在编译时完全消解为 C++ 函数调用，**零运行时开销**。

### 1. 基础类型转换

在任意表达式上调用，将结果转换为对应原生类型：

```php
declare(strict_types=1);
use native_types;

function convert_basic(mixed $input): void
{
    $i = $input->toInt();          // → php::Int    等价于 (int) $input
    $f = $input->toFloat();        // → php::Float  等价于 (float) $input
    $s = $input->toString();       // → php::Str    等价于 (string) $input
    $b = $input->toBool();         // → php::Bool   等价于 (bool) $input
    $a = $input->toArray();        // → php::Array  等价于 (array) $input
}
```

| 方法 | 目标类型 | 生成代码 | 说明 |
| --- | --- | --- | --- |
| `toInt()` | `php::Int` | `php::toInt($expr)` | 可能溢出 |
| `toFloat()` | `php::Float` | `php::toFloat($expr)` | |
| `toString()` | `php::Str` | `php::toString($expr)` | |
| `toBool()` | `php::Bool` | `php::toBool($expr)` | |
| `toArray()` | `php::Array` | `php::toArray($expr)` | |

#### 与 PHP 强制转换的区别

PHP 的 `(int)` / `(float)` 等强制转换语法虽然也支持，但 `to*` 方法有以下优势：

- **链式调用**：`$result->toString()->length()` 一步完成转换和操作，无需中间变量
- **类型推断**：编译器精确推断 `to*` 返回类型，后续方法调用可享受针对性优化
- **通用性**：`to*` 在 `mixed` 类型和 Big* 类型上均可使用

### 2. Stream 类型转换

`toStream()` 将表达式的值转换为 `php::Stream`，之后可直接调用 Stream 通用方法（如 `write`、`read`、`close`）。

```php
declare(strict_types=1);
use native_types;

function stream_example(): void
{
    // 从数组取出元素后接续 Stream 类型
    $pair = stream_socket_pair(AF_UNIX, SOCK_STREAM, 0);
    $pair[0]->toStream()->write("hello");   // → fwrite($pair[0], "hello")
    $data = $pair[1]->toStream()->read(5);  // → fread($pair[1], 5)
    var_dump($data);                        // string(5) "hello"
}
```

> **注意**：`toStream()` 不会自动关闭连接。使用完毕后应调用 `->close()` 释放资源。

### 3. 高精度数值类型转换

Big* 类型（BigInt / Decimal / BigFloat）定义了更精确的转换路径，编译器针对每种源类型使用专用的转换函数，避免精度损失。

#### 3.1 BigInt 上的转换

```php
declare(strict_types=1);
use native_types;

function bigint_convert(): void
{
    $b = std::bigInt("12345678901234567890");

    $i = $b->toInt();            // php::BigInt::toInt($b) — 可能溢出
    $f = $b->toFloat();          // php::BigInt::toFloat($b)
    $s = $b->toString();         // php::BigInt::toString($b)
    $d = $b->toDecimal();        // php::newDecimal(php::BigInt::toString($b))
    $bf = $b->toBigFloat();      // php::BigFloat::newInstance(php::BigInt::toString($b))
}
```

#### 3.2 Decimal 上的转换

```php
$d = std::decimal("123.456");

$i = $d->toInt();               // php::Decimal::toInt($d)
$f = $d->toFloat();             // php::Decimal::toFloat($d)
$s = $d->toString();            // php::Decimal::toString($d)
$b = $d->toBigInt();            // php::newBigInt(php::Decimal::toString($d))
$bf = $d->toBigFloat();         // php::BigFloat::newInstance(php::Decimal::toString($d))
```

#### 3.3 BigFloat 上的转换

```php
$bf = std::bigFloat("1.23e100");

$i = $bf->toInt();              // php::BigFloat::toInt($bf)
$f = $bf->toFloat();            // php::BigFloat::toFloat($bf)
$s = $bf->toString();           // php::BigFloat::toString($bf)
$b = $bf->toBigInt();           // php::newBigInt(php::BigFloat::toString($bf))
$d = $bf->toDecimal();          // php::newDecimal(php::BigFloat::toString($bf))
```

#### 3.4 跨 Big* 类型转换规则

Big* → String 始终通过各类型的 `toString()` 静态方法，避免二进制浮点误差：

| 源类型 | 目标类型 | 生成代码 |
| --- | --- | --- |
| BigInt | Decimal | `php::newDecimal(php::BigInt::toString($b))` |
| BigInt | BigFloat | `php::BigFloat::newInstance(php::BigInt::toString($b))` |
| Decimal | BigInt | `php::newBigInt(php::Decimal::toString($d))` |
| Decimal | BigFloat | `php::BigFloat::newInstance(php::Decimal::toString($d))` |
| BigFloat | BigInt | `php::newBigInt(php::BigFloat::toString($bf))` |
| BigFloat | Decimal | `php::newDecimal(php::BigFloat::toString($bf))` |

> 所有跨 Big* 转换都经过字符串中间态，确保十进制精度不丢失。

### 4. 对象类型转换

`toObject()` 将表达式的值转换为 `php::Object`。无参数时得到泛型对象（无具体类信息），传 `ClassName::class` 可重建带类信息的类型。

```php
declare(strict_types=1);
use native_types;

function object_convert(mixed $input): void
{
    // 无参数：泛型对象（无类信息）
    $obj = $input->toObject();

    // 带类名：编译器可优化后续方法调用
    $user = $input->toObject(User::class);
    echo $user->getName();   // Native Call
}
```

| 用法 | 生成代码 | 类型信息 |
| --- | --- | --- |
| `$x->toObject()` | `php::toObject($x)` | 无（`php::Object`） |
| `$x->toObject(User::class)` | `php::toObject($x, ce_User, true)` | 有（编译器知道具体类） |

> **提示**：编译期重建对象类型的功能与 `objval()` 等效，建议在需要链式调用时使用 `toObject(ClassName::class)`。

### 5. Std 容器类型转换

`toStd*` 方法将 `php::Var` 类型的变量转换为指定的 C++ 标准库容器类型。这类方法**必须在顶层作用域**调用，且**不能重复赋值**已声明的变量。

```php
declare(strict_types=1);
use native_types;

function std_convert(): void
{
    $data = get_data();  // 返回 mixed，内部持有 StdContainerBox

    // 从 mixed 恢复为 std::vector<int>
    $v = $data->toStdVector(native_types::type_int);
    $v[] = 42;
    echo $v[0];  // 42

    // 从 mixed 恢复为 std::map<string, int>
    $m = $data->toStdMap(complex_types::type_string, native_types::type_int);
    $m["key"] = 100;
}
```

| 方法 | 目标类型 | 键类型限制 | 说明 |
| --- | --- | --- | --- |
| `toStdArray(type, size)` | `php::StdArray<T, N>` | 索引为 `int` | 定长数组，大小在编译期确定 |
| `toStdVector(type)` | `php::StdVector<T>` | 索引为 `int` | 动态数组 |
| `toStdMap(ktype, vtype)` | `php::StdMap<K, T>` | `int` 或 `string` | 有序映射 |
| `toStdUnorderedMap(ktype, vtype)` | `php::StdUnorderedMap<K, T>` | `int` 或 `string` | 哈希映射 |

#### 使用限制

- **必须在顶层作用域**：`toStd*` 只能在函数体的最外层作用域调用，不能在 `if` / `for` 等嵌套块中调用
- **不能重复赋值**：被 `toStd*` 赋值的变量不能再被赋值为其他类型
- **源变量必须已存在**：`toStd*` 必须作用在一个已定义且有值的变量上

### 6. 类型推断

编译器对 `to*` 方法的返回类型有精确的内置推断规则。无论 receiver 是何种类型，检测到 `to*` 方法时直接返回对应目标类型：

```php
$val = get_any_value();
// 编译器推断：$val->toString() 返回 php::Str，length() 是 Str 上的通用方法
echo $val->toString()->length();
```

类型推断覆盖以下场景：
- **链式调用**：`$a->toBigInt()->mul(3)->toString()` — 编译器逐级推断 BigInt → BigInt → Str
- **条件分支**：`if` 两个分支中 `to*` 的返回类型在不同分支中独立推断
- **函数返回值**：`return $x->toString()` → 函数返回类型为 `php::Str`

### 7. void 类型限制

返回类型为 `void` 的表达式不能调用任何方法（包括 `to*`），编译器会在编译期报错：

```php
function bar(): void
{
    var_dump(__FUNCTION__);
}

// ❌ 编译错误：Cannot call method on void
bar()->toString();
bar()->toInt();
```

> 这是一项编译期安全检查，防止在无意义的 void 表达式上链式调用方法。

### 8. 与通用方法的关系

`to*` 方法是**语言关键词**，而非普通的通用方法：

| 特征 | `to*` 关键词方法 | 普通通用方法 |
| --- | --- | --- |
| 方法查找 | 直接匹配内置表 `TO_METHOD_TYPE_MAP` | 按类型查找 `UNIVERSAL_METHODS` 表 |
| receiver 类型 | 任意类型均可（包括 `mixed`） | 必须匹配已注册的类型 |
| 参数校验 | 编译期特殊处理 | 通用 `min_args` / `max_args` 校验 |
| 代码生成 | `genToConvertCall()` 专用逻辑 | 按 handler 类型分派 |

这种设计使 `to*` 方法在 `mixed` / `any` 类型上也能无歧义地工作，是实现类型接续（从动态类型恢复到静态类型）的核心机制。

### 9. 综合示例

```php
declare(strict_types=1);
use native_types;

function comprehensive_convert(): void
{
    // 链式转换 + 高精度运算
    $a = std::bigInt("100");
    $result = $a->toDecimal()
                ->mul(std::decimal("0.05"))
                ->toBigFloat()
                ->add(std::bigFloat("1.0"))
                ->toString();
    var_dump($result);  // 精确的十进制结果字符串

    // Stream 链式操作
    $pair = stream_socket_pair(AF_UNIX, SOCK_STREAM, 0);
    $pair[0]->toStream()->write("ping");
    $response = $pair[1]->toStream()->read(4);
    var_dump($response);  // string(4) "ping"

    // Std 容器提取
    $raw = get_container();                 // 返回 mixed
    $vec = $raw->toStdVector(native_types::type_int);
    $vec[] = 10;
    $vec[] = 20;
    echo $vec->count();                     // 2
}
```

## 高精度运算

`AOT` 编译器提供了三种高精度数值类型，可用于编写高精度、零开销的数值计算程序，例如科学计算、金融财务计算等场景。

1. **BigInt**（任意精度整数）
2. **Decimal**（任意精度十进制数）
3. **BigFloat**（任意精度浮点数）

### 1. 为什么需要高精度类型

PHP 的原生 `int` 是 64 位有符号整数，最大值为 `9223372036854775807`（约 9.22×10¹⁸）。超过这个范围的整数字面量会被 PHP 解析器静默转换为 `float`（double），丢失有效位数。

PHP 的原生 `float`（IEEE 754 double）最多只能保证约 15–16 位有效数字。对于金融计算、科学计算、密码学等场景，这远远不够。

```php
// PHP 原生行为的精度问题
$a = 123456789012345678901234567890;  // 30 位整数 → 被转为 float，精度丢失
// 实际存储：1.2345678901234568E+29，末尾数字已经不可靠

$b = 0.1 + 0.2;  // 0.30000000000000004 — 经典的浮点误差
```

AOT 编译器提供了三种高精度类型，底层基于成熟的 C/C++ 数学库，编译为本地机器码，**零运行时开销**：

| 类型 | 底层库 | 特点 |
| --- | --- | --- |
| BigInt | GMP (`libgmp`) | 任意精度整数，不会溢出 |
| Decimal | libmpdec | 十进制小数，约 50 位有效数字，无二进制浮点误差 |
| BigFloat | MPFR (`libmpfr`) | 任意精度浮点数，可调精度 |

### 2. 快速开始

使用高精度类型的前提条件：

1. 文件头部声明 `declare(strict_types=1)`
2. 导入原生类型声明 `use native_types`
3. 系统已安装对应的 C++ 库（`libgmp-dev`、`libmpdec-dev`、`libmpfr-dev`）

```php
<?php
declare(strict_types=1);
use native_types;

function main(): void {
    // 你的高精度计算代码
    $a = std::bigInt("123456789012345678901234567890");
    $b = std::bigInt("987654321098765432109876543210");
    $sum = $a + $b;
    echo $sum->toString();
}
?>
```

编译运行：

```bash
php bin/compiler.php my_program.php -o my_program
./my_program
```

> **提示**：和所有 native_types 一样，Big* 类型只能在 AOT 编译模式下使用，不能在普通 PHP 解释器中运行。AOT 编译器会对 `std::bigInt()` 等函数进行编译期求值，直接生成 C++ 代码。

### 3. 三种类型概览

#### BigInt — 任意精度整数

适用于大整数计算，不会溢出，不会丢失精度。整数除法结果为整数（截断）。

```php
$a = std::bigInt("1234567890123456789012345678901234567890");  // 40 位
$b = $a * 2;  // 80 位，不会溢出
```

#### Decimal — 任意精度十进制数

适用于金融计算等需要精确十进制表示的场景。`0.1 + 0.2` 精确等于 `0.3`，不存在二进制浮点误差。

```php
$price = std::decimal("19.99");
$quantity = 3;
$total = $price * $quantity;  // 59.97，精确
```

#### BigFloat — 任意精度浮点数

适用于科学计算等需要高精度浮点运算的场景。基于 MPFR，使用二进制浮点但精度远超 IEEE 754 double。

```php
$pi = std::bigFloat("3.141592653589793238462643383279502884197");
$area = $pi * 100 * 100;  // 高精度 π × r²
```

### 4. 构造与声明

#### 4.1 从字面量构造

`std::bigInt()`、`std::decimal()`、`std::bigFloat()` 是**编译期函数**，在生成的 C++ 代码中直接构造对应的 C++ 对象，不产生运行时函数调用。

```php
// BigInt — 从 int 或字符串构造
$a = std::bigInt(100);                                    // 普通整数
$b = std::bigInt("123456789012345678901234567890");       // 超长整数，必须用字符串

// Decimal — 建议从字符串构造以避免浮点精度丢失
$c = std::decimal("123.456");                             // ✅ 推荐：精确字符串
$d = std::decimal(42);                                    // ✅ 可行：从 int

// BigFloat — 从 int、float 或字符串构造
$e = std::bigFloat(100.5);                                // 从 float
$f = std::bigFloat(42);                                   // 从 int
$g = std::bigFloat("3.14159265358979323846");             // 从字符串（精确）
```

#### 4.2 类型标注

在 `use native_types` 下，Big* 类型变量自动获得原生 C++ 存储类型：

```php
use native_types;

// 编译器自动推断类型为 php::BigInt / php::Decimal / php::BigFloat
$a = std::bigInt(100);         // → C++: php::Variant(new BigInt(100))
$b = std::decimal("100.50");   // → C++: php::Variant(new Decimal("100.50"))
$c = std::bigFloat(3.14);      // → C++: php::Variant(new BigFloat(3.14))
```

> **关键细节**：Big* 类型是**不可变（immutable）**的。每次运算都返回新值，不会修改原变量。详见 [第 7 节：复合赋值](#7-复合赋值)。

### 5. 算术运算

#### 5.1 标准运算符

所有标准二元运算符都可以直接用于 Big* 类型：

```php
$a = std::bigInt(100);
$b = std::bigInt(200);

$sum  = $a + $b;    // 加法
$diff = $a - $b;    // 减法
$prod = $a * $b;    // 乘法
$quot = $a / $b;    // 除法（BigInt 为整数除法）
$mod  = $a % $b;    // 取模
$pow  = $a ** 10;   // 幂运算（BigInt 支持）

// 一元取负
$neg  = -$a;        // 取负
```

生成的 C++ 代码示例（`$a + $b`）：

```cpp
php::BigInt::add(a, b)      // BigInt 加法
php::BigInt::sub(a, b)      // BigInt 减法
php::BigInt::mul(a, b)      // BigInt 乘法
php::BigInt::div(a, b)      // BigInt 除法
php::BigInt::mod(a, b)      // BigInt 取模
php::BigInt::pow(a, b)      // BigInt 幂运算
```

#### 5.2 与 int / float 混合运算

Big* 类型可以自由地与普通 int 和 float 混合运算，编译器自动进行类型提升：

```php
$a = std::bigInt(100);

$b = $a + 50;       // BigInt + Int → BigInt
$c = 200 + $a;      // Int + BigInt → BigInt
$d = $a * 3.5;      // BigInt * Float → 编译错误！
                    // 浮点数无法精确提升为 BigInt，
                    // 应使用 Decimal 或 BigFloat
```

#### 5.3 BigInt 除法注意事项

`BigInt / BigInt` 是整数除法（截断），类似于 PHP 的 `intdiv()`：

```php
$a = std::bigInt(100);
$b = $a / 3;  // 33（不是 33.333...）
```

如果需要精确的小数结果，先将操作数转换为 Decimal：

```php
$a = std::bigInt(100);
$result = std::decimal($a->toString()) / std::decimal("3");
// 33.333333333...
```

#### 5.4 各类型支持的运算符汇总

| 运算符 | BigInt | Decimal | BigFloat |
| --- | --- | --- | --- |
| `+` `-` `*` | ✅ | ✅ | ✅ |
| `/` | ✅ 整数除法 | ✅ | ✅ |
| `%` | ✅ | ✅ | ❌ |
| `**` | ✅ | ❌ | ❌ |
| `-` (一元取负) | ✅ | ✅ | ✅ |
| `&` `|` `^` `~` | ✅ | ❌ | ❌ |
| `<<` `>>` | ✅ | ❌ | ❌ |
| `&=` `|=` `^=` | ✅ | ❌ | ❌ |
| `<<=` `>>=` | ✅ | ❌ | ❌ |

### 6. 比较运算

所有六种比较运算符均可用于 Big* 类型：

```php
$a = std::bigInt(100);
$b = 200;

// 比较运算返回 bool（需要 (int) 转换输出）
echo (int)($a < $b);     // 1 (true)   → cmp(a,b) < 0
echo (int)($a > $b);     // 0 (false)  → cmp(a,b) > 0
echo (int)($a <= 100);   // 1 (true)   → cmp(a,b) <= 0
echo (int)($a >= 100);   // 1 (true)   → cmp(a,b) >= 0
echo (int)($a == 100);   // 1 (true)   → cmp(a,b) == 0
echo (int)($a != 50);    // 1 (true)   → cmp(a,b) != 0

// 太空船运算符
$cmp = $a <=> $b;        // -1 ($a < $b)
echo (int)$cmp;          // -1
```

生成的 C++ 代码示例：

```cpp
php::BigInt::cmp(a, b) < 0    // a < b
php::BigInt::cmp(a, b) == 0   // a == b
php::BigInt::cmp(a, b) != 0   // a != b
php::BigInt::cmp(a, b)        // a <=> b（直接返回 -1/0/1）
```

### 7. 复合赋值

Big* 类型**支持** `+=`、`-=`、`*=`、`/=`、`%=` 等复合赋值运算符。

#### 7.1 工作方式

Big* 类型是**不可变的**（immutable）。`$a += 50` 在编译时被展开为 `$a = BigInt::add($a, 50)`——创建一个新值然后赋值给原变量。

```php
$a = std::bigInt(100);
$a += 50;          // → a = php::BigInt::add(a, php::newBigInt(50))
echo $a->toString();  // "150"

$a -= 30;          // → a = php::BigInt::sub(a, php::newBigInt(30))
$a *= 5;           // → a = php::BigInt::mul(a, php::newBigInt(5))
$a /= 3;           // → a = php::BigInt::div(a, php::newBigInt(3))
$a %= 7;           // → a = php::BigInt::mod(a, php::newBigInt(7))
```

Decimal 和 BigFloat 同样支持：

```php
// Decimal 复合赋值
$d = std::decimal("100.50");
$d += 25.25;       // → d = php::Decimal::add(d, php::newDecimal("25.25"))
$d -= 123.45;      // → d = php::Decimal::sub(d, php::newDecimal("123.45"))
$d *= 2;           // → d = php::Decimal::mul(d, php::newDecimal(2))
$d /= 4;           // → d = php::Decimal::div(d, php::newDecimal(4))
$d %= 5.0;         // → d = php::Decimal::mod(d, php::newDecimal("5.0"))

// BigFloat 复合赋值（不支持 %=）
$bf = std::bigFloat(100.0);
$bf += 50.0;
$bf -= 30.0;
$bf *= 2.0;
$bf /= 3.0;
```

#### 7.2 `++` / `--` 不可用

由于 Big* 类型是不可变的，`++` / `--` 运算符在语义上不匹配。编译器会给出清晰的错误提示：

```php
$a = std::bigInt(100);
$a++;  // ❌ 编译错误：Cannot use ++ on php::BigInt. Use += 1 instead.
++$a;  // ❌ 编译错误：Cannot use ++ on php::BigInt. Use += 1 instead.
--$a;  // ❌ 编译错误：Cannot use -- on php::BigInt. Use -= 1 instead.
```

正确的替代写法：

```php
$a += 1;   // ✅ 代替 $a++
$a -= 1;   // ✅ 代替 $a--
```

### 8. 通用方法调用

Big* 类型支持通过 `$value->method()` 语法（通用方法/Universal Methods）调用方法。这些调用在编译时被翻译为对应的 C++ 静态函数，**零运行时开销**。

#### 8.1 BigInt 方法

```php
$a = std::bigInt("12345678901234567890");

// 算术方法（均返回新的 BigInt）
$b = $a->add(1);        // 加法：$a + 1
$c = $a->sub(1);        // 减法：$a - 1
$d = $a->mul(2);        // 乘法：$a * 2
$e = $a->div(10);       // 除法：$a / 10
$f = $a->mod(1000000);  // 取模：$a % 1000000
$g = $a->pow(3);        // 幂运算：$a ** 3

// 一元方法
$h = $a->neg();         // 取负：-$a
$i = $a->abs();         // 绝对值

// 特殊方法
$j = $a->gcd(15);       // 最大公约数：gcd($a, 15)
$k = $a->divmod(3);     // 商和余数：返回 [$quotient, $remainder]
$l = $a->powmod(5, 97); // 模幂：($a ** 5) % 97
$m = $a->sqrt();        // 平方根（截断取整）

// 位运算方法
$n = $a->bitAnd(0xFF);       // 按位与：$a & 0xFF
$o = $a->bitOr(0xFF);        // 按位或：$a | 0xFF
$p = $a->bitXor(0xFF);       // 按位异或：$a ^ 0xFF
$q = $a->bitNot();           // 按位取反：~$a
$r = $a->testBit(3);         // 测试第 3 位是否为 1
$s = $a->popCount();         // 二进制中 1 的个数
$t = $a->bitShiftLeft(3);    // 左移：$a << 3
$u = $a->bitShiftRight(2);   // 右移：$a >> 2

// 比较方法
$cmp = $a->cmp(100);    // 比较：返回 -1/0/1
if ($a->cmp(100) > 0) { /* $a > 100 */ }

// 类型转换方法
echo $a->toString();    // 转字符串："12345678901234567890"
echo $a->toInt();       // 转 int（可能截断）
echo $a->toFloat();     // 转 float（可能丢精度）
```

#### 8.2 Decimal 方法

```php
$d = std::decimal("123.456");

// 算术方法
echo $d->add(std::decimal("50.25"))->toString();  // "173.706"
echo $d->sub(std::decimal("50.25"))->toString();  // "73.206"
echo $d->mul(2)->toString();                      // "246.912"
echo $d->div(3)->toString();                      // "41.152"
echo $d->mod(std::decimal("5.0"))->toString();    // "3.456"
echo $d->pow(2)->toString();                      // "15241.543936"

// 特殊方法
$q = $d->divmod(std::decimal("10"));  // 商和余数：[$q, $r]
echo $d->powmod(3, std::decimal("100"))->toString();  // 模幂：($d**3) % 100
echo $d->sqrt()->toString();          // 平方根
echo $d->floor()->toString();         // 向下取整
echo $d->ceil()->toString();          // 向上取整
echo $d->round()->toString();         // 四舍五入到整数
echo $d->round(2)->toString();        // 保留 2 位小数

// 一元方法
echo $d->neg()->toString();   // "-123.456"
echo $d->abs()->toString();   // "123.456"

// 比较与转换
echo $d->cmp(std::decimal("100")) > 0 ? "greater" : "less";  // "greater"
echo $d->toInt();             // 123
echo $d->toFloat();           // 123.456
echo $d->toString();          // "123.456"
```

#### 8.3 BigFloat 方法

```php
$bf = std::bigFloat(3.14159265);

echo $bf->add(1.0)->toString();   // "4.14159265..."
echo $bf->mul(2.0)->toString();   // "6.2831853..."
echo $bf->div(2.0)->toFloat();    // 1.570796325
echo $bf->neg()->toString();      // "-3.14159265..."
echo $bf->abs()->toString();      // "3.14159265..."

// 比较
echo $bf->cmp(3.0);               // > 0（$bf > 3.0）
```

#### 8.4 通用方法 vs 运算符

运算符和方法调用在功能上等价，选择哪种取决于代码风格：

```php
$a = std::bigInt(100);
$b = std::bigInt(50);

// 两种等价写法
$result1 = $a + $b;             // 运算符风格
$result2 = $a->add($b);         // 方法调用风格

// 方法调用支持链式调用
$result3 = $a->add(10)->mul(2)->sub(5)->toString();  // "215"
```

### 9. 类型转换

#### 9.1 Big* 之间的转换

```php
// BigInt → Decimal（精确，推荐方式）
$big = std::bigInt("12345678901234567890");
$dec = std::decimal($big->toString());

// Decimal → BigInt（截断小数部分）
$d = std::decimal("123.456");
$i = std::bigInt($d->toInt());  // 123

// Int → BigInt / Decimal / BigFloat
$bi = std::bigInt(42);
$dc = std::decimal(42);
$bf = std::bigFloat(42);

// Float → BigFloat（Float → Decimal 不推荐直接使用 float 字面量）
$bf2 = std::bigFloat(3.14);

// 任意类型 → BigFloat
$bf3 = std::bigFloat($big->toString());
```

#### 9.2 Big* 与普通类型的转换

```php
// BigInt → 普通类型
$a = std::bigInt("99999999999999999999");
$s = $a->toString();  // "99999999999999999999"
$i = $a->toInt();     // PHP_INT_MAX（超出范围时截断）
$f = $a->toFloat();   // 1.0E+20（可能丢失精度）

// 普通类型 → BigInt（通过编译期函数）
$b = std::bigInt(42);           // int → BigInt
$c = std::bigInt("123456...");  // string → BigInt
```

#### 9.3 跨类型隐式混合的限制

编译器会阻止可能导致精度损失的跨类型隐式混合运算：

```php
$a = std::bigFloat(100.5);
$b = std::bigInt(200);

$c = $a + $b;  // ❌ 编译错误：Cannot mix BigFloat and BigInt implicitly.
               //    Use std::bigFloat() to convert explicitly.

// 正确的做法：显式转换
$c = $a + std::bigFloat($b->toString());  // ✅
```

| 组合 | 是否允许 | 说明 |
| --- | --- | --- |
| BigInt + BigFloat | ❌ 编译错误 | 精密度量不同，需显式转换 |
| BigInt + Decimal | ❌ 编译错误 | 精密度量不同，需显式转换 |
| BigFloat + Decimal | ❌ 编译错误 | 精密度量不同，需显式转换 |
| BigInt + Int | ✅ 自动提升 Int → BigInt | 无精度损失 |
| BigInt + Float | ❌ 编译错误 | Float 无法精确提升为 BigInt |
| Decimal + Int | ✅ 自动提升 Int → Decimal | 无精度损失 |
| Decimal + Float | ✅ 自动提升 Float → Decimal | 可能有微小误差 |
| BigFloat + Int | ✅ 自动提升 Int → BigFloat | 无精度损失 |
| BigFloat + Float | ✅ 自动提升 Float → BigFloat | 无精度损失 |

### 10. 混合运算与类型提升

当 Big* 类型与普通 Int/Float 混合运算时，编译器按优先级确定运算类型：

```
BigFloat  >  Decimal  >  BigInt  >  Float  >  Int
```

**规则**：
1. 若任一操作数是 Var（非原生类型），则全部转为 Var，使用 ZendVM 运行时运算
2. 若两操作数均为 Int/Float，则 Float 优先（Int → Float）
3. 若任一操作数为 Big* 类型，则另一操作数自动提升为同类型（Int → BigInt 等）

```php
// 类型提升示例
$a = std::bigInt(100);
$b = 50;                // Int

$c = $a + $b;           // BigInt + Int → BigInt
                        // $b 自动提升为 BigInt

$d = std::decimal("10.5");
$e = $d + 3;            // Decimal + Int → Decimal
                        // 3 自动提升为 Decimal

$f = std::bigFloat(1.5);
$g = $f + 2.0;          // BigFloat + Float → BigFloat
                        // 2.0 自动提升为 BigFloat
```

### 11. 超长字面量自动识别

AOT 编译器会自动检测超出原生类型精度的数值字面量，并自动转为对应的 Big* 类型。你**不需要手动包装**。

```php
// 19 位以上整数 → 自动转为 BigInt
$a = 12345678901234567890;
echo $a->toString();  // "12345678901234567890"
// 编译器自动处理：等同于 std::bigInt("12345678901234567890")

// 16 位有效数字以上的小数 → 自动转为 Decimal
$b = 3.14159265358979323846;
// 编译器自动处理：等同于 std::decimal("3.14159265358979323846")
```

**识别规则**：
- 纯数字，19 位及以上 → BigInt
- 含小数点或指数，16 位有效数字以上 → Decimal
- 禁用下划线 `_`（如 `1_234_567_890_123_456_789_0`）

> **推荐做法**：对于关键精度，仍然建议显式使用 `std::bigInt("...")` 或 `std::decimal("...")`，确保意图明确。自动识别是一个便利特性，适用于快速原型开发。

### 12. 限制与注意事项

#### 12.1 不可变性

所有 Big* 类型是**不可变的**。每次运算创建新值：

```php
$a = std::bigInt(100);
$b = $a->add(50);    // $a 依然是 100，$b 是 150
$c = $a + 50;        // $a 依然是 100，$c 是 150
```

#### 12.2 `++` / `--` 不支持

参见 [第 7.2 节](#72----不可用)。使用 `+= 1` / `-= 1` 代替。

#### 12.3 BigFloat 不支持 `%` 和 `**`

```php
$bf = std::bigFloat(10.0);
$bf %= 3;   // ❌ 编译错误
$bf ** 2;   // ❌ 编译错误
```

#### 12.4 Decimal 不支持 `**`

```php
$d = std::decimal("10.5");
$d ** 2;    // ❌ 编译错误
```

#### 12.5 跨 Big* 类型不能隐式混合

BigFloat、Decimal、BigInt 之间必须显式转换：

```php
$a = std::bigFloat(100.5);
$b = std::bigInt(200);
$c = $a + $b;  // ❌ 编译错误
// 改为
$c = $a + std::bigFloat($b->toString());  // ✅
```

#### 12.6 不能在普通 PHP 解释器中运行

Big* 类型是 AOT 编译器的专有特性，依赖编译期代码生成和 C++ 底层库。源码不能被 `php` 命令直接解释执行。

#### 12.7 启用 `use native_types`

忘记添加 `use native_types` 会导致 Big* 变量被当作 Var（通用类型），失去原生类型的大部分性能优势。

### 13. 完整示例

#### 13.1 大整数阶乘

```php
<?php
declare(strict_types=1);
use native_types;

/**
 * 计算 n 的阶乘，支持任意大的结果
 */
function factorial(int $n): void {
    $result = std::bigInt(1);
    for ($i = 2; $i <= $n; $i++) {
        $result *= $i;
    }
    echo "{$n}! = " . $result->toString() . "\n";
    echo "位数: " . strlen($result->toString()) . "\n";
}

function main(): void {
    factorial(10);   // 10! = 3628800
    factorial(50);   // 3041409320171337804361260816606476884...
    factorial(100);  // 933262154439441526816992388562667004...
}
?>
```

#### 13.2 金融计算：订单明细

```php
<?php
declare(strict_types=1);
use native_types;

function main(): void {
    // 使用 Decimal 精确表示金额
    $price = std::decimal("19.99");
    $quantity = 3;
    $taxRate = std::decimal("0.08");

    $subtotal = $price * $quantity;
    $tax = $subtotal * $taxRate;
    $total = $subtotal + $tax;

    echo "单价: " . $price->toString() . "\n";
    echo "数量: {$quantity}\n";
    echo "小计: " . $subtotal->toString() . "\n";
    echo "税额: " . $tax->toString() . "\n";
    echo "总计: " . $total->toString() . "\n";
}
?>
```

输出：

```
单价: 19.99
数量: 3
小计: 59.97
税额: 4.7976
总计: 64.7676
```

#### 13.3 高精度圆周率计算

```php
<?php
declare(strict_types=1);
use native_types;

function main(): void {
    // 使用 BigFloat 进行高精度数学运算
    $pi = std::bigFloat("3.141592653589793238462643383279502884197");
    $radius = 100;

    // 圆面积
    $area = $pi * std::bigFloat($radius * $radius);
    echo "圆面积: " . $area->toString() . "\n";

    // 圆周长
    $circumference = $pi * std::bigFloat(2 * $radius);
    echo "圆周长: " . $circumference->toString() . "\n";

    // 比较
    $earthRadius = 6371;
    $earthArea = $pi * std::bigFloat($earthRadius * $earthRadius);
    echo "如果半径是 {$earthRadius}km...\n";
    echo "面积约: " . $earthArea->toInt() . " km²\n";
}
?>
```

#### 13.4 综合示例：多种类型混合

```php
<?php
declare(strict_types=1);
use native_types;

function main(): void {
    // BigInt — 大整数运算
    $big = std::bigInt("100000000000000000000");
    $big += std::bigInt("99999999999999999999");
    echo "BigInt: " . $big->toString() . "\n";

    // 运算符 + 比较
    $a = std::bigInt(100);
    echo "BigInt + Int: " . ($a + 50)->toString() . "\n";
    echo "BigInt * 5: " . ($a * 5)->toString() . "\n";
    echo "BigInt > 50: " . (int)($a > 50) . "\n";
    echo "a == 100: " . (int)($a == 100) . "\n";

    // Unary minus
    $neg = -$a;
    echo "-a: " . $neg->toString() . "\n";

    // Decimal — 精确十进制运算
    $price = std::decimal("99.99");
    $price *= 3;   // 复合赋值
    echo "价格 × 3: " . $price->toString() . "\n";

    // 比较
    $d = std::decimal("100.25");
    echo "d > 50: " . (int)($d > 50) . "\n";
    echo "d != 100: " . (int)($d != 100) . "\n";

    // BigFloat — 高精度浮点
    $bf = std::bigFloat(3.14159);
    $bf *= 2.0;
    echo "pi × 2: " . $bf->toString() . "\n";

    // 方法链式调用
    $result = std::bigInt(100)
        ->add(50)
        ->mul(3)
        ->sub(100)
        ->toString();
    echo "100 + 50 × 3 - 100 = " . $result . "\n";
}
?>
```

输出：

```
BigInt: 200000000000000000099
BigInt + Int: 150
BigInt * 5: 500
BigInt > 50: 1
a == 100: 1
-a: -100
价格 × 3: 299.97
d > 50: 1
d != 100: 1
pi × 2: 6.2831800000000000
100 + 50 × 3 - 100 = 350
```

## Box 对象封装

### php::Box — C++ 对象封装机制

`php::Box` 是 PHPX 运行时提供的基础类，允许将 C++ 对象封装为 PHP 资源（Resource），在 PHP 与 C++ 之间安全地传递和操作原生 C++ 对象。它是实现 C++ 互操作的重要基础——编译器的 `BigInt`、`Decimal`、`BigFloat` 类型均基于 `Box` 实现。

#### 为什么需要 Box

PHP 调用 C++ 函数时，普通的 PHP 类型（`php::Int`、`php::Str`、`php::Array`）可以直接作为参数和返回值传递。但当你需要在 C++ 中维护一个**有状态的、生命周期需要跨越多次调用的对象**时，就需要一种机制将 C++ 对象的指针安全地保存在 PHP 变量中——这就是 `Box` 的作用。

典型场景：
- 游戏状态对象（如俄罗斯方块的棋盘、分数）
- 数据库连接句柄
- GPU 渲染上下文
- 任何需要在多次 PHP→C++ 调用之间保持状态的复杂 C++ 对象

#### Box 的底层机制

```mermaid
flowchart-v2
    subgraph PHP层
        P1["$game: mixed<br>（不可见的资源 ID）"]
    end

    subgraph C++层
        C1["zend_resource<br>type: Box 资源类型<br>ptr: TetrisBox*"]
        C2["TetrisBox 实例<br>（堆上）"]
    end

    P1 --> C1 --> C2
```

- **创建**：`return {new MyBox()}` 调用 `Variant(Box*)` 构造函数，将 C++ 指针注册为 Zend 资源，分配资源 ID
- **传递**：PHP 层持有的 `mixed` 变量内部是一个 `zend_resource`，指针指向 C++ 堆对象
- **提取**：`box.toBox<MyBox>()` 验证资源类型后，将 `res->ptr` 安全地 static_cast 回 C++ 类型
- **销毁**：PHP GC 回收 `zend_resource` 时自动调用 `Box::destroy()` → `delete this`

源代码参考：
- `Box` 基类定义：`phpx.h:1724`
- `Variant(Box*)` 构造函数：`phpx.h:661`
- `toBox<T>()` 模板方法：`phpx.h:876`

#### 使用三步曲

##### 1. 定义类 — 继承自 `Box`

```cpp
#include <phpx.h>
using namespace php;

class TetrisBox : public Box {
public:
    int board[20][10];
    int score;
    bool gameOver;

    TetrisBox() : score(0), gameOver(false) {
        memset(board, 0, sizeof(board));
    }

    void reset() {
        score = 0;
        gameOver = false;
        memset(board, 0, sizeof(board));
    }
};
```

关键点：
- **必须继承** `public Box`
- 基类提供 `type_info`、`extra_info` 两个 `uint32_t` 字段用于可选元数据
- 析构函数为 `virtual ~Box()`，确保子类正确析构

##### 2. 创建并返回 — `{new MyBox()}` 语法

```cpp
var php_tetris_new() {
    return {new TetrisBox()};   // ✅ 花括号初始化 Variant
}
```

> **语法解释**：`return {new TetrisBox()}` 等价于 `return Variant(new TetrisBox())`，使用 C++ 大括号初始化语法触发 `Variant(Box*)` 构造函数，将裸指针注册为 Zend 资源。

常见错误：

```cpp
// ❌ 缺少花括号——new 返回裸指针，不会触发 Variant(Box*) 构造函数
var php_tetris_new() {
    return new TetrisBox();
}

// ❌ 手动 Var()-包装——走的是 Void* 路径，不会注册为 Box 资源
var php_tetris_new() {
    auto* state = new TetrisBox();
    return var(state);
}
```

##### 3. 提取使用 — `toBox<T>()`

```cpp
Int php_tetris_get_score(var box) {
    auto tetris = box.toBox<TetrisBox>();   // ✅ 类型安全转换
    return tetris->score;
}

void php_tetris_reset(var box) {
    auto tetris = box.toBox<TetrisBox>();
    tetris->reset();
}
```

`toBox<T>()` 内部执行两步校验：
1. 检查 Variant 是否持有 Resource 类型
2. 检查资源的 `type` 是否为 Box 资源 ID

任一校验失败，抛出 PHP 异常。

常见错误：

```cpp
// ❌ 直接 ptr() + C 风格强转——不验证资源类型，不安全
void php_tetris_reset(var box) {
    auto* state = (TetrisBox*)box.ptr();
    state->reset();
}
```

#### Stub 文件声明

Box 对象在 stub 文件中使用 **`mixed`** 类型声明（对应 C++ 的 `var`/`Variant`）：

```php
<?php

// 返回 Box 对象的函数
function tetris_new(): mixed {}

// 接收 Box 对象的函数
function tetris_reset(mixed $game): void {}
function tetris_get_score(mixed $game): int {}
function tetris_is_game_over(mixed $game): bool {}
```

> **重要**：不能使用 `object` 或其他具体类型。`mixed` 是唯一正确的 stub 类型，因为 Box 在 PHP 层表现为 Resource 类型。

#### PHP 层使用

```php
declare(strict_types=1);
use native_types;

class TetrisGame
{
    private mixed $game;

    public function __construct()
    {
        $this->game = tetris_new();
    }

    public function getScore(): int
    {
        return tetris_get_score($this->game);
    }

    public function reset(): void
    {
        tetris_reset($this->game);
    }
}

function main(): void {
    $game = new TetrisGame();
    echo "初始分数: " . $game->getScore() . "\n";
    // 游戏逻辑...
    $game->reset();
}
```

#### 类型映射规则

| C++ 类型 | Stub 声明 | PHP 声明 | 说明 |
| --- | --- | --- | --- |
| `var` | `mixed` | `mixed` | Box 对象或任意 Variant 值 |
| `Variant` | `mixed` | `mixed` | 同上（`var` 是 `Variant` 的别名） |
| `Int` | `int` | `int` | 整数 |
| `Bool` | `bool` | `bool` | 布尔值 |
| `Str` / `String` | `string` | `string` | 字符串 |
| `Array` | `array` | `array` | 数组 |
| `Float` | `float` | `float` | 浮点数 |
| `void` | `void` | `void` | 无返回值 |

#### Box 的生命周期

```mermaid
sequence
    participant PHP as PHP层
    participant VM as ZendVM
    participant CXX as C++层
    participant HEAP as 堆内存

    PHP->>CXX: tetris_new()
    CXX->>HEAP: new TetrisBox()
    CXX->>VM: zend_register_resource(box)
    VM-->>PHP: 返回 Variant（资源 ID）
    Note over PHP,VM: $game 持有资源引用
    PHP->>CXX: tetris_get_score($game)
    CXX->>CXX: box.toBox<TetrisBox>()
    CXX->>HEAP: 访问 tetris->score
    CXX-->>PHP: 返回 Int 值
    Note over PHP,VM: unset($game) 或请求结束
    PHP->>VM: GC 回收 zend_resource
    VM->>CXX: Box::destroy()
    CXX->>HEAP: delete this
    Note over HEAP: 对象析构，内存释放
```

关键点：
- Box 对象在堆上分配，生命周期由 PHP 的引用计数/GC 管理
- PHP 变量被 `unset` 或超出作用域时，`Box::destroy()` 自动调用 `delete this`
- 不需要手动 `delete`，避免了悬空指针和内存泄漏

#### 完整示例：俄罗斯方块

以下是从 `examples/tetris-sdl` 和 `examples/tetris-win32` 中提取的 Box 使用模式。

##### C++ 层（tetris.cc）

```cpp
#include <phpx.h>
#include <cstring>

using namespace php;

// 1. 定义 Box 子类
class TetrisBox : public Box {
public:
    int board[20][10];
    int score;
    bool gameOver;

    TetrisBox() : score(0), gameOver(false) {
        memset(board, 0, sizeof(board));
    }

    void reset() {
        score = 0;
        gameOver = false;
        memset(board, 0, sizeof(board));
    }
};

// 2. 创建 Box 并返回
var php_tetris_new() {
    return {new TetrisBox()};
}

// 3. 从 Variant 提取 Box 操作
void php_tetris_reset(var box) {
    auto tetris = box.toBox<TetrisBox>();
    tetris->reset();
}

Int php_tetris_get_score(var box) {
    auto tetris = box.toBox<TetrisBox>();
    return tetris->score;
}

Bool php_tetris_is_game_over(var box) {
    auto tetris = box.toBox<TetrisBox>();
    return tetris->gameOver;
}
```

##### Stub 文件（tetris.stub.php）

```php
<?php

function tetris_new(): mixed {}
function tetris_reset(mixed $game): void {}
function tetris_get_score(mixed $game): int {}
function tetris_is_game_over(mixed $game): bool {}
```

##### project.yml

```yaml
name: tetris
build-mode: bin
sources:
  - main.php
  - php-src/
  - cpp-src/
```

##### 编译运行

```shell
php bin/compiler.php examples/tetris-sdl/project.yml -O2 -o tetris
./tetris
```

#### 可选：空指针安全检查

```cpp
void php_tetris_reset(var box) {
    if (!box.isResource()) {
        throw Exception("Invalid game object");
    }
    auto tetris = box.toBox<TetrisBox>();
    tetris->reset();
}
```

#### 常见错误

**错误 1：忘记继承 Box**

```cpp
// ❌ 缺少继承
class TetrisBox {
    int score;
};

// ✅ 正确
class TetrisBox : public Box {
    int score;
};
```

**错误 2：返回语法错误**

```cpp
// ❌ 少花括号
var php_tetris_new() {
    return new TetrisBox();
}

// ✅ 正确
var php_tetris_new() {
    return {new TetrisBox()};
}
```

**错误 3：Stub 类型错误**

```php
// ❌ 不能用 object
function tetris_new(): object {}
function tetris_reset(object $game): void {}

// ✅ 正确
function tetris_new(): mixed {}
function tetris_reset(mixed $game): void {}
```

**错误 4：使用 ptr() 替代 toBox()**

```cpp
// ❌ 不安全
void php_tetris_reset(var box) {
    auto* tetris = (TetrisBox*)box.ptr();
    tetris->reset();
}

// ✅ 类型安全
void php_tetris_reset(var box) {
    auto tetris = box.toBox<TetrisBox>();
    tetris->reset();
}
```

#### 原理：Box 的内部实现

`Box` 本身是极简的基类：

```cpp
class Box {
protected:
    uint32_t type_info = 0;   // 子类可自定义类型标记
    uint32_t extra_info = 0;  // 子类可自定义附加信息
    virtual ~Box() = default; // 虚析构保证子类正确析构
};
```

`Variant(Box*)` 构造函数将 Box 指针转为 Zend 资源：

```cpp
Variant(Box *v) {
    zend_resource *res = zend_register_resource(v, getBoxResourceId());
    ZVAL_RES(&val, res);
}
```

`toBox<T>()` 模板方法安全提取：

```cpp
template <class T>
T *toBox() {
    if (UNEXPECTED(!isResource())) {
        throwError("This variant is not a resource type.");
        return nullptr;
    }
    auto res = Z_RES_P(unwrap_ptr());
    if (UNEXPECTED(res->type != getBoxResourceId())) {
        throwError("This resource is not type of `%s`.", box_res_name);
        return nullptr;
    }
    return static_cast<T *>(res->ptr);
}
```

> 编译器内置的 `BigInt`、`Decimal`、`BigFloat` 类型均继承自 `Box`，使用完全相同的资源封装机制。用户自定义的 Box 子类与内置类型在 PHP 层表现一致——都是持有 `zend_resource` 的 `mixed` 变量。

## C++ 互操作性

### 在 PHP 代码中调用 C++ 函数

`C++`函数需满足以下条件，就可以在`PHP`代码调用：

1. 必须以 `php_` 为前缀
2. 必须以 `PHP` 类型作为参数和返回值
3. 必须在 `.stub.php` 文件中声明该函数

> 在`C++`中函数名称必须为小写，在`PHP`代码中函数名称是大小写不敏感的

#### PHP 与 C++ 类型映射表

| PHP 类型 | C++ 类型 |
| --- | --- |
| int | `php::Int` |
| bool | `php::Bool` |
| array | `php::Array` |
| mixed | `php::Var` |
| float | `php::Float` |
| string | `php::Str` |
| object | `php::Object` |
| resource | `php::Resource` |
| void | `void` |

例如在`.stub.php`文件中声明一个函数：

```php
function bar(int $a, bool $b, float $c, string $d, array $e, object $f, mixed $g): array {
   // stub 仅声明，函数没有实现代码
}
```

则在 `C++` 中对应的函数为:

```cpp
php::Array php_bar(php::Int a, php::Bool b, php::Float c, php::String d, php::Array e, php::Object f, php::Var g) {
   // 在 C++ 代码中实现此函数
}
```

可使用`using namespace php` 简化为：

```cpp
using namespace php;

Array php_bar(Int a, Bool b, Float $c, String d, Array e, Object f, Var g) {
   // 在 C++ 代码中实现此函数
}
```

> 注意在`C++`代码中函数名称必须添加`php_`前缀

在其他 `PHP` 代码中调用此函数：

```php
$arr = bar(1, false, 3.14, "hello", [1, 2, 3,], new stdClass, null);
```

### 在 C++ 代码中调用 PHP 函数

1. 函数名称必须添加 `php_` 前缀
2. 必须包含 `php_func_decl.h` 头文件
3. 仅限于被`AOT`编译器编译后的函数，若是动态函数、内置函数，无法直接调用

例如在`PHP`中定义了一个函数：

```php
function my_func($a, $b, $c): mixed {
    var_dump($a, $b, $c);
    return [$a, $b, $c];
}
```

`C++`中调用的方式为：

```cpp
php::Array list = php_my_func("hello", 1234, php::null);
```

#### 调用内置函数

使用`PHPX`提供的`Facade API`

```cpp
php::var_dump(v1);
php::file_get_contents(file);
```

#### 调用用户函数

用户函数必须通过`ZendVM`动态调用。

```cpp
php::call("my_user_func", {a, b, c});
```

### 命名空间

若函数使用了命名空间，则需要修改为 `php_{命名空间}__{函数名称}` ，命名空间为多层，则需要将斜杠`\\`替换为双下划线。例如：

```php
Foo\\Bar\\baz();
```

对应的`C++`函数为

```cpp
php_foo__bar__baz();
```

### 类和方法

除了函数之外，也可以实现`PHP`类，方法、静态方法、属性、常量、静态属性在`stub`文件中声明，在`C++`文件中只实现方法和静态方法。

- 命名空间和类名使用双下划线（`__`）分割作为前缀

#### stub 文件

```php
class ClassFoo {
    protected string $prop;
    public function __construct(string $name);
    public function bar(int $a): int;
}
```

属性、静态属性、常量在`stub`文件中定义即可。`C++`代码仅需实现类的方法

```cpp
void php_classfoo____construct(php::Object &this_, php::String name) {}
php::Int php_classfoo__bar(php::Object &this_, php::String name) {}
```

- 类方法函数的第一个参数是`this_`，表示当前对象，若是静态方法，则第一个参数为`NULL`
- 可使用 `this_.attr()` 读写对象属性，使用 `this_.call()` 调用对象方法

### 默认参数

允许使用默认参数，例如在`stub`文件中函数声明为：

```php
function foo(string $a = "hello", int $b = 2026);
```

调用的代码为：

```php
foo();
```

C++ 代码：

```cpp
void php_foo(String a, Int b) {
    // a 的值是 "hello"
    // b 的值是 2026
}
```

## Std 容器

AOT 编译器将 C++ 标准库容器封装为 Box 资源，通过 `php::Var` 持有，提供零开销的类型安全存储。容器本体位于 `StdContainerBox<T>` 内部，通过 `_ref` 引用访问，跨函数传递时 Box 资源保持引用语义——被调方修改会反映到原容器。

### 四种 Std 容器

| 容器 | C++ 类型 | PHP 表达式 | 说明 |
| --- | --- | --- | --- |
| 定长数组 | `php::StdArray<T, N>` | `std::array(type, size)` | 编译期固定大小，通过 Box 堆上分配 |
| 动态数组 | `php::StdVector<T>` | `std::vector(type, [size])` | `std::vector` 包装，堆上分配 |
| 有序映射 | `php::StdMap<K, T>` | `std::map(ktype, vtype)` | `std::map`，字符串键使用 `zend_binary_strcmp` |
| 哈希映射 | `php::StdUnorderedMap<K, T>` | `std::unordered_map(ktype, vtype)` | `std::unordered_map`，字符串键使用 `zend_string_hash_val` |

### 内部实现

每个 std 容器变量在 C++ 中展开为两条语句：

```cpp
php::Var v = php::Var(new php::StdContainerBox<php::StdVector<php::Int>>(typeId));
auto &v_ref = v.toBox<php::StdContainerBox<php::StdVector<php::Int>>>()->container;
```

- **`php::Var v`** — Box 资源句柄，拥有容器所有权，可跨函数传递
- **`auto &v_ref`** — 容器本体引用，所有读写操作通过此引用进行

### 值类型参数

容器值类型通过辅助类常量指定：

| 辅助类 | 可用常量 | 映射类型 | C++ 存储 |
| --- | --- | --- | --- |
| `native_types` | `type_int` | `php::Int` | `php::Int` |
| `native_types` | `type_float` | `php::Float` | `php::Float` |
| `native_types` | `type_bool` | `php::Bool` | `php::Bool` |
| `native_types` | `type_bigint` | `php::BigInt` | `php::Var` |
| `native_types` | `type_bigfloat` | `php::BigFloat` | `php::Var` |
| `native_types` | `type_decimal` | `php::Decimal` | `php::Var` |
| `complex_types` | `type_string` / `type_str` | `php::Str` | `php::Str` |
| `complex_types` | `type_array` | `php::Array` | `php::Array` |
| `complex_types` | `type_object` | `php::Object` | `php::Object` |
| `complex_types` | `type_any` / `type_var` | `php::Var` | `php::Var` |
| `complex_types` | `type_stream` | `php::Stream` | `php::Var` |
| — | `ClassName::class` | `php::Object`（带类信息） | `php::Object` |

> **注意**：`BigInt`、`BigFloat`、`Decimal`、`Stream` 底层存储使用 `php::Var`（Box 资源），写入时编译器自动通过 `php::newBigInt()`、`php::newBigFloat()` 等函数进行类型转换，读取后可直接调用对应的通用方法（如 `->toString()`）。

键类型仅支持 `native_types::type_int` 和 `complex_types::type_string`。

### 高精度类型与 Stream 示例

```php
declare(strict_types=1);
use native_types;

// BigInt vector —— 写入时 int 字面量自动转换为 BigInt
$bigVec = std::vector(native_types::type_bigint);
$bigVec[] = 99;
$bigVec[] = 12345678901234567890;
var_dump($bigVec[0]->toString());  // "99"

// BigFloat map —— key 为 int，value 为 BigFloat
$bigMap = std::map(native_types::type_int, native_types::type_bigfloat);
$bigMap[0] = 3.14;
$bigMap[1] = 2.71;
var_dump($bigMap[0]->toString());  // "3.1400000000000001"

// Decimal array —— 定长 Decimal 数组
$decArray = std::array(native_types::type_decimal, 3);
$decArray[0] = 0.1;
$decArray[1] = 0.2;
var_dump($decArray[0]->toString());  // "0.1"

// Stream vector —— 存放多个流资源
$streamVec = std::vector(complex_types::type_stream);
$fp = fopen("test.txt", "r");
$streamVec[] = $fp;
var_dump($streamVec[0]->read(1024));
```

生成的 C++ 代码使用 `StdVector<php::Var>` 等 Var 类型存储，写入时编译器自动插入类型转换：

```cpp
php::Var bigVec = php::Var(new php::StdContainerBox<php::StdVector<php::Var>>(1));
auto &bigVec_ref = bigVec.toBox<php::StdContainerBox<php::StdVector<php::Var>>>()->container;
bigVec_ref.push_back(php::newBigInt(99L));                         // int → BigInt
bigVec_ref.push_back(php::newBigInt(12345678901234567890L));       // int → BigInt
php::BigInt::toString(bigVec_ref.offsetGet(php::toInt(0L)));       // 调用通用方法
```

### 1. StdVector — 动态数组

基于 `std::vector<T>`，支持动态追加和随机访问。

```php
declare(strict_types=1);
use native_types;

function main(): void {
    // 创建空的 int 类型 vector
    $v = std::vector(native_types::type_int);

    // push_back 追加
    $v[] = 10;
    $v[] = 20;
    $v[] = 30;

    // 随机访问
    echo $v[0];  // 10
    echo $v[1];  // 20

    // 复合赋值
    $v[1] += 5;
    echo $v[1];  // 25

    // 获取大小
    echo count($v);  // 3

    // foreach 遍历
    foreach ($v as $val) {
        echo $val;
    }
}
```

生成的 C++ 代码：

```cpp
php::Var v = php::Var(new php::StdContainerBox<php::StdVector<php::Int>>(1));
auto &v_ref = v.toBox<php::StdContainerBox<php::StdVector<php::Int>>>()->container;
v_ref.push_back(php::toInt(10L));
v_ref.push_back(php::toInt(20L));
v_ref.push_back(php::toInt(30L));
php::echo(v_ref.offsetGet(php::toInt(0L)));
v_ref.offsetGet(php::toInt(1L)) += php::toInt(5L);
php::echo(php::toInt(v_ref.size()));
```

**指定初始大小**：

```php
$v = std::vector(native_types::type_int, 100);
```

生成的 C++ 代码：

```cpp
php::Var v = php::Var(new php::StdContainerBox<php::StdVector<php::Int>>(1, 100));
auto &v_ref = v.toBox<php::StdContainerBox<php::StdVector<php::Int>>>()->container;
```

#### StdVector 方法速查

| 操作 | PHP | 生成的 C++ |
| --- | --- | --- |
| 追加 | `$v[] = $x` | `v_ref.push_back(x)` |
| 读取 | `$v[$i]` | `v_ref.offsetGet(i)` |
| 写入 | `$v[$i] = $x` | `v_ref.offsetSet(i, x)` |
| 复合赋值 | `$v[$i] += $x` | `v_ref.offsetGet(i) += x` |
| 大小 | `count($v)` | `v_ref.size()` |
| 遍历 | `foreach ($v as $val)` | `for (auto it = v_ref.begin(); ...)` |
| 删除 | `unset($v[$i])` | `v_ref.offsetUnset(i)` → 重置为 `T{}` |

> **注意**：`unset` 将元素重置为 `T{}`（零值），不会缩减数组大小。

### 2. StdArray — 定长数组

基于 `std::array<T, N>`，编译期确定大小，通过 `StdContainerBox` 在堆上分配，支持边界检查。

```php
declare(strict_types=1);
use native_types;

function main(): void {
    // 创建 int 类型、大小为 5 的定长数组
    $a = std::array(native_types::type_int, 5);

    // 索引写入
    $a[0] = 42;
    $a[1] = 100;
    $a[4] = 999;

    // 索引读取
    echo $a[0];  // 42

    // 越界访问：编译期常量索引在编译时检查，变量索引在运行时检查
    // $a[5] = 10;  // ❌ 编译错误：index out of bounds (0..4)

    // 填充
    std::fill($a, 7);  // 所有元素设为 7

    // foreach 遍历
    foreach ($a as $val) {
        echo $val;
    }
}
```

生成的 C++ 代码：

```cpp
php::Var a = php::Var(new php::StdContainerBox<php::StdArray<php::Int, 5>>(1));
auto &a_ref = a.toBox<php::StdContainerBox<php::StdArray<php::Int, 5>>>()->container;
a_ref[php::safeIndex(php::toInt(0L), 5)] = php::toInt(42L);
a_ref.offsetSet(php::toInt(1L), php::toInt(100L));
a_ref.offsetSet(php::toInt(4L), php::toInt(999L));
```

#### 边界检查

- **编译期**：使用整数字面量作为索引时，编译器验证 `0 <= index < N`
- **运行时**：变量索引通过 `offsetGet`/`offsetSet` 调用 `safeIndex()`，越界抛出错误

#### 嵌套 StdArray

支持多维定长数组：

```php
// 4×5 的二维 int 数组
$matrix = std::array(std::array(native_types::type_int, 5), 4);

// 访问: $matrix[row][col]
$matrix[0][0] = 1;
echo $matrix[2][3];

// 填充嵌套数组
std::fill($matrix[0], 0);
```

生成的 C++ 类型：

```cpp
php::Var matrix = php::Var(new php::StdContainerBox<php::StdArray<php::StdArray<php::Int, 5>, 4>>(1));
auto &matrix_ref = matrix.toBox<php::StdContainerBox<php::StdArray<php::StdArray<php::Int, 5>, 4>>>()->container;
matrix_ref[0L][0L] = php::toInt(1L);
```

#### 内存分配

所有 std 容器（包括 StdArray）均通过 `php::StdContainerBox<T>` 封装，Box 对象在堆上分配。容器本体（`container` 成员）位于 Box 内部，随 Box 一起在堆上管理。访问始终通过 `name_ref` 引用，对 PHP 代码完全透明——用法不变。

#### StdArray 方法速查

| 操作 | PHP | 生成的 C++ |
| --- | --- | --- |
| 读取 | `$a[$i]` | `a_ref.offsetGet(i)` （运行时边界检查） |
| 写入 | `$a[$i] = $x` | `a_ref.offsetSet(i, x)` |
| 字面量索引 | `$a[3]` | `a_ref[3L]` （编译期边界检查） |
| 大小 | `count($a)` | `a_ref.size()` |
| 遍历 | `foreach ($a as $val)` | `for (auto it = a_ref.begin(); ...)` |
| 填充 | `std::fill($a, $v)` | 循环赋值 |
| 删除 | `unset($a[$i])` | `a_ref.offsetUnset(i)` → 重置为 `T{}` |

### 3. StdMap — 有序映射

基于 `std::map<K, T>`，键有序存储。字符串键使用 `zend_binary_strcmp` 比较。

```php
declare(strict_types=1);
use native_types;

function main(): void {
    // 创建 string → int 的映射
    $m = std::map(complex_types::type_string, native_types::type_int);

    // 写入
    $m["alpha"] = 100;
    $m["beta"] = 200;

    // 读取
    echo $m["alpha"];  // 100

    // 复合赋值
    $m["alpha"] += 10;
    echo $m["alpha"];  // 110

    // 检查大小
    echo count($m);  // 2

    // foreach 遍历（按 key 顺序）
    foreach ($m as $key => $val) {
        echo $key . "=" . $val;
    }
    // 输出: alpha=110 beta=200

    // 删除
    unset($m["beta"]);
}
```

生成的 C++ 代码：

```cpp
php::Var m = php::Var(new php::StdContainerBox<php::StdMap<php::Str, php::Int>>(1));
auto &m_ref = m.toBox<php::StdContainerBox<php::StdMap<php::Str, php::Int>>>()->container;
m_ref.offsetSet(php::Str("alpha"), php::toInt(100L));
m_ref.offsetSet(php::Str("beta"), php::toInt(200L));
php::echo(m_ref.offsetGet(php::Str("alpha")));
m_ref.offsetGet(php::Str("alpha")) += php::toInt(10L);
```

#### 读取行为差异

- **`$v = $map[$k]`（右值）**：使用 `std::map::at()`——若 key 不存在，抛出 `std::out_of_range`
- **`$map[$k] = $v`（左值）**：使用 `std::map::operator[]`——若 key 不存在，默认插入

#### StdMap 方法速查

| 操作 | PHP | 生成的 C++ |
| --- | --- | --- |
| 写入 | `$m[$k] = $v` | `m_ref.offsetSet(k, v)` |
| 读取 | `$m[$k]` | `m_ref.offsetGet(k)` （不存在则抛异常） |
| 复合赋值 | `$m[$k] += $v` | `m_ref.offsetGet(k) += v` |
| 大小 | `count($m)` | `m_ref.size()` |
| 遍历 | `foreach ($m as $k => $v)` | `for (auto it = m_ref.begin(); ...)` |
| 删除 | `unset($m[$k])` | `m_ref.offsetUnset(k)` → 真正删除（`erase`） |

### 4. StdUnorderedMap — 哈希映射

基于 `std::unordered_map<K, T>`，字符串键使用 `zend_string_hash_val` 哈希和 `zend_string_equals` 比较。接口与 `StdMap` 一致。

```php
declare(strict_types=1);
use native_types;

function main(): void {
    // 创建 int → User 的哈希映射
    $u = std::unordered_map(native_types::type_int, User::class);

    $u[1] = new User(1);
    $u[2] = new User(2);

    echo $u[1]->id;   // 1
    echo count($u);   // 2

    unset($u[2]);
}
```

生成的 C++ 代码：

```cpp
php::Var u = php::Var(new php::StdContainerBox<php::StdUnorderedMap<php::Int, php::Object>>(1));
auto &u_ref = u.toBox<php::StdContainerBox<php::StdUnorderedMap<php::Int, php::Object>>>()->container;
u_ref.offsetSet(php::toInt(1L), user1);
u_ref.offsetSet(php::toInt(2L), user2);
```

#### StdMap vs StdUnorderedMap

| 特性 | StdMap | StdUnorderedMap |
| --- | --- | --- |
| 底层 | `std::map`（红黑树） | `std::unordered_map`（哈希表） |
| 遍历顺序 | 按键排序 | 无顺序保证 |
| 查找性能 | O(log n) | O(1) 平均 |
| 字符串键比较 | `zend_binary_strcmp` | `zend_string_hash_val` + `zend_string_equals` |
| foreach 中删除 | ❌ 禁止 | ❌ 禁止 |

### 5. 跨函数引用传递与 std::unsafe_cast

Std 容器以 `php::Var`（Box 资源）形式持有，作为函数参数传递时传递的是 Box 句柄。被调方可通过 `std::unsafe_cast` 提取容器引用，修改会反映到调用方的原容器——**零拷贝，零分配**。

#### 工作机制

```mermaid
sequence
    participant Caller as 调用方
    participant Runtime as phpx 运行时
    participant Callee as 被调方

    Caller->>Runtime: $vector（Box 资源，含 container 引用 + typeId）
    Caller->>Callee: 函数调用（传递 php::Var Box 句柄）
    Callee->>Runtime: std::unsafe_cast(type, $source)
    Runtime->>Runtime: toBox<StdContainerBox<T>>() 提取 Box
    Runtime->>Runtime: 校验 type_id 是否匹配
    Runtime-->>Callee: 返回容器引用 T&
    Note over Callee: 直接读写原始容器，零拷贝
```

1. **调用方**：将 std 容器变量传给被调方——编译器直接传递 `php::Var` Box 句柄
2. **被调方**：使用 `std::unsafe_cast(type, $source)` 提取容器引用并做运行时类型校验
3. 校验通过后直接返回容器引用——**零拷贝，零分配**

#### 使用示例

```php
declare(strict_types=1);
use native_types;

// 被调方：接收容器并修改
function vector_update($source): void
{
    $v = std::unsafe_cast(std::vector(native_types::type_int), $source);
    // $v 现在是调用方 vector 的引用，修改会反映到原容器
    var_dump($v[1]);
    $v[2] = 9;
}

function main(): void {
    $vector = std::vector(native_types::type_int, 3);
    $vector[0] = 1;
    $vector[1] = 7;
    $vector[2] = 3;

    vector_update($vector);   // 传递 Box 句柄，内部引用原容器
    var_dump($vector[2]);     // 9 —— 修改已生效
}
```

输出：

```
int(7)
int(9)
```

生成的 C++ 代码：

```cpp
// vector_update
void php_vector_update(php::Var source) {
    auto &v_ref = php_unsafe_cast<php::StdVector<php::Int>>(source, 1);
    php::var_dump(v_ref.offsetGet(php::toInt(1L)));
    v_ref.offsetSet(php::toInt(2L), php::toInt(9L));
}

// main
php::Var vector = php::Var(new php::StdContainerBox<php::StdVector<php::Int>>(1, 3));
auto &vector_ref = vector.toBox<php::StdContainerBox<php::StdVector<php::Int>>>()->container;
vector_ref.offsetSet(php::toInt(0L), php::toInt(1L));
vector_ref.offsetSet(php::toInt(1L), php::toInt(7L));
vector_ref.offsetSet(php::toInt(2L), php::toInt(3L));
php_vector_update(vector);                                          // 传递 Box 句柄
php::var_dump(vector_ref.offsetGet(php::toInt(2L)));                // 9
```

#### 运行时类型校验

类型 ID 在编译期分配，运行时校验。类型不匹配时抛出 `TypeError`：

```php
function process_float_array($source): void
{
    // 期望 float 数组
    $array = std::unsafe_cast(std::array(native_types::type_float, 3), $source);
}

function main(): void {
    $array = std::array(native_types::type_int, 3);  // 实际是 int 数组

    try {
        process_float_array($array);
    } catch (TypeError $e) {
        echo $e->getMessage();  // "std::unsafe_cast(): std container type mismatch"
    }
}
```

#### Polyfill

```php
class std {
    // 从 Box 句柄提取容器引用，附带类型校验
    public static function unsafe_cast(mixed $type, mixed $source): mixed { return $source; }
}
```

### 6. 限制

1. **顶层作用域声明**：std 容器和 `std::unsafe_cast` 只能在函数顶层作用域声明，不能在 `if`/`for`/`while` 等嵌套块中
2. **不可重新赋值**：变量一旦声明为某种 std 容器类型，不能重新赋值给不同类型的容器
3. **不支持嵌套访问（非 Array 类型）**：`$vec[a][b]` 仅 StdArray 支持嵌套；StdVector/StdMap/StdUnorderedMap 不支持
4. **foreach 中不可删除**：StdMap/StdUnorderedMap 处于 foreach 循环中时，不可 `unset` 其元素
5. **键类型限制**：map/unordered_map 键仅支持 `type_int` 和 `type_string`
6. **unset 语义差异**：StdVector/StdArray 对元素 `unset` 是重置为零值（`T{}`），不改变容器大小；StdMap/StdUnorderedMap 对元素 `unset` 是真正删除（`erase`），会缩减容器大小，之后读取该键会抛出异常
7. **不可作为引用参数传递**：std 容器变量不可通过 `&$var` 引用方式传递

## 性能

`AOT`编译器将性能作为第一目标，力求生成最佳的可执行指令，将`PHP`程序的性能提升至与`C/C++`、`Rust`、`Golang`等静态编程语言同等水平。

在保持高性能的同时，又保证绝对的内存安全。这与`C/C++`、`Rust`不同，`AOT`编译器不存在`Unsafe`代码。与`Rust`零成本抽象的设计不同，`AOT`编译器的设计目标是在保持`PHP`语言的易用性前提下，低成本抽象+绝对安全地实现高性能。

### Native Type

`AOT`编译器只提供了`3`种原生类型：

- `Int`：`8`字节有符号整数
- `Float`：`8`字节有符号浮点型
- `Bool`：`1`字节，仅`0`或`1`

在执行阶段，所有原生类型计算时可理解为对`int64_t`类型的直接操作。例如下面的代码：

```php
function add(int $a, int $b): int {
    return $a + $b;
}
```

对应的汇编指令为：

```asm
add:
    mov rax, rdi    ; RAX = a
    add rax, rsi    ; RAX = RAX + b
    ret
```

当使用`O2/O3`优化时，使用`$result = add($x, $y)`会内联被优化为`2`条指令：

```asm
lea rax, [x + y]   ; 没有call/ret指令
mov [result], rax
```

### 对象属性

`AOT`编译器对对象属性的读写，会优化为高效的内存操作，几乎可以等同于`C Struct`的性能。

```php
class Obj {
     public int $a;
     public float $b;
}
$o = new Obj;
$obj->a = 10;
```

实际执行时，`$obj->a`会直接转为对象指针的偏移，性能会非常高。这与访问`C Struct`元素的方式几乎是一致的。

```c
zend_object *o;
Z_LVAL_P(o + property_offset) = 10;
```

若对象属性为原生类型，`AOT`编译器还会生成更高效的指令：

```c
zend_object *o;
php::Int &property_a = Z_LVAL_P(o + property_offset);

property_a = 10;
```

这在大循环中对属性计算的程序中性能将达到极致。

```php
class Obj {
     public int $a;
     public float $b;

     function foo() {
        $n = 10000000;
        while($n--) {
            $this->a += $n;
        }
    }
}
```

等价于下面的`C++`代码

```cpp
php::Int &property_a = Z_LVAL_P(this_ + property_offset);
int64_t n = 10000000;
while (n--) {
    property_a += n;
}
```

### 函数/方法调用

#### 内置函数/类方法

由`ZendVM`提供的内置函数、内置类方法，使用`ZendVM`的`zend_call_known_function`动态调用，`AOT`编译器会在运行时一次性获取`zend_function *`指针，并存储至函数表中，减少对`EG(function_table)`的查询。

此类函数通常在编译期就可以得到参数、返回值，并且可以确定函数存在，因此可以被缓存至函数表中，以提高性能。

若使用了动态调用，则无法优化为`known call`。只能在运行时动态调用。

```php
$fn = "str_repeat";
// 无法优化
$fn("a", 100);
```

#### 动态函数/类方法

有用户代码定义、通过`Composer Autoload`加载的函数和类方法，需要动态查找`EG(function_table)`，获取`zend_function *`指针后提交至`ZendVM`动态执行。

#### 原生函数/类方法

由`AOT`编译后`PHP`定义的函数/类方法将作为原生函数调用。原生函数调用仅需进行入栈和出栈的内存、寄存器操作，相比`ZendVM`的动态函数调用性能更好。

> 原生函数调用不会产生堆栈，无法使用`debug_backtrace`获取

原生函数可被`C++`编译器内联优化，性能会非常高。例如：

```php
function fib(int $n): int
{
    if ($n == 1 || $n == 2) {
        return 1;
    } else {
        return fib($n - 1) + fib($n - 2);
    }
}

function main(int $argc, array $argv): void
{
    $n = $argv[2];
    $begin = microtime(true);
    echo fib($n) . "\n";
    echo "Time: " . (microtime(true) - $begin) . "\n";
}
```

这段代码中`fib`函数将会被编译器进行尾递归优化，最终生成平坦的`CPU`指令，性能将比普通的`PHP`动态函数调用高出数百倍。

## 专有特性

`AOT`编译器除了常规的`PHP`语法之外添加了一些专有的特性。非`AOT`编译器可以使用下列的`polyfills`垫片函数实现兼容。

> 文档中`any`类型表示该变量无类型，对应的`PHP`类型为`mixed`，`PHPX`类型为`php::Var`

### use native_types

要求编译器将`int`、`float`、`bool`类型转为原生的类型，以提高运算性能。

```php
use native_types;

function foo() {
    $a = 1000;
    while($a--) {

    }
}
```

使用`use native_types`后，局部变量赋值为整数时，会被声明为`php::Int`而不是`php::Var`，这在密集运算场景下会有巨大的性能提升。若不添加，则默认为`php::Var`，将使用`zval`结构体保存整数。

#### 垫片函数

无。在 `ZendPHP` 下无效果。

### use bigint_types

将当前文件中所有整数字面量自动声明为 `BigInt` 类型，无需手动使用 `std::bigInt()` 包装。

```php
declare(strict_types=1);
use bigint_types;

function main(): void {
    // 普通整数字面量自动成为 BigInt
    $a = 42;
    echo $a->toString();   // "42"

    // BigInt 之间运算
    $b = $a + 10;
    echo $b->toString();   // "52"

    // 字面量运算也是 BigInt
    $c = 100 + 200;
    echo $c->toString();   // "300"
}
```

使用 `use bigint_types` 后，所有 `Scalar_Int` 字面量在编译时会被转为 `php::newBigInt(N)` 调用。若不使用此指令，则只有 19 位及以上的超长整数字面量才会被自动识别为 BigInt（参见 [math.md §11](#11-超长字面量自动识别)）。

#### 与 `use native_types` 的区别

| 指令 | 整数字面量类型 | 适用场景 |
| --- | --- | --- |
| 无 | `php::Int`（原生 int64） | 普通整数运算 |
| `use native_types` | `php::Int`（原生 int64） | 高性能整数运算 |
| `use bigint_types` | `php::BigInt`（任意精度） | 需要大整数或链式调用 BigInt 方法 |

`use bigint_types` 和 `use native_types` 可以同时使用。同时使用时，非字面量的整数变量仍为 `php::Int`，但整数字面量会被提升为 `php::BigInt`。

#### 垫片函数

无。在 `ZendPHP` 下无效果。

### use decimal_types

将当前文件中所有浮点数字面量自动声明为 `Decimal` 类型，无需手动使用 `std::decimal()` 包装。

```php
declare(strict_types=1);
use decimal_types;

function main(): void {
    // 普通浮点字面量自动成为 Decimal
    $a = 3.1;
    echo $a->toString();   // "3.1"

    // Decimal 之间运算
    $b = 2.5;
    $c = $a->add($b);
    echo $c->toString();   // "5.6"

    // 混合运算：Int + Float 字面量 → Decimal
    $d = 10 + 0.5;
    echo $d->toString();   // "10.5"
}
```

使用 `use decimal_types` 后，所有 `Scalar_Float` 字面量在编译时会被转为 `php::newDecimal(...)` 调用。若不使用此指令，则只有 16 位及以上有效数字的浮点字面量才会被自动识别为 Decimal。

> **注意**：由于 PHP 解析器在解析浮点数字面量时可能已经引入了二进制浮点误差，建议在高精度要求下仍然使用字符串形式的 `std::decimal("...")` 构造。`use decimal_types` 最适合在全部使用 Decimal 运算的项目中减少样板代码。

#### 与 `use bigint_types` 同时使用

```php
declare(strict_types=1);
use bigint_types;
use decimal_types;

function main(): void {
    // 整数字面量 → BigInt
    $a = 100;
    echo $a->toString();   // "100"

    // 浮点字面量 → Decimal
    $b = 2.5;
    echo $b->toString();   // "2.5"

    // BigInt + Int 字面量 → BigInt
    $c = $a + 50;
    echo $c->toString();   // "150"

    // Decimal + Float 字面量 → Decimal
    $d = $b + 1.5;
    echo $d->toString();   // "4.0"
}
```

#### 垫片函数

无。在 `ZendPHP` 下无效果。

### objval($obj, $class)

将一个变量声明为某个类的对象。此函数只是检查表达式是否为对象类型，并且是`class`的实例。
作用：从数组中读取元素，会发生类型丢失，使用此函数可以重建类型。

```php
$obj = objval($array['object'], App\Hello\Test::class);
$obj->foo();
```

编译器可以重新得到 `$obj` 对象的类型。这样就有助于编译器将对`$obj`的方法调用转为`Native Call`而不是`zend_call_function()`的动态调用，性能可以得到大幅提升。

#### 垫片函数

```php
function objval(mixed $obj, string $class): object
{
    if ($obj instanceof $class) {
        return $obj;
    }
    throw new Exception('Invalid object type');
}
```

从数组提取元素，默认该变量的类型是`any`。对象可使用`objval()`重新接续类型，其他类型则可以使用类型转换函数或者类型转换语法实现接续。方法如下：

##### 1. 使用转换语法接续类型

- 整数：`$v = (int) $array[$key]`
- 浮点：`$v = (float) $array[$key]`
- 布尔值：`$v = (bool) $array[$key]`
- 字符串：`$v = (string) $array[$key]`
- 数组：`$v = (array) $array[$key]`

请注意对象（`object`）类型在`PHP`中实际上是无类型的，它与`any`类型几乎是等价的。若使用`$v = (object) $array[$key]`，`$v`会被声明为`php::Object`而不是`php::Var`，但编译器无法获得该对象的`class`信息。因此`object`转换对编译器来说没有任何意义。同样，`callable`、`iterator`类型对编译器也没有任何帮助。

##### 2. 使用转换函数接续类型

- 整数：`$v = intval($array[$key])`
- 浮点：`$v = floatval($array[$key])`
- 布尔值：`$v = boolval($array[$key])`
- 字符串：`$v = strval($array[$key])`

请注意内置的转换函数仅此`4`种，`PHP`未提供`arrayval()`函数，因此若需要将变量声明为`array`类型，只能使用转换语法实现。

除了数组元素的类型接续之外，赋值操作若右值为`any`类型，默认左值也会被声明为`any`类型，可以使用上述方法声明更准确的类型。

```php
$a = any(3.001);
// $b 的类型将是 php::Int，值会转为 3 
$b = intval($a);
```

### any($value)

此函数的目的是将变量类型标注为 `php::Var` ，而不是原生类型。例如：

```php
$a = any(123);
$b = 123;
```

如果不使用 `any` 函数，变量会声明为 `php::Int` 类型。将无法用于非整数的赋值，丢失溢出检测等能力。

```php
$a = 10; // $a 的类型为 Int
$b = $a / 3;  // $b 的值为 3 ，类型为整型

$a = any(10); // $a 的类型为 Var
$b = $a / 3;  // $b 的值为 3.33333...，类型为浮点型
```

#### 垫片函数

```php
function any(mixed $var): mixed
{
    return $var;
}
```

### refval($value)

在动态调用中将值传递修改为引用传递。例如下面的代码：

```php
eval('function retval_test(&$name) { $name .= "refval test"; }');

$name = 'php ';
retval_test(refval($name));
```

`eval()` 是一个运行时执行指令的函数，它动态生成了一个`retval_test`函数。由于在静态编译阶段，根本不存在`retval_test`函数，因此编译器无法将它的参数识别为引用传递，这时就需要`refval()`函数显式地将`$name`转为引用传递。

而下面的代码是不需要添加`refval()`的：

```php
class Request {
    public $data;
}

function main()
{
    $req = new Request;
    $req->data = ['get' => [],];

    parse_str("hello=world", $req->data['get']);
    var_dump($req->data['get']['hello']);
}
```

`parse_str()`是一个内置函数，在编译期就可以得到它的参数信息，第二个参数是引用类型，因此编译器会自动将参数修改为引用传递，而不需要额外添加`refval()`函数。

#### 垫片函数

```php
function &refval(&$var)
{
    return $var;
}
```

### stream_cast($stream)

将一个变量声明为 Stream 类型。作用：从数组中读取元素时编译器无法追踪其具体类型，使用 `stream_cast()` 可以重建 Stream 类型，从而支持链式调用 `write()`、`read()`、`close()` 等 Stream 方法。

此函数通常用于 `proc_open()` 或 `stream_socket_pair()` 返回的管道数组。

```php
// stream_socket_pair 返回两个 stream 元素组成的数组
$sockets = stream_socket_pair(
    STREAM_PF_UNIX,
    STREAM_SOCK_STREAM,
    0
);

// 编译器无法追踪数组元素的类型，使用 stream_cast 重建
$client = stream_cast($sockets[0]);
$server = stream_cast($sockets[1]);

$client->write("hello");
echo $server->read(5);   // "hello"

$client->close();
$server->close();
```

`stream_cast()` 在编译期被直接替换为表达式本身，无任何运行时开销。

#### 垫片函数

```php
function stream_cast($stream)
{
    return $stream;
}
```

## 兼容性

### 语法兼容性

`AOT`编译器支持绝大部分`PHP`的语法。不过由于`AOT`是静态编译，某些依赖运行时确定的特性是无法支持的：

1. 不支持 `$$` 语法，局部变量为编译器符号，无法在运行时使用
2. 不支持 `extract` 函数，无法运行时创建局部变量
3. 不支持 `yield`/`generator` 生成器语法，建议使用`fiber/swoole/swow`协程，`AOT`编译器支持协程
4. 不支持多层 `break` 或者 `continue` 语法，需要改成 `goto` 或 `try/catch`，这个特性只能在虚拟机模式中实现
5. 禁止字面量字符串包含`\0`，例如`$a = "hello \0 world;"`，与`C++`不兼容
6. 不支持参数数量不匹配的函数调用，例如某个函数的参数是`3`个，但是实际运行的代码传入了`4`个，这在`PHP`动态执行阶段是允许的，但是`AOT`编译器无法支持
7. 不支持 `Property Hook` 语法
8. 不支持动态调用中使用引用，例如`Closure`闭包函数的参数是引用类型，在运行时才能确定，在`AOT`编译器中不支持，需要显式使用`refval()`函数转为引用

```php
// 运行时才能得到函数的参数和返回值
$fn = getClosure();
// 编译器无法确定参数应该使用值还是引用，默认使用值传递
$fn($a, $b, $c);
// $c 将显式地使用引用传递，而不是值
$fn($a, $b, refval($c)); 
```

### 不支持游离代码

编译器要求所有代码必须在`function`内，不得存在游离代码，不支持内嵌  `HTML`，也就是`PHP`模版文件。这与`PHP`、`JavaScript`等脚本语言完全不同，而是与`C++`、`Java`、`Golang`、`Rust`一致。

因此模版文件、配置文件不支持编译，需使用`include/require`动态加载，在`ZendPHP`中动态执行。

### 类型不可变性

`AOT`编译器要求不得转换变量类型。例如一个变量声明为`Object`，则不允许作为字符串或数组来使用。这与 `PHP` 截然不同。

```php
$str = "hello world";
$str = new StringObject("hello");
```

无法将`string`类型的变量，赋值为对象。若文件使用了严格类型，`declare(strict_types=1)` 上述代码将出现编译错误。

```bash
Fatal error: Cannot re-assign variable from `php::Object` to `php::Str`
```

若未声明严格类型，则自动转为字符串。相当于以下代码：

```php
$str = "hello world";
$obj = new StringObject("hello");
$str = strval($obj);
```

这里的行为与`ZendPHP`完全不同，在`ZendPHP`中`$str`变量会从字符串类型转为对象。

> `ZendPHP`在底层设计上是`any`类型的，语言层面不会存储变量的类型

除了字符串之外，对象的类型也是不可变的。

```php
$o = new stdClass;
$o = new ArrayObject;
```

变量`$o`被声明为了`stdClass`类型，而在运行过程中又被转为`ArrayObject`，在`AOT`编译器中是不被允许的。将抛出下列编译错误：

```bash
Fatal error: Cannot re-assign typed object `$o` from `TestObject` to `stdClass`
```

### 变量作用域

由于`PHP`的设计所有局部变量`Local Var`的作用域是`function`级别的，所以即使在`if/else/for/while`代码块中声明的变量也会被当做`function`内的顶层局部变量来处理。这与`ZendPHP`行为是一致的。

```php
function foo() {
    if ($cond) {
        $a = [];
    }
    // 这是不被允许的
    // $a 虽然是在 if 语句中声明，但实际的作用域是整个 function
    $a = "str";
}
```

### 未定义变量

在`ZendPHP`可以使用`isset()`判断变量是否存在。`AOT`编译器不支持这种写法。局部变量必须是先定义再使用。因此下面的代码是不被允许的。

```php
// 变量没有定义，isset 返回 false
if (!isset($var)) {
    // stmts
}
```

必须修改为：

```php
$var = null;
if (!isset($var)) {
    // stmts
}
```

`isset($var)`表达式在静态编译时将一直是`true`。

若使用未定义变量，在`ZendPHP`中仅会抛出一条`Warnning`警告，但编译器会直接报错，不允许使用未定义的变量。

```php
function main()
{
    var_dump($testVar);
}
```

```bash
Fatal error: Undefined variable $testVar in undef-var.php:4
```

在 `ZendPHP` 中的执行结果如下：

```bash
Warning: Undefined variable $testVar in undef-var.php on line 4
NULL
```

### 注解语法

`AOT`编译器支持注解语法，但由于`ZendVM`自身的限制，不支持非空数组类型的注解参数。

```php
#[MyAttribute]
#[MyAttribute(1234)]
#[MyAttribute(value: 1234)]
#[MyAttribute(MyAttribute::VALUE)]
#[MyAttribute([])]
#[MyAttribute(100 + 200)]
class Thing
{
}
```

下面的注解语法暂时无法支持：

```php
#[MyAttribute([1, 2, 3, 'str', bool])]
class Thing
{
}
```

## GC 机制

AOT 编译器沿用 ZendVM 的内存管理机制——值类型直接复制、引用类型使用引用计数、写时复制（COW）确保共享安全。Std 容器则使用 C++ RAII 管理生命周期。

### 整体架构

```mermaid
flowchart-v2
    subgraph VALUE [值类型 — 直接复制]
        V1["php::Int / Float / Bool / null"]
        V2["zval 内联存储<br>IS_LONG / IS_DOUBLE / IS_TRUE / IS_FALSE / IS_NULL"]
        V3["无引用计数<br>复制 = memcpy"]
    end

    subgraph REFCOUNTED [引用计数类型 — Zend GC]
        R1["php::Str → zend_string"]
        R2["php::Array → zend_array"]
        R3["php::Object → zend_object"]
        R4["GC_ADDREF / GC_DELREF"]
        R5["COW 写时复制<br>SEPARATE_STRING / SEPARATE_ARRAY"]
    end

    subgraph STD [Std 容器 — C++ RAII]
        S1["StdVector / StdArray / StdMap / StdUnorderedMap"]
        S2["std::vector / std::array / std::map<br>内嵌 Variant 元素"]
        S3["容器析构 → 元素 Variant 析构 → zval_ptr_dtor"]
    end

    VALUE --> APP[应用层]
    REFCOUNTED --> APP
    STD --> APP
```

### 1. 值类型 — 直接复制

`php::Int`、`php::Float`、`php::Bool` 不参与引用计数。

#### 原理

值类型直接存储在 `zval` 结构体内部，不分配堆内存。类型标记（`IS_LONG`、`IS_DOUBLE`、`IS_TRUE`、`IS_FALSE`）不设置 `Z_REFCOUNTED` 标志位，因此所有引用计数操作（`Z_TRY_ADDREF`、`Z_TRY_DELREF`）都是空操作。

```cpp
// 赋值即复制值，零 GC 开销
Variant &operator=(long v) {
    destroy();        // 释放旧值（若旧值持有引用计数类型）
    ZVAL_LONG(unwrap_ptr(), v);  // 直接写入 zval 的 lval 字段
    return *this;
}

Variant &operator=(double v) {
    destroy();
    ZVAL_DOUBLE(unwrap_ptr(), v);  // 直接写入 zval 的 dval 字段
    return *this;
}

Variant &operator=(bool v) {
    destroy();
    ZVAL_BOOL(unwrap_ptr(), v);   // 直接设置 IS_TRUE / IS_FALSE
    return *this;
}

// null 同样是值类型，存储在 zval 内部
Variant &setNull() {
    destroy();
    ZVAL_NULL(unwrap_ptr());    // 直接设置 IS_NULL，无堆分配
    return *this;
}
```

#### 行为

```php
use native_types;

$a = 42;        // php::Int — 值直接存于栈上的 zval.lval
$b = $a;        // 复制 8 字节整数，无引用计数操作
$b = 100;       // $a 不受影响
```

```cpp
// 生成的 C++ 代码
php::Int a = 42L;
php::Int b = a;     // memcpy 语义
b = 100L;           // 直接覆盖
```

#### 值类型清单

| 类型 | C++ 类型 | zval 存储 | 引用计数 |
| --- | --- | --- | --- |
| `int` | `php::Int` | `zval.value.lval` (`IS_LONG`) | 否 |
| `float` | `php::Float` | `zval.value.dval` (`IS_DOUBLE`) | 否 |
| `bool` | `php::Bool` | `zval.value.val` 的 `IS_TRUE`/`IS_FALSE` | 否 |
| `null` | `php::Var` (值) | `zval` 的 `IS_NULL` | 否 |

### 2. 引用计数 — String / Array / Object

`php::Str`、`php::Array`、`php::Object` 内部持有指向 Zend 堆对象的指针（`zend_string*`、`zend_array*`、`zend_object*`），通过引用计数追踪共享。

#### 2.1 引用计数基本操作

Zend 引擎在 `zend_types.h` 中提供了条件化的引用计数宏：

```c
// 仅对 Z_REFCOUNTED 类型生效；值类型无操作
#define Z_TRY_ADDREF_P(pz) do { \
    if (Z_REFCOUNTED_P((pz))) { \
        Z_ADDREF_P((pz));       \
    }                           \
} while (0)

#define Z_TRY_DELREF_P(pz) do { \
    if (Z_REFCOUNTED_P((pz))) { \
        Z_DELREF_P((pz));       \
    }                           \
} while (0)
```

#### 2.2 Variant 的 GC 职责

`php::Var` 是唯一的动态类型容器，持有 `zval`。其构造、赋值、析构完整管理引用计数：

**复制（`operator=`）**：

```cpp
void Variant::copyFrom(const zval *src) {
    auto zv = unwrap_ptr();
    zval tmp = *zv;               // 1. 保存旧值
    zval_copy(zv, src);           // 2. 复制新值 + Z_TRY_ADDREF
    zval_ptr_dtor(&tmp);          // 3. 释放旧值（减引用计数或直接释放）
}
```

三步保证：先保存旧值，复制新值并增加引用计数，再释放旧值。即使新旧指向同一对象，旧值在步骤 3 才释放，不会提前回收。

**析构**：

```cpp
~Variant() {
    if (isReference()) {
        zval_ptr_dtor(&val);        // IS_REFERENCE — 直接释放
    } else if (!isIndirect()) {
        destroy();                   // 调用 zval_ptr_dtor
    }
    // IS_INDIRECT — 无操作：指针借自数组/对象，不管理生命周期
}
```

三种情况：
1. **IS_REFERENCE**：`zval` 本身是引用类型，需要释放 `zend_reference` 结构
2. **IS_INDIRECT**：`zval` 是指向数组桶或对象属性槽的指针——不拥有数据，无释放
3. **其他**：正常的 refcounted 类型，调用 `zval_ptr_dtor` 递减引用计数

#### 2.3 String — zend_string 引用计数

```cpp
// 构造：复制 zend_string（增加引用计数）
String(zend_string *v) {
    ZVAL_STR(ptr(), zend_string_copy(v));  // zend_string_copy → GC_ADDREF
}

// 赋值：先释放旧，再增加新的引用计数
Variant &operator=(zend_string *v) {
    destroy();                                // 释放旧值
    ZVAL_STR(unwrap_ptr(), zend_string_copy(v));  // 新值 + GC_ADDREF
    return *this;
}
```

**示例**：

```php
$s = "hello";   // zend_string{refcount:1} — 初次分配
$t = $s;        // GC_ADDREF → refcount:2
$s = "world";   // $s 新建 zend_string{refcount:1}；$t 原 zend_string refcount:1
```

```cpp
// 生成的 C++ 伪代码
php::Str s = php::Str("hello");
php::Str t = s;             // Z_TRY_ADDREF: s 的 zend_string 引用计数 +1
s = php::Str("world");      // destroy() → GC_DELREF: 旧 zend_string -1
                            // ZVAL_STR + zend_string_copy: 新 zend_string +1
```

#### 2.4 Array — zend_array 引用计数

```cpp
// 写入时自动处理
void Array::append(const Variant &v) {
    auto zv = NO_CONST_Z(v.direct_ptr());
    Z_TRY_ADDREF_P(zv);         // 1. 值 +1
    auto zarr = unwrap_ptr();
    SEPARATE_ARRAY(zarr);       // 2. 若共享则先复制
    add_next_index_zval(zarr, zv);  // 3. 存入
}
```

**示例**：

```php
$a = [1, 2];    // zend_array{refcount:1}
$b = $a;        // GC_ADDREF → refcount:2
```

```cpp
php::Array a;
a.append(1); a.append(2);  // refcount:1
php::Array b = a;           // Z_TRY_ADDREF: refcount → 2
```

#### 2.5 Object — zend_object 引用计数

```cpp
Object(zend_object *o, Ctor method = Ctor::Copy) {
    ZVAL_OBJ(&val, o);
    if (method == Ctor::Copy) {
        addRef();   // GC_ADDREF 增加 zend_object 的引用计数
    }
}
```

### 3. 写时复制（COW）

当多个变量共享同一个 `zend_string` 或 `zend_array`（refcount > 1）时，写入操作必须先复制一份私有副本再修改——这就是 COW。

#### 3.1 SEPARATE_STRING

```c
#define SEPARATE_STRING(zv) do {                          \
    zval *_zv = (zv);                                     \
    if (Z_REFCOUNT_P(_zv) > 1) {                          \
        zend_string *_str = Z_STR_P(_zv);                 \
        ZVAL_NEW_STR(_zv, zend_string_init(               \
            ZSTR_VAL(_str), ZSTR_LEN(_str), 0));          \
        GC_DELREF(_str);                                  \
    }                                                     \
} while (0)
```

当 `refcount > 1`：创建新的 `zend_string`，内容相同，递减旧字符串的引用计数，用新指针替换 `zval`。

#### 3.2 SEPARATE_ARRAY

```c
#define SEPARATE_ARRAY(zv) do {                           \
    zval *__zv = (zv);                                    \
    zend_array *_arr = Z_ARR_P(__zv);                     \
    if (UNEXPECTED(GC_REFCOUNT(_arr) > 1)) {              \
        ZVAL_ARR(__zv, zend_array_dup(_arr));             \
        GC_TRY_DELREF(_arr);                              \
    }                                                     \
} while (0)
```

当 `GC_REFCOUNT > 1`：通过 `zend_array_dup` 深拷贝数组，递减旧数组引用计数。

#### 3.3 COW 触发时机

String 和 Array 的所有**修改操作**都会先调用 `SEPARATE_*`：

| 类型 | 触发 COW 的操作 | 调用位置 |
| --- | --- | --- |
| `String` | `offsetSet($i, $v)` — 修改字符 | `SEPARATE_STRING` |
| `Array` | `set()` / `append()` / `del()` / `clean()` / `sort()` / `merge()` | `SEPARATE_ARRAY` |
| `Object` | `updateArrayProperty()` / `appendArrayProperty()` | `SEPARATE_ARRAY` |

#### 3.4 示例

```php
$a = [1, 2, 3];   // zend_array{refcount:1}
$b = $a;           // GC_ADDREF → refcount:2，共享同一份数据

$b[] = 4;          // 写入前 SEPARATE_ARRAY: refcount > 1
                   //   → zend_array_dup 创建副本{refcount:1}
                   //   → GC_DELREF 旧数组 → refcount:1 ($a 仍持有)
                   //   → 在新副本上追加 4

// 结果：$a = [1, 2, 3]，$b = [1, 2, 3, 4]
```

```mermaid
sequence
    participant A as $a
    participant Z as zend_array
    participant B as $b

    A->>Z: 创建 {refcount:1}
    B->>Z: GC_ADDREF {refcount:2}
    Note over A,Z: 此时 $a 和 $b 共享同一 zend_array
    B->>Z: SEPARATE_ARRAY 检查 refcount > 1
    Z->>Z: zend_array_dup 创建副本 {refcount:1}
    B->>Z: GC_DELREF {refcount:1}
    Note over A,Z: $a → 原始数组 refcount:1<br>$b → 新副本 refcount:1
    B->>B: append(4) 在新副本上
```

COW 确保：
- 共享时零拷贝——仅增加引用计数
- 修改时自动隔离——不会意外影响其他变量
- 对 PHP 代码完全透明

### 4. Std 容器的特殊性

Std 容器使用 C++ 原生内存管理，不经过 Zend GC。

#### 4.1 内存模型

```cpp
template <typename T>
class StdVector {
private:
    std::vector<T> data_;  // C++ 标准库容器，RAII 管理
    // ...
};
```

- **容器自身**：可以是栈上对象（小 StdArray）或堆上 `make_unique`（大 StdArray），生命周期由 C++ 作用域/智能指针管理
- **元素存储**：`std::vector<T>` 在堆上分配连续内存存储元素，析构时自动释放
- **元素 GC**：元素的 `Variant` 析构函数调用 `zval_ptr_dtor`——C++ RAII 与 Zend GC 在此交汇

#### 4.2 生命周期

```cpp
void php_example() {
    php::StdVector<php::Int> vector;  // 栈上构造，元素的 std::vector 在堆上

    vector.push_back(php::toInt(1L));  // Variant 元素被复制到 vector 内部
    vector.push_back(php::toInt(2L));

}  // 作用域结束
   // → StdVector 析构 → std::vector::~vector()
   // → 每个 Variant 元素调用 ~Variant()
   // → 值类型（Int）没有堆资源，无操作
```

```cpp
void php_example_string_vector() {
    php::StdVector<php::Str> vector;

    vector.push_back(php::Str("hello"));  // 字符串的 zend_string refcount:1

}  // StdVector 析构
   // → 每个 Str 元素调用 ~Variant() → zval_ptr_dtor
   // → GC_DELREF 递减 zend_string 引用计数
   // → refcount 归零 → 释放 zend_string 内存
```

#### 4.3 与 php::Array 的对比

| 维度 | `php::Array` | `php::StdVector<Int>` |
| --- | --- | --- |
| 底层容器 | `zend_array`（Zend HashTable） | `std::vector`（C++ 标准库） |
| 内存管理 | Zend GC（引用计数 + COW） | C++ RAII（析构函数链式释放） |
| 拷贝语义 | 共享 + COW | 深拷贝或禁用 |
| 类型安全 | 动态（可混存任意类型） | 静态（编译期类型检查） |
| 性能特征 | 通用 HashTable 有一定开销 | 零开销 C++ 模板，直接机器码 |

#### 4.4 大 StdArray 的智能指针

单独 StdArray 超过 65536 字节时，编译器自动切换为 `std::make_unique`：

```cpp
// 小数组 — 栈上
php::StdArray<php::Int, 100> small{};

// 大数组 — unique_ptr 堆分配
auto large = std::make_unique<php::StdArray<php::Int, 10000>>();
// 访问：large->offsetGet(i) / large->offsetSet(i, v)
// 析构：unique_ptr::~unique_ptr() → StdArray::~StdArray() → std::array 析构
```

编译器自动处理 `.` 到 `->` 的语法切换，对 PHP 代码透明。

### 5. $var / mixed — 动态类型的完整生命周期

`php::Var` 是唯一可以持有任意类型值的容器，其赋值的完整 GC 流程：

```mermaid
flowchart-v2
    A["$a = 42"] --> B["ZVAL_LONG → IS_LONG<br>无堆分配，无引用计数"]
    C["$a = 'hello'"] --> D["destroy() → zval_ptr_dtor(旧值 IS_LONG)<br>→ 值类型，无操作"] --> E["ZVAL_STR + zend_string_copy<br>→ 新 zend_string refcount:1"]
    F["$b = $a"] --> G["zval_copy → Z_TRY_ADDREF<br>→ zend_string refcount:2"]
    H["$a = [1,2]"] --> I["destroy() → GC_DELREF<br>→ zend_string refcount:1"] --> J["ZVAL_ARR + GC_ADDREF<br>→ 新 zend_array refcount:1"]
    K["unset($a)"] --> L["~Variant() → zval_ptr_dtor<br>→ GC_DELREF → refcount 归零 → 释放"]
```

每个赋值都遵循"释放旧 → 持有新"的语义：旧值的引用计数递减（为零则释放），新值的引用计数递增。

### 6. 总结

| 类型 | 存储方式 | GC 机制 | COW |
| --- | --- | --- | --- |
| `php::Int` / `Float` / `Bool` / `null` | `zval` 内联 | 无（直接复制值） | 不适用 |
| `php::Str` | 指向 `zend_string*` | Zend 引用计数 | `SEPARATE_STRING` |
| `php::Array` | 指向 `zend_array*` | Zend 引用计数 | `SEPARATE_ARRAY` |
| `php::Object` | 指向 `zend_object*` | Zend 引用计数 | 属性修改时 `SEPARATE_ARRAY` |
| `php::Var` | `zval`（任意类型） | 动态：值类型无 GC，引用类型走 Zend GC | 取决于实际持有的类型 |
| Std 容器 | C++ 模板类 | C++ RAII + 元素 Variant 析构链 | 深拷贝 / 引用传递 |

## 执行过程

### ZendVM 的执行方式

ZendVM 是 PHP 官方的参考实现，采用"解析—编译—解释执行"的经典虚拟机架构。每次请求到达时，ZendVM 重新执行完整的编译-执行流程。

#### 整体执行流程

```mermaid
flowchart-v2
    Z0["📁 PHP 源代码<br>（.php 文件）"]
    Z1["1. 词法分析<br>Re2c"]
    Z2["Opcode 缓存<br>（OPcache）"]
    Z4["4. Opcode 执行<br>zend_vm_execute.h"]
    Z3["2. 语法分析<br>Bison → AST"]
    Z5["3. Opcode 编译<br>AST → OPArray"]

    Z0 --> Z1
    Z0 --> Z2
    Z2 --> Z4
    Z1 --> Z3
    Z3 --> Z5
    Z5 --> Z4
```

> OPcache 可以缓存阶段 1~3 的产物（Opcode 数组），避免重复解析和编译。但即使命中缓存，阶段 4 的解释执行仍然每次请求都发生。

#### 1. 词法分析（Lexical Analysis）

将 PHP 源代码字符流拆分为 `Token`（词元）序列。

**实现机制**：使用 `Re2c` 工具生成词法分析器 C 代码。`Re2c` 将正则规则编译为确定性有限自动机（DFA），输出的 C 代码使用 `goto` 跳转表实现高效字符匹配。生成的扫描器位于 `Zend/zend_language_scanner.c`。

**Token 类型**：包括标识符（`T_VARIABLE`、`T_STRING`）、字面量（`T_LNUMBER`、`T_DNUMBER`、`T_CONSTANT_ENCAPSED_STRING`）、运算符（`+`、`-`、`.`）、关键字（`if`、`while`、`class`）等。

在用户侧可使用 `token_get_all()` 观察词法分析结果：

```php
// 源代码
$name = "World"; echo "Hello, " . $name;

// token_get_all() 输出:
[
    [T_OPEN_TAG, "<?php ", 1],
    [T_VARIABLE, "$name", 2],
    "=",
    [T_CONSTANT_ENCAPSED_STRING, '"World"', 2],
    ";",
    [T_ECHO, "echo", 3],
    [T_CONSTANT_ENCAPSED_STRING, '"Hello, "', 3],
    ".",
    [T_VARIABLE, "$name", 3],
    ";",
    [T_CLOSE_TAG, "?>", 4],
]
```

#### 2. 语法分析（Syntax Analysis）

使用 **Bison**（LALR(1) 解析器生成器）将 Token 流转换为**抽象语法树（AST）**。

**实现机制**：PHP 语法规则定义在 `Zend/zend_language_parser.y` 中。Bison 将其编译为 C 语言的 LALR(1) 解析表，生成的解析器通过移进-归约（shift-reduce）算法将 Token 序列归约为 AST 节点。每个语法规则对应一个 AST 节点构造动作。

**AST 节点结构**（`Zend/zend_ast.h`）：

```c
struct _zend_ast {
    zend_ast_kind kind;       // 节点类型（如 ZEND_AST_ASSIGN）
    zend_ast_attr attr;       // 属性（运算符、修饰符等）
    uint32_t lineno;          // 源代码行号
    zend_ast *children[1];    // 可变长子节点数组
};
```

**AST 示例** — `$name = "World"; echo "Hello, " . $name;`：

```
ZEND_AST_STMT_LIST
├── ZEND_AST_ASSIGN
│   ├── ZEND_AST_VAR (name: "name")
│   └── ZEND_AST_ZVAL (value: "World", type: IS_STRING)
└── ZEND_AST_ECHO
    └── ZEND_AST_BINARY_OP (op: CONCAT)
        ├── ZEND_AST_ZVAL (value: "Hello, ", type: IS_STRING)
        └── ZEND_AST_VAR (name: "name")
```

#### 3. Opcode 编译（AST → OPArray）

遍历 AST 生成 **OPArray**——操作码数组及关联的运行时数据。

**实现机制**：编译器位于 `Zend/zend_compile.c`，通过递归下降遍历 AST，每个 AST 节点类型对应一个 `zend_compile_*()` 函数。生成的 Opcode 是 ZendVM 的"字节码"——每个 Opcode 包含操作码和操作数。

**OPArray 结构**（`Zend/zend_compile.h`）：

```c
struct _zend_op_array {
    zend_op *opcodes;              // Opcode 序列
    zval *literals;                // 字面量数组（字符串、数字等）
    int last;                      // Opcode 数量
    // ... 变量槽位、临时变量、异常表等
};

struct _zend_op {
    const void *handler;           // 执行时填充：对应的 handler 函数指针
    znode_op op1, op2, result;     // 操作数和结果（寄存器/常量/跳转偏移）
    uint32_t lineno;               // 对应源代码行号
    uint8_t opcode;                // 操作码（如 ZEND_ASSIGN、ZEND_ECHO）
};
```

**示例** — `$a = 3 + $b;` 编译为：

```
OPArray:
  [0] ZEND_ADD      CV($b)  CONST(3)  TMP_VAR($0)
  [1] ZEND_ASSIGN   CV($a)  TMP_VAR($0)
```

| Opcode | 说明 | op1 | op2 | result |
| --- | --- | --- | --- | --- |
| `ZEND_ADD` | 加法运算 | `CV($b)` — 编译变量 | `CONST(3)` — 常量 3 | `TMP_VAR` — 临时寄存器 |
| `ZEND_ASSIGN` | 赋值 | `CV($a)` — 目标变量 | `TMP_VAR` — 源临时值 | — |

ZendVM 使用**虚拟寄存器**模型：`CV`（Compiled Variable）映射到调用帧的变量槽，`TMP_VAR` 是临时寄存器，`CONST` 引用字面量池。

**OPcache 的作用**：OPcache 将编译产出的 OPArray 缓存到共享内存中。命中缓存时，阶段 1~3 被完全跳过——这是 ZendVM 最重要的性能优化。但请注意，OPcache 只缓存编译结果，执行阶段仍然每次发生。

#### 4. Opcode 执行（VM 解释循环）

ZendVM 核心执行器加载 OPArray 并逐条执行 Opcode。

**实现机制**：执行器位于 `Zend/zend_vm_execute.h`。这是一个巨大的 `while` 或 `goto` 分发循环，每个 Opcode 对应一个 `C 函数` 或内联 `handler`。CPU 从 OPArray 中取指、通过 handler 函数指针间接跳转、解码操作数、执行语义、写入结果、推进指令指针，循环往复。

```mermaid
flowchart-v2
    E0["OPArray<br>（Opcode 序列）"]
    E1["取指<br>读取下一条 Opcode"]
    E2["解码<br>解析 op1 / op2 / result"]
    E3["通过 handler 函数指针分发"]
    E4["Opcode 类型"]
    E5["ZEND_ASSIGN<br>复制值到变量槽"]
    E6["ZEND_ADD<br>取出操作数，执行加法<br>结果写入临时寄存器"]
    E7["ZEND_ECHO<br>将值转为字符串输出"]
    E8["ZEND_JMP<br>修改指令指针实现跳转"]
    E9["返回，结束当前帧"]
    E10["还有下一条?"]

    E0 --> E1 --> E2 --> E3 --> E4
    E4 --> E5
    E4 --> E6
    E4 --> E7
    E4 --> E8
    E5 --> E10
    E6 --> E10
    E7 --> E10
    E8 --> E1
    E10 -->|是| E1
    E10 -->|否| E9
```

**执行开销分析**：每条 Opcode 执行时，CPU 必须完成：
1. 间接跳转（handler 函数指针调用）
2. 操作数解码（区分 CV / TMP / CONST 类型）
3. 类型检查（`zval` 的类型标记，决定实际运算逻辑）
4. 引用计数管理（`zval` 的 `refcount` 增减）
5. 运算执行
6. 下一条 Opcode 取指

`zval` 的装箱/拆箱和类型标记检查是主要开销来源。即使最简单的 `$a + $b`，ZendVM 也需要检查两个操作数的类型、处理类型转换、分配临时 `zval` 存储结果。

```mermaid
flowchart-v2
    subgraph ZVM_OVERHEAD [ZendVM 单条 Opcode 开销]
        V1["间接跳转<br>handler 指针"]
        V2["操作数解码<br>CV / TMP / CONST"]
        V3["类型检查<br>zval.u1.type_info"]
        V4["引用计数<br>GC_ADDREF / GC_DELREF"]
        V5["运算执行"]
        V6["下一条 Opcode"]
    end
    V1 --> V2 --> V3 --> V4 --> V5 --> V6
```

#### ZendVM 完整流程总结

```mermaid
flowchart-v2
    SRC["📁 PHP 源文件"]
    LEX["1. 词法分析<br>Re2c → Token 流"]
    PARSE["2. 语法分析<br>Bison → AST"]
    COMPILE["3. Opcode 编译<br>AST → OPArray"]
    EXEC["4. Opcode 执行<br>VM 解释循环"]
    PARSE2["动态编译<br>（include / eval）"]
    COMPILE2["OPArray"]
    CACHE["OPcache<br>共享内存"]

    SRC --> LEX --> PARSE --> COMPILE --> EXEC
    EXEC -->|函数调用| PARSE2
    PARSE2 --> COMPILE2 --> EXEC
    CACHE -.->|缓存命中| EXEC
```

关键点：
- **无 OPcache 时**：每次请求执行完整的"解析 → 编译 → 执行"管线
- **有 OPcache 时**：跳过解析和编译，但仍需执行 Opcode 解释循环
- **动态特性支持**：`include`、`eval`、`create_function()` 等可在运行时触发新的编译
- **`zval` 开销**：所有值（包括整数、浮点）都装箱在 `zval` 结构体中，每条 Opcode 都涉及类型检查和引用计数

### AOT 编译器的执行方式

AOT 编译器将 PHP 编译为 C++ 再编译为原生机器码，分为 `4` 个阶段。整个编译流程完全离线完成，运行时不再需要解析、编译或解释 PHP 代码。

#### 整体执行流程

```mermaid
flowchart-v2
    A["📁 PHP 源码<br>（文件/目录/project.yml）"]
    B["1. 预处理<br>prepare()"]
    C["2. 转换为 C++ 代码<br>convert()"]
    D["3. 编译 C++ 源码<br>compile()"]
    E["4. 链接<br>build()"]
    F["✅ 可执行文件<br>（ELF / PE）"]
    G["✅ 动态链接库<br>（.so / .dll）"]

    A --> B --> C --> D --> E
    E --> F
    E --> G
```

#### 1. 预处理 — `prepare()`

预处理阶段负责扫描、收集、排序所有源文件，构建完整的符号表。

```mermaid
flowchart-v2
    P1["解析入口<br>（文件/目录/YAML）"]
    P2["文件发现<br>递归扫描目录"]
    P3["YAML 配置解析<br>sources / ignore / build-mode"]
    P4["逐个文件预解析<br>prepareFile()"]
    P5["文件类型?"]
    P6["php-parser 解析 AST"]
    P7["标记为原生源文件<br>跳过 AST 解析"]
    P8["收集符号声明<br>命名空间 / 类 / 函数 / 常量"]
    P9["收集符号调用<br>记录函数调用依赖"]
    P10["拓扑排序<br>按依赖关系排序文件"]
    P11["✅ 有序文件列表<br>+ 完整符号表"]

    P1 --> P2 --> P3 --> P4 --> P5
    P5 -->|.php| P6 --> P8 --> P9 --> P10 --> P11
    P5 -->|.cpp/.c/.s/.mm| P7 --> P11
```

**详细步骤**

**1.1 入口解析**

支持三种入口模式：
- **单文件**：`php bin/compiler.php app.php`
- **目录**：`php bin/compiler.php src/`，递归发现所有源文件
- **YAML 配置**：`php bin/compiler.php project.yml`，从配置文件加载项目设置

**1.2 文件发现**

使用 `FileScanner` 递归扫描目录，支持以下扩展名：
- **PHP**: `.php`
- **C++**: `.cpp`, `.cc`, `.cxx`
- **C**: `.c`
- **汇编**: `.s`
- **Objective-C** (macOS): `.m`, `.mm`

**1.3 YAML 配置解析**

读取 `project.yml` 中的 `sources`、`ignore`、`build-mode`、`cxx-flags`、`ld-flags`、`cpp-compiler`、`resource` 等配置项。配置文件路径作为项目根目录，所有相对路径基于此解析。

**1.4 预解析（prepareFile）**

对每个 PHP 文件执行 AST 解析，但**不生成代码**，只收集符号信息：

| 语句类型 | 收集内容 |
| --- | --- |
| `Stmt_Namespace` | 当前命名空间 |
| `Stmt_Class` / `Stmt_Trait` / `Stmt_Enum` | 类名、父类、属性、方法签名 |
| `Stmt_Interface` | 接口名、方法签名 |
| `Stmt_Function` | 函数名、参数类型、返回值类型 |
| `Stmt_Const` | 常量定义 |
| `Expr_FuncCall` | 全局函数调用（用于依赖分析） |

**1.5 依赖分析与拓扑排序**

编译器记录每个文件调用的函数符号，并映射到声明该符号的文件。使用拓扑排序确定编译顺序，确保依赖的文件先被编译。不参与依赖管理的文件（无交叉引用）排在最后。

> 内置函数不参与依赖管理——它们由 phpx 运行时库提供，不在用户文件中声明。

> **⚠️ 已知问题（v1054）**：prepare 阶段实际文件处理顺序为字符串字典序，而非拓扑排序结果。**convert 阶段利用 prepare 收集的符号信息做去虚化优化决策**（Direct Call Optimization），导致优化行为依赖文件名字典序。
>
> 当子类文件字典序小于父类文件时，子类先被 prepare，编译器已知子类有额外属性（distinct state），激进地将 `$this->method()` 优化为直接函数调用。
>
> 影响范围：abstract 方法有 concrete 空实现 + 跨文件继承 + 子类有额外属性时触发。
> 解决方案：请勿为抽象方法提供 concrete 空实现，改为声明为 abstract。详见 docs/经验.md 第 5.4 节。

**1.6 检测平台环境**

在预处理阶段同步检测运行环境：
- 操作系统：Linux / macOS / Windows
- C++ 编译器：GCC / Clang / MSVC
- `clang-format` 可用性（用于代码格式化）

#### 2. 转换为 C++ 代码 — `convert()`

转换阶段将每个 PHP 文件的 AST 逐个翻译为 C++ 源代码。

```mermaid
flowchart-v2
    C1["有序文件列表"]
    C2["是否有缓存?"]
    C3["跳过，直接使用缓存 .cc"]
    C4["加载 PHP 源代码"]
    C5["php-parser 解析为 AST"]
    C6["逐个 AST 节点翻译"]
    C7["表达式 → C++ 表达式"]
    C8["语句 → C++ 语句"]
    C9["函数/方法 → C++ 函数"]
    C10["类 → C++ struct + zend_class_entry"]
    C11["类型推断"]
    C12["拼接 C++ 代码 + 头文件"]
    C13["写入 .cc 文件"]
    C14["✅ 所有 .cc 文件"]

    C1 --> C2
    C2 -->|有缓存且非 -f| C3 --> C14
    C2 -->|无缓存| C4 --> C5 --> C6
    C6 --> C7 --> C11
    C6 --> C8 --> C11
    C6 --> C9 --> C11
    C6 --> C10 --> C11
    C11 --> C12 --> C13 --> C14
```

**详细步骤**

**2.1 缓存检查**

编译器为每个 PHP 文件维护编译缓存。若源文件未修改且对应的 `.cc` 文件已存在，则跳过转换，直接使用缓存。使用 `-f`（`--force`）可强制重新转换。

**2.2 AST 解析与遍历**

使用 `nikic/php-parser` 将 PHP 源码解析为 AST。生成的 AST 被逐节点遍历，每个节点类型对应一个 `parse*()` 处理方法。部分关键映射：

| PHP 节点 | 翻译方法 | C++ 产物 |
| --- | --- | --- |
| `Expr_Assign` | `parseAssignExpr()` | `var = expr;` |
| `Expr_BinaryOp_Plus` | `parseBinaryOp()` | `php::BigInt::add(a, b)` 或 `a + b` |
| `Expr_FuncCall` | `parseFuncCall()` | `php::func_name(args)` |
| `Expr_MethodCall` | `parseMethodCall()` | `obj.method(args)` |
| `Stmt_If` | `parseIf()` | `if (cond) { ... }` |
| `Stmt_For` / `Stmt_Foreach` | `parseFor()` / `parseForeach()` | C++ for/for-each 循环 |
| `Stmt_Class` | `parseClass()` | struct + 注册函数 |
| `Scalar_String` | `parseScalar()` | `php::String("...")` |

**2.3 类型推断**

编译器在翻译过程中进行类型推断（`detectTypeOfExpr()`），分析每个表达式的 C++ 类型。推断结果直接影响生成的代码：
- 若类型明确（如 `php::Int`），生成原生运算
- 若类型为 `php::Var`，生成 `zval` 动态运算

**2.4 编译缓存（Redo 机制）**

若翻译过程中遇到尚未声明的符号（如未解析的类常量），翻译器会抛出 `Redo` 异常，待依赖的文件处理完毕后重新执行转换。这确保所有符号引用在生成代码时已可用。

**2.5 生成产物**

每份 PHP 文件生成一个对应的 `.cc` 文件，放置在 `build/` 目录中，目录结构与源码目录保持一致。

#### 3. 编译 C++ 源码 — `compile()`

编译阶段调用平台 C++ 编译器（GCC / Clang / MSVC），将 `.cc` 源文件编译为 `.o` 目标文件。

```mermaid
flowchart-v2
    O1["所有 .cc 文件"]
    O2["生成扩展模块源文件<br>extension-{name}.cc"]
    O3["生成函数声明头文件"]
    O4["构建模式?"]
    O5["添加 main 入口文件<br>+ CLI 内置函数"]
    O6["跳过 main 入口"]
    O7["Windows?"]
    O8["编译资源文件<br>（图标/版本信息）"]
    O9["并行编译?"]
    O10["pcntl_fork 并行编译"]
    O11["顺序编译"]
    O12["分进程调用 GCC/Clang/MSVC"]
    O13["✅ 所有 .o 目标文件"]

    O1 --> O2 --> O3 --> O4
    O4 -->|bin| O5 --> O7
    O4 -->|ext| O6 --> O7
    O7 -->|是| O8 --> O9
    O7 -->|否| O9
    O9 -->|pcntl 可用且 -j > 1| O10 --> O12 --> O13
    O9 -->|否| O11 --> O12 --> O13
```

**详细步骤**

**3.1 生成扩展模块源文件**

编译器自动生成 `extension-{name}.cc`，包含：
- 所有 PHP 类的 `zend_class_entry` 注册
- 全局变量的 C++ 定义
- 函数/类入口映射表
- `get_module()` 函数（扩展模式）

**3.2 生成头文件**

生成两个辅助头文件：
- `php_{name}_func_decl.h`：所有函数的 C++ 前向声明
- `php_{name}_global_var_decl.h`：全局变量的 extern 声明

**3.3 平台特定处理**

| 平台 | 编译器 | 特殊处理 |
| --- | --- | --- |
| Linux | GCC / Clang | `-fPIC`（扩展模式），rpath 设置 |
| macOS | Clang / GCC | `-undefined dynamic_lookup`，rpath 设置 |
| Windows | MSVC / Clang-cl | 资源文件编译（`.rc` → `.res`），PDB 调试信息 |

**3.4 并行编译**

Unix/Linux/macOS 平台支持 `pcntl_fork` 多进程并行编译，通过 `-j` 参数控制并发数。编译进度在终端实时显示。若 `pcntl` 不可用或 `-j 1`，则回退到顺序编译。

**3.5 编译选项**

从 CLI 参数和 `project.yml` 合并编译选项：
- 优化级别（`-O0` ~ `-O3`）
- 调试符号（`-g`，debug 模式）
- Sanitizer（`-fsanitize=address` 等）
- C++ 标准版本（`-std=c++17`）
- 用户自定义 `cxx-flags`

#### 4. 链接 — `build()`

链接阶段将所有目标文件连接为最终的可执行文件或动态库。

```mermaid
flowchart-v2
    L1["所有 .o 目标文件"]
    L2["构建模式?"]
    L3["链接为可执行文件<br>ELF / PE / Mach-O"]
    L4["链接为共享库<br>.so / .dll"]
    L5["加入 phpx 静态库"]
    L6["加入 phpx 静态库<br>+ PHP 符号"]
    L7["Windows?"]
    L8["加入 .res 资源文件"]
    L9["执行链接命令"]
    L10["链接成功?"]
    L11["✅ 原生二进制产物"]
    L12["❌ 输出链接错误"]

    L1 --> L2
    L2 -->|bin| L3 --> L5 --> L7
    L2 -->|ext| L4 --> L6 --> L7
    L7 -->|是| L8 --> L9 --> L10
    L7 -->|否| L9 --> L10
    L10 -->|成功| L11
    L10 -->|失败| L12
```

**详细步骤**

**4.1 生成链接命令**

根据平台和构建模式，编译后端（`CompilerBackend`）生成对应的链接命令：

| 平台 | bin 模式链接参数 | ext 模式链接参数 |
| --- | --- | --- |
| Linux | `-Wl,-rpath,...` | `-shared -fPIC` |
| macOS | `-Wl,-rpath,...` | `-dynamiclib -undefined dynamic_lookup` |
| Windows | `-Wl,/SUBSYSTEM:CONSOLE` | `-shared` |

**4.2 最终产物**

| 构建模式 | Linux/macOS 产物 | Windows 产物 |
| --- | --- | --- |
| `bin` | 可执行文件（ELF/Mach-O） | `.exe`（PE） |
| `ext` | `.so` / `.dylib` | `.dll` |

### ZendVM vs AOT 编译器对比

```mermaid
flowchart-v2
    subgraph AOT [AOT 编译器（编译执行）]
        A1["PHP 源码"] --> A2["预处理<br>（符号扫描）"] --> A3["转换为 C++<br>（类型推断 + 代码生成）"] --> A4["GCC/Clang 编译<br>（优化 + 并行）"] --> A5["链接为原生二进制"] --> A6["⚡ 直接运行机器码"]
    end

    subgraph ZendVM [ZendVM（解释执行）]
        Z1["PHP 源码"] --> Z2["词法分析"] --> Z3["语法分析"] --> Z4["Opcode 编译"] --> Z5["Opcode 解释执行"]
        Z5 -.->|每次请求重复| Z2
    end
```

| 维度 | ZendVM | AOT 编译器 |
| --- | --- | --- |
| 翻译方式 | 每请求逐行解释 Opcode | 编译期一次性翻译为机器码 |
| 运行时依赖 | 需要 PHP 解释器 | 独立二进制，仅依赖 phpx 运行时库 |
| 类型系统 | 动态类型，zval 装箱 | 静态推断 + `use native_types` 原生 C++ 类型 |
| 函数调用 | `zend_call_function()` 动态分发 | 直接 C++ 函数调用（Native Call） |
| 动态特性 | 完整支持 `eval`、`call_user_func` 等 | 部分支持（受限的动态调用） |
| 性能 | 基准（1x） | 大幅提升（数十倍 ~ 百倍） |
| 启动速度 | 需要加载 PHP + 解析脚本 | 瞬时启动（预编译机器码） |

> **关键取舍**：AOT 编译后所有 PHP 代码从 Opcode 字节码转为机器指令，执行效率大幅增强。但编译后丧失了动态性——不允许运行时修改 Opcode 字节码或动态插入新的执行指令。`eval()` 和动态函数定义等特性在 AOT 模式下受限或不可用。

## 基础类型方法

`AOT` 编译器提供了一种在原生类型上使用对象方法调用的语法，称之为：通用方法（`Universal Methods`）。通用方法是零成本抽象的设计，仅在编译阶段工作，无运行时开销。

### 1. 什么是通用方法

通用方法（`Universal Methods`）允许你在 PHP 原生类型的变量上直接调用方法，就像在对象上调用一样。编译器在编译时将这些方法调用翻译为对应的 C 函数或 C++ 方法调用，**生成零开销的本地代码**。

```php
// 字符串上直接调用方法
$s = "hello world";
echo $s->length();          // → strlen($s) → 11
echo $s->upper();           // → strtoupper($s) → "HELLO WORLD"
echo $s->substr(0, 5);      // → substr($s, 0, 5) → "hello"

// 数组上直接调用方法
$arr = [1, 3, 5, 7, 9];
echo $arr->count();         // → count($arr) → 5
echo $arr->contains(3);     // → in_array(3, $arr) → true
$arr->push(11);             // → array_push($arr, 11)

// 整数上直接调用方法
$a = 100;
$a->add(50);                // → a += 50 → 150
echo $a->toString();        // → "150"

// 高精度类型上直接调用方法
$big = std::bigInt("12345678901234567890");
echo $big->mul(2)->toString();  // → "24691357802469135780"
```

> **关键理念**：所有通用方法调用在编译时全部消解为直接函数调用。不存在虚表查找、反射、或运行时类型检查——生成的 C++ 代码与手写 C 函数调用完全等价。

### 2. 设计原理

#### 2.1 编译期消解

每个通用方法调用在编译时经过以下步骤：
1. **类型推断**：编译器推断调用者（receiver）的类型（Int / Float / String / Array / Stream / BigInt / Decimal / BigFloat / Var）
2. **方法查找**：在该类型的方法表中查找方法定义
3. **参数校验**：检查参数个数是否在 `min_args` ~ `max_args` 范围内
4. **代码生成**：根据 handler 类型生成对应的 C/C++ 调用代码

### 3. 支持的类型一览

| 类型 | 方法数量 | 主要分类 |
| --- | --- | --- |
| **Int** | 26 | 算术、数学函数、类型转换 |
| **Float** | 26 | 算术、数学函数、三角函数、类型转换 |
| **Bool** | 2 | 类型转换 |
| **String** | 70+ | 字符串操作、搜索、编码、Hash、多字节、序列化 |
| **Array** | 50+ | 增删改查、排序、遍历、集合运算、序列化 |
| **Stream** | 30+ | 读写、定位、锁、Socket、过滤 |
| **BigInt** | 24 | 算术、比较、转换、GCD、位运算、位移、divmod、powmod、sqrt |
| **Decimal** | 18 | 算术、比较、转换、pow、divmod、powmod、sqrt、floor、ceil、round |
| **BigFloat** | 10 | 算术、比较、转换 |

> **类型转换方法**：`toInt()` / `toFloat()` / `toString()` / `toBool()` / `toArray()` / `toStream()` / `toBigInt()` / `toBigFloat()` / `toDecimal()` / `toObject()` / `toStd*` 等关键词方法不属于通用方法，它们是编译器内置的一等公民关键词，详见 [类型转换](#类型转换)。

### 4. Int 整型方法

#### 4.1 算数运算（返回 Int）

```php
$a = 100;

$a->add(50);        // $a + 50  → 返回 150
$a->sub(30);        // $a - 30  → 返回 70
$a->mul(2);         // $a * 2   → 返回 200
$a->div(4);         // $a / 4   → 返回 25
$a->mod(7);         // $a % 7   → 返回 2

$a->inc();          // $a + 1  → 返回 101
$a->dec();          // $a - 1  → 返回 99
```

```php
$a = 100;
$b = $a->add(50);  // $a 依然是 100，$b 是 150（返回新值，原值不变）
```

#### 4.2 数学函数

```php
$a = -100;

$a->abs();          // abs($a)         → 100
$a->ceil();         // ceil($a)        → -100.0 (float)
$a->floor();        // floor($a)       → -100.0 (float)
$a->round();        // round($a)       → -100.0 (float)
$a->sqrt();         // sqrt($a)        → NaN（负数无平方根）
$a->pow(3);         // $a ** 3         → -1000000
$a->log();          // log($a)         → NaN
$a->log10();        // log10($a)       → NaN
$a->exp();          // exp($a)         → 3.72e-44 (float)

$a->max(50);        // max($a, 50)     → 50
$a->min(50);        // min($a, 50)     → -100
```

> **注意**：`ceil`、`floor`、`round` 返回 **Float 类型**（PHP 标准行为）。`pow` 返回 **Var 类型**（因为幂运算结果可能溢出）。

#### 4.3 三角函数

```php
$a = 0;

$a->sin();          // sin(0)     → 0.0
$a->cos();          // cos(0)     → 1.0
$a->tan();          // tan(0)     → 0.0
$a->asin();         // asin(0)    → 0.0
$a->acos();         // acos(1)    → 0.0
$a->atan();         // atan(0)    → 0.0
$a->atan2(1);       // atan2(0,1) → 0.0
$a->deg2rad();      // deg2rad(0) → 0.0
$a->rad2deg();      // rad2deg(0) → 0.0
```

#### 4.4 类型转换

```php
$a = 42;

$a->toFloat();      // (float) $a  → 42.0
$a->toString();     // (string) $a → "42"
$a->toBool();       // (bool) $a   → true
```

#### 4.5 完整方法列表

| 方法 | 参数 | 返回类型 | 说明 |
| --- | --- | --- | --- |
| `add($x)` | 1 | Int | 加法 |
| `sub($x)` | 1 | Int | 减法 |
| `mul($x)` | 1 | Int | 乘法 |
| `div($x)` | 1 | Int | 除法 |
| `mod($x)` | 1 | Int | 取模 |
| `inc()` | 0 | Int | 自增（返回 $a + 1） |
| `dec()` | 0 | Int | 自减（返回 $a - 1） |
| `abs()` | 0 | Int | 绝对值 |
| `ceil()` | 0 | Float | 向上取整 |
| `floor()` | 0 | Float | 向下取整 |
| `round()` | 0-2 | Float | 四舍五入 |
| `sqrt()` | 0 | Float | 平方根 |
| `pow($x)` | 1 | Var | 幂运算 |
| `log()` | 0-1 | Float | 自然对数 |
| `log10()` | 0 | Float | 以 10 为底的对数 |
| `exp()` | 0 | Float | e 的指数 |
| `sin()` | 0 | Float | 正弦 |
| `cos()` | 0 | Float | 余弦 |
| `tan()` | 0 | Float | 正切 |
| `asin()` | 0 | Float | 反正弦 |
| `acos()` | 0 | Float | 反余弦 |
| `atan()` | 0 | Float | 反正切 |
| `atan2($x)` | 1 | Float | 二参数反正切 |
| `deg2rad()` | 0 | Float | 角度转弧度 |
| `rad2deg()` | 0 | Float | 弧度转角度 |
| `max($x)` | 1 | Int/Float | 取最大值 |
| `min($x)` | 1 | Int/Float | 取最小值 |
| `toFloat()` | 0 | Float | 转浮点 |
| `toString()` | 0 | String | 转字符串 |
| `toBool()` | 0 | Bool | 转布尔 |

### 5. Float 浮点型方法

Float 的方法集与 Int 几乎完全相同，但返回类型为 Float（`ceil`/`floor`/`round`/三角函数等仍为 Float）。

```php
$f = 3.14;

$f->add(1.0);       // $f + 1.0  → 返回 4.14
$f->sub(1.0);       // $f - 1.0  → 返回 2.14
$f->mul(2.0);       // $f * 2.0  → 返回 6.28
$f->div(2.0);       // $f / 2.0  → 返回 1.57

$f->abs();          // abs(3.14) → 3.14
$f->sqrt();         // sqrt(3.14) → 1.772...
$f->sin();          // sin(3.14)  → 0.00159...
$f->round(2);       // round(3.14, 2) → 3.14

// Float 特有的转换
$f->toInt();        // (int) $f   → 3
$f->toString();     // (string) $f → "3.14"
$f->toBool();       // (bool) $f  → true
```

Float 与 Int 方法的主要区别：
- Int 有 `mod($x)`，Float 没有（浮点取模无意义）
- 算术方法直接操作 C++ `double`，性能与手写 C 代码一致

### 6. Bool 布尔型方法

Bool 类型仅有两个转换方法：

```php
$b = true;

$b->toInt();        // (int) $b   → 1
$b->toString();     // (string) $b → "1"
```

### 7. String 字符串方法

String 拥有最丰富的方法集，涵盖日常开发的大部分字符串操作需求。

#### 7.1 基本操作

```php
$s = "hello world";

$s->length();           // strlen($s)         → 11
$s->isEmpty();          // empty($s)          → false
$s->upper();            // strtoupper($s)     → "HELLO WORLD"
$s->lower();            // strtolower($s)     → "hello world"
$s->upperFirst();       // ucfirst($s)        → "Hello world"
$s->lowerFirst();       // lcfirst($s)        → "hello world"
$s->upperWords();       // ucwords($s)        → "Hello World"

$s->trim();             // trim($s)           → "hello world"
$s->lTrim();            // ltrim($s)          → "hello world"
$s->rTrim();            // rtrim($s)          → "hello world"
$s->trim(" \t\n\r");    // trim($s, chars)
```

#### 7.2 搜索与比较

```php
$s = "hello world";

// 判断
$s->startsWith("hello");    // str_starts_with(...)    → true
$s->endsWith("world");      // str_ends_with(...)      → true
$s->contains("lo wo");      // str_contains(...)       → true
$s->compare("hello");       // strcmp(...)             → >0
$s->iCompare("HELLO");      // strcasecmp(...)         → 0
$s->isNumeric();            // is_numeric(...)         → false

// 位置查找
$s->indexOf("world");       // strpos($s, "world")     → 6
$s->lastIndexOf("o");       // strrpos($s, "o")        → 7
$s->iIndexOf("WORLD");      // stripos($s, "WORLD")    → 6
$s->iLastIndexOf("O");      // strripos($s, "O")       → 7

// 内容查找
$s->find("world");          // strstr($s, "world")     → "world"
$s->iFind("WORLD");         // stristr($s, "WORLD")    → "world"
$s->lastCharIndexOf("o");   // strrchr($s, "o")        → "orld"
```

#### 7.3 截取与替换

```php
$s = "hello world";

// 截取
$s->substr(0, 5);           // substr($s, 0, 5)        → "hello"
$s->substr(6);              // substr($s, 6)           → "world"

// 统计
$s->substrCount("l");       // substr_count($s, "l")   → 3
$s->wordCount();            // str_word_count($s)      → 2

// 替换
$s->replace("hello", "hi");        // str_replace("hello", "hi", $s)
$s->iReplace("HELLO", "hi");       // str_ireplace(...)
$s->substrReplace("hi", 0, 5);     // substr_replace($s, "hi", 0, 5)
$s->stripTags();                   // strip_tags($s)
$s->stripTags("<br><p>");          // strip_tags($s, tags)
```

#### 7.4 拆分与连接

```php
$s = "hello world";

// 拆分
$words = $s->split(" ");            // explode(" ", $s) → ["hello", "world"]
$words->count();                    // 2

// 连接（在数组上操作）
$words->join(", ");                 // implode(", ", $words) → "hello, world"

// 重复
$s->repeat(3);                      // str_repeat($s, 3) → "hello worldhello worldhello world"

// 填充
$s->pad(20, "-");                   // str_pad($s, 20, "-")
```

#### 7.5 编码与转义

```php
$s = "hello world & <test>";

$s->htmlEntityEncode();             // htmlentities($s)
$s->htmlEntityDecode();             // html_entity_decode($s)
$s->htmlSpecialCharsEncode();       // htmlspecialchars($s)
$s->htmlSpecialCharsDecode();       // htmlspecialchars_decode($s)

$s->urlEncode();                    // urlencode($s)       → "hello+world+%26+%3Ctest%3E"
$s->urlDecode();                    // urldecode($s)
$s->rawUrlEncode();                 // rawurlencode($s)
$s->rawUrlDecode();                 // rawurldecode($s)

$s->addSlashes();                   // addslashes($s)
$s->stripSlashes();                 // stripslashes($s)
$s->addCSlashes("A..z");            // addcslashes($s, "A..z")
$s->stripCSlashes();                // stripcslashes($s)

$s->base64Encode();                 // base64_encode($s)
$s->base64Decode();                 // base64_decode($s)
```

#### 7.6 Hash 与校验

```php
$s = "hello";

$s->md5();          // md5($s)       → "5d41402abc4b2a76b9719d911017c592"
$s->sha1();         // sha1($s)      → "aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d"
$s->crc32();        // crc32($s)     → 907060870
$s->hash("sha256"); // hash("sha256", $s) → "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824"
$s->hashCode();     // C++ std::hash → 哈希值 (int)
```

#### 7.7 正则匹配

```php
$s = "hello world";

// match(pattern) → 返回匹配数组
$result = $s->match("/hello/");

// matchAll(pattern) → 返回所有匹配结果
$results = $s->matchAll("/[a-z]+/");
```

#### 7.8 序列化

```php
$s = '{"name":"John","age":30}';

$data = $s->jsonDecode();           // json_decode($s, true)  → ["name" => "John", ...]
$obj = $s->jsonDecodeToObject();    // json_decode($s)        → stdClass

$s = 'a:3:{i:0;s:3:"foo";i:1;s:3:"bar";i:2;s:3:"baz";}';
$arr = $s->unserialize();           // unserialize($s)        → ["foo", "bar", "baz"]
```

#### 7.9 多字节字符串（mbstring）

以 `mb` 前缀开头的方法对应 PHP 的 `mb_*` 函数族：

```php
$s = "你好世界";

$s->mbLength();                     // mb_strlen($s)          → 4
$s->mbUpper();                      // mb_strtoupper($s)
$s->mbLower();                      // mb_strtolower($s)
$s->mbSubstr(0, 2);                 // mb_substr($s, 0, 2)    → "你好"
$s->mbIndexOf("世界");              // mb_strpos($s, "世界")  → 2
$s->mbFind("世");                   // mb_strstr($s, "世")
$s->mbDetectEncoding();             // mb_detect_encoding($s)
$s->mbConvertEncoding("UTF-8");     // mb_convert_encoding($s, "UTF-8")
$s->mbConvertCase(MB_CASE_TITLE);   // mb_convert_case($s, MB_CASE_TITLE)
$s->mbTrim();                       // mb_trim($s)
$s->mbLTrim();                      // mb_ltrim($s)
$s->mbRTrim();                      // mb_rtrim($s)
```

#### 7.10 C++ 原生方法

以下方法直接调用 `phpx::Variant` 的 `C++` 成员函数，无 `PHP` 函数对应：

```php
$s = "hello";

$s->equals("hello");        // C++ String.equals() —— 值比较
```

### 8. Array 数组方法

Array 方法分为**只读方法**和**变异方法**两类。变异方法会直接修改原数组变量。

#### 8.1 基本信息

```php
$arr = [1, 3, 5, 7, 9];

$arr->count();          // count($arr)         → 5
$arr->isEmpty();        // empty($arr)         → false
$arr->isList();         // array_is_list($arr) → true
```

#### 8.2 增删改查

```php
$arr = [1, 2, 3];

// 变异方法（修改原数组）
$arr->push(4);              // array_push($arr, 4)          → [1,2,3,4]
$arr->push(5, 6, 7);        // 支持多个参数
$arr->pop();                // array_pop($arr)              → 返回 7
$arr->shift();              // array_shift($arr)            → 返回 1
$arr->unshift(0);           // array_unshift($arr, 0)       → [0,...]
$arr->set(0, 100);          // C++ Array.set(0, 100)        → [100,...]
$arr->del(0);               // C++ Array.del(0)             → 删除索引 0
$arr->clean();              // C++ Array.clean()            → []

// 只读方法
$arr = ['a' => 1, 'b' => 2, 'c' => 3];
$arr->get('a');             // C++ Array.get('a')           → 1
$arr->keyExists('a');       // array_key_exists('a', $arr)  → true
$arr->contains(2);          // in_array(2, $arr)            → true
$arr->search(2);            // array_search(2, $arr)        → "b"
```

#### 8.3 遍历与聚合

```php
$arr = [1, 3, 5, 7, 9];

$arr->sum();                // array_sum($arr)       → 25
$arr->product();            // array_product($arr)   → 945
$arr->all(fn($v) => ...);   // array_all($arr, fn)
$arr->any(fn($v) => ...);   // array_any($arr, fn)

$arr->map(fn($v) => $v * 2);// array_map(fn, $arr)   → [2,6,10,14,18]
$arr->reduce(fn($c, $v) => $c + $v, 0);  // array_reduce($arr, fn, 0)
$arr->filter(fn($v) => $v > 5);          // array_filter($arr, fn)
$arr->walk(fn(&$v) => $v *= 2);          // array_walk($arr, fn)
```

#### 8.4 排序

```php
$arr = [3, 1, 4, 1, 5, 9];

$arr->sort();               // sort($arr)            → [1,1,3,4,5,9]
$arr->sortDesc();           // rsort($arr)           → [9,5,4,3,1,1]
$arr->keySort();            // ksort($arr)           → 按键排序
$arr->valueSort();          // asort($arr)           → 按值排序（保持键）
```

所有排序方法都会**修改原数组**。

#### 8.5 集合运算

```php
$a = [1, 2, 3, 4, 5];
$b = [4, 5, 6, 7, 8];

$a->diff($b);               // array_diff($a, $b)   → [1,2,3]
$a->intersect($b);          // array_intersect(...)  → [4,5]
$a->merge($b);              // array_merge($a, $b)   → [1,2,3,4,5,4,5,6,7,8]
$a->unique();               // array_unique($a)      → [1,2,3,4,5]
$a->flip();                 // array_flip($a)        → {1:0, 2:1, 3:2, ...}
$a->reverse();              // array_reverse($a)     → [5,4,3,2,1]
$a->replace($b);            // array_replace($a, $b)
$a->values();               // array_values($a)      → 重索引
$a->combine($keys);         // array_combine($keys, $a)
$a->fillKeys($value);       // array_fill_keys($a, $value)
```

> `diff`、`intersect`、`merge`、`replace` 等方法支持**可变参数**：`$a->merge($b, $c, $d)` 可以一次合并多个数组。

#### 8.6 提取与切片

```php
$arr = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5];

$arr->keys();               // array_keys($arr)         → ['a','b','c','d','e']
$arr->slice(1, 3);          // array_slice($arr, 1, 3) → ['b'=>2, 'c'=>3, 'd'=>4]
$arr->chunk(2);             // array_chunk($arr, 2)    → [[1,2],[3,4],[5]]
$arr->column('name');       // array_column($arr, 'name')
$arr->splice(1, 3, [6,7]);  // array_splice($arr, 1, 3, [6,7]) —— 修改原数组
$arr->rand(2);              // array_rand($arr, 2)

$arr->keyFirst();           // array_key_first($arr) → "a"
$arr->keyLast();            // array_key_last($arr)  → "e"
$arr->find(fn($v) => $v > 3);  // array_find($arr, fn)
```

#### 8.7 字符串相关

```php
$arr = ["hello", "world"];

$arr->join(", ");           // implode(", ", $arr) → "hello, world"
$arr->replaceStr("hello", "hi");    // str_replace("hello", "hi", $arr)
$arr->iReplaceStr("HELLO", "hi");   // str_ireplace(...)
```

#### 8.8 序列化

```php
$arr = ["name" => "John", "age" => 30];

$arr->serialize();          // serialize($arr)      → "a:2:{...}"
$arr->marshal();            // serialize($arr) —— 别名
$arr->jsonEncode();         // json_encode($arr)    → '{"name":"John","age":30}'
```

#### 8.9 类型转换

```php
$arr = [1, 2, 3];

$arr->toInt();              // (int) 非空数组 → 1
$arr->toFloat();            // (float) 非空数组 → 1.0
$arr->toBool();             // (bool) 非空数组 → true
$arr->toString();           // → "Array"
```

#### 8.10 完整方法分类表

| 分类 | 方法 |
| --- | --- |
| 基本信息 | `count`, `isEmpty`, `isList` |
| 增删改查 | `push`, `pop`, `shift`, `unshift`, `set`, `get`, `del`, `clean`, `keyExists`, `contains`, `search` |
| 遍历聚合 | `sum`, `product`, `all`, `any`, `map`, `reduce`, `filter`, `walk` |
| 排序 | `sort`, `sortDesc`, `keySort`, `valueSort` |
| 集合运算 | `diff`, `diffAssoc`, `diffKey`, `intersect`, `intersectAssoc`, `merge`, `unique`, `flip`, `reverse`, `replace`, `values`, `combine`, `fillKeys` |
| 提取切片 | `keys`, `slice`, `chunk`, `column`, `splice`, `rand`, `keyFirst`, `keyLast`, `find` |
| 字符串 | `join`, `replaceStr`, `iReplaceStr`, `countValues`, `pad` |
| 序列化 | `serialize`, `marshal`, `jsonEncode` |
| 类型转换 | `toInt`, `toFloat`, `toBool`, `toString` |

### 9. Stream 流方法

Stream 类型表示文件句柄或网络连接。通过 `fopen()` 等函数获取。

#### 9.1 读写

```php
$fp = fopen("test.txt", "w+");

$fp->write("hello world\n");      // fwrite($fp, "hello world\n") → 写入字节数
$fp->write("more data", 4);       // fwrite($fp, "more data", 4)  → 只写 4 字节

$fp->seek(0);                     // fseek($fp, 0)   → 回到开头
$content = $fp->read(1024);       // fread($fp, 1024)
$content = $fp->getContents();    // stream_get_contents($fp)

$char = $fp->getChar();           // fgetc($fp)      → 读取一个字符
$line = $fp->getLine();           // fgets($fp)      → 读取一行
$line = $fp->getLine(1024);       // fgets($fp, 1024)
$line = $fp->getRecord(1024, "\n"); // stream_get_line($fp, 1024, "\n")
```

#### 9.2 元数据与状态

```php
$fp->tell();                // ftell($fp)           → 当前位置
$fp->eof();                 // feof($fp)            → 是否 EOF
$fp->stat();                // fstat($fp)           → 文件状态数组
$fp->getMetaData();         // stream_get_meta_data($fp)
$fp->isLocal();             // stream_is_local($fp)
$fp->isTTY();               // stream_isatty($fp)
```

#### 9.3 控制操作

```php
$fp->truncate(0);           // ftruncate($fp, 0)    → 截断文件
$fp->sync();                // fsync($fp)           → 同步到磁盘
$fp->dataSync();            // fdatasync($fp)       → 同步数据
$fp->close();               // fclose($fp)          → 关闭

// 锁
$fp->lock(LOCK_EX);         // flock($fp, LOCK_EX)
$fp->lock(LOCK_SH);         // flock($fp, LOCK_SH)
$fp->lock(LOCK_UN);         // flock($fp, LOCK_UN)

// 缓冲设置
$fp->setBlocking(true);     // stream_set_blocking($fp, true)
$fp->setChunkSize(8192);    // stream_set_chunk_size($fp, 8192)
$fp->setReadBuffer(8192);   // stream_set_read_buffer($fp, 8192)
$fp->setWriteBuffer(8192);  // stream_set_write_buffer($fp, 8192)
$fp->setTimeout(30);        // stream_set_timeout($fp, 30)
$fp->supportsLock();        // stream_supports_lock($fp)
```

#### 9.4 Socket 操作

```php
// 服务端
$server = stream_socket_server("tcp://0.0.0.0:8080");
$client = $server->accept();             // stream_socket_accept($server)
$client->accept(30);                     // 超时 30 秒

// 信息
$client->getSocketName(true);            // stream_socket_get_name —— 远程地址
$server->getSocketName(false);           // stream_socket_get_name —— 本地地址

// 数据
$client->sendTo("hello", 0, $addr);      // stream_socket_sendto(...)
$client->recvFrom(1024);                 // stream_socket_recvfrom(...)
$client->recvFrom(1024, 0, $addr);       // 带地址

// 控制
$client->enableCrypto(true);             // stream_socket_enable_crypto → 启用 TLS
$client->shutdown(STREAM_SHUT_RDWR);     // stream_socket_shutdown(...)

// 过滤器
$fp->appendFilter("string.toupper");     // stream_filter_append(...)
$fp->prependFilter("string.tolower");    // stream_filter_prepend(...)
```

#### 9.5 流拷贝

```php
$src = fopen("source.txt", "r");
$dst = fopen("dest.txt", "w");

$src->copy($dst);                    // stream_copy_to_stream($src, $dst)
$src->copy($dst, 4096);              // 指定缓冲区大小
```

### 10. Big* 高精度类型方法

#### 10.1 BigInt 方法

```php
$a = std::bigInt("12345678901234567890");

// 算术（均返回新 BigInt，原值不变）
$b = $a->add(1);        // $a + 1
$c = $a->sub(1);        // $a - 1
$d = $a->mul(2);        // $a * 2
$e = $a->div(10);       // $a / 10
$f = $a->mod(1000000);  // $a % 1000000
$g = $a->pow(3);        // $a ** 3

// 一元方法
$h = $a->neg();         // -$a
$i = $a->abs();         // abs($a)

// 特殊方法
$j = $a->gcd(15);       // gcd($a, 15)
$k = $a->divmod(3);     // 商和余数：返回 [$q, $r]
$l = $a->powmod(5, 97); // 模幂：($a ** 5) % 97
$m = $a->sqrt();        // 平方根（截断取整）

// 位运算方法
$n = $a->bitAnd(0xFF);   // 按位与：$a & 0xFF
$o = $a->bitOr(0xFF);    // 按位或：$a | 0xFF
$p = $a->bitXor(0xFF);   // 按位异或：$a ^ 0xFF
$q = $a->bitNot();       // 按位取反：~$a
$r = $a->testBit(3);         // 测试第 3 位是否为 1
$s = $a->popCount();         // 二进制中 1 的个数
$t = $a->bitShiftLeft(3);    // 左移：$a << 3
$u = $a->bitShiftRight(2);   // 右移：$a >> 2

// 比较
$cmp = $a->cmp(100);    // -1/0/1

// 类型转换
$a->toString();         // → "12345678901234567890"
$a->toInt();            // → int（可能截断）
$a->toFloat();          // → float（可能丢精度）
```

| 方法 | 参数 | 返回 | 说明 |
| --- | --- | --- | --- |
| `add($x)` | 1 | BigInt | 加法 |
| `sub($x)` | 1 | BigInt | 减法 |
| `mul($x)` | 1 | BigInt | 乘法 |
| `div($x)` | 1 | BigInt | 整数除法 |
| `mod($x)` | 1 | BigInt | 取模 |
| `pow($x)` | 1 | BigInt | 幂运算 |
| `neg()` | 0 | BigInt | 取负 |
| `abs()` | 0 | BigInt | 绝对值 |
| `gcd($x)` | 1 | BigInt | 最大公约数 |
| `divmod($x)` | 1 | Array | 商和余数 |
| `powmod($exp, $mod)` | 2 | BigInt | 模幂运算 |
| `sqrt()` | 0 | BigInt | 平方根（截断） |
| `bitAnd($x)` | 1 | BigInt | 按位与 |
| `bitOr($x)` | 1 | BigInt | 按位或 |
| `bitXor($x)` | 1 | BigInt | 按位异或 |
| `bitNot()` | 0 | BigInt | 按位取反 |
| `testBit($index)` | 1 | Int | 测试某位是否为 1 |
| `popCount()` | 0 | Int | 二进制 1 的个数 |
| `bitShiftLeft($n)` | 1 | BigInt | 左移 `$a << $n` |
| `bitShiftRight($n)` | 1 | BigInt | 右移 `$a >> $n` |
| `cmp($x)` | 1 | Int | 比较 |
| `toString()` | 0 | String | 转字符串 |
| `toInt()` | 0 | Int | 转整数 |
| `toFloat()` | 0 | Float | 转浮点 |

#### 10.2 Decimal 方法

```php
$d = std::decimal("123.456");

$d->add(std::decimal("50.25"));   // 加法
$d->sub(std::decimal("50.25"));   // 减法
$d->mul(2);                       // 乘法
$d->div(3);                       // 除法
$d->mod(std::decimal("5.0"));     // 取模
$d->pow(2);                       // 幂运算：$d ** 2
$d->neg();                        // 取负
$d->abs();                        // 绝对值
$d->divmod(std::decimal("10"));   // 商和余数：返回 [$q, $r]
$d->powmod(3, std::decimal("100")); // 模幂：($d**3) % 100
$d->sqrt();                       // 平方根
$d->floor();                      // 向下取整
$d->ceil();                       // 向上取整
$d->round();                      // 四舍五入到整数
$d->round(2);                     // 保留 2 位小数
$d->cmp(std::decimal("100"));     // 比较
$d->toString();                   // → "123.456"
$d->toInt();                      // → 123
$d->toFloat();                    // → 123.456 (double)
```

#### 10.3 BigFloat 方法

```php
$bf = std::bigFloat(3.14159265);

$bf->add(1.0);          // 加法
$bf->sub(1.0);          // 减法
$bf->mul(2.0);          // 乘法
$bf->div(2.0);          // 除法
$bf->neg();             // 取负
$bf->abs();             // 绝对值
$bf->cmp(3.0);          // 比较 → >0
$bf->toString();        // 转字符串
$bf->toInt();           // → 3
$bf->toFloat();         // → 3.14159265
```

> **不可变性**：Big* 类型的所有方法都**返回新值**，不修改原变量。Int、Float、String 等方法同样不可变。只有 Array 的变异方法会修改原数组。参见 [第 12 节](#12-可变方法-vs-不可变方法)。

### 11. 方法链式调用

通用方法的返回值类型是编译时已知的，因此可以直接链式调用：

```php
// 字符串链式调用
$result = "  Hello World!  "
    ->trim()
    ->lower()
    ->substr(0, 5)
    ->upper();
echo $result;  // "HELLO"

// Int 链式调用
$result = 100
    ->add(50)     // 150
    ->mul(3)      // 450
    ->sub(100)    // 350
    ->toString();
echo $result;  // "350"

// BigInt 链式调用（不可变，返回新值）
$result = std::bigInt("100")
    ->add(std::bigInt(50))
    ->mul(std::bigInt(3))
    ->toString();
echo $result;  // "450"

// 跨类型链式调用
$sum = "123456789012345678901234567890"
    ->length();     // String.length() → Int
echo $sum;  // 30

// 链式调用 + 最终转换
$result = std::bigInt("99999999999999999999")
    ->add(std::bigInt(1))
    ->toString();
echo "100000000000000000000 = " . $result;
```

> **注意**：链式调用的中间值是通过返回值传递的，原变量始终不变。由于所有类型的方法（除 Array 的变异方法外）都不修改原值，链式调用的每一步操作的都是上一步的返回值。

### 12. 可变方法 vs 不可变方法

不同类型的通用方法在可变性上有不同行为：

#### 12.1 可变方法（修改原值）

**Array** 的变异方法可能会**修改原数组**：

```php
$arr = [1, 2, 3];

$arr->push(4);      // 修改 $arr → [1,2,3,4]
$arr->pop();        // 修改 $arr → [1,2,3]
$arr->sort();       // 修改 $arr → [1,2,3]
$arr->set(0, 100);  // 修改 $arr → [100,2,3]
$arr->clean();      // 修改 $arr → []
```

#### 12.2 不可变方法（返回新值）

除 Array 的变异方法外，所有类型的方法都是不可变的，只会返回新值，**不修改原值**。

**Int 和 Float**：

```php
$a = 100;
$b = $a->add(50);
echo $a;   // 100（原值不变）
echo $b;   // 150
```

**BigInt、Decimal、BigFloat** 的所有方法都**不修改原值**，返回新创建的对象：

```php
$a = std::bigInt(100);
$b = $a->add(50);   // $a 依然是 100，$b 是 150

$a = std::bigInt(100);
$a->add(50);        // 返回值被丢弃！$a 依然是 100
```

**String** 的所有方法，也都是返回新值，原有字符串不变：

```php
$s = "hello";
$upper = $s->upper();  // $s 依然是 "hello"，$upper 是 "HELLO"
```

#### 12.3 可变方法汇总

| Handler 类型 | 影响类型 | 示例 |
| --- | --- | --- |
| `direct_method_mutate` | Array | `append`, `set`, `del`, `clean` |
| `php_fn_ref` | Array | `push`, `pop`, `shift`, `unshift`, `sort`, `sortDesc`, `splice`, `walk` |

### 13. Var 类型的方法查找

当变量的类型为 `Var`（通用 PHP 类型）时，编译器会按以下顺序依次在方法表中查找：

```
String → Array → Int → Float → Bool → Stream → BigInt → Decimal → BigFloat
```

一旦找到匹配的方法名，就生成对应类型的调用代码。如果找不到，则退化为动态方法调用（通过 ZendVM）。

```php
// $x 的类型是 Var（来自函数返回值、数组提取等）
$x = some_func_returning_var();

// 编译器按顺序查找：
// 1. 先在 String 方法表中找 length → 找到！→ strlen($x)
echo $x->length();

// 2. 先在 String 方法表中找 contains → 找到！→ str_contains($x, ...)
echo $x->contains("test");
```

> **注意**：如果多个类型有同名方法，先匹配到的类型优先。例如 `toInt` 在多个类型中都存在——查找会停在第一个匹配的类型上。

### 14. 扩展方法：自动发现

除了内置的通用方法外，编译器还支持**自定义扩展方法**。只要在当前项目中定义了命名符合约定的 PHP 函数，编译器就会自动将其发现为通用方法。

#### 14.1 命名约定

格式：`{类型前缀}_{snake_case方法名}`

| 类型 | 前缀 | 示例 |
| --- | --- | --- |
| Int | `int_` | `int_is_prime` → `$a->isPrime()` |
| Float | `float_` | `float_normalize` → `$f->normalize()` |
| Bool | `bool_` | `bool_toggle` → `$b->toggle()` |
| String | `str_` | `str_capitalize` → `$s->capitalize()` |
| Array | `array_` | `array_flatten` → `$arr->flatten()` |
| Stream | `stream_` | `stream_rewind` → `$fp->rewind()` |
| BigInt | `bigint_` | `bigint_is_probable_prime` → `$a->isProbablePrime()` |
| Decimal | `decimal_` | `decimal_round_to` → `$d->roundTo()` |
| BigFloat | `bigfloat_` | `bigfloat_truncate` → `$bf->truncate()` |

#### 14.2 实现示例

```php
<?php
declare(strict_types=1);
use native_types;

/**
 * 扩展方法：判断 Int 是否为素数
 * 命名：类型前缀 int_ + snake_case 方法名 is_prime
 */
function int_is_prime(int $n): bool {
    if ($n < 2) return false;
    for ($i = 2; $i * $i <= $n; $i++) {
        if ($n % $i == 0) return false;
    }
    return true;
}

/**
 * 扩展方法：数组扁平化
 * 命名：类型前缀 array_ + snake_case 方法名 flatten
 */
function array_flatten(array $arr): array {
    $result = [];
    array_walk_recursive($arr, function($v) use (&$result) {
        $result[] = $v;
    });
    return $result;
}

function main(): void {
    // 自动发现：int_is_prime → $n->isPrime()
    $n = 97;
    if ($n->isPrime()) {
        echo "$n is prime\n";
    }

    // 自动发现：array_flatten → $arr->flatten()
    $nested = [[1, 2], [3, [4, 5]], 6];
    $flat = $nested->flatten();
    var_dump($flat);  // [1, 2, 3, 4, 5, 6]
}
?>
```

#### 14.3 注意事项

- 函数**第一个参数**是接收者（`receiver`），从方法调用中自动传入，参数类型必须与扩展方法对应的类型一致，例如：`array_flatten`扩展方法，第一个参数的类型比如是`array`
- 需要先定义函数再调用——编译器在分析阶段发现函数，转换阶段使用它们
- 方法的驼峰命名会**自动转为下划线命名**：`isPrime` → `is_prime`，`flattenNestedArray` → `flatten_nested_array`

### 15. 完整示例

#### 15.1 字符串处理管道

```php
<?php
declare(strict_types=1);
use native_types;

function main(): void {
    $raw = "  <h1>Hello World!</h1>  \n";

    $processed = $raw
        ->trim()                        // 去头尾空白
        ->stripTags()                   // 去 HTML 标签
        ->lower()                       // 转小写
        ->upperWords();                 // 首字母大写

    echo "原始: " . $raw->jsonEncode() . "\n";
    echo "处理: " . $processed . "\n";
    echo "长度: " . $processed->length() . "\n";
    echo "是否为数字: " . (int)$processed->isNumeric() . "\n";
}
?>
```

#### 15.2 数组数据处理

```php
<?php
declare(strict_types=1);
use native_types;

function main(): void {
    $data = [5, 2, 8, 1, 9, 3, 7];

    echo "原始: " . $data->join(", ") . "\n";

    // 排序
    $data->sort();
    echo "排序: " . $data->join(", ") . "\n";

    // 统计
    echo "数量: " . $data->count() . "\n";
    echo "求和: " . $data->sum() . "\n";
    echo "最小值: " . $data->get(0) . "\n";
    echo "最大值: " . $data->get($data->count() - 1) . "\n";
    echo "是否包含 5: " . (int)$data->contains(5) . "\n";

    // 过滤与映射
    $even = $data->filter(function($v) { return $v % 2 == 0; });
    echo "偶数: " . $even->values()->join(", ") . "\n";

    $doubled = $data->map(function($v) { return $v * 2; });
    echo "翻倍: " . $doubled->join(", ") . "\n";

    // 序列化
    echo "JSON: " . $data->jsonEncode() . "\n";
}
?>
```

#### 15.3 高精度计算与链式调用

```php
<?php
declare(strict_types=1);
use native_types;

function main(): void {
    // 大整数阶乘（使用复合赋值，更简洁）
    $n = 50;
    $result = std::bigInt(1);
    for ($i = 2; $i <= $n; $i++) {
        $result *= $i;
    }
    $digits = strlen($result->toString());
    echo "{$n}! 有 {$digits} 位数字\n";

    // 方法链：BigInt 算术 + 转换
    $big = std::bigInt(1000);
    $val = $big->mul(3)->add(200)->sub(50)->div(10)->toString();
    echo "1000 * 3 + 200 - 50 / 10 = {$val}\n";

    // Decimal 金融计算
    $price = std::decimal("19.99");
    $qty = 5;
    $taxRate = std::decimal("0.08");
    $total = $price * $qty * ($taxRate->add(std::decimal(1)));
    echo "总价: " . $total->toString() . "\n";

    // BigFloat 科学计算
    $pi = std::bigFloat("3.14159265358979323846");
    $area = $pi * 100;
    echo "圆面积: " . $area->toString() . "\n";
    echo "取整: " . $area->toInt() . "\n";
}
?>
```

#### 15.4 文件处理

```php
<?php
declare(strict_types=1);
use native_types;

function main(): void {
    // 写入文件
    $fp = fopen("data.txt", "w+");
    $fp->write("Line 1\n");
    $fp->write("Line 2\n");
    $fp->write("Line 3\n");

    // 回到开头并读取
    $fp->seek(0);
    while (!$fp->eof()) {
        $line = $fp->getLine();
        if ($line !== false) {
            echo $line->trim();
            echo " (长度: " . $line->trim()->length() . ")\n";
        }
    }

    $fp->close();
}
?>
```

## 最佳实践

### 1. 框架和类库是否编译

`vendor` 目录中的框架和类库，建议仍然使用 `Composer Autoload` 加载不编译。或者使用白名单配置的方式，单独对部分`vendor`子目录进行编译。

若`vendor`子目录中的个别`PHP`文件不支持静态编译，还可以配置`ignore`忽略部分文件。

```yaml
name: thinkphp
type: ext
version: 0.0.1
cxxflags: |
  -std=c++14
  -Wall
sources:
  - ./src/think
  - ./vendor/topthink/think-helper/src
  - ./vendor/topthink/think-orm/src
  - ./vendor/topthink/think-container/src
ignore:
  - ./vendor/topthink/think-helper/src/functions.php
```

### 2. 如何打包分发

`AOT`编译器与`Golang`这样的静态编译方式不同，为了减少文件尺寸，采用了动态链接的方式，因此需要依赖操作系统的`.so`库。因此编译生成的二进制文件部署分发需要遵循下列规则：

1. 操作系统必须一致，安装的基础库列表需要保持一致，可使用`apt/yum/dnf`等操作系统包管理工具自动完成
2. 在编译机构建`PHP`和`PHPX`，产生`libphp.so`和`libphpx.so`，保存至包中
3. 所有`PHP`扩展均使用静态编译的方式，直接编译到`PHP`中，而不是动态加载

最终分发的软件包中，应包含下列内容：

- 编译生成的二进制可执行文件
- `libphp.so`和`libphpx.so`
- `vendor/` 目录中的开源框架和类库文件

> 若动态加载了`PHP`扩展，需要将其`.so`复制到软件包中

### 3. 是否可使用 `__FILE__` 和 `__DIR__`

由于`AOT`编译器是在编译阶段确定 `__FILE__` 和 `__DIR__` 魔术常量的值，因此最终的目录是编译时`.php`文件的路径，而不是运行时。因此虽然可以使用魔术常量，但它的结果与预期可能不一致。建议使用 `getcwd()` 或者其他配置文件方式确定最终运行时的目录。

### 4. 是否可以继承动态类

项目中如果某个类必须要继承自`vendor`目录文件定义的动态类。则必须将整个继承链上的所有类全部作为静态编译的类。

例如：
`App\Controller\TestController` 继承自 `Framework\Controller`，而`Framework\Controller`又继承自`Framework\BaseController`。那么这`3`个类就必须全部为静态编译类。

### 5. 函数参数返回值、类属性是否不标注类型？

可以。无类型标注时将自动默认为`any`类型。例如：

```php
function foo($a, $b, $c) {
}

class Bar {
    public $prop1;
    static public $prop2;
}
```

类型是非强制性的，`AOT`编译器将自动作为`any`类型编译生成指令。但建议对所有函数、类属性标注类型，明确类型可以让编译器生成性能更好代码。

### 6. Nullable 与 UnionType

由于`Nullable` 与 `UnionType` 类型不明确，`AOT`编译器在处理时会将其视为`any`类型，无法进行更多性能优化。建议使用`Nullable` 与 `UnionType`时，在逻辑链条中加入`objval`类型接续，使得编译器可以优化代码。

```php
function foo(): ?MyClass {
}

function bar(): MyClass1 | MyClass2 {
}

function main() {
    $rs = foo();
    if ($rs == null) {
        // 失败分支
    }
    // 类型接续
    $o = objval($rs, MyClass::class);
    // 生成原生方法调用
    $o->someMethod();

    $rs = bar();
    if ($rs instanceof MyClass1) {
        $o1 = objval($rs, MyClass::class);
        $o1->someMethod();
    } elseif ($rs instanceof MyClass2 ) {
        $o2 = objval($rs, MyClass::class);
        $o2->someMethod();
    }
}
```