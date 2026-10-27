@props(['for'])
@error($for)
    <p id="{{ $for }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
@enderror
