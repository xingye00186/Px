以下是从 AOT 编译器文档中提取的**性能提升、编译器优化及避免 ZendVM 调用的完整指南*。

---

## 一、使用原生类型（`use native_types`）

- **作用**：将局部变量中的 `int`、`float`、`bool` 映射为原生 C++ 类型（`php::Int`、`php::Float`、`php::Bool`），消除 `zval` 包装和引用计数开销。
- **效果**：算术运算直接生成 `int64_t` 机器指令，性能接近手写 C 代码。
- **示例**：
  ```php
  use native_types;
  function sum(int $n): int {
      $total = 0;   // php::Int，无 zval
      while($n--) $total += $n;
      return $total;
  }
  ```
- **注意**：未使用 `native_types` 时，变量默认为 `php::Var`（动态类型），每次运算都有类型检查和 `zval` 装箱开销。

## 二、高精度类型字面量指令（`use bigint_types` / `use decimal_types`）

- **`use bigint_types`**：将文件中所有整数字面量自动转为 `BigInt`，避免隐式转换为 `float` 导致精度丢失和后续动态调用。
- **`use decimal_types`**：将文件中所有浮点字面量自动转为 `Decimal`，避免二进制浮点误差，且运算直接调用底层 C++ 库（libmpdec）。
- **注意**：这些类型仍然是不可变的，但运算符和方法调用均编译为直接 C++ 函数调用，无 ZendVM 介入。

## 三、为函数/类属性标注具体类型

- **目的**：让编译器精确推断变量类型，生成针对性的原生操作，而非退化为 `any`（`php::Var`）。
- **效果**：
  - 参数和返回值类型明确 → 函数调用可优化为 Native Call（直接 C++ 函数调用），绕过 `zend_call_function`。
  - 对象属性类型明确 → 属性读写转换为结构体偏移量的直接内存访问，等同于 C struct。
- **避免**：使用 `Nullable` 或 `UnionType` → 会退化为 `any`，失去优化机会。若必须使用，请配合 `toObject()` 或 `to*()` 接续类型。

## 四、类型接续：从 `any` 恢复具体类型

当从数组、动态函数返回值等获得 `mixed` 变量时，编译器丢失类型信息。使用以下方法重建类型，使后续调用变为 Native Call：

- **对象**：`$obj->toObject(ClassName::class)` → 编译器得知具体类，方法调用转为静态 C++ 函数调用。
- **Stream**：`stream_cast($stream)` → 重建为 `php::Stream`，后续 `read`/`write` 等调用变为直接 C 函数。
- **基础类型**：使用 `(int)`、`intval()` 等转换，或 `toInt()`/`toString()` 等关键词方法。
- **示例**：
  ```php
  $user = $array['user']->toObject(User::class);
  $user->getName();   // Native Call，无 ZendVM
  ```

## 五、使用 `to*` 关键词方法而非强制转换

- **`toInt()`、`toString()`、`toStream()`、`toBigInt()`** 等是编译期关键词，直接映射到 C++ 类型转换函数，零运行时开销。
- **优势**：可链式调用，且编译器能精确推断返回类型，后续方法继续享受优化。
- **避免**：`(object)` 转换只产生 `php::Object`，丢失类信息 → 方法调用仍走 ZendVM。

## 六、使用原生函数/类方法（AOT 编译后的 PHP 函数）

- 由 AOT 编译器编译的 PHP 函数/方法会成为**原生函数**，调用时直接执行机器码，无 Opcode 解释、无动态分发。
- **条件**：函数必须在编译时可知（非动态调用），且参数/返回值类型明确。
- **效果**：递归可被尾递归优化；小函数可被内联。
- **避免**：动态调用 `$fn()`、`call_user_func()`、`eval()` 定义的函数 → 强制走 ZendVM。

## 七、将内置函数/类方法调用转为已知调用

- 内置函数（如 `strlen`、`array_push`）在编译期可确定，编译器会将函数指针缓存至函数表，每次调用无需查询 `EG(function_table)`，直接通过指针调用 ZendVM 的 `known function` 处理函数。
- 虽仍需 ZendVM 执行，但省去了符号查找开销。

## 八、使用 Std 容器（零开销 C++ 容器）

- `std::vector`、`std::array`、`std::map`、`std::unordered_map` 直接映射到 C++ 标准库容器，无 Zend HashTable 开销。
- **类型安全**：容器内元素类型固定，编译期检查，无类型转换。
- **访问**：`$v[$i]` 编译为 `v_ref.offsetGet(i)`，直接调用 C++ 成员函数，无 ZendVM 参与。
- **注意**：避免在 Std 容器上使用 `&$ref` 引用传递（不支持），但可通过 `std::unsafe_cast` 跨函数零拷贝传递。

## 九、利用写时复制（COW）减少内存复制

- `php::Str`、`php::Array` 使用 Zend 的引用计数 + COW。多个变量共享同一数据时仅增加引用计数，修改时自动分离。
- **效果**：赋值无内存复制，性能接近指针传递。
- **注意**：COW 对 PHP 代码透明，无需额外操作。

## 十、值类型直接复制（无 GC 开销）

- `php::Int`、`php::Float`、`php::Bool`、`null` 均存储在 `zval` 内联，不分配堆内存，复制时直接 `memcpy`，无引用计数操作。
- 使用 `use native_types` 后这些类型完全原生。

## 十一、禁用不必要的优化（调试时）

- 生产环境应使用 `-O2` 或 `-O3` 优化级别，启用内联、循环展开、向量化等。
- 调试时使用 `-O0` 避免内联影响断点。

## 十二、避免导致 ZendVM 回退的动态特性

- **禁止**：
  - `$$var` 可变变量
  - `extract()` 运行时创建变量
  - `yield`/`generator`
  - 多层 `break`/`continue`
  - 参数数量不匹配的函数调用
  - 动态类继承（继承链中任何类为非静态编译）
- **限制**：`eval()`、动态函数定义、`call_user_func` 等会强制走 ZendVM，应尽量避免。

## 十三、使用 `any()` 主动放弃类型跟踪（仅在必要时）

- 当变量确实需要持有多种不同类型时（如不同分支返回不同类对象），使用 `any($value)` 标注为 `php::Var`，避免编译器的静态类型冲突。
- 注意：这会退化为动态类型，后续方法调用将走 ZendVM。仅在不追求极致性能的分支使用。

## 十四、优化循环与属性访问

- 对象属性若声明为 `int`/`float`/`bool`，编译器会生成直接内存偏移访问，在循环中性能极高。
- 示例：
  ```php
  class Obj {
      public int $a;
      function foo() {
          $n = 10000000;
          while($n--) $this->a += $n;   // 直接操作 C++ int64_t
      }
  }
  ```

## 十五、总结：性能优化优先级

| 优化措施 | 效果 | 是否避免 ZendVM |
|---------|------|----------------|
| `use native_types` | 消除 zval 装箱 | 是（原生整数运算） |
| 类型标注 + `toObject()` | Native Call | 是 |
| 使用 `to*` 关键词 | 编译期转换 | 是 |
| Std 容器 | 零开销 C++ 容器 | 是 |
| 避免动态特性 | 防止回退 ZendVM | 是 |
| `use bigint_types` / `decimal_types` | 高精度直接 C++ 调用 | 是 |
| 内置函数已知调用 | 省去符号查找 | 否（但仍调用 ZendVM 内置 handler） |

以上指南涵盖了文档中所有与性能提升、编译器优化以及避免 ZendVM 调用相关的内容。按照这些建议编写的 PHP 代码将在 AOT 编译后获得接近原生 C++ 的执行效率。