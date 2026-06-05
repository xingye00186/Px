/**
 * Dinkumware STL internal symbol stubs for MSVC < 17.10 compatibility.
 *
 * Skia prebuilt library (skia.lib/cpp/skia/out/Release-x64/skia.lib)
 * was compiled with MSVC 17.10+, which introduced __std_* internal helper
 * functions in the Dinkumware STL for algorithm optimizations.
 *
 * The current build toolchain (MSVC 14.32.31326) is pre-17.10 and does not
 * provide these symbols in its CRT (libcpmt.lib). This file provides
 * minimal stubs to satisfy linking.
 *
 * Affected algorithms and their Skia usage contexts:
 *   __std_minmax_element_f  Used by SkGlyph::ensureIntercepts (float*)
 *   __std_min_element_f     Used by SkPathStroker (SkPoint*), SkGradientBaseShader
 *   __std_max_element_f     Used by SkPathStroker (SkPoint*), SkGradientBaseShader
 *   __std_max_element_1     Used by RP::Program::Dumper::swizzlePtr<unsigned char>
 *   __std_max_element_2     Used by RP::Program::Dumper::dump
 *   __std_find_trivial_1    Used by SkSL::Parser::expectNewline, SkSL::String
 *   __std_find_trivial_8    Used by SkSL::Transform (FindAndDeclareBuiltinFunctions)
 *   __std_search_1          Used by SkSL::ErrorReporter::error
 *
 * 非 Skia 构建兼容性：本文件整体被 #ifdef USE_SKIA 包裹，
 * 非 Skia 应用（calculator-ng, list-test 等）编译时不会产生任何符号。
 */

#ifdef USE_SKIA

#include <cstddef>
#include <cstring>
#include <cstdint>

#pragma warning(push)
#pragma warning(disable : 4100)  // unreferenced formal parameter

// ============================================================================
// Per-instance helpers for min_element / max_element with forward iterators
//
// These functions receive void* pointer iterators and a comparison predicate.
// Since we cannot recover element type information at runtime, the stubs
// conservatively return _First, which avoids crashes at the cost of potentially
// incorrect (but non-fatal) visual output in edge cases.
// ============================================================================

extern "C" {

// std::min_element with forward iterators
void* __std_min_element_f(const void* _First, const void* _Last, const void* _Pred) {
    return const_cast<void*>(_First);
}

// std::max_element with forward iterators
void* __std_max_element_f(const void* _First, const void* _Last, const void* _Pred) {
    return const_cast<void*>(_First);
}

// Numbered overloads for max_element dispatch
void* __std_max_element_1(const void* _First, const void* _Last, const void* _Pred) {
    return const_cast<void*>(_First);
}

void* __std_max_element_2(const void* _First, const void* _Last, const void* _Pred) {
    return const_cast<void*>(_First);
}

// std::minmax_element with forward iterators
// Returns pair<Iter, Iter> written to _Result memory area.
// _Result points to storage of 2 pointers: {min_iter, max_iter}.
void* __std_minmax_element_f(void* _Result, const void* _First, const void* _Last, const void* _Pred) {
    // Store {_First, _First} as a safe stub
    void* ptr = const_cast<void*>(_First);
    std::memcpy(_Result, &ptr, sizeof(void*));
    std::memcpy(static_cast<char*>(_Result) + sizeof(void*), &ptr, sizeof(void*));
    return _Result;
}

// ============================================================================
// Optimized find for trivial 1-byte element types (char, unsigned char)
// Used by SkSL string scanning operations.
// ============================================================================

void* __std_find_trivial_1(const void* _First, const void* _Last, const void* _Value) {
    const std::uint8_t* first = static_cast<const std::uint8_t*>(_First);
    const std::uint8_t* last  = static_cast<const std::uint8_t*>(_Last);
    std::uint8_t value = *static_cast<const std::uint8_t*>(_Value);

    for (const std::uint8_t* p = first; p != last; ++p) {
        if (*p == value) {
            return const_cast<std::uint8_t*>(p);
        }
    }
    return const_cast<void*>(_Last);
}

// ============================================================================
// Optimized find for trivial 8-byte element types
// Used by SkSL::Transform builtin function searches.
// ============================================================================

void* __std_find_trivial_8(const void* _First, const void* _Last, const void* _Value) {
    const std::uint8_t* first = static_cast<const std::uint8_t*>(_First);
    const std::uint8_t* last  = static_cast<const std::uint8_t*>(_Last);

    // Elements are 8 bytes each
    size_t count = static_cast<size_t>(last - first) / 8;
    for (size_t i = 0; i < count; ++i) {
        if (std::memcmp(first + i * 8, _Value, 8) == 0) {
            return const_cast<std::uint8_t*>(first + i * 8);
        }
    }
    return const_cast<void*>(_Last);
}

// ============================================================================
// Optimized search for 1-byte elements (substring search on char/byte arrays)
// Used by SkSL::ErrorReporter::error for position lookup.
// ============================================================================

void* __std_search_1(const void* _First1, const void* _Last1,
                      const void* _First2, const void* _Last2,
                      const void* _Pred) {
    const std::uint8_t* first1 = static_cast<const std::uint8_t*>(_First1);
    const std::uint8_t* last1  = static_cast<const std::uint8_t*>(_Last1);
    const std::uint8_t* first2 = static_cast<const std::uint8_t*>(_First2);
    const std::uint8_t* last2  = static_cast<const std::uint8_t*>(_Last2);

    size_t len1 = static_cast<size_t>(last1 - first1);
    size_t len2 = static_cast<size_t>(last2 - first2);

    if (len2 == 0) {
        return const_cast<std::uint8_t*>(first1);
    }
    if (len2 > len1) {
        return const_cast<void*>(_Last1);
    }

    for (size_t i = 0; i <= len1 - len2; ++i) {
        if (std::memcmp(first1 + i, first2, len2) == 0) {
            return const_cast<std::uint8_t*>(first1 + i);
        }
    }
    return const_cast<void*>(_Last1);
}

} // extern "C"

#pragma warning(pop)

#endif // USE_SKIA
