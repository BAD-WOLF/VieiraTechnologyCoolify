<x-emails.layout>
# {{ $title }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($actionUrl)
[{{ $actionText }}]({{ $actionUrl }})
@endif
</x-emails.layout>
