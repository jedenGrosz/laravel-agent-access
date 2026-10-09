@extends('agent-access::layout')
@section('title', __('Access grants'))
@section('content')
<h1>{{ __('Access grants') }}</h1><p class="muted">{{ __('Revocation blocks future attempts. Work already admitted may still complete.') }}</p>
<div class="card table"><table><thead><tr><th>{{ __('Action / resource') }}</th><th>{{ __('Recipient') }}</th><th>{{ __('Uses') }}</th><th>{{ __('Expires') }}</th><th>{{ __('Manage') }}</th></tr></thead><tbody>
@forelse($records['data'] as $item)<tr><td>{{ $item['action'] }}<br><span class="muted small">{{ $item['resource_type'] ?? '—' }} {{ $item['resource_id'] ?? '' }}</span></td><td>{{ $item['principal_id'] }}</td><td>{{ $item['uses'] }} / {{ $item['max_uses'] ?? '∞' }}</td><td class="small">{{ $item['expires_at'] }}</td><td>@if($item['revoked_at'])<span class="badge">{{ __('Revoked') }}</span>@else<form method="post" action="{{ route('agent-access.panel.revoke',['id'=>$item['id']]) }}">@csrf<button class="secondary">{{ __('Revoke') }}</button></form>@endif</td></tr>
@empty<tr><td colspan="5" class="empty">{{ __('No visible grants in this scan window.') }}</td></tr>@endforelse
</tbody></table></div>
@if($records['next_cursor'])<a class="button secondary" href="{{ route('agent-access.panel.grants',['cursor'=>$records['next_cursor']]) }}">{{ __('Continue') }}</a>@endif
@endsection
