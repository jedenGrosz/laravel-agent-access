@extends('agent-access::layout')
@section('title', __('Access requests'))
@section('content')
<h1>{{ __('Access requests') }}</h1><p class="muted">{{ __('Review the exact scope before deciding. Approval never runs the action automatically.') }}</p>
<form method="get" class="actions"><label for="status">{{ __('Status') }}</label><select id="status" name="status">@foreach(['pending','approved','rejected','cancelled','expired'] as $choice)<option @selected($status === $choice)>{{ $choice }}</option>@endforeach</select><button class="secondary">{{ __('Filter') }}</button></form>
<div class="card table"><table><thead><tr><th>{{ __('Action') }}</th><th>{{ __('Recipient') }}</th><th>{{ __('Resource') }}</th><th>{{ __('Status') }}</th><th>{{ __('Review') }}</th></tr></thead><tbody>
@forelse($records['data'] as $item)<tr><td>{{ $item['action'] }}</td><td>{{ $item['principal_id'] }}</td><td>{{ $item['resource_type'] ?? '—' }} {{ $item['resource_id'] ?? '' }}</td><td><span class="badge">{{ $item['status'] }}</span></td><td><a href="{{ route('agent-access.panel.request', ['id'=>$item['id']]) }}">{{ __('Open request') }}</a></td></tr>
@empty<tr><td colspan="5" class="empty">{{ __('No visible requests in this scan window.') }}</td></tr>@endforelse
</tbody></table></div>
@if($records['next_cursor'])<a class="button secondary" href="{{ route('agent-access.panel.requests', ['cursor'=>$records['next_cursor'],'status'=>$status]) }}">{{ __('Continue') }}</a>@endif
@endsection
