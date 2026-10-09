@props(['value'])
{{ $value ? $value->copy()->timezone(config('monitor.display_timezone'))->format('M d, Y · h:i:s A') : 'Not recorded' }}
