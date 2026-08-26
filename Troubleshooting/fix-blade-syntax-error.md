# Troubleshooting: SSID List View Error

## Error
```
ParseError: resources\views\invoice-periods\partials\ssid-list.blade.php:1132
syntax error, unexpected end of file, expecting "elseif" or "else" or "endif"
```

## Cause
Blade template had mismatched `@if/@endif` blocks and duplicate HTML elements.

## Solution Applied

### 1. Rewrote the entire `ssid-list.blade.php` with correct structure

**Structure:**
```blade
{{-- Main container --}}
<div class="p-6">

    {{-- State: HAS DATA ($invoiceLinesCount > 0) --}}
    @if($invoiceLinesCount > 0)
        {{-- CTA Buttons --}}
        {{-- Complete Section --}}
        {{-- Import Section --}}
        {{-- Pending SSID Section (if any) --}}
        {{-- Data Table --}}
    @endif

    {{-- State: NO DATA ($invoiceLinesCount == 0) --}}
    @if($invoiceLinesCount == 0)
        {{-- Placeholder with inline import form --}}
    @endif

</div>

{{-- Modals (outside main div) --}}
{{-- Add SSID Modal --}}
{{-- Edit SSID Modal --}}
```

### 2. Key Blade Directives Used

- `@if(condition)` / `@endif` - Standard if blocks
- `@foreach($collection as $item)` / `@endforeach` - Loop iteration
- `@php` blocks NOT used in final version

### 3. Common Blade Syntax Errors

1. **Missing `@endif`** - Every `@if` must have closing `@endif`
2. **Mismatched brackets** - Check all HTML tags are properly closed
3. **Quote mismatch** - Use consistent quotes (`'` or `"`)
4. **Duplicate IDs** - Each `id=""` must be unique in the document

## Prevention

1. Keep Blade templates simple - avoid deeply nested conditions
2. Use `@php` blocks for complex logic, then use simple `@if` in template
3. Use a code formatter/beautifier for Blade files
4. Test each section incrementally

## Verification

After any change to Blade files:
1. Clear Laravel cache: `php artisan view:clear`
2. Check syntax: Use IDE highlighting or `blade-formatter`
3. Test in browser

## Related Files

- `resources/views/invoice-periods/partials/ssid-list.blade.php`
- `app/Http/Controllers/InvoicePeriodController.php` (step1 method)
