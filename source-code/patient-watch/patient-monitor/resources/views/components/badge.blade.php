@props(['value'])
<span {{ $attributes->class(['badge', 'badge-'.str_replace('_', '-', $value)]) }}>{{ ucfirst(str_replace('_', ' ', $value)) }}</span>
