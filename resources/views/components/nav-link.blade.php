@props(['route', 'active' => null])
@php($isActive = request()->routeIs(...explode(',', $active ?? $route)))
<a href="{{ route($route) }}"
   @if ($isActive) aria-current="page" @endif
   {{ $attributes->class([
       'block rounded-md px-3 py-2 text-sm font-medium',
       'bg-charcoal-800 text-white' => $isActive,
       'text-zinc-300 hover:bg-charcoal-800 hover:text-white' => ! $isActive,
   ]) }}>{{ $slot }}</a>
